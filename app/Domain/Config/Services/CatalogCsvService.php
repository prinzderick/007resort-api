<?php

namespace App\Domain\Config\Services;

use App\Domain\Catalog\Services\CatalogAdmin;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CSV export/import of products and prices. Import = validate + apply inside ONE transaction that is rolled back for a dry run (so the
 * dry-run report is exactly what the real run would do, including price-overlap checks) and for any error (all-or-nothing).
 */
class CatalogCsvService
{
    public const PRODUCT_COLUMNS = ['sku', 'name', 'category', 'kind', 'description', 'barcode', 'taxRateCode', 'prepRoute', 'trackStock', 'imageUrl', 'active', 'price'];

    public const PRICE_COLUMNS = ['sku', 'facilityCode', 'priceList', 'amount', 'validFrom', 'validTo'];

    private const KINDS = ['GOOD', 'SERVICE', 'TICKET', 'RENTAL', 'MEMBERSHIP', 'FEE'];

    private const MAX_ROWS = 5000;

    public function __construct(private readonly CatalogAdmin $admin, private readonly CatalogConfigService $config) {}

    private function orgBin(): string
    {
        return Ids::toBinary((string) Tenant::organizationId());
    }

    // ---- export -------------------------------------------------------------------------------------------------------------

    public function exportProducts(): string
    {
        $org = $this->orgBin();
        $list = DB::table('price_list')->where('organization_id', $org)->where('is_default', 1)->where('is_active', 1)->value('id');
        $now = Fmt::now();
        $prices = $list === null ? collect() : DB::table('price')->where('price_list_id', $list)->whereNull('facility_unit_id')->where('is_active', 1)->where('valid_from', '<=', $now)
            ->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>', $now))->orderBy('valid_from')->pluck('amount', 'product_id');
        $rows = DB::table('product as p')->leftJoin('product_category as c', 'c.id', '=', 'p.category_id')->leftJoin('tax_rate as t', 't.id', '=', 'p.tax_rate_id')->leftJoin('prep_route as r', 'r.id', '=', 'p.prep_route_id')
            ->where('p.organization_id', $org)->whereNull('p.deleted_at')->orderBy('p.sku')->get(['p.*', 'c.name as category_name', 't.code as tax_code', 'r.code as route_code']);
        $out = [self::PRODUCT_COLUMNS];
        foreach ($rows as $r) {
            $out[] = [$r->sku, $this->safe($r->name), $this->safe($r->category_name), $r->kind, $this->safe($r->description), $r->barcode, $r->tax_code, $r->route_code, $r->track_stock ? 'true' : 'false',
                $r->image_url, $r->is_active ? 'true' : 'false', isset($prices[$r->id]) ? Money::normalize($prices[$r->id]) : ''];
        }

        return $this->encode($out);
    }

    public function exportPrices(): string
    {
        $rows = DB::table('price as x')->join('product as p', 'p.id', '=', 'x.product_id')->join('price_list as l', 'l.id', '=', 'x.price_list_id')->leftJoin('facility_unit as f', 'f.id', '=', 'x.facility_unit_id')
            ->where('p.organization_id', $this->orgBin())->where('x.is_active', 1)->orderBy('p.sku')->orderBy('f.code')->orderBy('x.valid_from')->get(['x.*', 'p.sku', 'l.name as list_name', 'f.code as facility_code']);
        $out = [self::PRICE_COLUMNS];
        foreach ($rows as $r) {
            $out[] = [$r->sku, $r->facility_code, $this->safe($r->list_name), Money::normalize($r->amount), Fmt::ts($r->valid_from), Fmt::ts($r->valid_to)];
        }

        return $this->encode($out);
    }

    // ---- import -------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> report */
    public function importProducts(string $csv, bool $apply): array
    {
        return $this->run('products', $csv, self::PRODUCT_COLUMNS, ['sku'], $apply, fn (array $row, array &$rep) => $this->productRow($row, $rep));
    }

