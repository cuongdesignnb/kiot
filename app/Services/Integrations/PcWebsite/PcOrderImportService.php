<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Support\Customers\CustomerGroupSnapshot;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PcOrderImportService
{
    public const SOURCE = 'pc_website';

    public function __construct(
        private readonly PcProductResolver $productResolver,
        private readonly PcCustomerResolver $customerResolver,
        private readonly PcInventoryReservationService $reservationService,
    ) {}

    public function import(array $payload, string $idempotencyKey, string $rawBody): array
    {
        $payload = $this->publicPayload($payload);
        $payloadHash = hash('sha256', $rawBody);

        try {
            $this->assertTotals($payload);
            $result = DB::transaction(fn () => $this->importInTransaction($payload, $idempotencyKey, $payloadHash), 3);
            if (isset($result['error']) && $result['error'] instanceof PcIntegrationException) {
                throw $result['error'];
            }

            return $result;
        } catch (PcIntegrationException $exception) {
            $this->recordFailure($payload, $idempotencyKey, $payloadHash, 'order.create', $exception);
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return $this->resolveConcurrentReplay($payload, $idempotencyKey, $payloadHash);
            }
            $this->recordInternalFailure($payload, $idempotencyKey, $payloadHash, 'order.create');
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordInternalFailure($payload, $idempotencyKey, $payloadHash, 'order.create');
            throw $exception;
        }
    }

    public function cancel(string $externalOrderId, array $payload, string $idempotencyKey, string $rawBody): array
    {
        $payload = $this->publicPayload($payload);
        $payloadHash = hash('sha256', $rawBody);

        try {
            $result = DB::transaction(function () use ($externalOrderId, $payload, $idempotencyKey, $payloadHash) {
                $event = $this->findExistingEvent((string) $payload['event_id'], $idempotencyKey);
                if ($event) {
                    if (! $this->sameEvent($event, $payload, $idempotencyKey, $payloadHash, $externalOrderId)) {
                        $this->markConflict($event, 'IDEMPOTENCY_KEY_CONFLICT', 'Event ID hoặc Idempotency-Key đã được dùng cho request khác.');

                        return ['error' => new PcIntegrationException('IDEMPOTENCY_KEY_CONFLICT', 'Idempotency-Key hoặc event_id bị xung đột.', 409)];
                    }
                    if (in_array($event->status, [IntegrationEvent::STATUS_PROCESSED, IntegrationEvent::STATUS_IGNORED], true)) {
                        $event->increment('attempt_count');
                        $order = $this->findExternalOrder($externalOrderId);

                        return ['duplicate' => true, 'order' => $order];
                    }
                    $event->update(['status' => IntegrationEvent::STATUS_PROCESSING, 'attempt_count' => $event->attempt_count + 1]);
                } else {
                    $event = $this->createEvent($payload, $idempotencyKey, $payloadHash, 'order.cancel', $externalOrderId);
                }

                $order = Order::query()
                    ->where('external_source', self::SOURCE)
                    ->where('external_order_id', $externalOrderId)
                    ->lockForUpdate()
                    ->first();
                if (! $order) {
                    return $this->eventError($event, 'EXTERNAL_ORDER_NOT_FOUND', 'Không tìm thấy đơn hàng website.', 404);
                }
                if ($order->status === Order::STATUS_CANCELLED) {
                    return $this->eventError($event, 'ORDER_ALREADY_CANCELLED', 'Đơn hàng đã bị hủy trước đó.', 409);
                }
                if ($order->invoice()->exists()) {
                    return $this->eventError($event, 'ORDER_ALREADY_INVOICED', 'Đơn hàng đã được chuyển thành hóa đơn.', 409);
                }
                if (in_array($order->status, [Order::STATUS_COMPLETED, Order::STATUS_ENDED, 'return', 'returned'], true)) {
                    return $this->eventError($event, 'ORDER_NOT_CANCELLABLE', 'Đơn hàng không còn ở trạng thái có thể hủy.', 409);
                }

                $order->update([
                    'status' => Order::STATUS_CANCELLED,
                    'note' => $this->appendNote($order->note, 'Website PC hủy: '.trim((string) $payload['reason'])),
                ]);
                $this->reservationService->releaseForOrder($order, 'website_cancel');
                $event->update([
                    'status' => IntegrationEvent::STATUS_PROCESSED,
                    'processed_at' => now(),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
                ActivityLog::log(
                    ActivityLog::ACTION_EXTERNAL_ORDER_CANCELLED,
                    "Website PC hủy đơn {$order->code}",
                    $order,
                    ['external_order_id' => $externalOrderId],
                );

                return ['duplicate' => false, 'order' => $order->fresh()];
            });

            if (isset($result['error']) && $result['error'] instanceof PcIntegrationException) {
                throw $result['error'];
            }

            return $result;
        } catch (PcIntegrationException $exception) {
            $this->recordFailure($payload, $idempotencyKey, $payloadHash, 'order.cancel', $exception, $externalOrderId);
            throw $exception;
        }
    }

    public function status(string $externalOrderId): array
    {
        $order = $this->findExternalOrder($externalOrderId);
        if (! $order) {
            throw new PcIntegrationException('EXTERNAL_ORDER_NOT_FOUND', 'Không tìm thấy đơn hàng website.', 404);
        }
        $order->load(['invoice', 'items.product']);

        return [
            'external_order_id' => $order->external_order_id,
            'external_order_code' => $order->external_order_code,
            'kiot_order_id' => $order->id,
            'kiot_order_code' => $order->code,
            'order_status' => $order->status,
            'invoice_code' => $order->invoice?->code,
            'reservation_status' => $this->reservationService->reservationStatus($order),
            'serial_allocation_status' => $this->reservationService->serialAllocationStatus($order),
            'received_at' => $order->integration_received_at?->utc()->toIso8601String(),
            'updated_at' => $order->updated_at?->utc()->toIso8601String(),
        ];
    }

    private function importInTransaction(array $payload, string $idempotencyKey, string $payloadHash): array
    {
        $event = $this->findExistingEvent((string) $payload['event_id'], $idempotencyKey);
        if ($event) {
            if (! $this->sameEvent($event, $payload, $idempotencyKey, $payloadHash, (string) $payload['external_order_id'])) {
                $this->markConflict($event, 'IDEMPOTENCY_KEY_CONFLICT', 'Event ID hoặc Idempotency-Key đã được dùng cho request khác.');

                return ['error' => new PcIntegrationException('IDEMPOTENCY_KEY_CONFLICT', 'Idempotency-Key hoặc event_id bị xung đột.', 409)];
            }
            if (in_array($event->status, [IntegrationEvent::STATUS_PROCESSED, IntegrationEvent::STATUS_IGNORED], true)) {
                $event->increment('attempt_count');
                $order = $this->findExternalOrder((string) $payload['external_order_id']);
                if ($order && hash_equals((string) $order->integration_payload_hash, $payloadHash)) {
                    $this->logDuplicate($order);

                    return ['duplicate' => true, 'order' => $order];
                }
            }
            $event->update([
                'status' => IntegrationEvent::STATUS_PROCESSING,
                'attempt_count' => $event->attempt_count + 1,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
        } else {
            $event = $this->createEvent($payload, $idempotencyKey, $payloadHash, 'order.create');
        }

        $existingOrder = Order::query()
            ->where('external_source', self::SOURCE)
            ->where('external_order_id', (string) $payload['external_order_id'])
            ->lockForUpdate()
            ->first();
        if ($existingOrder) {
            if (hash_equals((string) $existingOrder->integration_payload_hash, $payloadHash)) {
                $event->update(['status' => IntegrationEvent::STATUS_IGNORED, 'processed_at' => now()]);
                $this->logDuplicate($existingOrder);

                return ['duplicate' => true, 'order' => $existingOrder];
            }

            $event->update([
                'status' => IntegrationEvent::STATUS_CONFLICT,
                'last_error_code' => 'EXTERNAL_ORDER_CONFLICT',
                'last_error_message' => 'External order ID đã tồn tại với payload khác.',
                'processed_at' => now(),
            ]);
            ActivityLog::log(ActivityLog::ACTION_EXTERNAL_ORDER_CONFLICT, "Xung đột đơn Website PC {$existingOrder->code}", $existingOrder, [
                'external_order_id' => $payload['external_order_id'],
                'existing_payload_hash' => $existingOrder->integration_payload_hash,
                'received_payload_hash' => $payloadHash,
            ]);

            return ['error' => new PcIntegrationException('EXTERNAL_ORDER_CONFLICT', 'External order ID đã tồn tại với payload khác.', 409, [[
                'external_order_id' => (string) $payload['external_order_id'],
                'existing_payload_hash' => $existingOrder->integration_payload_hash,
                'received_payload_hash' => $payloadHash,
                'kiot_order_code' => $existingOrder->code,
            ]])];
        }

        $branchId = (int) config('integrations.pc_website.default_branch_id');
        $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->first();
        if (! $branch) {
            throw new PcIntegrationException('INTEGRATION_NOT_CONFIGURED', 'Chi nhánh tích hợp không hợp lệ.', 503);
        }

        $skus = collect($payload['items'])->pluck('sku')->map(fn ($sku) => trim((string) $sku))->all();
        $productsBySku = $this->productResolver->resolveForImport($skus);
        $products = collect($productsBySku)->keyBy('id');
        $requested = [];
        foreach ($payload['items'] as $item) {
            $product = $productsBySku[trim((string) $item['sku'])];
            $requested[$product->id] = ($requested[$product->id] ?? 0) + (int) $item['quantity'];
        }
        $this->reservationService->assertAvailable($requested, $products);

        $customer = $this->customerResolver->resolve($payload['customer'], $branchId);
        $salesChannel = trim((string) config('integrations.pc_website.sales_channel', 'Website PC')) ?: 'Website PC';
        $orderData = [
            'external_source' => self::SOURCE,
            'external_order_id' => trim((string) $payload['external_order_id']),
            'external_order_code' => trim((string) $payload['external_order_code']),
            'external_payment_method' => $payload['payment']['method'] ?? null,
            'external_payment_status' => $payload['payment']['status'] ?? null,
            'integration_payload_hash' => $payloadHash,
            'integration_received_at' => now(),
            'code' => $this->newOrderCode(),
            'customer_id' => $customer->id,
            'branch_id' => $branchId,
            'created_by' => null,
            'created_by_name' => $salesChannel,
            'assigned_to_name' => null,
            'sales_channel' => $salesChannel,
            'status' => Order::STATUS_CONFIRMED,
            'total_price' => $payload['totals']['subtotal'],
            'discount' => $payload['totals']['discount'],
            'other_fees' => $payload['totals']['shipping_fee'],
            'total_payment' => $payload['totals']['total'],
            'amount_paid' => 0,
            'note' => $this->integrationNote((string) $payload['external_order_code'], $payload['note'] ?? null),
            'is_delivery' => (bool) $payload['delivery']['is_delivery'],
            'receiver_name' => $payload['delivery']['receiver_name'] ?? null,
            'receiver_phone' => $payload['delivery']['receiver_phone'] ?? null,
            'receiver_address' => $payload['delivery']['receiver_address'] ?? null,
            'receiver_ward' => $payload['delivery']['receiver_ward'] ?? null,
            'receiver_district' => $payload['delivery']['receiver_district'] ?? null,
            'receiver_city' => $payload['delivery']['receiver_city'] ?? null,
            'weight' => $payload['delivery']['weight'] ?? 0,
            'delivery_fee' => $payload['delivery']['shipping_fee'],
            'cod_amount' => 0,
            'created_at' => Carbon::parse($payload['ordered_at']),
        ];
        $orderData = CustomerGroupSnapshot::applyToAttributes($orderData, $customer->id, 'orders');
        $order = Order::create($orderData);

        $orderItems = collect();
        $priceMismatches = [];
        foreach ($payload['items'] as $item) {
            $product = $productsBySku[trim((string) $item['sku'])];
            $orderItems->push($order->items()->create([
                'product_id' => $product->id,
                'qty' => (int) $item['quantity'],
                'price' => $item['unit_price'],
                'discount' => $item['discount'],
                'subtotal' => $item['line_total'],
                'serial_ids' => null,
            ]));
            if ($this->toCents($item['unit_price']) !== $this->toCents($product->retail_price)) {
                $priceMismatches[] = $product->sku;
            }
        }
        $this->reservationService->createForOrder($order, $orderItems);

        $event->update([
            'status' => IntegrationEvent::STATUS_PROCESSED,
            'processed_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        ActivityLog::log(ActivityLog::ACTION_EXTERNAL_ORDER_CREATED, "Tạo đơn {$order->code} từ Website PC", $order, [
            'external_order_id' => $order->external_order_id,
            'price_mismatch_skus' => $priceMismatches,
        ]);

        return ['duplicate' => false, 'order' => $order->fresh()];
    }

    private function assertTotals(array $payload): void
    {
        $details = [];
        $lineSum = 0;
        foreach ($payload['items'] as $index => $item) {
            $calculated = ((int) $item['quantity'] * $this->toCents($item['unit_price'])) - $this->toCents($item['discount']);
            $received = $this->toCents($item['line_total']);
            if (abs($calculated - $received) > 1) {
                $details[] = [
                    'field' => "items.{$index}.line_total",
                    'expected' => $calculated / 100,
                    'received' => $received / 100,
                ];
            }
            $lineSum += $calculated;
        }

        $subtotal = $this->toCents($payload['totals']['subtotal']);
        $deliveryShipping = $this->toCents($payload['delivery']['shipping_fee']);
        $totalsShipping = $this->toCents($payload['totals']['shipping_fee']);
        $calculatedTotal = $lineSum - $this->toCents($payload['totals']['discount']) + $totalsShipping;
        $receivedTotal = $this->toCents($payload['totals']['total']);

        foreach ([
            ['field' => 'totals.subtotal', 'expected' => $lineSum, 'received' => $subtotal],
            ['field' => 'totals.shipping_fee', 'expected' => $deliveryShipping, 'received' => $totalsShipping],
            ['field' => 'totals.total', 'expected' => $calculatedTotal, 'received' => $receivedTotal],
        ] as $comparison) {
            if (abs($comparison['expected'] - $comparison['received']) > 1) {
                $details[] = [
                    'field' => $comparison['field'],
                    'expected' => $comparison['expected'] / 100,
                    'received' => $comparison['received'] / 100,
                ];
            }
        }

        if ($details !== []) {
            throw new PcIntegrationException('ORDER_TOTAL_MISMATCH', 'Tổng tiền đơn hàng không khớp.', 422, $details);
        }
    }

    private function findExistingEvent(string $eventId, string $idempotencyKey): ?IntegrationEvent
    {
        // Use point locking reads on the two unique indexes. Besides claiming an
        // existing event safely, locking reads avoid opening a stale InnoDB
        // consistent snapshot before this transaction waits on product locks.
        $byEventId = IntegrationEvent::query()
            ->where('source', self::SOURCE)
            ->where('event_id', $eventId)
            ->lockForUpdate()
            ->first();

        $byIdempotencyKey = IntegrationEvent::query()
            ->where('source', self::SOURCE)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        return $byEventId ?? $byIdempotencyKey;
    }

    private function sameEvent(IntegrationEvent $event, array $payload, string $idempotencyKey, string $payloadHash, string $externalOrderId): bool
    {
        return hash_equals((string) $event->event_id, (string) ($payload['event_id'] ?? ''))
            && hash_equals((string) $event->idempotency_key, $idempotencyKey)
            && hash_equals((string) $event->external_order_id, $externalOrderId)
            && hash_equals((string) $event->payload_hash, $payloadHash);
    }

    private function createEvent(array $payload, string $idempotencyKey, string $payloadHash, string $type, ?string $externalOrderId = null): IntegrationEvent
    {
        return IntegrationEvent::create([
            'source' => self::SOURCE,
            'event_id' => isset($payload['event_id']) ? substr((string) $payload['event_id'], 0, 64) : null,
            'event_type' => $type,
            'external_order_id' => $externalOrderId ?? ($payload['external_order_id'] ?? null),
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'status' => IntegrationEvent::STATUS_PROCESSING,
            'received_at' => now(),
        ]);
    }

    private function eventError(IntegrationEvent $event, string $code, string $message, int $status): array
    {
        $event->update([
            'status' => $status === 409 ? IntegrationEvent::STATUS_CONFLICT : IntegrationEvent::STATUS_FAILED,
            'last_error_code' => $code,
            'last_error_message' => $message,
            'processed_at' => now(),
        ]);

        return ['error' => new PcIntegrationException($code, $message, $status)];
    }

    private function markConflict(IntegrationEvent $event, string $code, string $message): void
    {
        $event->update([
            'attempt_count' => $event->attempt_count + 1,
            'status' => IntegrationEvent::STATUS_CONFLICT,
            'last_error_code' => $code,
            'last_error_message' => $message,
            'processed_at' => now(),
        ]);
    }

    private function recordFailure(array $payload, string $idempotencyKey, string $payloadHash, string $type, PcIntegrationException $exception, ?string $externalOrderId = null): void
    {
        try {
            DB::transaction(function () use ($payload, $idempotencyKey, $payloadHash, $type, $exception, $externalOrderId) {
                $event = $this->findExistingEvent((string) ($payload['event_id'] ?? ''), $idempotencyKey);
                if ($event && in_array($event->status, [IntegrationEvent::STATUS_PROCESSED, IntegrationEvent::STATUS_IGNORED, IntegrationEvent::STATUS_CONFLICT], true)) {
                    return;
                }
                $event ??= $this->createEvent($payload, $idempotencyKey, $payloadHash, $type, $externalOrderId);
                $event->update([
                    'status' => $exception->httpStatus === 409 ? IntegrationEvent::STATUS_CONFLICT : IntegrationEvent::STATUS_FAILED,
                    'last_error_code' => $exception->errorCode,
                    'last_error_message' => mb_substr($exception->getMessage(), 0, 2000),
                    'processed_at' => now(),
                ]);
            });
        } catch (Throwable) {
            // Audit failure must never replace the original integration response.
        }
    }

    private function recordInternalFailure(array $payload, string $idempotencyKey, string $payloadHash, string $type): void
    {
        $this->recordFailure(
            $payload,
            $idempotencyKey,
            $payloadHash,
            $type,
            new PcIntegrationException('INTERNAL_INTEGRATION_ERROR', 'Lỗi xử lý tích hợp nội bộ.', 500),
        );
    }

    private function resolveConcurrentReplay(array $payload, string $idempotencyKey, string $payloadHash): array
    {
        $order = $this->findExternalOrder((string) $payload['external_order_id']);
        if ($order && hash_equals((string) $order->integration_payload_hash, $payloadHash)) {
            $this->logDuplicate($order);

            return ['duplicate' => true, 'order' => $order];
        }

        throw new PcIntegrationException('IDEMPOTENCY_KEY_CONFLICT', 'Request đồng thời bị xung đột idempotency.', 409);
    }

    private function findExternalOrder(string $externalOrderId): ?Order
    {
        return Order::query()
            ->where('external_source', self::SOURCE)
            ->where('external_order_id', $externalOrderId)
            ->first();
    }

    private function logDuplicate(Order $order): void
    {
        ActivityLog::log(ActivityLog::ACTION_EXTERNAL_ORDER_DUPLICATE, "Nhận lại đơn Website PC {$order->code}", $order, [
            'external_order_id' => $order->external_order_id,
        ]);
    }

    private function integrationNote(string $externalCode, ?string $customerNote): string
    {
        $note = "Đồng bộ từ Website PC: {$externalCode}";
        $customerNote = trim((string) $customerNote);

        return $customerNote !== '' ? $note.' | Khách ghi chú: '.$customerNote : $note;
    }

    private function appendNote(?string $current, string $addition): string
    {
        return trim((string) $current) !== '' ? trim((string) $current).' | '.$addition : $addition;
    }

    private function newOrderCode(): string
    {
        do {
            $code = 'DH'.now()->format('ymdHis').random_int(1000, 9999);
        } while (Order::query()->where('code', $code)->exists());

        return $code;
    }

    private function toCents(mixed $value): int
    {
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function publicPayload(array $payload): array
    {
        unset($payload['_idempotency_key']);

        return $payload;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
