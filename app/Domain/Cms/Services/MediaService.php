<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Support\Cms;
use App\Domain\Cms\Support\CmsAudit;
use App\Domain\Cms\Support\Rows;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Image upload pipeline: real type by content (finfo + getimagesize), no SVG, decode -> (EXIF orientation) -> re-encode with GD, which
 * drops EXIF/GPS/ICC/appended payloads by construction, then width-limited WebP variants + dominant colour. Files live on
 * `config('cms.media.disk')` (default `public`, so `storage/app/public/cms/YYYY/MM/...` behind the storage symlink); `CMS_MEDIA_URL`
 * can point at a CDN / the other node's origin.
 */
class MediaService
{
    private const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_AVIF => ['image/avif', 'avif'],
    ];

    public function __construct(private readonly MediaUsage $usage) {}

    public function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? (string) config('cms.media.disk', 'public'));
    }

    public function url(string $path, ?string $disk = null): string
    {
        if (($base = config('cms.media.url')) !== null && $base !== '') {
            return rtrim((string) $base, '/').'/'.ltrim($path, '/');
        }

        $url = $this->disk($disk)->url($path);

        return preg_match('#^https?://#i', $url) === 1 ? $url : url($url); // always absolute (a relative disk URL is resolved against the request host)
    }

    /**
     * @param  array{alt?: ?string, credit?: ?string, sourceUrl?: ?string, tags?: list<string>}  $meta
     * @return array<string, mixed> admin shape
     */
    public function upload(UploadedFile $file, array $meta = [], ?string $forcedId = null): array
    {
        if (! $file->isValid()) {
            throw match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => new ApiProblem(413, 'media_too_large', 'The file is larger than the allowed size.', 'Payload too large'),
                default => ApiProblem::unprocessable('validation_failed', 'The upload failed.', ['file' => ['The upload failed.']]),
            };
        }

        return $this->ingest((string) $file->getRealPath(), $file->getClientOriginalName(), $meta, $forcedId);
    }

    /** @param array<string, mixed> $meta @return array<string, mixed> */
    public function ingest(string $path, ?string $originalName, array $meta = [], ?string $forcedId = null): array
    {
        $size = (int) filesize($path);
        if ($size > Cms::MEDIA_MAX_BYTES) {
            throw new ApiProblem(413, 'media_too_large', 'Images may be at most 8 MB.', 'Payload too large');
        }
        if ($size === 0) {
            throw ApiProblem::unprocessable('validation_failed', 'The file is empty.', ['file' => ['The file is empty.']]);
        }
        $head = (string) file_get_contents($path, false, null, 0, 512);
        $finfo = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (stripos($head, '<svg') !== false || $finfo === 'image/svg+xml' || stripos($finfo, 'xml') !== false || $finfo === 'text/html') {
            throw new ApiProblem(415, 'media_type_unsupported', 'SVG and other non-raster files are not accepted.', 'Unsupported media type');
        }
        $info = @getimagesize($path);
        if ($info === false || ! isset(self::TYPES[$info[2]]) || ! in_array($finfo, Cms::MEDIA_MIME, true) || self::TYPES[$info[2]][0] !== $finfo) {
            throw new ApiProblem(415, 'media_type_unsupported', 'Only JPEG, PNG, WebP and AVIF images are accepted (detected: '.($finfo ?: 'unknown').').', 'Unsupported media type');
        }
        [$width, $height, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000 || $width * $height > 24_000_000) {
            throw ApiProblem::unprocessable('validation_failed', 'Image dimensions are too large (max 8000 px per side, 24 megapixels).', ['file' => ['Image dimensions are too large.']]);
        }
        [$mime, $ext] = self::TYPES[$type];

        @ini_set('memory_limit', '768M');
        $bytes = (string) file_get_contents($path);
        $img = @imagecreatefromstring($bytes);
        unset($bytes);
        if (! $img instanceof GdImage) {
            throw ApiProblem::unprocessable('validation_failed', 'The image could not be decoded.', ['file' => ['The image could not be decoded.']]);
        }
        if ($type === IMAGETYPE_JPEG) {
            $img = $this->orient($img, $path);
        }
        $width = imagesx($img);
        $height = imagesy($img);
        imagesavealpha($img, true);

        $id = $forcedId ?? Ids::uuid7();
        $dir = 'cms/'.now('UTC')->format('Y/m');
        $base = "{$dir}/{$id}";
        $original = $this->encode($img, $type);
        $disk = $this->disk();
        $diskName = (string) config('cms.media.disk', 'public');
        $written = [];
        try {
            $disk->put("{$base}.{$ext}", $original);
            $written[] = "{$base}.{$ext}";
            $variants = [];
            $widths = array_values(array_filter([480, 960, 1600], fn ($w) => $w < $width));
            if ($width <= 1600) {
                $widths[] = $width;
            }
            foreach ($widths as $w) {
                $scaled = $w === $width ? $img : imagescale($img, $w, -1, IMG_BICUBIC);
                if (! $scaled instanceof GdImage) {
                    continue;
                }
                $webp = $this->encode($scaled, IMAGETYPE_WEBP);
                if ($scaled !== $img) {
                    imagedestroy($scaled);
                }
                $disk->put("{$base}-{$w}.webp", $webp);
                $written[] = "{$base}-{$w}.webp";
                $variants[] = ['width' => $w, 'format' => 'webp', 'path' => "{$base}-{$w}.webp", 'sizeBytes' => strlen($webp)];
            }
            $color = $this->dominantColor($img);
            imagedestroy($img);

            $row = [
                'id' => Ids::toBinary($id), 'path' => "{$base}.{$ext}", 'disk' => $diskName, 'original_name' => $originalName === null ? null : mb_substr(basename($originalName), 0, 255),
                'mime_type' => $mime, 'size_bytes' => strlen($original), 'width' => $width, 'height' => $height, 'dominant_color' => $color, 'sha256' => hash('sha256', $original),
                'alt' => mb_substr((string) ($meta['alt'] ?? ''), 0, 300), 'credit' => $meta['credit'] ?? null, 'source_url' => $meta['sourceUrl'] ?? null,
                'tags' => Rows::enc(array_values($meta['tags'] ?? [])), 'variants' => Rows::enc($variants),
                'created_by' => Rows::bin(RequestContext::staffId()), 'created_at' => Rows::now(), 'updated_at' => Rows::now(),
            ];
            DB::transaction(function () use ($row, $id, $variants) {
                DB::table('cms_media')->insert($row);

                CmsAudit::record('cms.media.upload', 'CmsMedia', $id, null, ['path' => $row['path'], 'mimeType' => $row['mime_type'], 'width' => $row['width'], 'height' => $row['height'], 'sizeBytes' => $row['size_bytes'], 'variants' => count($variants)]);

                CmsSync::mediaUploaded($id, $row, $variants);
            });
        } catch (\Throwable $e) {
            foreach ($written as $w) {
                $disk->delete($w);
            }
            throw $e;
        }

        return $this->adminShape(DB::table('cms_media')->where('id', Ids::toBinary($id))->first());
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    public function update(string $id, array $d): array
    {
        return DB::transaction(function () use ($id, $d) {
            $row = DB::table('cms_media')->where('id', Ids::toBinary($id))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Media not found.');
            $old = ['alt' => $row->alt, 'credit' => $row->credit, 'sourceUrl' => $row->source_url, 'tags' => Rows::json($row->tags)];
            $set = [];
            foreach (['alt' => 'alt', 'credit' => 'credit', 'sourceUrl' => 'source_url'] as $in => $col) {
                if (array_key_exists($in, $d)) {
                    $set[$col] = $in === 'alt' ? (string) ($d[$in] ?? '') : $d[$in];
                }
            }
            if (array_key_exists('tags', $d)) {
                $set['tags'] = Rows::enc(array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $d['tags'] ?? []))));
            }
            if ($set !== []) {
                DB::table('cms_media')->where('id', $row->id)->update($set + ['row_version' => $row->row_version + 1, 'updated_at' => Rows::now()]);
                $new = DB::table('cms_media')->where('id', $row->id)->first();
                CmsAudit::record('cms.media.update', 'CmsMedia', $id, $old, ['alt' => $new->alt, 'credit' => $new->credit, 'sourceUrl' => $new->source_url, 'tags' => Rows::json($new->tags)]);
            }

            return $this->adminShape(DB::table('cms_media')->where('id', $row->id)->first());
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = DB::table('cms_media')->where('id', Ids::toBinary($id))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Media not found.');
            $usage = $this->usage->of($id);
            if ($usage !== []) {
                throw ApiProblem::conflict('media_in_use', 'This media is still used; remove it from the content that references it first.', ['usage' => $usage, 'usageCount' => count($usage)]);
            }
            DB::table('cms_media')->where('id', $row->id)->delete();
            CmsAudit::record('cms.media.delete', 'CmsMedia', $id, ['path' => $row->path, 'alt' => $row->alt], null);
            $paths = [$row->path, ...array_column(Rows::json($row->variants), 'path')];
            DB::afterCommit(function () use ($paths, $row) {
                $disk = $this->disk($row->disk);
                foreach ($paths as $p) {
                    $disk->delete($p);
                }
            });
        });
    }

    /** @return array<string, mixed> */
    public function publicShape(object $r): array
    {
        return [
            'id' => Rows::id($r->id), 'url' => $this->url($r->path, $r->disk), 'width' => (int) $r->width, 'height' => (int) $r->height, 'mimeType' => $r->mime_type,
            'alt' => $r->alt, 'credit' => $r->credit, 'dominantColor' => $r->dominant_color,
            'variants' => array_map(fn ($v) => ['width' => (int) $v['width'], 'format' => $v['format'], 'url' => $this->url($v['path'], $r->disk)], Rows::json($r->variants)),
        ];
    }

    /** @return array<string, mixed> */
    public function adminShape(object $r): array
    {
        $id = Rows::id($r->id);

        return $this->publicShape($r) + [
            'sourceUrl' => $r->source_url, 'tags' => Rows::json($r->tags), 'sizeBytes' => (int) $r->size_bytes, 'originalName' => $r->original_name,
            'usageCount' => count($this->usage->of($id)), 'rowVersion' => (int) $r->row_version, 'createdAt' => Rows::iso($r->created_at), 'updatedAt' => Rows::iso($r->updated_at),
        ];
    }

    private function orient(GdImage $img, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };

        return $rotated instanceof GdImage ? $rotated : $img;
    }

    private function encode(GdImage $img, int $type): string
    {
        ob_start();
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($this->flatten($img), null, 86),
            IMAGETYPE_PNG => imagepng($img, null, 6),
            IMAGETYPE_WEBP => imagewebp($img, null, 80),
            IMAGETYPE_AVIF => imageavif($img, null, 60),
        };

        return (string) ob_get_clean();
    }

    private function flatten(GdImage $img): GdImage
    {
        return $img; // JPEG sources have no alpha; nothing to flatten
    }

    private function dominantColor(GdImage $img): string
    {
        $one = imagecreatetruecolor(1, 1);
        imagecopyresampled($one, $img, 0, 0, 0, 0, 1, 1, imagesx($img), imagesy($img));
        $c = imagecolorat($one, 0, 0);
        imagedestroy($one);

        return sprintf('#%02x%02x%02x', ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
    }
}
