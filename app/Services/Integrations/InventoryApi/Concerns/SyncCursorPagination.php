<?php

namespace App\Services\Integrations\InventoryApi\Concerns;

use App\Services\Integrations\InventoryApi\InventoryApiException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;

/**
 * Shared keyset pagination for the public Kiot API: cursor on
 * COALESCE(updated_at, created_at) + id, `updated_since`, `limit`.
 */
trait SyncCursorPagination
{
    /**
     * Non-null sort key for keyset pagination. Rows with a NULL updated_at
     * (raw inserts/legacy imports) used to end up in the cursor as null, and
     * the next page then failed with "Illegal operator and value combination"
     * (HTTP 500), so the partner saw missing pages / an empty result.
     */
    private function addSyncKey(Builder $query, string $table): void
    {
        $query->selectRaw("COALESCE({$table}.updated_at, {$table}.created_at, '1970-01-01 00:00:00') as sync_updated_at");
    }

    private function meta(?string $nextCursor, bool $hasMore, int $limit, ?CarbonInterface $since, CarbonInterface $watermark): array
    {
        return [
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'limit' => $limit,
            'updated_since' => $since ? $this->time($since) : null,
            // Use this value (minus a small overlap) as the next updated_since.
            'sync_watermark' => $this->time($watermark),
        ];
    }

    private function limit(array $filters, int $max = 100): int
    {
        return min($max, max(1, (int) ($filters['limit'] ?? 50)));
    }

    private function since(array $filters): ?Carbon
    {
        return ! empty($filters['updated_since'])
            ? Carbon::parse($filters['updated_since'])->setTimezone((string) config('app.timezone', 'UTC'))
            : null;
    }

    private function cursor(array $filters): ?Cursor
    {
        $encoded = $filters['cursor'] ?? null;
        if ($encoded === null || trim((string) $encoded) === '') {
            return null;
        }

        $cursor = Cursor::fromEncoded($encoded);
        if (! $cursor instanceof Cursor) {
            throw new InventoryApiException('INVALID_CURSOR', 'Cursor phân trang không hợp lệ.', 422);
        }

        return $cursor;
    }

    private function time(?CarbonInterface $value): ?string
    {
        return $value?->copy()->setTimezone((string) config('app.timezone', 'UTC'))->toIso8601String();
    }
}
