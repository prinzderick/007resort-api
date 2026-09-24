<?php

namespace App\Domain\Orders\Support;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Fixed-width text for an 80mm thermal printer (48 columns; `payments.receipt.columns`). A pre-bill is deliberately
 * unmistakable: it says NOT A RECEIPT at the top and bottom and carries no receipt number.
 */
final class PreBillRenderer
{
    /**
     * @param  array<string, mixed>  $b  the PreBill payload
     * @return list<string>
     */
    public static function lines(array $b, int $cols = 48): array
    {
        $out = [];
        $center = function (string $s) use (&$out, $cols): void {
            foreach (self::wrap($s, $cols) as $w) {
                $out[] = str_repeat(' ', max(0, intdiv($cols - mb_strlen($w), 2))).$w;
            }
        };
        $rule = fn (string $c = '-') => str_repeat($c, $cols);
        $pair = function (string $l, string $r) use ($cols): string {
            if (mb_strlen($l) + mb_strlen($r) + 1 > $cols) {
                $l = mb_substr($l, 0, max(1, $cols - mb_strlen($r) - 1));
            }

            return $l.str_repeat(' ', max(1, $cols - mb_strlen($l) - mb_strlen($r))).$r;
        };

        $center('*** BILL - NOT A RECEIPT ***');
        if ($b['reprint']) {
            $center('*** REPRINT #'.$b['printCount'].' ***');
        }
        $center(strtoupper((string) $b['businessName']));
        $center((string) $b['facility']['name']);
        $out[] = $rule('=');
        $out[] = $pair('Order: '.$b['orderNumber'], self::localDate((string) $b['printedAt']));
        if (! empty($b['tableLabel'])) {
            $out[] = 'Table: '.$b['tableLabel'];
        }
        if (! empty($b['waiter']['name'])) {
            $out[] = 'Waiter: '.$b['waiter']['name'];
        }
        $out[] = $rule();
        foreach ($b['lines'] as $l) {
            $total = self::money($l['lineTotal']);
            $left = ($l['quantity'] > 1 ? $l['quantity'].' x ' : '').$l['name'];
            $wrapped = self::wrap($left, max(8, $cols - mb_strlen($total) - 1));
            $out[] = $pair(array_shift($wrapped), $total);
            foreach ($wrapped as $w) {
                $out[] = '  '.$w;
            }
            if ($l['quantity'] > 1) {
                $out[] = '  @ '.self::money($l['unitPrice']);
            }
        }
        $out[] = $rule();
        $out[] = $pair('Subtotal', self::money($b['subtotal']));
        if (bccomp($b['discountTotal'], '0', 4) > 0) {
            $out[] = $pair('Discount', '-'.self::money($b['discountTotal']));
        }
        foreach ($b['taxLines'] as $t) {
            $out[] = $pair($t['label'], self::money($t['amount']));
        }
        $out[] = $pair('TOTAL DUE', self::money($b['balanceDue']));
        if (bccomp($b['amountPaid'], '0', 4) > 0) {
            $out[] = $pair('(Order total '.self::money($b['total']).', paid '.self::money($b['amountPaid']).')', '');
        }
        $out[] = $rule();
        if (($b['payLink']['enabled'] ?? false) && ! empty($b['payLink']['reference'])) {
            $out[] = 'Pay reference: '.$b['payLink']['reference'];
            if (! empty($b['payLink']['url'])) {
                foreach (self::wrap((string) $b['payLink']['url'], $cols) as $w) {
                    $out[] = $w;
                }
            }
            $out[] = $rule();
        }
        $center('Pay ONLY through the card machine, the');
        $center('transfer link or the cashier. A receipt is');
        $center('issued after payment is confirmed.');
        $center('*** NOT A RECEIPT - NOT PROOF OF PAYMENT ***');

        return $out;
    }

    public static function money(string $v): string
    {
        [$int, $dec] = array_pad(explode('.', Money::roundHalfUp($v, 2)), 2, '00');
        $neg = str_starts_with($int, '-');

        return ($neg ? '-' : '').'N'.number_format((int) ltrim($int, '-')).'.'.$dec;
    }

    private static function localDate(string $iso): string
    {
        try {
            return CarbonImmutable::parse($iso)->setTimezone(config('payments.receipt.timezone', 'Africa/Lagos'))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /** @return list<string> */
    private static function wrap(string $s, int $width): array
    {
        $s = trim($s);

        return $s === '' ? [''] : array_map('rtrim', explode("\n", wordwrap($s, $width, "\n", true)));
    }
}
