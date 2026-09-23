<?php

namespace App\Support\Http;

use App\Support\Ids;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Keyset ("cursor") pagination, stable under concurrent inserts (architecture/15).
 *
 * Request: ?limit=50&cursor=<opaque>. Response shape (via toArray()):
 *   { "data": [...], "page": { "nextCursor": "..."|null, "hasMore": bool, "limit": 50 } }
 *
 *   $page = CursorPage::paginate(Device::query()->where('site_id', $site), $request);
 *   return response()->json($page->toArray(fn ($d) => DeviceResource::make($d)->resolve()));
 *
 * Ordering is `$orderBy $direction, $idColumn $direction` (id = UUIDv7, so ordering by id alone
 * is chronological). $orderBy must be a real, indexed column; NULL values are not supported in it.
 */
final class CursorPage
{
    /** @param Collection<int, mixed> $items */
    private function __construct(
        public readonly Collection $items,
        public readonly ?string $nextCursor,
        public readonly bool $hasMore,
        public readonly int $limit,
    ) {}

    public static function paginate(
        BuilderContract $query,
        Request $request,
        string $orderBy = 'id',
        string $direction = 'asc',
        int $defaultLimit = 50,
        int $maxLimit = 200,
        string $idColumn = 'id',
    ): self {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $limit = max(1, min($maxLimit, (int) $request->query('limit', $defaultLimit) ?: $defaultLimit));

        if (($cursor = $request->query('cursor')) !== null && $cursor !== '') {
            [$value, $id] = self::decode((string) $cursor);
            $op = $direction === 'asc' ? '>' : '<';
            $idBin = Ids::toBinary($id);
            if ($orderBy === $idColumn) {
                $query->where($idColumn, $op, $idBin);
            } else {
                $query->where(function ($q) use ($orderBy, $idColumn, $op, $value, $idBin): void {
                    $q->where($orderBy, $op, $value)
                        ->orWhere(fn ($q2) => $q2->where($orderBy, '=', $value)->where($idColumn, $op, $idBin));
                });
            }
        }

        if ($orderBy !== $idColumn) {
            $query->orderBy($orderBy, $direction);
        }
        $rows = $query->orderBy($idColumn, $direction)->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit)->values() : $rows->values();
        $next = null;
        if ($hasMore && ($last = $items->last()) !== null) {
            $lastId = data_get($last, $idColumn);
            $lastId = is_string($lastId) && strlen($lastId) === 16 ? Ids::fromBinary($lastId) : (string) $lastId;
            $lastValue = $orderBy === $idColumn ? null : (string) data_get($last, $orderBy);
            $next = self::encode($lastValue, $lastId);
        }

        return new self($items, $next, $hasMore, $limit);
    }

    /** @return array{data: list<mixed>, page: array{nextCursor: ?string, hasMore: bool, limit: int}} */
    public function toArray(?callable $map = null): array
    {
        return [
            'data' => ($map ? $this->items->map($map) : $this->items)->values()->all(),
            'page' => ['nextCursor' => $this->nextCursor, 'hasMore' => $this->hasMore, 'limit' => $this->limit],
        ];
    }

    private static function encode(?string $value, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['v' => $value, 'i' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{0: ?string, 1: string} */
    private static function decode(string $cursor): array
    {
        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (! is_array($data) || ! isset($data['i']) || ! Ids::isUuid($data['i']) || (isset($data['v']) && ! is_string($data['v']))) {
            throw ApiProblem::badRequest('invalid_cursor', 'The pagination cursor is invalid.');
        }

        return [$data['v'] ?? null, $data['i']];
    }
}
