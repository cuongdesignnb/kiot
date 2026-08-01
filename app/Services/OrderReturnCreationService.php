<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use App\Support\BusinessDateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderReturnCreationService
{
    public function __construct(private readonly PartnerDebtMutationCoordinator $coordinator) {}

    public function create(array $payload, array $context = []): OrderReturn
    {
        app(PartnerTransactionGuard::class)->assertCanTransact(
            isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
            'customer_id'
        );

        $payload = $this->canonicalizeTotals($payload);
        $this->validateReturnable($payload);
        $this->validateSerials($payload);

        $createReturn = function () use ($payload, $context) {
            $this->assertReturnTimeLimit($payload);
            $returnDate = $this->resolveReturnDate($payload, $context);
            $receiver = $this->resolveReceiver($payload);

            $returnPayload = [
                'code' => $context['code'] ?? ('TH'.date('YmdHis').rand(10, 99)),
                'invoice_id' => $payload['invoice_id'] ?? null,
                'customer_id' => $payload['customer_id'] ?? null,
                'branch_id' => $payload['branch_id'] ?? null,
                'status' => $context['status'] ?? 'Đã trả',
                'subtotal' => $payload['subtotal'],
                'discount' => $payload['discount'] ?? 0,
                'fee' => $payload['fee'] ?? 0,
                'total' => $payload['total'],
                'paid_to_customer' => $payload['paid_to_customer'] ?? $payload['total'],
                'note' => $payload['note'] ?? null,
                'created_by_name' => $context['created_by_name'] ?? auth()->user()?->name ?? 'Admin',
                'received_by_employee_id' => $receiver['id'],
                'received_by_name' => $receiver['name'],
                'created_at' => $returnDate,
            ];

            if (Schema::hasColumn('returns', 'fee_type')) {
                $returnPayload['fee_type'] = $payload['fee_type'] ?? 'amount';
            }
            if (Schema::hasColumn('returns', 'fee_value')) {
                $returnPayload['fee_value'] = $payload['fee_value'] ?? 0;
            }

            $return = OrderReturn::create($returnPayload);

            foreach ($payload['items'] as $item) {
                $this->createReturnItemAndRestoreStock($return, $item, $payload, $returnDate);
            }

            $this->recordCustomerImpact($return, $payload, $context);
            $this->recordCashFlow($return, $payload, $context, $returnDate);

            ActivityLog::log(
                ActivityLog::ACTION_RETURN_CREATE,
                "Tao phieu tra hang {$return->code}",
                $return,
                [
                    'total' => (float) $return->total,
                    'business_time' => optional($return->created_at)->toDateTimeString(),
                ],
            );

            return $return->load('items.product', 'invoice', 'receivedByEmployee');
        };

        $customerId = (int) ($payload['customer_id'] ?? 0);
        $createdReturn = $customerId > 0
            ? $this->coordinator->execute(
                $customerId,
                'customer_return_create',
                hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
                function (Customer $lockedCustomer) use ($createReturn): OrderReturn {
                    if (! (bool) $lockedCustomer->is_customer) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                        ]);
                    }

                    return DB::transaction($createReturn);
                },
                isset($context['idempotency_key']) ? (string) $context['idempotency_key'] : null,
            )
            : DB::transaction($createReturn);

        return $createdReturn;
    }

    /**
     * Receiver is operational metadata. It is validated and snapshotted, but
     * it never participates in seller/report/debt/inventory attribution.
     */
    private function resolveReceiver(array $payload): array
    {
        $id = $payload['received_by_employee_id'] ?? null;
        if ($id === null || $id === '') {
            return ['id' => null, 'name' => null];
        }

        $employee = Employee::query()->lockForUpdate()->find((int) $id);
        if (! $employee) {
            throw ValidationException::withMessages([
                'received_by_employee_id' => 'Nhân viên nhận trả không tồn tại.',
            ]);
        }

        if (! $employee->is_active) {
            throw ValidationException::withMessages([
                'received_by_employee_id' => 'Chỉ được chọn nhân viên đang hoạt động.',
            ]);
        }

        return ['id' => (int) $employee->id, 'name' => $employee->name];
    }

    private function canonicalizeTotals(array $payload): array
    {
        $calculated = app(ReturnTotalCalculator::class)->calculate([
            'items' => $payload['items'] ?? [],
            'subtotal' => $payload['subtotal'] ?? null,
            'discount' => $payload['discount'] ?? 0,
            'fee_type' => $payload['fee_type'] ?? null,
            'fee_value' => $payload['fee_value'] ?? null,
            'fee' => $payload['fee'] ?? null,
            'paid_to_customer' => $payload['paid_to_customer'] ?? null,
        ]);

        $payload['subtotal'] = $calculated['subtotal'];
        $payload['discount'] = $calculated['discount'];
        $payload['fee'] = $calculated['fee_amount'];
        $payload['fee_type'] = $calculated['fee_type'];
        $payload['fee_value'] = $calculated['fee_value'];
        $payload['total'] = $calculated['total_refund'];
        $payload['paid_to_customer'] = $calculated['paid_to_customer'];

        return $payload;
    }

    private function validateReturnable(array $payload): void
    {
        if (empty($payload['invoice_id'])) {
            return;
        }

        $invoice = Invoice::find($payload['invoice_id']);
        if (! $invoice) {
            return;
        }

        if ($invoice->status === 'Da huy' || $invoice->status === 'Đã hủy') {
            throw ValidationException::withMessages([
                'invoice_id' => 'Khong the tra hang cho hoa don da bi huy.',
            ]);
        }

        $requestedByProduct = [];
        foreach ($payload['items'] as $item) {
            $pid = (int) $item['product_id'];
            $requestedByProduct[$pid] = ($requestedByProduct[$pid] ?? 0) + (int) $item['qty'];
        }

        foreach ($requestedByProduct as $productId => $requestedQty) {
            $soldQty = InvoiceItem::where('invoice_id', $invoice->id)
                ->where('product_id', $productId)
                ->sum('quantity');

            $alreadyReturned = ReturnItem::where('product_id', $productId)
                ->whereHas('orderReturn', function ($q) use ($invoice) {
                    $q->where('invoice_id', $invoice->id)
                        ->where('status', '!=', 'Da huy')
                        ->where('status', '!=', 'Đã hủy');
                })
                ->sum('quantity');

            $remainingQty = $soldQty - $alreadyReturned;
            if ($requestedQty > $remainingQty) {
                $product = Product::find($productId);
                $productName = $product ? $product->name : "ID {$productId}";
                throw ValidationException::withMessages([
                    'items' => "San pham '{$productName}' chi con duoc tra {$remainingQty}, yeu cau tra {$requestedQty}.",
                ]);
            }
        }
    }

    private function validateSerials(array $payload): void
    {
        $seenSerialIds = [];
        foreach ($payload['items'] as $item) {
            $product = Product::find($item['product_id']);
            if (! $product || ! $product->tracksInventory() || ! $product->has_serial) {
                continue;
            }

            $qty = (int) $item['qty'];
            $serialIds = array_values(array_filter(array_map('intval', (array) ($item['serial_ids'] ?? []))));
            if (count($serialIds) !== $qty) {
                throw ValidationException::withMessages([
                    'items' => "San pham '{$product->name}' can chon dung {$qty} serial, hien da chon ".count($serialIds).'.',
                ]);
            }

            foreach ($serialIds as $sid) {
                if (isset($seenSerialIds[$sid])) {
                    throw ValidationException::withMessages([
                        'items' => "Serial ID {$sid} bi chon trung nhieu dong.",
                    ]);
                }
                $seenSerialIds[$sid] = true;
            }

            $serialQuery = SerialImei::whereIn('id', $serialIds)
                ->where('product_id', $product->id)
                ->where('status', 'sold');
            if (! empty($payload['invoice_id'])) {
                $serialQuery->where('invoice_id', $payload['invoice_id']);
            }
            if ($serialQuery->count() !== count($serialIds)) {
                throw ValidationException::withMessages([
                    'items' => "San pham '{$product->name}': co serial khong hop le hoac khong thuoc hoa don nay.",
                ]);
            }
        }
    }

    private function assertReturnTimeLimit(array $payload): void
    {
        if (! Setting::get('return_time_limit_enabled', false) || empty($payload['invoice_id'])) {
            return;
        }

        $invoice = Invoice::find($payload['invoice_id']);
        if (! $invoice) {
            return;
        }

        $limitDays = Setting::get('return_time_limit_days', 7);
        if ($invoice->created_at->diffInDays(now()) <= $limitDays) {
            return;
        }

        if (Setting::get('return_overdue_action', 'warn') === 'block') {
            throw new \Exception("Hoa don da qua {$limitDays} ngay, khong the tra hang.");
        }
    }

    private function createReturnItemAndRestoreStock(OrderReturn $return, array $item, array $payload, \Carbon\Carbon $returnDate): void
    {
        $product = Product::lockForUpdate()->find($item['product_id']);
        if (! $product) {
            return;
        }

        $qty = (int) $item['qty'];
        $invoiceItem = null;
        if (! empty($item['invoice_item_id'])) {
            $invoiceItem = InvoiceItem::find($item['invoice_item_id']);
        } elseif (! empty($payload['invoice_id'])) {
            $invoiceItem = InvoiceItem::where('invoice_id', $payload['invoice_id'])
                ->where('product_id', $product->id)
                ->orderBy('id')
                ->first();
        }

        $restoredSerials = collect();
        if ($product->tracksInventory() && $product->has_serial) {
            if (! empty($item['serial_ids'])) {
                $restoredSerials = SerialImei::whereIn('id', $item['serial_ids'])
                    ->where('product_id', $product->id)
                    ->where('status', 'sold')
                    ->get();
            } elseif ($invoiceItem) {
                $linkSerialIds = InvoiceItemSerial::where('invoice_item_id', $invoiceItem->id)
                    ->pluck('serial_imei_id')->filter()->all();
                if (! empty($linkSerialIds)) {
                    $restoredSerials = SerialImei::whereIn('id', $linkSerialIds)
                        ->where('status', 'sold')
                        ->limit($qty)
                        ->get();
                }
            }

            if ($restoredSerials->isEmpty() && ! empty($payload['invoice_id'])) {
                $restoredSerials = SerialImei::where('invoice_id', $payload['invoice_id'])
                    ->where('product_id', $product->id)
                    ->where('status', 'sold')
                    ->limit($qty)
                    ->get();
            }
        }

        $restoredCostPerUnit = $product->tracksInventory()
            ? ($invoiceItem ? (float) $invoiceItem->cost_price : (float) $product->cost_price)
            : 0.0;

        $serialIdsForItem = $product->tracksInventory() && $product->has_serial
            ? $restoredSerials->pluck('id')->map(fn ($id) => (int) $id)->all()
            : null;

        $return->items()->create([
            'product_id' => $item['product_id'],
            'invoice_item_id' => $invoiceItem?->id,
            'quantity' => $qty,
            'price' => $item['price'],
            'discount' => $item['discount'] ?? 0,
            'import_price' => $item['price'],
            'cost_price' => $restoredCostPerUnit,
            'serial_ids' => ! empty($serialIdsForItem) ? $serialIdsForItem : null,
        ]);

        if (! $product->tracksInventory()) {
            return;
        }

        MovingAvgCostingService::applySaleReturn($product, $qty, $restoredCostPerUnit);
        $product->refresh();

        foreach ($restoredSerials as $serial) {
            $serial->status = 'in_stock';
            $serial->sold_at = null;
            $serial->invoice_id = null;
            $serial->sold_cost_price = null;
            $serial->save();
        }

        if ($product->has_serial) {
            $product->recomputeFromSerials();
        }

        StockMovementService::record(
            $product,
            StockMovementService::TYPE_IN_INVOICE_RETURN,
            $qty,
            $restoredCostPerUnit,
            $return,
            [
                'branch_id' => $return->branch_id ?? null,
                'ref_code' => $return->code,
                'moved_at' => $returnDate,
                'note' => 'Khach tra hang phieu '.$return->code,
            ]
        );
    }

    private function recordCustomerImpact(OrderReturn $return, array $payload, array $context): void
    {
        if (empty($payload['customer_id'])) {
            return;
        }

        $customer = \App\Models\Customer::find($payload['customer_id']);
        if (! $customer) {
            return;
        }

        app(CustomerDebtService::class)->recordReturn(
            $customer->id,
            (float) $payload['total'],
            $return,
            "Giam cong no do tra hang phieu {$return->code}"
        );

        $paidToCustomer = (float) ($payload['paid_to_customer'] ?? 0);
        if (($context['record_paid_refund_debt_settlement'] ?? true) && $paidToCustomer > 0) {
            app(CustomerDebtService::class)->recordAdjustment(
                $customer->id,
                $paidToCustomer,
                "Tat toan tien da tra khach cho phieu tra {$return->code}",
                ['order_return_id' => $return->id, 'ref_code' => $return->code]
            );
        }

        $customer->decrement('total_spent', $payload['total']);
    }

    private function recordCashFlow(OrderReturn $return, array $payload, array $context, \Carbon\Carbon $returnDate): void
    {
        if ((float) $return->paid_to_customer <= 0) {
            return;
        }

        $customer = ! empty($payload['customer_id']) ? \App\Models\Customer::find($payload['customer_id']) : null;
        CashFlow::create([
            'code' => 'PC'.date('YmdHis').rand(10, 99),
            'type' => 'payment',
            'amount' => $return->paid_to_customer,
            'time' => $returnDate,
            'category' => 'Chi tien tra hang khach',
            'target_type' => 'Khach hang',
            'target_id' => $return->customer_id,
            'target_name' => $customer?->name ?? 'Khach le',
            'reference_type' => 'OrderReturn',
            'reference_code' => $return->code,
            'payment_method' => $context['payment_method'] ?? 'cash',
            'description' => "Chi tra hang khach cho phieu {$return->code}".($customer ? " - {$customer->name}" : ''),
        ]);
    }

    private function resolveReturnDate(array $payload, array $context): \Carbon\Carbon
    {
        $orderDate = $context['order_date'] ?? $payload['return_date'] ?? $payload['order_date'] ?? null;
        $returnDate = BusinessDateTime::forCreate($orderDate);

        if (! empty($payload['invoice_id'])) {
            $invoice = Invoice::find($payload['invoice_id']);
            $invoiceDate = $invoice?->transaction_date ?? $invoice?->created_at;
            if ($invoiceDate && $returnDate->lt($invoiceDate)) {
                throw new \Exception('Ngay tra hang khong the truoc ngay hoa don goc.');
            }
        }

        return $returnDate;
    }
}
