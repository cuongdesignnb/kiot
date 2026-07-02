<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * STEP 24.9 — Single source of truth for normalising warranty / maintenance
 * policy rows on `products` and resolving the primary duration in months.
 *
 * Used by ProductController (store/update) and WarrantyGenerationService.
 */
class ProductWarrantyPolicyNormalizer
{
    public const UNITS = ['day', 'month', 'year'];

    /**
     * Normalise the warranty_policies array:
     *   - drop empty / invalid rows
     *   - trim name
     *   - cast duration_value, duration_unit
     *   - ensure exactly one is_default=true (first row wins if none marked)
     *
     * @param  array<int, array{name?:string, duration_value?:mixed, duration_unit?:mixed, is_default?:mixed}>|null  $rows
     * @return array<int, array{name:string, duration_value:int, duration_unit:string, is_default:bool}>
     */
    public function normalizeWarrantyPolicies($rows): array
    {
        if (!is_array($rows)) return [];

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            $value = (int) ($row['duration_value'] ?? 0);
            $unit  = strtolower((string) ($row['duration_unit'] ?? 'month'));
            if (!in_array($unit, self::UNITS, true)) {
                $unit = 'month';
            }
            if ($name === '' || $value < 0) {
                continue;
            }
            $clean[] = [
                'name'           => $name,
                'duration_value' => $value,
                'duration_unit'  => $unit,
                'is_default'     => (bool) ($row['is_default'] ?? false),
            ];
        }

        if (empty($clean)) return [];

        // Ensure exactly one default — keep the first marked, otherwise the first row.
        $foundDefault = false;
        foreach ($clean as $i => &$row) {
            if ($row['is_default']) {
                if ($foundDefault) {
                    $row['is_default'] = false;
                } else {
                    $foundDefault = true;
                }
            }
        }
        unset($row);
        if (!$foundDefault) {
            $clean[0]['is_default'] = true;
        }
        return $clean;
    }

    /**
     * Normalise maintenance_policies — same shape as warranty but no is_default.
     *
     * @param  array<int, array{name?:string, duration_value?:mixed, duration_unit?:mixed}>|null  $rows
     * @return array<int, array{name:string, duration_value:int, duration_unit:string}>
     */
    public function normalizeMaintenancePolicies($rows): array
    {
        if (!is_array($rows)) return [];

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            $value = (int) ($row['duration_value'] ?? 0);
            $unit  = strtolower((string) ($row['duration_unit'] ?? 'month'));
            if (!in_array($unit, self::UNITS, true)) {
                $unit = 'month';
            }
            if ($name === '' || $value <= 0) {
                continue;
            }
            $clean[] = [
                'name'           => $name,
                'duration_value' => $value,
                'duration_unit'  => $unit,
            ];
        }
        return $clean;
    }

    /**
     * Resolve the primary warranty duration in MONTHS from a normalised
     * warranty_policies array. Used to populate `products.warranty_months`
     * (kept for backward-compat with WarrantyGenerationService fallbacks).
     *
     * Primary = the row marked is_default; otherwise the first row.
     */
    public function resolvePrimaryWarrantyMonths(array $policies): int
    {
        if (empty($policies)) return 0;
        $primary = null;
        foreach ($policies as $row) {
            if (!empty($row['is_default'])) {
                $primary = $row;
                break;
            }
        }
        if (!$primary) {
            $primary = $policies[0];
        }
        return $this->durationInMonths(
            (int) ($primary['duration_value'] ?? 0),
            (string) ($primary['duration_unit'] ?? 'month'),
        );
    }

    /**
     * Convert a (value, unit) duration into months for the primary column.
     * day → ceil(value/30); year → value*12; month → value as-is.
     */
    public function durationInMonths(int $value, string $unit): int
    {
        if ($value <= 0) return 0;
        $unit = strtolower($unit);
        return match ($unit) {
            'year'  => $value * 12,
            'day'   => (int) ceil($value / 30),
            default => $value,
        };
    }

    /**
     * Add a (value, unit) duration to a Carbon date. Used by
     * WarrantyGenerationService to compute warranty_end_date and
     * next_maintenance_date.
     */
    public function addDurationToDate(Carbon $date, int $value, string $unit): Carbon
    {
        if ($value <= 0) return $date->copy();
        $unit = strtolower($unit);
        $copy = $date->copy();
        return match ($unit) {
            'day'   => $copy->addDays($value),
            'year'  => $copy->addYears($value),
            default => $copy->addMonths($value),
        };
    }
}
