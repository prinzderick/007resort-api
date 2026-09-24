<?php

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Services\MediaService;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Batch media lookups and the `xMediaId` -> `x` expansion for public responses (docs/CMS_API.md "Media references"). */
final class MediaResolver
{
    /** @var array<string, array<string, mixed>|null> */
    private array $cache = [];

    public function __construct(private readonly MediaService $media) {}

    /** @param iterable<?string> $ids canonical UUIDs (nulls ignored) */
    public function load(iterable $ids): void
    {
        $missing = [];
        foreach ($ids as $id) {
            if ($id !== null && ! array_key_exists($id, $this->cache)) {
                $missing[$id] = Ids::toBinary($id);
            }
        }
        if ($missing === []) {
            return;
        }
        $rows = DB::table('cms_media')->whereIn('id', array_values($missing))->get()->keyBy(fn ($r) => Ids::fromBinary($r->id));
        foreach ($missing as $id => $_) {
            $this->cache[$id] = isset($rows[$id]) ? $rows[$id] : null;
        }
    }

    /** @return array<string, mixed>|null */
    public function public(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $this->load([$id]);

        return isset($this->cache[$id]) ? $this->media->publicShape($this->cache[$id]) : null;
    }

    /** @return array<string, mixed>|null */
    public function admin(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $this->load([$id]);

        return isset($this->cache[$id]) ? $this->media->adminShape($this->cache[$id]) : null;
    }

    /** @return list<string> every media id referenced by an `xMediaId` key anywhere in the structure */
    public static function collect(mixed $data): array
    {
        $ids = [];
        $walk = function (mixed $v) use (&$walk, &$ids): void {
            if (! is_array($v)) {
                return;
            }
            foreach ($v as $k => $x) {
                if (is_string($k) && preg_match('/[Mm]ediaId$/', $k) === 1 && is_string($x) && Ids::isUuid($x)) {
                    $ids[$x] = true;
                } else {
                    $walk($x);
                }
            }
        };
        $walk($data);

        return array_keys($ids);
    }

    /** Replace `xMediaId` by `x` (Media object or null), recursively. */
    public function expand(mixed $data): mixed
    {
        $this->load(self::collect($data));

        return $this->expandLoaded($data);
    }

    private function expandLoaded(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && preg_match('/^(.*?)[Mm]ediaId$/', $k, $m) === 1 && (is_string($x) || $x === null)) {
                $out[lcfirst($m[1]) !== '' ? lcfirst($m[1]) : 'media'] = $x === null ? null : $this->public($x);
            } else {
                $out[$k] = $this->expandLoaded($x);
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> id => admin Media, for the `media` map of settings / home-section lists */
    public function adminMap(mixed $data): array
    {
        $ids = self::collect($data);
        $this->load($ids);
        $map = [];
        foreach ($ids as $id) {
            if (($m = $this->admin($id)) !== null) {
                $map[$id] = $m;
            }
        }

        return $map;
    }
}
