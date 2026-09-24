<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Support\Cms;
use App\Domain\Cms\Support\CmsAudit;
use App\Domain\Cms\Support\MediaResolver;
use App\Domain\Cms\Support\Rows;
use App\Domain\Cms\Support\SettingGroups;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsService
{
    public function __construct(private readonly MediaResolver $media) {}

    /** Create any missing group with its default (idempotent). */
    public function ensureDefaults(): void
    {
        foreach (SettingGroups::defaults() as $group => $value) {
            if (! DB::table('cms_setting')->where('grp', $group)->exists()) {
                DB::table('cms_setting')->insertOrIgnore(['id' => Ids::toBinary(Ids::uuid7()), 'grp' => $group, 'value' => Rows::enc($value), 'row_version' => 1, 'created_at' => Rows::now(), 'updated_at' => Rows::now()]);
            }
        }
    }

    /** @return array<string, object> group => row */
    public function rows(): array
    {
        $this->ensureDefaults();

        return DB::table('cms_setting')->get()->keyBy('grp')->all();
    }

    public function row(string $group): object
    {
        if (! in_array($group, Cms::SETTING_GROUPS, true)) {
            throw ApiProblem::notFound('not_found', 'Unknown setting group.');
        }
        $this->ensureDefaults();

        return DB::table('cms_setting')->where('grp', $group)->first() ?? throw ApiProblem::notFound();
    }

    /** @return array<string, mixed> */
    public function shape(object $row): array
    {
        return ['group' => $row->grp, 'value' => Rows::jsonObj($row->value), 'rowVersion' => (int) $row->row_version, 'updatedAt' => Rows::iso($row->updated_at)];
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    public function replace(string $group, array $value, Request $request): array
    {
        $this->row($group);
        $clean = SettingGroups::validate($group, $value);

        return DB::transaction(function () use ($group, $clean, $request) {
            $row = DB::table('cms_setting')->where('grp', $group)->lockForUpdate()->first();
            Concurrency::assertVersion((int) $row->row_version, Concurrency::ifMatch($request), "settings group '{$group}'");
            $old = Rows::jsonObj($row->value);
            if ($this->canon($old) === $this->canon($clean)) {
                return $this->shape($row);
            }
            DB::table('cms_setting')->where('id', $row->id)->update(['value' => Rows::enc($clean), 'row_version' => $row->row_version + 1, 'updated_by' => Rows::bin(RequestContext::staffId()), 'updated_at' => Rows::now()]);
            $new = DB::table('cms_setting')->where('id', $row->id)->first();
            CmsAudit::record("cms.setting.{$group}.update", 'CmsSetting', Rows::id($row->id), ['value' => $old], ['value' => $clean]);
            CmsSync::content('setting', Rows::id($row->id), 'update', (int) $new->row_version, ['group' => $group, 'value' => $clean]);

            return $this->shape($new);
        });
    }

    private function canon(mixed $v): string
    {
        $sort = function ($x) use (&$sort) {
            if (is_array($x)) {
                $x = array_map($sort, $x);
                if (! array_is_list($x)) {
                    ksort($x);
                }
            }

            return $x;
        };

        return Rows::enc($sort($v));
    }

    /** @return array<string, mixed> the public `GET /site` body */
    public function publicSite(): array
    {
        $rows = $this->rows();
        $body = [];
        $latest = null;
        foreach (Cms::SETTING_GROUPS as $g) {
            $body[$g] = Rows::jsonObj($rows[$g]->value);
            $latest = max($latest ?? '', $rows[$g]->updated_at);
        }
        $body = $this->media->expand($body);
        $body['updatedAt'] = Rows::iso($latest);

        return $body;
    }
}
