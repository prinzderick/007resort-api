<?php

namespace App\Domain\Customer\Support;

/** What the public may see of a catalogue product, plus the additive `ticketCategory` (ADULT|CHILD) the website needs. */
final class TicketCatalog
{
    public static function category(string $name, string $sku): string
    {
        return preg_match('/child|kid|junior|minor/i', $name.' '.$sku) ? 'CHILD' : 'ADULT';
    }

    /** @param array<string, mixed> $p a presented product @return array<string, mixed> */
    public static function publicView(array $p): array
    {
        unset($p['prepRoute'], $p['trackStock'], $p['taxAmount']);
        if (($p['commercialKind'] ?? null) === 'TICKET') {
            $p['ticketCategory'] = self::category((string) $p['name'], (string) $p['sku']);
        }

        return $p;
    }
}
