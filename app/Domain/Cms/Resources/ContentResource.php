<?php

namespace App\Domain\Cms\Resources;

use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Rows;
use App\Domain\Cms\Support\Slugs;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Declarative description of one admin-managed content type. `AdminContentController` (generic CRUD + publish + reorder) drives it;
 * everything type specific (validation, column mapping, response shape, guards) lives in the subclass.
 */
abstract class ContentResource
{
    /** camelCase input => DB column (simple copies; everything else is done in extra()). */
    protected array $map = [];

    /** input names that hold a UUID (converted to BINARY(16)), booleans, and JSON values. */
    protected array $uuids = [];

    protected array $bools = [];

    protected array $jsons = [];

    abstract public function key(): string;

    abstract public function table(): string;

    /** audit / outbox entity name, e.g. `page` (audit action `cms.page.create`). */
    abstract public function entity(): string;

    public function hasStatus(): bool
    {
        return true;
    }

    public function hasSlug(): bool
    {
        return true;
    }

    public function requireIfMatch(): bool
    {
        return false;
    }

    /** @return array<string, list<mixed>> field => rules (presence handled by the controller) */
    abstract public function fields(): array;

    /** @return list<string> fields required on create */
    abstract public function required(): array;

    /** Field used to derive the slug and for `q` filtering. */
    public function titleField(): string
    {
        return 'title';
    }

    /**
     * DB columns for the validated input.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public function columns(array $d, ?object $existing): array
    {
        $out = [];
        foreach ($this->map as $in => $col) {
            if (! array_key_exists($in, $d)) {
                continue;
            }
            $v = $d[$in];
            if ($v !== null && in_array($in, $this->uuids, true)) {
                $v = Rows::bin($v);
            } elseif (in_array($in, $this->bools, true)) {
                $v = $v ? 1 : 0;
            } elseif ($v !== null && in_array($in, $this->jsons, true)) {
                $v = Rows::enc($v);
            } elseif (is_string($v) && trim($v) === '') {
                $v = null;
            }
            $out[$col] = $v;
        }

        return $out + $this->extra($d, $existing);
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    protected function extra(array $d, ?object $existing): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    abstract public function admin(object $row, MediaResolver $media): array;

    public function filter(Builder $q, Request $r): void
    {
        if ($this->hasStatus() && in_array($s = strtoupper((string) $r->query('status')), ['DRAFT', 'PUBLISHED', 'ARCHIVED'], true)) {
            $q->where('status', $s);
        }
        if (($term = trim((string) $r->query('q'))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->where($this->titleField(), 'like', $like)->when($this->hasSlug(), fn ($w2) => $w2->orWhere('slug', 'like', $like)));
        }
    }

    /** @return array{0: string, 1: string} order column and direction for the admin list */
    public function order(): array
    {
        return ['updated_at', 'desc'];
    }

    /** Throw ApiProblem to refuse a delete. */
    public function guardDelete(object $row): void {}

    /** @param array<string, mixed> $d validated input (before columns()) */
    public function beforeSave(array &$d, ?object $existing): void {}

    /** Shared header of every admin resource. @return array<string, mixed> */
    protected function head(object $r): array
    {
        $h = ['id' => Rows::id($r->id)];
        if ($this->hasSlug()) {
            $h['slug'] = $r->slug;
        }

        return $h;
    }

    /** @return array<string, mixed> */
    protected function tail(object $r): array
    {
        $t = [];
        if ($this->hasStatus()) {
            $t['status'] = $r->status;
            $t['publishedAt'] = Rows::iso($r->published_at);
        }

        return $t + ['rowVersion' => (int) $r->row_version, 'createdAt' => Rows::iso($r->created_at), 'updatedAt' => Rows::iso($r->updated_at)];
    }

    protected function uniqueSlug(string $title, ?string $exclude = null): string
    {
        return Slugs::generate($this->table(), $title, $exclude);
    }

    /** MediaIds referenced by a row, for batch loading. @return list<?string> */
    public function mediaIds(object $row): array
    {
        return [];
    }
}
