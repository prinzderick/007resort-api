<?php

namespace App\Domain\Booking\Support;

use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transaction helper for the Booking/Ticketing services.
 *  - No transaction active: a real transaction, automatically retried on deadlock (3 attempts).
 *  - Already inside one (the `idempotent` middleware wraps every mutating request): a MANUAL savepoint, so a failed
 *    operation undoes only itself, and — unlike DB::transaction()'s nested handling — a deadlock victim (MySQL has already
 *    rolled the WHOLE transaction back) can't have its real error masked by "SAVEPOINT does not exist".
 * A deadlock / lock-wait timeout surfaces as 409 `concurrency_conflict` (retryable) instead of a 500.
 */
final class Tx
{
    private static int $n = 0;

    /** @template T @param Closure(): T $cb @return T */
    public static function run(Closure $cb): mixed
    {
        if (DB::transactionLevel() === 0) {
            try {
                return DB::transaction($cb, 3);
            } catch (QueryException $e) {
                throw self::map($e);
            }
        }
        $name = 'r007_sp_'.(++self::$n);
        DB::statement("SAVEPOINT {$name}");
        try {
            $result = $cb();
            DB::statement("RELEASE SAVEPOINT {$name}");

            return $result;
        } catch (Throwable $e) {
            try {
                DB::statement("ROLLBACK TO SAVEPOINT {$name}");
            } catch (Throwable) {
                // The transaction is already gone (deadlock victim): nothing left to roll back.
            }
            throw $e instanceof QueryException ? self::map($e) : $e;
        }
    }

    private static function map(QueryException $e): Throwable
    {
        if (in_array($e->errorInfo[1] ?? null, [1213, 1205], true)) {
            return ApiProblem::conflict('concurrency_conflict', 'The operation collided with a concurrent request; retry it.', ['meta' => ['retryable' => true]]);
        }

        return $e;
    }
}
