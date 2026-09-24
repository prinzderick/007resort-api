<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Support\Cms;
use App\Domain\Cms\Support\CmsAudit;
use App\Domain\Cms\Support\Rows;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

class ContactService
{
    /** @param array<string, mixed> $d @return array{status: string} */
    public function submit(array $d, string $clientIp, ?string $userAgent): array
    {
        DB::table('cms_contact_message')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'name' => mb_substr(trim($d['name']), 0, 120), 'email' => mb_strtolower(trim($d['email'])), 'phone' => filled($d['phone'] ?? null) ? mb_substr(trim($d['phone']), 0, 40) : null,
            'topic' => $d['topic'] ?? 'GENERAL', 'message' => trim($d['message']), 'status' => blank($d['website'] ?? null) ? 'NEW' : 'SPAM',
            'ip_hash' => hash_hmac('sha256', $clientIp, (string) config('app.key')), 'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'created_at' => Rows::now(), 'updated_at' => Rows::now(),
        ]);

        return ['status' => 'RECEIVED'];
    }

    public function find(string $id, bool $lock = false): object
    {
        $q = Ids::isUuid($id) ? DB::table('cms_contact_message')->where('id', Ids::toBinary($id)) : null;
        if ($q !== null && $lock) {
            $q->lockForUpdate();
        }

        return $q?->first() ?? throw ApiProblem::notFound('not_found', 'Message not found.');
    }

    /** @return array<string, mixed> */
    public function present(object $r): array
    {
        return [
            'id' => Rows::id($r->id), 'name' => $r->name, 'email' => $r->email, 'phone' => $r->phone, 'topic' => $r->topic, 'message' => $r->message, 'status' => $r->status,
            'internalNote' => $r->internal_note, 'handledBy' => Rows::id($r->handled_by), 'handledAt' => Rows::iso($r->handled_at), 'createdAt' => Rows::iso($r->created_at),
        ];
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    public function update(string $id, array $d): array
    {
        return DB::transaction(function () use ($id, $d) {
            $row = $this->find($id, true);
            $set = [];
            if (isset($d['status']) && $d['status'] !== $row->status) {
                $set['status'] = $d['status'];
            }
            if (array_key_exists('internalNote', $d) && $d['internalNote'] !== $row->internal_note) {
                $set['internal_note'] = $d['internalNote'];
            }
            if ($set !== []) {
                DB::table('cms_contact_message')->where('id', $row->id)->update($set + ['handled_by' => Rows::bin(RequestContext::staffId()), 'handled_at' => Rows::now(), 'row_version' => $row->row_version + 1, 'updated_at' => Rows::now()]);
                CmsAudit::record('cms.message.update', 'CmsContactMessage', $id, ['status' => $row->status, 'internalNote' => $row->internal_note], ['status' => $set['status'] ?? $row->status, 'internalNote' => array_key_exists('internal_note', $set) ? $set['internal_note'] : $row->internal_note]);
            }

            return $this->present($this->find($id));
        });
    }

    public function erase(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = $this->find($id, true);
            DB::table('cms_contact_message')->where('id', $row->id)->delete();
            CmsAudit::record('cms.message.erase', 'CmsContactMessage', $id, ['status' => $row->status, 'topic' => $row->topic, 'emailHash' => hash('sha256', $row->email)], null);
        });
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $c = array_fill_keys(array_map('strtolower', Cms::CONTACT_STATUSES), 0);
        foreach (DB::table('cms_contact_message')->selectRaw('status, COUNT(*) n')->groupBy('status')->get() as $r) {
            $c[strtolower($r->status)] = (int) $r->n;
        }

        return $c;
    }
}
