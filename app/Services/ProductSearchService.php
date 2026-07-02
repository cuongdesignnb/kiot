<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class ProductSearchService
{
    public function tokens(?string $keyword): array
    {
        $keyword = trim((string) $keyword);

        if ($keyword === '') {
            return [];
        }

        $keyword = mb_substr($keyword, 0, 120, 'UTF-8');
        $normalized = mb_strtolower($keyword, 'UTF-8');
        $normalized = preg_replace('/[\s\-_\/\\\\,;:]+/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        $normalized = trim($normalized);

        $tokens = array_values(array_filter(explode(' ', $normalized), function ($token) {
            return mb_strlen(trim($token), 'UTF-8') >= 1;
        }));

        return array_slice(array_values(array_unique($tokens)), 0, 8);
    }

    public function apply(Builder $query, ?string $keyword, array $options = []): Builder
    {
        $tokens = $this->tokens($keyword);

        if (count($tokens) === 0) {
            return $query;
        }

        foreach ($tokens as $token) {
            $like = '%' . $this->escapeLike($token) . '%';

            $query->where(function (Builder $q) use ($like, $options) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);

                if (($options['include_serials'] ?? true) === true) {
                    $relation = $options['serial_relation'] ?? 'serials';

                    $q->orWhereHas($relation, function (Builder $serialQuery) use ($like) {
                        $serialQuery->where('serial_number', 'like', $like);
                    });
                }
            });
        }

        return $query;
    }

    public function applyScore(Builder $query, ?string $keyword): Builder
    {
        $keyword = trim(mb_substr((string) $keyword, 0, 120, 'UTF-8'));

        if ($keyword === '') {
            return $query;
        }

        $escaped = $this->escapeLike($keyword);
        $prefix = $escaped . '%';
        $contains = '%' . $escaped . '%';

        return $query->orderByRaw(
            "CASE
                WHEN sku = ? THEN 0
                WHEN barcode = ? THEN 1
                WHEN name = ? THEN 2
                WHEN sku LIKE ? THEN 3
                WHEN barcode LIKE ? THEN 4
                WHEN name LIKE ? THEN 5
                WHEN name LIKE ? THEN 6
                ELSE 10
            END",
            [$keyword, $keyword, $keyword, $prefix, $prefix, $prefix, $contains]
        );
    }

    public function serialLikePattern(?string $keyword): ?string
    {
        $tokens = $this->tokens($keyword);

        if (count($tokens) !== 1) {
            return null;
        }

        return '%' . $this->escapeLike($tokens[0]) . '%';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