    /** @return array<string, mixed> report */
    public function importPrices(string $csv, bool $apply): array
    {
        return $this->run('prices', $csv, self::PRICE_COLUMNS, ['sku', 'amount'], $apply, fn (array $row, array &$rep) => $this->priceRow($row, $rep));
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function run(string $what, string $csv, array $columns, array $required, bool $apply, callable $handler): array
    {
        $rep = ['dryRun' => ! $apply, 'totalRows' => 0, 'valid' => 0, 'willCreate' => 0, 'willUpdate' => 0, 'unchanged' => 0, 'createdCategories' => [], 'errors' => [], 'applied' => false];
        [$header, $rows] = $this->parse($csv);
        foreach ($required as $c) {
            if (! in_array($c, $header, true)) {
                $rep['errors'][] = ['row' => 1, 'field' => $c, 'message' => "Missing required column '{$c}'. Expected columns: ".implode(', ', $columns).'.'];
            }
        }
        foreach (array_diff($header, $columns) as $extra) {
            $rep['errors'][] = ['row' => 1, 'field' => $extra, 'message' => "Unknown column '{$extra}'."];
        }
        $rep['totalRows'] = count($rows);
        if (count($rows) > self::MAX_ROWS) {
            $rep['errors'][] = ['row' => 1, 'field' => '_file', 'message' => 'At most '.self::MAX_ROWS.' rows per import.'];
        }
        if ($rep['errors'] !== []) {
            return $this->finish($rep, $apply);
        }

        $seen = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $i => $cells) {
                $line = $i + 2; // 1 = header
                $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
                $row = array_map(fn ($v) => $this->unsafe(trim((string) $v)), $row);
                $key = strtolower(($row['sku'] ?? '').'|'.($row['facilityCode'] ?? '').'|'.($row['priceList'] ?? '').'|'.($row['validFrom'] ?? ''));
                if ($what === 'products' && $row['sku'] !== '' && isset($seen[strtolower($row['sku'])])) {
                    $rep['errors'][] = ['row' => $line, 'field' => 'sku', 'message' => "Duplicate sku '{$row['sku']}' (also on row {$seen[strtolower($row['sku'])]})."];

                    continue;
                }
                if ($what === 'prices' && isset($seen[$key])) {
                    $rep['errors'][] = ['row' => $line, 'field' => 'sku', 'message' => "Duplicate price row (also on row {$seen[$key]})."];

                    continue;
                }
                $seen[$what === 'products' ? strtolower($row['sku']) : $key] = $line;
                try {
                    DB::transaction(function () use ($handler, $row, &$rep): void { // savepoint per row
                        $handler($row, $rep);
                    });
                    $rep['valid']++;
                } catch (ApiProblem $e) {
                    $errs = $e->extensions['errors'] ?? [];
                    if ($errs !== []) {
                        foreach ($errs as $field => $msgs) {
                            $rep['errors'][] = ['row' => $line, 'field' => preg_replace('/^.*\./', '', (string) $field), 'message' => (string) ($msgs[0] ?? $e->getMessage())];
                        }
                    } else {
                        $rep['errors'][] = ['row' => $line, 'field' => $this->guessField($e->problemCode), 'message' => $e->getMessage()];
                    }
                }
            }
            if ($apply && $rep['errors'] === [] && ($rep['willCreate'] + $rep['willUpdate']) > 0) {
                Audit::record("config.catalog.{$what}.import", 'Organization', (string) Tenant::organizationId(), null,
                    ['created' => $rep['willCreate'], 'updated' => $rep['willUpdate'], 'unchanged' => $rep['unchanged'], 'rows' => $rep['totalRows'], 'createdCategories' => $rep['createdCategories']]);
            }
            if ($apply && $rep['errors'] === []) {
                DB::commit();
                $rep['applied'] = true;
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $t) {
            DB::rollBack();
            throw $t;
        }

        return $this->finish($rep, $apply);
    }

