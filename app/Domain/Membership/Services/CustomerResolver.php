<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Find-or-create a customer by normalised phone, then email. Race-safe via the (org, phone)/(org, email) unique keys. */
class CustomerResolver
{
    /** @param array{name: string, phone?: ?string, email?: ?string} $input */
    public function resolve(string $organizationId, array $input): Customer
    {
        $phone = $this->phone($input['phone'] ?? null);
        $email = isset($input['email']) && $input['email'] !== '' ? strtolower(trim($input['email'])) : null;

        $find = function () use ($organizationId, $phone, $email): ?Customer {
            if ($phone !== null && ($c = Customer::query()->where('organization_id', $organizationId)->where('phone', $phone)->first())) {
                return $c;
            }
            if ($email !== null && ($c = Customer::query()->where('organization_id', $organizationId)->where('email', $email)->first())) {
                return $c;
            }

            return null;
        };

        if ($existing = $find()) {
            return $existing;
        }
        try {
            // Savepoint so a duplicate-key error does not poison the caller's transaction.
            return DB::transaction(fn () => Customer::create([
                'organization_id' => $organizationId, 'full_name' => trim($input['name']), 'phone' => $phone, 'email' => $email,
            ]));
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new \RuntimeException('Customer vanished after duplicate key.');
        }
    }

    private function phone(?string $p): ?string
    {
        if ($p === null || trim($p) === '') {
            return null;
        }
        $digits = preg_replace('/[^0-9+]/', '', $p);

        return $digits === '' ? null : $digits;
    }
}
