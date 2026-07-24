<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Models\ActivityLog;
use App\Models\ExternalInventoryReservation;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

class PcInventoryReservationService
{
    public const SOURCE = 'pc_website';

    public function __construct(private readonly PcIntegrationRuntimeConfiguration $runtimeConfiguration) {}

    /** @param array<int, int> $requestedByProduct */
    public function assertAvailable(array $requestedByProduct, Collection $products, ?int $excludeOrderId = null): void
    {
        if ($requestedByProduct === []) {
            return;
        }

        // Locking read is intentional: under MySQL REPEATABLE READ, a plain
        // aggregate can reuse a snapshot taken before this transaction waited
        // for the product lock and miss a reservation that just committed.
        $reserved = ExternalInventoryReservation::query()
            ->whereIn('product_id', array_keys($requestedByProduct))
            ->where('status', ExternalInventoryReservation::STATUS_ACTIVE)
            ->when($excludeOrderId, fn ($query) => $query->where('order_id', '!=', $excludeOrderId))
            ->orderBy('product_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => (int) $rows->sum('quantity'));

        $insufficient = [];
        foreach ($requestedByProduct as $productId => $quantity) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product) {
                throw new PcIntegrationException('INTERNAL_INTEGRATION_ERROR', 'Không thể khóa đầy đủ sản phẩm của đơn hàng.', 500);
            }
            $activeReserved = (int) ($reserved[$productId] ?? 0);
            $available = max(0, (int) $product->stock_quantity - $activeReserved);
            if ($quantity > $available) {
                $insufficient[] = [
                    'sku' => $product->sku,
                    'requested_quantity' => $quantity,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'reserved_quantity' => $activeReserved,
                    'available_quantity' => $available,
                ];
            }
        }

        if ($insufficient !== []) {
            throw new PcIntegrationException(
                'INSUFFICIENT_AVAILABLE_STOCK',
                'Một hoặc nhiều sản phẩm không đủ tồn khả dụng.',
                422,
                $insufficient,
            );
        }
    }

    public function createForOrder(Order $order, Collection $orderItems): void
    {
        $expiresAt = now()->addMinutes($this->runtimeConfiguration->current()->reservationTtlMinutes);
        $ids = [];
        foreach ($orderItems as $item) {
            $reservation = ExternalInventoryReservation::create([
                'source' => self::SOURCE,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'branch_id' => $order->branch_id,
                'quantity' => (int) $item->qty,
                'status' => ExternalInventoryReservation::STATUS_ACTIVE,
                'expires_at' => $expiresAt,
            ]);
            $ids[] = $reservation->id;
        }

        ActivityLog::log(
            ActivityLog::ACTION_EXTERNAL_RESERVATION_CREATED,
            "Giữ tồn cho đơn Website PC {$order->code}",
            $order,
            ['reservation_ids' => $ids],
        );
    }

    public function releaseForOrder(Order $order, string $reason = 'order_cancelled'): int
    {
        $reservations = ExternalInventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', ExternalInventoryReservation::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $reservation->update([
                'status' => ExternalInventoryReservation::STATUS_RELEASED,
                'released_at' => now(),
            ]);
        }

        if ($reservations->isNotEmpty()) {
            ActivityLog::log(
                ActivityLog::ACTION_EXTERNAL_RESERVATION_RELEASED,
                "Giải phóng giữ tồn cho đơn {$order->code}",
                $order,
                ['count' => $reservations->count(), 'reason' => $reason],
            );
        }

        return $reservations->count();
    }

    public function consumeForOrder(Order $order): int
    {
        $reservations = ExternalInventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', ExternalInventoryReservation::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $reservation->update([
                'status' => ExternalInventoryReservation::STATUS_CONSUMED,
                'consumed_at' => now(),
            ]);
        }

        if ($reservations->isNotEmpty()) {
            ActivityLog::log(
                ActivityLog::ACTION_EXTERNAL_RESERVATION_CONSUMED,
                "Sử dụng giữ tồn khi chuyển đơn {$order->code} thành hóa đơn",
                $order,
                ['count' => $reservations->count()],
            );
        }

        return $reservations->count();
    }

    public function assertProcessable(Order $order): void
    {
        $order->loadMissing('items.product');
        $requested = $order->items
            ->groupBy('product_id')
            ->map(fn (Collection $items) => (int) $items->sum('qty'))
            ->all();
        $productIds = array_keys($requested);
        sort($productIds, SORT_NUMERIC);

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $this->assertAvailable($requested, $products, $order->id);
    }

    public function reservationStatus(Order $order): string
    {
        $statuses = ExternalInventoryReservation::query()
            ->where('order_id', $order->id)
            ->distinct()
            ->pluck('status');

        if ($statuses->isEmpty()) {
            return 'none';
        }
        foreach ([
            ExternalInventoryReservation::STATUS_ACTIVE,
            ExternalInventoryReservation::STATUS_CONSUMED,
            ExternalInventoryReservation::STATUS_RELEASED,
            ExternalInventoryReservation::STATUS_EXPIRED,
        ] as $priority) {
            if ($statuses->contains($priority)) {
                return $priority;
            }
        }

        return 'none';
    }

    public function serialAllocationStatus(Order $order): string
    {
        $order->loadMissing('items.product');
        $serialItems = $order->items->filter(fn ($item) => (bool) $item->product?->has_serial);
        if ($serialItems->isEmpty()) {
            return 'not_required';
        }

        $allocated = $serialItems->every(fn ($item) => is_array($item->serial_ids) && count($item->serial_ids) === (int) $item->qty);

        return $allocated ? 'allocated' : 'pending';
    }
}