    /** @param array<string, mixed> $rep @return array<string, mixed> */
    private function finish(array $rep, bool $apply): array
    {
        if ($apply && $rep['errors'] !== []) {
            throw new ApiProblem(422, 'import_validation_failed', 'The file has '.count($rep['errors']).' problem(s); nothing was imported. Fix them and upload again (or run with dryRun=true to check first).', 'Unprocessable entity', ['report' => $rep]);
        }

        return $rep;
    }

    /** @param array<string, string> $row only the columns present in the file @param array<string, mixed> $rep */
    private function productRow(array $row, array &$rep): void
    {
        $orgBin = $this->orgBin();
        $has = fn (string $c) => array_key_exists($c, $row);
        $filled = fn (string $c) => $has($c) && $row[$c] !== '';
        $existing = DB::table('product')->where('organization_id', $orgBin)->where('sku', $row['sku'] ?? '')->whereNull('deleted_at')->first();
        $err = [];
        if (! preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $row['sku'] ?? '')) {
            $err['sku'] = ['sku is required (letters, digits, . _ -; max 64).'];
        }
        if (($existing === null || $has('name')) && (! $filled('name') || mb_strlen($row['name']) > 200)) {
            $err['name'] = ['name is required (max 200 characters).'];
        }
        if (($existing === null || $has('category')) && $existing === null && (! $filled('category') || mb_strlen($row['category']) > 120)) {
            $err['category'] = ['category is required for a new product (max 120 characters).'];
        }
        $fields = [];
        if ($filled('name')) {
            $fields['name'] = $row['name'];
        }
        if ($filled('kind')) {
            $kind = strtoupper($row['kind']);
            in_array($kind, self::KINDS, true) ? $fields['kind'] = $kind : $err['kind'] = ['kind must be one of '.implode(', ', self::KINDS).'.'];
        }
        if ($has('description')) {
            $fields['description'] = $row['description'] === '' ? null : $row['description'];
        }
        if ($has('barcode')) {
            $fields['barcode'] = $row['barcode'] === '' ? null : $row['barcode'];
            if ($fields['barcode'] !== null && ! preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $fields['barcode'])) {
                $err['barcode'] = ['barcode may only contain letters, digits, . _ -.'];
            }
        }
        if ($has('taxRateCode')) {
            $fields['taxRateId'] = null;
            if ($row['taxRateCode'] !== '') {
                $tax = DB::table('tax_rate')->where('organization_id', $orgBin)->where('code', strtoupper($row['taxRateCode']))->where('is_active', 1)->value('id');
                $tax === null ? $err['taxRateCode'] = ["Unknown or inactive tax rate '{$row['taxRateCode']}'."] : $fields['taxRateId'] = Ids::fromBinary($tax);
            }
        }
        if ($has('prepRoute')) {
            $fields['prepRouteId'] = null;
            if ($row['prepRoute'] !== '') {
                $route = DB::table('prep_route')->where('organization_id', $orgBin)->where('code', strtoupper($row['prepRoute']))->value('id');
                $route === null ? $err['prepRoute'] = ["Unknown prep route '{$row['prepRoute']}'."] : $fields['prepRouteId'] = Ids::fromBinary($route);
            }
        }
        foreach (['trackStock' => 'trackStock', 'active' => 'active'] as $col => $key) {
            if ($filled($col)) {
                $fields[$key] = $this->bool($row[$col], false, $col, $err);
            }
        }
        if ($has('imageUrl')) {
            $fields['imageUrl'] = $row['imageUrl'] === '' ? null : $row['imageUrl'];
            if ($fields['imageUrl'] !== null && (! filter_var($fields['imageUrl'], FILTER_VALIDATE_URL) || strlen($fields['imageUrl']) > 500)) {
                $err['imageUrl'] = ['imageUrl must be a valid URL.'];
            }
        }
        $price = $filled('price') ? $row['price'] : null;
        if ($price !== null && ! preg_match('/^\d{1,15}(\.\d{1,4})?$/', $price)) {
            $err['price'] = ['price must be a non-negative decimal such as 2500 or 2500.50.'];
        }
        if ($err !== []) {
            throw ApiProblem::unprocessable('validation_failed', 'Invalid row.', $err);
        }

        if ($filled('category')) {
            $catBin = DB::table('product_category')->where('organization_id', $orgBin)->whereRaw('LOWER(name) = ?', [mb_strtolower($row['category'])])->value('id');
            if ($catBin === null) {
                $created = $this->admin->createCategory(['name' => $row['category']]);
                $catBin = Ids::toBinary($created['id']);
                $rep['createdCategories'][] = $row['category'];
            }
            $fields['categoryId'] = Ids::fromBinary($catBin);
        }

        if ($existing === null) {
            $this->admin->createProduct(['sku' => $row['sku'], 'kind' => 'GOOD', 'trackStock' => false, 'taxExempt' => false] + $fields + ($price !== null ? ['price' => $price] : []));
            $rep['willCreate']++;

            return;
        }
        $current = ['name' => $existing->name, 'categoryId' => Fmt::u($existing->category_id), 'kind' => $existing->kind, 'description' => $existing->description, 'barcode' => $existing->barcode,
            'taxRateId' => Fmt::u($existing->tax_rate_id), 'prepRouteId' => Fmt::u($existing->prep_route_id), 'trackStock' => (bool) $existing->track_stock, 'imageUrl' => $existing->image_url, 'active' => (bool) $existing->is_active];
        $changes = array_filter($fields, fn ($v, $k) => $current[$k] !== $v, ARRAY_FILTER_USE_BOTH);
        $curPrice = $this->currentListPrice($existing->id);
        $priceChanged = $price !== null && ($curPrice === null || Money::normalize($price) !== $curPrice);
        if ($changes === [] && ! $priceChanged) {
            $rep['unchanged']++;

            return;
        }
        if ($changes !== []) {
            $this->admin->updateProduct(Ids::fromBinary($existing->id), $changes, null);
        }
        if ($priceChanged) {
            $this->admin->setPrice(Ids::fromBinary($existing->id), $price, null);
        }
        $rep['willUpdate']++;
    }

    /** @param array<string, string> $row @param array<string, mixed> $rep */
    private function priceRow(array $row, array &$rep): void
    {
        $orgBin = $this->orgBin();
        $err = [];
        $product = DB::table('product')->where('organization_id', $orgBin)->where('sku', $row['sku'] ?? '')->whereNull('deleted_at')->first();
        $product ?? $err['sku'] = ["Unknown product sku '".($row['sku'] ?? '')."'."];
        if (! preg_match('/^\d{1,15}(\.\d{1,4})?$/', $row['amount'] ?? '')) {
            $err['amount'] = ['amount must be a non-negative decimal.'];
        }
        $facility = null;
        if (($row['facilityCode'] ?? '') !== '') {
            $facility = DB::table('facility_unit')->where('organization_id', $orgBin)->where('code', strtoupper($row['facilityCode']))->whereNull('deleted_at')->value('id');
            $facility ?? $err['facilityCode'] = ["Unknown facility code '{$row['facilityCode']}'."];
        }
        $list = null;
        if (($row['priceList'] ?? '') !== '') {
            $list = DB::table('price_list')->where('organization_id', $orgBin)->whereRaw('LOWER(name) = ?', [mb_strtolower($row['priceList'])])->value('id');
            $list ?? $err['priceList'] = ["Unknown price list '{$row['priceList']}'."];
        }
        foreach (['validFrom', 'validTo'] as $d) {
            if (($row[$d] ?? '') !== '' && Fmt::clientTs($row[$d]) === null) {
                $err[$d] = ["{$d} must be an ISO-8601 date/time."];
            }
        }
        if ($err !== []) {
            throw ApiProblem::unprocessable('validation_failed', 'Invalid row.', $err);
        }
        $from = ($row['validFrom'] ?? '') === '' ? null : Fmt::clientTs($row['validFrom']);
        $listBin = $list ?? DB::table('price_list')->where('organization_id', $orgBin)->where('is_default', 1)->where('is_active', 1)->value('id');
        if ($listBin !== null && $from !== null) {
            $fromEnd = CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', $from, 'UTC')->addMillisecond()->format('Y-m-d H:i:s.u'); // exports carry millisecond precision
            $same = DB::table('price')->where('product_id', $product->id)->where('price_list_id', $listBin)->where('is_active', 1)->where('valid_from', '>=', $from)->where('valid_from', '<', $fromEnd)
                ->where(fn ($q) => $facility === null ? $q->whereNull('facility_unit_id') : $q->where('facility_unit_id', $facility))->first();
            if ($same !== null && Money::normalize($same->amount) === Money::normalize($row['amount'])) {
                $rep['unchanged']++;

                return;
            }
        }
        $this->config->createPrice(array_filter([
            'productId' => Ids::fromBinary($product->id), 'priceListId' => Fmt::u($list), 'facilityId' => Fmt::u($facility), 'amount' => $row['amount'],
            'validFrom' => ($row['validFrom'] ?? '') === '' ? null : $row['validFrom'], 'validTo' => ($row['validTo'] ?? '') === '' ? null : $row['validTo'],
        ], fn ($v) => $v !== null));
        $rep['willCreate']++;
    }

    private function currentListPrice(string $productBin): ?string
    {
        $now = Fmt::now();
        $v = DB::table('price as p')->join('price_list as l', 'l.id', '=', 'p.price_list_id')->where('p.product_id', $productBin)->where('l.is_default', 1)->whereNull('p.facility_unit_id')->where('p.is_active', 1)
            ->where('p.valid_from', '<=', $now)->where(fn ($w) => $w->whereNull('p.valid_to')->orWhere('p.valid_to', '>', $now))->orderByDesc('p.valid_from')->value('p.amount');

        return $v === null ? null : Money::normalize($v);
    }

    /** @param array<string, list<string>> $err */
    private function bool(string $v, bool $default, string $field, array &$err): bool
    {
        if ($v === '') {
            return $default;
        }
        $l = strtolower($v);
        if (in_array($l, ['true', '1', 'yes', 'y'], true)) {
            return true;
        }
        if (in_array($l, ['false', '0', 'no', 'n'], true)) {
            return false;
        }
        $err[$field] = ["{$field} must be true or false."];

        return $default;
    }

    private function guessField(string $code): string
    {
        return match ($code) {
            'sku_taken' => 'sku', 'barcode_taken' => 'barcode', 'price_overlap', 'price_immutable' => 'validFrom', default => '_row',
        };
    }

    // ---- CSV plumbing ---------------------------------------------------------------------------------------------------------

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $header = fgetcsv($fh, 0, ',', '"', '');
        if ($header === false || $header === [null]) {
            throw ApiProblem::unprocessable('validation_failed', 'The file is empty. The first line must be the column headers.', ['csv' => ['Empty file.']]);
        }
        $header = array_map(fn ($h) => trim((string) $h), $header);
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($r === [null] || implode('', array_map('strval', $r)) === '') {
                continue;
            }
            $rows[] = array_map('strval', $r);
        }
        fclose($fh);

        return [$header, $rows];
    }

    /** @param list<list<mixed>> $rows */
    private function encode(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($v) => $v === null ? '' : (string) $v, $r), ',', '"', '', "\n");
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /** Spreadsheet-formula injection guard on export: cells starting with = + - @ get a leading apostrophe (stripped again on import). */
    private function safe(?string $v): ?string
    {
        return $v !== null && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }

    private function unsafe(string $v): string
    {
        return strlen($v) > 1 && $v[0] === "'" && str_contains('=+-@', $v[1]) ? substr($v, 1) : $v;
    }
}
