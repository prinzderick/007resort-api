<?php

namespace App\Domain\Cms\Resources;

use App\Domain\Cms\Support\HomeSectionTypes;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Rows;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HomeSectionResource extends ContentResource
{
    public function key(): string
    {
        return 'home-sections';
    }

    public function table(): string
    {
        return 'cms_home_section';
    }

    public function entity(): string
    {
        return 'home_section';
    }

    public function hasStatus(): bool
    {
        return false;
    }

    public function hasSlug(): bool
    {
        return false;
    }

    public function titleField(): string
    {
        return 'type';
    }

    public function order(): array
    {
        return ['sort_order', 'asc'];
    }

    public function fields(): array
    {
        return ['type' => [Rule::in(HomeSectionTypes::TYPES)], 'payload' => ['array'], 'sortOrder' => ['integer', 'between:-100000,100000']];
    }

    public function required(): array
    {
        return ['type', 'payload'];
    }

    public function filter(Builder $q, Request $r): void
    {
        if (in_array($t = strtoupper((string) $r->query('type')), HomeSectionTypes::TYPES, true)) {
            $q->where('type', $t);
        }
        if ($r->query('enabled') !== null) {
            $q->where('is_enabled', filter_var($r->query('enabled'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
    }

    public function beforeSave(array &$d, ?object $existing): void
    {
        if ($existing !== null) {
            unset($d['type']); // immutable
        }
        if (array_key_exists('payload', $d)) {
            $d['payload'] = HomeSectionTypes::validate($d['type'] ?? $existing->type, (array) $d['payload']);
        }
    }

    public function columns(array $d, ?object $existing): array
    {
        $out = [];
        if (isset($d['type'])) {
            $out['type'] = $d['type'];
        }
        if (array_key_exists('payload', $d)) {
            $out['payload'] = Rows::enc($d['payload']);
        }
        if (array_key_exists('sortOrder', $d)) {
            $out['sort_order'] = (int) $d['sortOrder'];
        }

        return $out;
    }

    public function admin(object $r, MediaResolver $media): array
    {
        return ['id' => Rows::id($r->id), 'type' => $r->type, 'sortOrder' => (int) $r->sort_order, 'enabled' => (bool) $r->is_enabled, 'payload' => Rows::jsonObj($r->payload)] + $this->tail($r);
    }

    public function mediaIds(object $row): array
    {
        return MediaResolver::collect(Rows::jsonObj($row->payload));
    }
}
