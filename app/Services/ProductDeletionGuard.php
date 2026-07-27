<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductDeletionGuard
{
    public const BLOCKED_MESSAGE = 'Không thể xóa hàng hóa đã phát sinh tồn kho hoặc chứng từ. Hãy sử dụng chức năng Ngừng kinh doanh.';

    private const HISTORY_TABLES = [
        'purchase_count' => 'purchase_items',
        'invoice_count' => 'invoice_items',
        'order_count' => 'order_items',
        'return_count' => 'return_items',
        'purchase_return_count' => 'purchase_return_items',
        'movement_count' => 'stock_movements',
        'stock_take_count' => 'stock_take_items',
        'stock_transfer_count' => 'stock_transfer_items',
        'damage_count' => 'damage_items',
        'task_part_count' => 'task_parts',
        'warranty_count' => 'warranties',
        'purchase_order_count' => 'purchase_order_items',
        'task_count' => 'tasks',
        'external_reservation_count' => 'external_inventory_reservations',
    ];

    public function inspect(Product $product): array
    {
        $counts = [
            'serial_count' => DB::table('serial_imeis')->where('product_id', $product->id)->count(),
        ];

        foreach (self::HISTORY_TABLES as $key => $table) {
            $counts[$key] = DB::table($table)->where('product_id', $product->id)->count();
        }

        $reasons = [];
        if ((float) $product->stock_quantity !== 0.0) {
            $reasons[] = 'stock_quantity_nonzero';
        }
        if ($counts['serial_count'] > 0) {
            $reasons[] = 'serial_count_nonzero';
        }
        foreach (self::HISTORY_TABLES as $key => $table) {
            if ($counts[$key] > 0) {
                $reasons[] = $table.'_nonzero';
            }
        }

        return array_merge([
            'id' => $product->id,
            'sku' => $product->sku,
            'stock_quantity' => (float) $product->stock_quantity,
        ], $counts, [
            'has_business_history' => collect($counts)->except('serial_count')->contains(fn ($count) => $count > 0),
            'blocked' => $reasons !== [],
            'reasons' => $reasons,
        ]);
    }

    public function delete(Product $product, string $source): array
    {
        return $this->deleteMany(collect([$product]), $source);
    }

    public function deleteMany(Collection $products, string $source): array
    {
        $products = $products->unique('id')->values();
        $context = $this->auditContext($products, $source);
        $preflight = $this->preflight($products);

        if ($preflight['blocked']) {
            $this->audit(ActivityLog::ACTION_PRODUCT_DELETE_BLOCKED, $products, $context, 'blocked', $preflight['reasons']);

            return ['deleted' => false, 'reasons' => $preflight['reasons']];
        }

        $result = DB::transaction(function () use ($products, $context): array {
            $locked = Product::query()
                ->whereKey($products->pluck('id'))
                ->lockForUpdate()
                ->get();

            $preflight = $this->preflight($locked);
            if ($locked->count() !== $products->count()) {
                $preflight['blocked'] = true;
                $preflight['reasons']['request'] = ['product_not_found'];
            }
            if ($preflight['blocked']) {
                return ['deleted' => false, 'products' => $locked, 'reasons' => $preflight['reasons']];
            }

            foreach ($locked as $product) {
                $product->delete();
            }
            $this->audit(ActivityLog::ACTION_PRODUCT_DELETE, $locked, $context, 'success', []);

            return ['deleted' => true, 'products' => $locked, 'reasons' => []];
        });

        if (! $result['deleted']) {
            $this->audit(
                ActivityLog::ACTION_PRODUCT_DELETE_BLOCKED,
                $result['products']->isEmpty() ? $products : $result['products'],
                $context,
                'blocked',
                $result['reasons']
            );
        }

        return ['deleted' => $result['deleted'], 'reasons' => $result['reasons']];
    }

    private function preflight(Collection $products): array
    {
        $reasons = [];
        foreach ($products as $product) {
            $inspection = $this->inspect($product);
            if ($inspection['blocked']) {
                $reasons[(string) $product->id] = $inspection['reasons'];
            }
        }

        return ['blocked' => $reasons !== [], 'reasons' => $reasons];
    }

    private function auditContext(Collection $products, string $source): array
    {
        $request = request();
        $userAgent = $request?->userAgent();

        return [
            'actor_user_id' => auth()->id(),
            'request_id' => $request?->header('X-Request-ID') ?: (string) Str::uuid(),
            'route' => $request?->route()?->getName(),
            'ip' => $request?->ip(),
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            'source' => $source,
            'product_ids' => $products->pluck('id')->values()->all(),
            'product_skus' => $products->pluck('sku')->values()->all(),
        ];
    }

    private function audit(string $action, Collection $products, array $context, string $result, array $reasons): void
    {
        ActivityLog::log(
            $action,
            $result === 'success' ? 'Xóa hàng hóa thành công' : 'Chặn xóa hàng hóa có tồn kho hoặc lịch sử',
            $products->count() === 1 ? $products->first() : null,
            array_merge($context, [
                'result' => $result,
                'reasons' => $reasons,
                'created_at' => now()->toIso8601String(),
            ])
        );
    }
}
