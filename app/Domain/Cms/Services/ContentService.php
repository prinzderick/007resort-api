<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Resources\ContentResource;
use App\Domain\Cms\Support\CmsAudit;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Rows;
use App\Domain\Cms\Support\Slugs;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Generic create / update / delete / publish / reorder for a {@see ContentResource}: locking, ETag, slug rules, audit and outbox in one transaction. */
class ContentService
{
    public function __construct(private readonly MediaResolver $media) {}

    public function find(ContentResource $res, string $id): object
    {
        return (Ids::isUuid($id) ? DB::table($res->table())->where('id', Ids::toBinary($id))->first() : null) ?? throw ApiProblem::notFound('not_found', 'Not found.');
    }

    /** @return array<string, mixed> */
    public function present(ContentResource $res, object $row): array
    {
        $this->media->load($res->mediaIds($row));

        return $res->admin($row, $this->media);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(ContentResource $res, array $input, bool $create): array
    {
        $rules = [];
        foreach ($res->fields() as $field => $fieldRules) {
            $top = ! str_contains($field, '.');
            if ($top && in_array($field, $res->required(), true)) {
                $rules[$field] = $create ? ['required', ...$fieldRules] : ['sometimes', 'required', ...$fieldRules];
            } else {
                $rules[$field] = $top ? ['sometimes', 'nullable', ...$fieldRules] : $fieldRules;
            }
        }
        $clean = [];
        foreach ($input as $k => $v) {
            $clean[$k] = is_string($v) ? (trim($v) === '' && $k !== 'bodyMarkdown' ? null : trim($v)) : $v;
        }

        return Validator::make($clean, $rules)->validate();
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    public function create(ContentResource $res, array $d): array
    {
        $res->beforeSave($d, null);
        $id = Ids::uuid7();
        $cols = $res->columns($d, null);
        if ($res->hasSlug()) {
            $slug = $d['slug'] ?? null;
            if ($slug !== null) {
                Slugs::assertFree($res->table(), $slug);
            } else {
                $slug = Slugs::generate($res->table(), (string) ($d[$res->titleField()] ?? 'item'));
            }
            $cols['slug'] = $slug;
        }
        if ($res->key() === 'home-sections' && ! array_key_exists('sort_order', $cols)) {
            $cols['sort_order'] = (int) DB::table($res->table())->max('sort_order') + 10;
        }
        $row = DB::transaction(function () use ($res, $id, $cols) {
            DB::table($res->table())->insert($cols + ['id' => Ids::toBinary($id), 'row_version' => 1, 'created_by' => Rows::bin(RequestContext::staffId()), 'created_at' => Rows::now(), 'updated_at' => Rows::now()]
                + ($res->hasStatus() ? ['status' => 'DRAFT'] : []));
            $row = DB::table($res->table())->where('id', Ids::toBinary($id))->first();
            CmsAudit::record("cms.{$res->entity()}.create", 'Cms'.Str::studly($res->entity()), $id, null, $this->snapshot($row));
            CmsSync::content($res->entity(), $id, 'create', 1, $this->snapshot($row));

            return $row;
        });

        return $this->present($res, $row);
    }

    /** @param array<string, mixed> $d @return array{0: array<string, mixed>, 1: object} */
    public function update(ContentResource $res, string $id, array $d, Request $request): array
    {
        return DB::transaction(function () use ($res, $id, $d, $request) {
            $row = DB::table($res->table())->where('id', Ids::toBinary($id))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Not found.');
            $expected = Concurrency::ifMatch($request, $res->requireIfMatch());
            Concurrency::assertVersion((int) $row->row_version, $expected, $res->entity());
            $res->beforeSave($d, $row);
            $cols = $res->columns($d, $row);
            if ($res->hasSlug() && array_key_exists('slug', $d) && $d['slug'] !== null && $d['slug'] !== $row->slug) {
                Slugs::assertFree($res->table(), $d['slug'], $id);
                $cols['slug'] = $d['slug'];
            }
            $changed = array_filter($cols, fn ($v, $k) => ! $this->same($row->{$k} ?? null, $v), ARRAY_FILTER_USE_BOTH);
            if ($changed === []) {
                return [$this->present($res, $row), $row];
            }
            DB::table($res->table())->where('id', $row->id)->update($changed + ['row_version' => $row->row_version + 1, 'updated_at' => Rows::now()]);
            $new = DB::table($res->table())->where('id', $row->id)->first();
            CmsAudit::record("cms.{$res->entity()}.update", 'Cms'.Str::studly($res->entity()), $id, $this->snapshot($row), $this->snapshot($new));
            CmsSync::content($res->entity(), $id, 'update', (int) $new->row_version, $this->snapshot($new));

            return [$this->present($res, $new), $new];
        });
    }

    public function delete(ContentResource $res, string $id): void
    {
        DB::transaction(function () use ($res, $id) {
            $row = DB::table($res->table())->where('id', Ids::toBinary($id))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Not found.');
            if ($res->hasStatus() && $row->status === 'PUBLISHED') {
                throw ApiProblem::conflict('must_archive_first', 'A published item must be unpublished or archived before it can be deleted.');
            }
            $res->guardDelete($row);
            DB::table($res->table())->where('id', $row->id)->delete();
            CmsAudit::record("cms.{$res->entity()}.delete", 'Cms'.Str::studly($res->entity()), $id, $this->snapshot($row), null);
            CmsSync::content($res->entity(), $id, 'delete', (int) $row->row_version + 1);
        });
    }

    /** @return array<string, mixed> */
    public function transition(ContentResource $res, string $id, string $action, ?string $publishedAt = null): array
    {
        return DB::transaction(function () use ($res, $id, $action, $publishedAt) {
            $row = DB::table($res->table())->where('id', Ids::toBinary($id))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Not found.');
            $target = match ($action) {
                'publish' => 'PUBLISHED', 'unpublish' => 'DRAFT', 'archive' => 'ARCHIVED'
            };
            $set = [];
            if ($target !== $row->status) {
                $set['status'] = $target;
            }
            if ($action === 'publish') {
                $at = $publishedAt !== null ? Rows::db(CarbonImmutable::parse($publishedAt)->utc()) : ($row->published_at ?? Rows::now());
                if ($at !== $row->published_at) {
                    $set['published_at'] = $at;
                }
            }
            if ($set === []) {
                return $this->present($res, $row);
            }
            DB::table($res->table())->where('id', $row->id)->update($set + ['row_version' => $row->row_version + 1, 'updated_at' => Rows::now()]);
            $new = DB::table($res->table())->where('id', $row->id)->first();
            CmsAudit::record("cms.{$res->entity()}.{$action}", 'Cms'.Str::studly($res->entity()), $id, ['status' => $row->status, 'publishedAt' => Rows::iso($row->published_at)], ['status' => $new->status, 'publishedAt' => Rows::iso($new->published_at)]);
            CmsSync::content($res->entity(), $id, $action, (int) $new->row_version, $this->snapshot($new));

            return $this->present($res, $new);
        });
    }

    public function setEnabled(ContentResource $res, string $id, bool $enabled): array
    {
        return DB::transaction(function () use ($res, $id, $enabled) {
            $row = DB::table($res->table())->where('id', Ids::toBinary($id))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'Not found.');
            if ((bool) $row->is_enabled === $enabled) {
                return $this->present($res, $row);
            }
            DB::table($res->table())->where('id', $row->id)->update(['is_enabled' => (int) $enabled, 'row_version' => $row->row_version + 1, 'updated_at' => Rows::now()]);
            $new = DB::table($res->table())->where('id', $row->id)->first();
            CmsAudit::record('cms.'.$res->entity().($enabled ? '.enable' : '.disable'), 'CmsHomeSection', $id, ['enabled' => ! $enabled], ['enabled' => $enabled]);
            CmsSync::content($res->entity(), $id, $enabled ? 'publish' : 'unpublish', (int) $new->row_version, $this->snapshot($new));

            return $this->present($res, $new);
        });
    }

    /** @param list<array{id: string, sortOrder: int}> $items @return int rows updated */
    public function reorder(string $table, string $entity, array $items, ?string $scopeColumn = null, ?string $scopeId = null): int
    {
        return DB::transaction(function () use ($table, $entity, $items, $scopeColumn, $scopeId) {
            $ids = array_map(fn ($i) => Ids::toBinary($i['id']), $items);
            $q = DB::table($table)->whereIn('id', $ids);
            if ($scopeColumn !== null) {
                $q->where($scopeColumn, Ids::toBinary((string) $scopeId));
            }
            $existing = $q->lockForUpdate()->get(['id', 'sort_order'])->keyBy(fn ($r) => Ids::fromBinary($r->id));
            foreach ($items as $i) {
                if (! isset($existing[$i['id']])) {
                    throw ApiProblem::unprocessable('validation_failed', 'Unknown id in reorder list.', ['items' => ["Unknown id {$i['id']}."]]);
                }
            }
            $old = [];
            $new = [];
            $n = 0;
            foreach ($items as $i) {
                $row = $existing[$i['id']];
                if ((int) $row->sort_order !== (int) $i['sortOrder']) {
                    DB::table($table)->where('id', $row->id)->update(['sort_order' => $i['sortOrder'], 'updated_at' => Rows::now()]);
                    $old[$i['id']] = (int) $row->sort_order;
                    $new[$i['id']] = (int) $i['sortOrder'];
                    $n++;
                }
            }
            if ($n > 0) {
                CmsAudit::record("cms.{$entity}.reorder", 'Cms'.Str::studly($entity), Ids::uuid7(), ['sortOrder' => $old], ['sortOrder' => $new]);
            }

            return count($items);
        });
    }

    /** Audit-safe copy of a row: uuids as strings, rendered HTML dropped. @return array<string, mixed> */
    public function snapshot(object $row): array
    {
        $out = [];
        foreach ((array) $row as $k => $v) {
            if (str_ends_with($k, '_html')) {
                continue;
            }
            $out[$k] = is_string($v) && strlen($v) === 16 && ($k === 'id' || str_ends_with($k, '_id') || $k === 'created_by') ? Ids::fromBinary($v) : $v;
        }

        return $out;
    }

    private function same(mixed $old, mixed $new): bool
    {
        if (is_string($old) && ($j = json_decode($old, true)) !== null && is_string($new) && ($j2 = json_decode($new, true)) !== null && (str_starts_with($old, '{') || str_starts_with($old, '['))) {
            return $this->ksort($j) === $this->ksort($j2);
        }

        return (string) $old === (string) $new;
    }

    private function ksort(mixed $v): mixed
    {
        if (is_array($v)) {
            $v = array_map($this->ksort(...), $v);
            if (! array_is_list($v)) {
                ksort($v);
            }
        }

        return $v;
    }
}
