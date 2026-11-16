<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

/**
 * Batch 10 đợt 4 — item 30: "nhân viên bán không xem giá vốn và lãi".
 *
 * One permission (`cost_profit.view`) decides whether a signed-in user may see
 * cost prices, COGS, profit and margin figures. Requests without a signed-in
 * user (integrations, console, signed webhooks) are not affected.
 *
 * sanitize() blanks every value whose key names a cost/profit figure. Keys
 * are matched on snake_case word boundaries, so `cost_price`, `unit_cost`,
 * `gross_profit`, `thisMonthProfit`, `cogs_net`, `profit_margin` match while
 * `inventory_costing_method`, `costing` or `other_costs` (purchase fees) do
 * not. Scalars become null; a list becomes [] (keeps `.length` working in
 * the UI); an object keeps its shape with every scalar nulled.
 */
class CostVisibility
{
    public const PERMISSION = 'cost_profit.view';

    /** Words that name a cost/profit figure when they appear as a key token. */
    private const SENSITIVE_TOKENS = [
        'cost', 'costs', 'cogs', 'profit', 'profits', 'margin', 'margins',
    ];

    /** Whole keys that are cost figures without a sensitive token (import prices). */
    private const SENSITIVE_KEYS = [
        'last_purchase_price',
        'import_price',
        'purchase_price',
        'avg_purchase_price',
    ];

    /** Keys that contain a sensitive token but are not cost/profit figures. */
    private const ALLOWED_KEYS = [
        'other_costs',
        'other_costs_total',
        'other_cost',
        'cost_pending',
        'cost_pending_sales',
        'cost_pending_products',
        'update_cost_price',
        'serial_cost_update',
        'inventory_costing_method',
        'below_cost',
        'has_below_cost',
        'below_cost_count',
        'warn_below_cost_sale',
    ];

    /** Key prefixes that are fees/salaries, not the cost of goods. */
    private const ALLOWED_PREFIXES = [
        'delivery_', 'shipping_', 'ship_', 'salary_', 'commission_', 'other_cost',
    ];

    public static function canView(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        return $user->hasPermission(self::PERMISSION);
    }

    public static function currentUserCanView(): bool
    {
        return self::canView(auth()->user());
    }

    public static function isSensitiveKey(string|int $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        $snake = Str::snake(str_replace(['-', ' '], '_', $key));
        $snake = preg_replace('/_+/', '_', $snake);

        if (in_array($snake, self::SENSITIVE_KEYS, true)) {
            return true;
        }
        if (in_array($snake, self::ALLOWED_KEYS, true)) {
            return false;
        }
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($snake, $prefix)) {
                return false;
            }
        }

        // Inventory value = cost × quantity, so it reveals the cost too.
        if (str_ends_with($snake, 'stock_value') || str_ends_with($snake, 'inventory_value')) {
            return true;
        }

        $tokens = explode('_', $snake);
        foreach ($tokens as $token) {
            if (in_array($token, self::SENSITIVE_TOKENS, true)) {
                return true;
            }
        }

        // "gia_von" / "loi_nhuan" (Vietnamese keys used by a few exports)
        return str_contains($snake, 'gia_von') || str_contains($snake, 'loi_nhuan');
    }

    /**
     * Recursively blank cost/profit values.
     */
    public static function sanitize(mixed $data): mixed
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        } elseif ($data instanceof \JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        if (is_object($data)) {
            $data = json_decode(json_encode($data), true);
        }

        if (! is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (self::isSensitiveKey($key)) {
                $data[$key] = self::blank($value);

                continue;
            }

            if (is_array($value) || is_object($value)) {
                $data[$key] = self::sanitize($value);
            }
        }

        return $data;
    }

    private static function blank(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }
        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }
        if (! is_array($value)) {
            return null;
        }
        if (array_is_list($value)) {
            return [];
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::blank($child);
        }

        return $value;
    }
}
