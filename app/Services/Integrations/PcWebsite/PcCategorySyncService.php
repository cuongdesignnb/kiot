<?php

namespace App\Services\Integrations\PcWebsite;

use App\Models\Category;
use App\Support\Integrations\PcWebsite\PcSyncCursor;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Str;

class PcCategorySyncService
{
    public function paginate(array $filters): array
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOL);
        $updatedSince = ! empty($filters['updated_since'])
            ? Carbon::parse($filters['updated_since'])->setTimezone((string) config('app.timezone', 'UTC'))
            : null;

        $query = Category::query();
        if ($includeInactive || $updatedSince) {
            $query->withTrashed();
        } else {
            $query->where('is_active', true);
        }
        if ($updatedSince) {
            $query->where('updated_at', '>=', $updatedSince);
        }

        $query->select(['id', 'name', 'code', 'slug', 'parent_id', 'is_active', 'show_on_pc_website', 'updated_at', 'deleted_at'])
            ->orderBy('updated_at')->orderBy('id');

        /** @var CursorPaginator $paginator */
        $paginator = $query->cursorPaginate($limit, ['*'], 'cursor', PcSyncCursor::decode($filters['cursor'] ?? null));

        return [
            'data' => collect($paginator->items())->map(fn (Category $category) => $this->transform($category))->all(),
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    public function transform(Category $category): array
    {
        $deleted = $category->trashed();

        return [
            'id' => $category->id,
            'code' => $category->code ?: 'CAT-'.$category->id,
            'name' => $category->name,
            'slug' => $category->slug ?: Str::slug($category->name),
            'parent_id' => (int) $category->parent_id ?: null,
            'is_active' => ! $deleted && (bool) $category->is_active,
            'show_on_pc_website' => ! $deleted && (bool) $category->show_on_pc_website,
            'sync_status' => $deleted ? 'deleted' : ((bool) $category->is_active ? 'active' : 'inactive'),
            'updated_at' => $category->updated_at?->utc()->toIso8601String(),
        ];
    }
}
