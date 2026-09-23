<?php

namespace App\Domain\Orders\Services;

use App\Support\Api\Authz;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Sync\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Open tabs: many orders accumulate and are settled once (Payments settles via OrderSettlementService::markTabSettled). */
final class TabService
{
    public function __construct(private readonly Presenter $presenter, private readonly Realtime $realtime, private readonly OperatingRules $rules, private readonly TableService $tables) {}

    public function find(string $tabId, bool $lock = false): object
    {
        $q = DB::table('tab')->where('id', Ids::toBinary($tabId));
        $lock && $q->lockForUpdate();

        return $q->first() ?? throw ApiProblem::notFound('not_found', 'Tab not found.');
    }

    /**
     * @param  array<string, mixed>  $in  facilityId, tableId?, customerName?, orderIds?, id?, clientCreatedAt?
     * @return array{tab: array<string, mixed>, replayed: bool}
     */
    public function open(array $in, string $requestHash): array
    {
        $facilityId = Ids::normalize($in['facilityId']);
        Authz::require('tab.open', $facilityId);
        $clientId = isset($in['id']) ? Ids::normalize($in['id']) : null;
        if ($clientId && ($existing = DB::table('tab')->where('id', Ids::toBinary($clientId))->first())) {
            return $this->replay($existing, $requestHash);
        }
        try {
            return DB::transaction(function () use ($in, $facilityId, $clientId, $requestHash) {
                $fac = DB::table('facility_unit')->where('id', Ids::toBinary($facilityId))->where('is_active', 1)->first();
                if (! $fac) {
                    throw ApiProblem::notFound('not_found', 'Facility not found.');
                }
                if (! $this->rules->forFacility($facilityId)['allowOpenTabs']) {
                    throw ApiProblem::unprocessable('capability_disabled', 'This facility does not allow open tabs.');
                }
                $tableBin = null;
                if (! empty($in['tableId'])) {
                    $table = $this->tables->find(Ids::normalize($in['tableId']), true);
                    if ($table->facility_unit_id !== $fac->id) {
                        throw ApiProblem::unprocessable('facility_mismatch', 'The table belongs to a different facility.');
                    }
                    if (DB::table('tab')->where('dining_table_id', $table->id)->where('status', 'OPEN')->exists()) {
                        throw ApiProblem::conflict('tab_already_open', 'This table already has an open tab.');
                    }
                    $tableBin = $table->id;
                    $this->tables->occupyForOrder($table);
                }
                $id = $clientId ?? Ids::uuid7();
                DB::table('tab')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => $fac->organization_id, 'site_id' => $fac->site_id, 'facility_unit_id' => $fac->id,
                    'dining_table_id' => $tableBin, 'customer_name' => $in['customerName'] ?? null, 'opened_by' => Ids::toBinary(Authz::staffId()),
                    'client_created_at' => Fmt::clientTs($in['clientCreatedAt'] ?? null), 'client_request_hash' => $clientId ? $requestHash : null,
                ]);
                Audit::record('tab.open', 'Tab', $id, new: ['facilityId' => $facilityId, 'tableId' => $in['tableId'] ?? null, 'customerName' => $in['customerName'] ?? null], facilityUnitId: $facilityId);
                Outbox::record('TabOpened', 'Tab', $id, ['tabId' => $id, 'facilityId' => $facilityId], facilityId: $facilityId);
                if (! empty($in['orderIds'])) {
                    $this->attach($id, array_map(Ids::normalize(...), $in['orderIds']));
                }

                return ['tab' => $this->presenter->tab($this->find($id)), 'replayed' => false];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && $clientId && ($existing = DB::table('tab')->where('id', Ids::toBinary($clientId))->first())) {
                return $this->replay($existing, $requestHash);
            }
            throw $e;
        }
    }

    /** @return array{tab: array<string, mixed>, replayed: bool} */
    private function replay(object $existing, string $requestHash): array
    {
        if ($existing->client_request_hash === null || ! hash_equals($existing->client_request_hash, $requestHash)) {
            throw ApiProblem::conflict('concurrency_conflict', 'That id is already used by a different tab.');
        }

        return ['tab' => $this->presenter->tab($existing), 'replayed' => true];
    }

    /** @param list<string> $orderIds @return array<string, mixed> */
    public function addOrders(string $tabId, array $orderIds, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($tabId, $orderIds, $ifMatch) {
            $tab = $this->find($tabId, true);
            Authz::require('tab.open', Ids::fromBinary($tab->facility_unit_id));
            Concurrency::assertVersion((int) $tab->row_version, $ifMatch, 'tab');
            $this->attach($tabId, $orderIds);

            return $this->presenter->tab($this->find($tabId));
        });
    }

    /** Attach orders to an OPEN tab. Caller holds a transaction. @param list<string> $orderIds */
    private function attach(string $tabId, array $orderIds): void
    {
        $tab = $this->find($tabId, true);
        if ($tab->status !== 'OPEN') {
            throw ApiProblem::conflict('order_state_invalid', 'The tab is not open.');
        }
        $bins = array_map(Ids::toBinary(...), array_values(array_unique($orderIds)));
        sort($bins);
        $orders = DB::table('order')->whereIn('id', $bins)->orderBy('id')->lockForUpdate()->get();
        if ($orders->count() !== count($bins)) {
            throw ApiProblem::unprocessable('validation_failed', 'One or more orders were not found.');
        }
        foreach ($orders as $o) {
            if ($o->facility_unit_id !== $tab->facility_unit_id) {
                throw ApiProblem::unprocessable('facility_mismatch', 'An order belongs to a different facility than the tab.');
            }
            if (in_array($o->status, ['VOIDED', 'SETTLED'], true)) {
                throw ApiProblem::conflict('order_state_invalid', "Order {$o->order_number} is {$o->status} and cannot join a tab.");
            }
            if ($o->tab_id !== null && $o->tab_id !== $tab->id) {
                throw ApiProblem::conflict('order_state_invalid', "Order {$o->order_number} is already on another tab.");
            }
            if ($o->tab_id === null) {
                DB::table('order')->where('id', $o->id)->update(['tab_id' => $tab->id, 'row_version' => $o->row_version + 1]);
                $this->realtime->orderUpdated($o, ['tab']);
            }
        }
        DB::table('tab')->where('id', $tab->id)->update(['row_version' => $tab->row_version + 1]);
        Audit::record('tab.orders.add', 'Tab', $tabId, new: ['orderIds' => array_map(Ids::fromBinary(...), $bins)], facilityUnitId: Ids::fromBinary($tab->facility_unit_id));
    }

    /** OPEN tab for the table (created on demand) — used for OPEN_TAB facilities. Caller holds a transaction. */
    public function ensureForOrder(object $order): string
    {
        if ($order->dining_table_id !== null) {
            $tab = DB::table('tab')->where('dining_table_id', $order->dining_table_id)->where('status', 'OPEN')->orderByDesc('id')->lockForUpdate()->first();
            if ($tab) {
                return $tab->id;
            }
        }
        $id = Ids::uuid7();
        DB::table('tab')->insert([
            'id' => Ids::toBinary($id), 'organization_id' => $order->organization_id, 'site_id' => $order->site_id, 'facility_unit_id' => $order->facility_unit_id,
            'dining_table_id' => $order->dining_table_id, 'customer_name' => $order->customer_name, 'opened_by' => $order->created_by,
        ]);
        Outbox::record('TabOpened', 'Tab', $id, ['tabId' => $id, 'facilityId' => Ids::fromBinary($order->facility_unit_id), 'auto' => true], facilityId: Ids::fromBinary($order->facility_unit_id));

        return Ids::toBinary($id);
    }

    /** @return array<string, mixed> */
    public function present(string $tabId): array
    {
        return $this->presenter->tab($this->find($tabId));
    }
}
