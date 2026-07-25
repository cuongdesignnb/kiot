<?php

namespace App\Services\Integrations\PcWebsite;

use App\Models\PriceBook;
use App\Support\Integrations\PcWebsite\PcSyncCursor;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;

class PcPriceBookSyncService
{
    public function paginate(array $filters): array
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOL);
        $updatedSince = ! empty($filters['updated_since'])
            ? Carbon::parse($filters['updated_since'])->setTimezone((string) config('app.timezone', 'UTC'))
            : null;

        $query = PriceBook::query();
        if ($includeInactive || $updatedSince) {
            $query->withTrashed();
        } else {
            $this->active($query);
        }
        if ($updatedSince) {
            $query->where('updated_at', '>=', $updatedSince);
        }

        $query->select(['id', 'code', 'name', 'is_active', 'status', 'start_date', 'end_date', 'updated_at', 'deleted_at'])
            ->orderBy('updated_at')->orderBy('id');

        /** @var CursorPaginator $paginator */
        $paginator = $query->cursorPaginate($limit, ['*'], 'cursor', PcSyncCursor::decode($filters['cursor'] ?? null));

        return [
            'data' => collect($paginator->items())->map(fn (PriceBook $book) => $this->transform($book))->all(),
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    public function activeBook(?int $id): ?PriceBook
    {
        if (! $id) {
            return null;
        }

        return $this->active(PriceBook::query())->find($id);
    }

    private function active($query)
    {
        return $query->where('is_active', true)
            ->where(function ($builder) {
                $builder->whereNull('status')->orWhere('status', 'active');
            })
            ->where(function ($builder) {
                $builder->whereNull('start_date')->orWhereDate('start_date', '<=', today());
            })
            ->where(function ($builder) {
                $builder->whereNull('end_date')->orWhereDate('end_date', '>=', today());
            });
    }

    private function transform(PriceBook $book): array
    {
        $deleted = $book->trashed();
        $active = ! $deleted
            && (bool) $book->is_active
            && ($book->status === null || $book->status === 'active')
            && ($book->start_date === null || $book->start_date->lte(today()))
            && ($book->end_date === null || $book->end_date->gte(today()));

        return [
            'id' => $book->id,
            'code' => $book->code,
            'name' => $book->name,
            'is_default' => false,
            'is_active' => $active,
            'sync_status' => $deleted ? 'deleted' : ($active ? 'active' : 'inactive'),
            'updated_at' => $book->updated_at?->utc()->toIso8601String(),
        ];
    }
}
