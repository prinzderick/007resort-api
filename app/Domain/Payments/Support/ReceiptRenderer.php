<?php

namespace App\Domain\Payments\Support;

/**
 * Turns a receipt payload into fixed-width text lines for 80mm thermal printers (ESC/POS font A = 48 columns; pass 32 for
 * 58mm). Clients that render natively can ignore this and use the structured fields; both come from ONE payload so they
 * can never disagree. The VAT line and TIN are printed only when the organization is VAT registered (ADR-0011).
 */
final class ReceiptRenderer
{
    /**
     * @param  array<string, mixed>  $r  receipt payload (see ReceiptService::build)
     * @return list<string>
     */
    public static function lines(array $r, int $cols = 48): array
    {
        $out = [];
        $center = function (string $s) use (&$out, $cols): void {
            foreach (self::wrap($s, $cols) as $w) {
                $out[] = str_repeat(' ', max(0, intdiv($cols - mb_strlen($w), 2))).$w;
            }
        };
        $rule = fn (string $c = '-') => str_repeat($c, $cols);
        $pair = function (string $l, string $rt) use ($cols): string {
            $space = max(1, $cols - mb_strlen($l) - mb_strlen($rt));
            if (mb_strlen($l) + mb_strlen($rt) + 1 > $cols) {
                $l = mb_substr($l, 0, max(1, $cols - mb_strlen($rt) - 1));
                $space = max(1, $cols - mb_strlen($l) - mb_strlen($rt));
            }

            return $l.str_repeat(' ', $space).$rt;
        };
        $money = fn (string $v): string => self::money($v);

        if (($r['duplicate'] ?? false) === true) {
            $center('*** DUPLICATE COPY ***');
        }
        $center(strtoupper((string) ($r['businessName'] ?? '')));
        $center((string) ($r['siteName'] ?? ''));
        if (! empty($r['siteAddress'])) {
            $center((string) $r['siteAddress']);
        }
        if (($r['vatRegistered'] ?? false) && ! empty($r['vatNumber'])) {
            $center('TIN: '.$r['vatNumber']);
        }
        $out[] = $rule('=');
        $center((string) ($r['facilityName'] ?? ''));
        $out[] = $pair('Receipt: '.($r['number'] ?? ''), self::localDate((string) ($r['issuedAt'] ?? '')));
        if (! empty($r['orderNumbers'])) {
            foreach (self::wrap('Order: '.implode(', ', $r['orderNumbers']), $cols) as $w) {
                $out[] = $w;
            }
        }
        if (! empty($r['tableLabel'])) {
            $out[] = 'Table: '.$r['tableLabel'];
        }
        $out[] = $rule();

        foreach ($r['lines'] ?? [] as $l) {
            $name = (string) $l['name'];
            $qty = (int) $l['quantity'];
            $total = $money($l['lineTotal']);
            $left = ($qty > 1 ? $qty.' x ' : '').$name;
            $wrapped = self::wrap($left, max(8, $cols - mb_strlen($total) - 1));
            $out[] = $pair(array_shift($wrapped), $total);
            foreach ($wrapped as $w) {
                $out[] = '  '.$w;
            }
            if ($qty > 1) {
                $out[] = '  @ '.$money($l['unitPrice']);
            }
        }
        $out[] = $rule();
        $out[] = $pair('Subtotal', $money($r['subtotal']));
        if (bccomp((string) ($r['discountTotal'] ?? '0'), '0', 4) > 0) {
            $out[] = $pair('Discount', '-'.$money($r['discountTotal']));
        }
        if (($r['vatRegistered'] ?? false) === true) {
            $out[] = $pair('VAT ('.rtrim(rtrim((string) ($r['vatRatePercent'] ?? '7.5'), '0'), '.').'%)', $money($r['taxTotal']));
        }
        $out[] = $pair('TOTAL', $money($r['total']));
        $out[] = $rule();
        foreach ($r['tenders'] ?? [] as $t) {
            $label = self::tenderLabel((string) $t['tenderType']).(! empty($t['reference']) ? ' ('.$t['reference'].')' : '');
            $out[] = $pair($label, $money($t['amount']));
            if (! empty($t['tendered'])) {
                $out[] = $pair('  Tendered', $money($t['tendered']));
            }
        }
        if (bccomp((string) ($r['changeGiven'] ?? '0'), '0', 4) > 0) {
            $out[] = $pair('Change', $money($r['changeGiven']));
        }
        if (bccomp((string) ($r['balanceDue'] ?? '0'), '0', 4) > 0) {
            $out[] = $pair('BALANCE DUE', $money($r['balanceDue']));
        }
        $out[] = $rule();
        $out[] = 'Served by: '.($r['cashierName'] ?? '');
        if (! empty($r['terminal'])) {
            $out[] = 'Terminal: '.$r['terminal'];
        }
        if (! empty($r['footer'])) {
            $out[] = '';
            $center((string) $r['footer']);
        }

        return $out;
    }

    public static function money(string $v): string
    {
        [$int, $dec] = array_pad(explode('.', bcadd($v, '0', 2)), 2, '00');
        $neg = str_starts_with($int, '-');
        $int = ltrim($int, '-');

        return ($neg ? '-' : '').'N'.number_format((int) $int).'.'.$dec;
    }

    private static function tenderLabel(string $t): string
    {
        return match ($t) {
            'CASH' => 'Cash', 'CARD' => 'Card', 'TRANSFER' => 'Bank transfer', 'POS_TERMINAL' => 'POS', default => $t,
        };
    }

    /** ISO-8601 UTC -> "dd/mm/yyyy HH:MM" in the site time zone (Africa/Lagos, UTC+1, no DST). */
    private static function localDate(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            return \Carbon\CarbonImmutable::parse($iso)->setTimezone(config('payments.receipt.timezone', 'Africa/Lagos'))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /** @return list<string> */
    private static function wrap(string $s, int $width): array
    {
        $s = trim($s);
        if ($s === '') {
            return [''];
        }

        return array_map('rtrim', explode("\n", wordwrap($s, $width, "\n", true)));
    }
}
