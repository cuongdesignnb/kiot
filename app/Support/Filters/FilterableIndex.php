<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Controller trait that applies standardized sidebar filters to an Eloquent query.
 *
 * Supported query params:
 *   - search                (string)   : full-text-ish search (requires $searchable[])
 *   - status[]              (array)    : whereIn on 'status' column
 *   - branch_id             (int)      : where('branch_id', ...)
 *   - supplier_id / customer_id        : same pattern
 *   - creator_id            (int)      : where(<creator column>, ...)
 *   - date_filter           (string)   : preset (see DateRangePresets)
 *   - date_from, date_to    (date)     : bounds for preset=custom
 *   - sort_by, sort_direction          : whitelist-driven ORDER BY
 *
 * Controllers configure the trait via:
 *   $this->searchable   = ['code', 'note'];
 *   $this->searchableRelations = ['supplier' => ['name','code']];
 *   $this->sortable     = ['code','created_at','total_amount'];
 *   $this->dateColumn   = 'created_at';
 *   $this->creatorColumn = 'employee_id';
 *   $this->scalarFilters = ['branch_id','supplier_id','customer_id'];
 */
trait FilterableIndex
{
    /** @var string[] */
    protected array $searchable = [];

    /** @var array<string, string[]>  relation => [columns] */
    protected array $searchableRelations = [];

    /** @var string[] */
    protected array $sortable = [];

    /** @var string|\Illuminate\Database\Query\Expression */
    protected string|\Illuminate\Database\Query\Expression $dateColumn = 'created_at';

    protected ?string $creatorColumn = null;

    /** @var string[] scalar filter columns (exact match) */
    protected array $scalarFilters = [];

    protected function applyFilters(Builder $query, Request $request): Builder
    {
        $this->applySearch($query, $request);
        $this->applyStatus($query, $request);
        $this->applyScalarFilters($query, $request);
        $this->applyCreator($query, $request);
        $this->applyDateRange($query, $request);
        $this->applySort($query, $request);

        return $query;
    }

    protected function applySearch(Builder $query, Request $request): void
    {
        // Standard param is `keyword`; accept legacy `search` as alias.
        $search = trim((string) ($request->input('keyword') ?? $request->input('search') ?? ''));
        if ($search === '') {
            return;
        }

        $columns = $this->searchable;
        $relations = $this->searchableRelations;

        $query->where(function ($q) use ($search, $columns, $relations) {
            foreach ($columns as $col) {
                $q->orWhere($col, 'LIKE', "%{$search}%");
            }
            foreach ($relations as $rel => $cols) {
                $q->orWhereHas($rel, function ($rq) use ($search, $cols) {
                    $rq->where(function ($inner) use ($search, $cols) {
                        foreach ($cols as $col) {
                            $inner->orWhere($col, 'LIKE', "%{$search}%");
                        }
                    });
                });
            }
        });
    }

    protected function applyStatus(Builder $query, Request $request): void
    {
        $statuses = $request->input('status');
        if (is_string($statuses) && $statuses !== '') {
            $statuses = [$statuses];
        }
        if (is_array($statuses) && count($statuses) > 0) {
            $query->whereIn('status', $statuses);
        }
    }

    protected function applyScalarFilters(Builder $query, Request $request): void
    {
        foreach ($this->scalarFilters as $field) {
            $value = $request->input($field);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $value = array_values(array_filter($value, fn($v) => $v !== null && $v !== ''));
                if (count($value) === 0) {
                    continue;
                }
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }
    }

    protected function applyCreator(Builder $query, Request $request): void
    {
        if (!$this->creatorColumn) {
            return;
        }
        // Standard param is `created_by`; accept legacy `creator_id` as alias.
        $creator = $request->input('created_by') ?? $request->input('creator_id');
        if ($creator !== null && $creator !== '') {
            $query->where($this->creatorColumn, $creator);
        }
    }

    protected function applyDateRange(Builder $query, Request $request): void
    {
        $preset = $request->input('date_filter');
        $from = $request->input('date_from');
        $to = $request->input('date_to');

        [$start, $end] = DateRangePresets::resolve($preset, $from, $to);
        $col = $this->dateColumn;

        if ($start) {
            $query->where($col, '>=', $start);
        }
        if ($end) {
            $query->where($col, '<=', $end);
        }
    }

    protected function applySort(Builder $query, Request $request): void
    {
        $sortBy = $request->input('sort_by');
        // Standard param is `sort_dir`; accept legacy `sort_direction` as alias.
        $rawDir = $request->input('sort_dir') ?? $request->input('sort_direction');
        $dir = $rawDir === 'asc' ? 'asc' : 'desc';

        if ($sortBy && in_array($sortBy, $this->sortable, true)) {
            $query->orderBy($sortBy, $dir);
        } else {
            $query->orderBy($this->dateColumn, 'desc');
        }
    }

    /**
     * Echo back the current filter state to the frontend.
     */
    protected function currentFilters(Request $request): array
    {
        $keyword = $request->input('keyword', $request->input('search', ''));
        $createdBy = $request->input('created_by', $request->input('creator_id', ''));
        $sortDir = $request->input('sort_dir', $request->input('sort_direction', ''));

        return [
            // New canonical names
            'keyword' => $keyword,
            'created_by' => $createdBy,
            'sort_dir' => $sortDir,
            // Legacy aliases kept in sync (so existing FE bindings still work)
            'search' => $keyword,
            'creator_id' => $createdBy,
            'sort_direction' => $sortDir,
            // Shared keys
            'status' => (array) $request->input('status', []),
            'date_filter' => $request->input('date_filter', 'all'),
            'date_from' => $request->input('date_from', ''),
            'date_to' => $request->input('date_to', ''),
            'sort_by' => $request->input('sort_by', ''),
        ] + collect($this->scalarFilters)
            ->mapWithKeys(fn($f) => [$f => $request->input($f, '')])
            ->all();
    }
}
