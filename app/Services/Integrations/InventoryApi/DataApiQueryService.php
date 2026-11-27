<?php

namespace App\Services\Integrations\InventoryApi;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\User;
use App\Services\Integrations\InventoryApi\Concerns\SyncCursorPagination;
use App\Support\Debt\PartnerDebtDisplayBalance;
use App\Support\Reports\MetricService;
use App\Support\Reports\SellerResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only projections for the `data:read` endpoints of the public Kiot API
 * (/api/public/v1/data/*). Money is returned as integer VND. Seller / staff
 * display names go through the report staff aliases (config
 * reports.staff_aliases); the raw snapshot names are returned alongside.
 */
class DataApiQueryService
{
    use SyncCursorPagination;

    public const MAX_LIMIT = 200;

    /** @var array<string, string>|null */
    private ?array $userNameAliases = null;

    // ───────────────────────── Invoices ─────────────────────────

    public function invoices(array $filters): array
    {
        $query = Invoice::query()
            ->with($this->invoiceRelations());

        if (! empty($filters['status'])) {
            $query->where('invoices.status', (string) $filters['status']);
        }
        if (! empty($filters['customer_code'])) {
            $query->whereIn('invoices.customer_id', Customer::query()->select('id')->where('code', (string) $filters['customer_code']));
        }

        return $this->paginate(
            $query,
            'invoices',
            $filters,
            'COALESCE(invoices.transaction_date, invoices.sale_time, invoices.created_at)',
            fn (Collection $invoices) => $this->transformInvoices($invoices),
        );
    }

    public function invoice(string $code): array
    {
        $invoice = Invoice::query()->with($this->invoiceRelations())->where('code', $code)->first();
        if (! $invoice) {
            throw new InventoryApiException('INVOICE_NOT_FOUND', 'Không tìm thấy hóa đơn '.$code.'.', 404);
        }

        return $this->transformInvoices(collect([$invoice]))[0];
    }

    private function invoiceRelations(): array
    {
        return [
            'customer:id,code,name,phone',
            'order:id,code',
            'items.product' => fn ($product) => $product->withTrashed()->select(['id', 'sku', 'name']),
            'items.serials:id,invoice_item_id,serial_number',
        ];
    }

    private function transformInvoices(Collection $invoices): array
    {
        $sellers = $this->invoiceSellerNames($invoices->pluck('id')->all());

        return $invoices->map(function (Invoice $invoice) use ($sellers) {
            $cancelled = $this->isCancelled($invoice->status);
            $total = $this->money($invoice->total);
            $paid = $this->money($invoice->customer_paid);

            return [
                'id' => $invoice->id,
                'code' => $invoice->code,
                'status' => $invoice->status,
                'is_cancelled' => $cancelled,
                'transaction_at' => $this->time($this->date($invoice->transaction_date ?? $invoice->sale_time ?? $invoice->created_at)),
                'customer' => $this->customerRef($invoice->customer),
                'seller' => [
                    'name' => $sellers[$invoice->id] ?? ($invoice->seller_name ?: null),
                    'raw_name' => $invoice->seller_name,
                    'employee_id' => $invoice->created_by !== null ? (int) $invoice->created_by : null,
                ],
                'created_by_name' => $this->aliasUserName($invoice->created_by_name),
                'created_by_raw_name' => $invoice->created_by_name,
                'sales_channel' => $invoice->sales_channel,
                'price_book_name' => $invoice->price_book_name,
                'payment_method' => $invoice->payment_method,
                'subtotal' => $this->money($invoice->subtotal),
                'discount' => $this->money($invoice->discount),
                'total' => $total,
                'customer_paid' => $paid,
                'order_deposit_applied' => $this->money($invoice->order_deposit_applied_amount ?? 0),
                'remaining' => $cancelled ? 0 : max(0, $total - $paid),
                'order_code' => $invoice->order?->code,
                'note' => $invoice->note,
                'delivery' => [
                    'is_delivery' => (bool) $invoice->is_delivery,
                    'delivery_partner' => $invoice->delivery_partner,
                    'tracking_code' => $invoice->tracking_code,
                    'delivery_fee' => $this->money($invoice->delivery_fee ?? 0),
                    'cod_amount' => $this->money($invoice->cod_amount ?? 0),
                    'receiver_name' => $invoice->receiver_name,
                    'receiver_phone' => $invoice->receiver_phone,
                    'receiver_address' => $invoice->receiver_address,
                    'receiver_ward' => $invoice->receiver_ward,
                    'receiver_district' => $invoice->receiver_district,
                    'receiver_city' => $invoice->receiver_city,
                    'delivery_note' => $invoice->delivery_note,
                ],
                'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                    'sku' => $item->product?->sku,
                    'name' => $item->product?->name,
                    'quantity' => (float) $item->quantity == (int) $item->quantity ? (int) $item->quantity : (float) $item->quantity,
                    'price' => $this->money($item->price),
                    'discount' => $this->money($item->discount ?? 0),
                    'subtotal' => $this->money($item->subtotal ?? ((float) $item->price * (float) $item->quantity - (float) ($item->discount ?? 0))),
                    'cost_price' => $this->money($item->cost_price ?? 0),
                    'serials' => $item->serials->isNotEmpty()
                        ? $item->serials->pluck('serial_number')->filter()->values()->all()
                        : $this->splitSerials($item->serial),
                    'warranty_policy' => $item->warranty_policy,
                ])->values()->all(),
                'created_at' => $this->time($invoice->created_at),
                'updated_at' => $this->time($invoice->updated_at),
            ];
        })->values()->all();
    }

    // ───────────────────────── Returns ─────────────────────────

    public function returns(array $filters): array
    {
        $query = OrderReturn::query()->with([
            'customer:id,code,name,phone',
            'invoice:id,code,created_by,seller_name',
            'items.product' => fn ($product) => $product->withTrashed()->select(['id', 'sku', 'name', 'cost_price']),
        ]);
        if (! empty($filters['status'])) {
            $query->where('returns.status', (string) $filters['status']);
        }

        $dateExpr = Schema::hasColumn('returns', 'recorded_at')
            ? 'COALESCE(returns.recorded_at, returns.created_at)'
            : 'returns.created_at';

        return $this->paginate($query, 'returns', $filters, $dateExpr, function (Collection $returns) {
            $resolver = SellerResolver::forStaffReports();
            $keys = $resolver->returnSellerMap(OrderReturn::query()->whereIn('returns.id', $returns->pluck('id')->all()));
            $meta = $resolver->sellerMeta(array_values($keys));
            $serials = $this->serialNumbers($returns->flatMap(fn (OrderReturn $return) => $return->items->flatMap(fn ($item) => (array) ($item->serial_ids ?? []))));

            return $returns->map(fn (OrderReturn $return) => [
                'id' => $return->id,
                'code' => $return->code,
                'status' => $return->status,
                'returned_at' => $this->time($this->date($return->recorded_at ?? $return->created_at)),
                'invoice_code' => $return->invoice?->code,
                'customer' => $this->customerRef($return->customer),
                'seller' => [
                    'name' => isset($keys[$return->id]) ? ($meta[$keys[$return->id]]['name'] ?? null) : null,
                    'raw_name' => $return->seller_name,
                ],
                'received_by_name' => $return->received_by_name,
                'created_by_name' => $this->aliasUserName($return->created_by_name),
                'created_by_raw_name' => $return->created_by_name,
                'sales_channel' => $return->sales_channel,
                'subtotal' => $this->money($return->subtotal),
                'discount' => $this->money($return->discount),
                'fee' => $this->money($return->fee),
                'total' => $this->money($return->total),
                'paid_to_customer' => $this->money($return->paid_to_customer),
                'note' => $return->note,
                'items' => $return->items->map(fn ($item) => [
                    'sku' => $item->product?->sku,
                    'name' => $item->product?->name,
                    'quantity' => (int) $item->quantity,
                    'price' => $this->money($item->price),
                    'discount' => $this->money($item->discount ?? 0),
                    // Same source as the reports (MetricService): the return's cost snapshot,
                    // else the product's cost. import_price is the refund price.
                    'cost_price' => $this->money((float) ($item->cost_price ?? 0) > 0 ? $item->cost_price : ($item->product?->cost_price ?? 0)),
                    'serials' => collect((array) ($item->serial_ids ?? []))
                        ->map(fn ($id) => $serials[(int) $id] ?? null)->filter()->values()->all(),
                ])->values()->all(),
                'created_at' => $this->time($return->created_at),
                'updated_at' => $this->time($return->updated_at),
            ])->values()->all();
        });
    }

    // ───────────────────────── Purchases ─────────────────────────

    public function purchases(array $filters): array
    {
        $query = Purchase::query()->with([
            'supplier:id,code,name,phone',
            'user:id,name',
            'employee:id,name',
            'items.product' => fn ($product) => $product->withTrashed()->select(['id', 'sku', 'name']),
        ]);
        if (! empty($filters['status'])) {
            $query->where('purchases.status', (string) $filters['status']);
        }

        return $this->paginate($query, 'purchases', $filters, 'COALESCE(purchases.purchase_date, purchases.created_at)', fn (Collection $purchases) => $purchases->map(fn (Purchase $purchase) => [
            'id' => $purchase->id,
            'code' => $purchase->code,
            'status' => $purchase->status,
            'purchase_date' => $this->time($this->date($purchase->purchase_date ?? $purchase->created_at)),
            'supplier' => $this->customerRef($purchase->supplier),
            'created_by_name' => $this->aliasUserName($purchase->user?->name),
            'created_by_raw_name' => $purchase->user?->name,
            'employee_name' => $purchase->employee?->name,
            'total_amount' => $this->money($purchase->total_amount),
            'discount' => $this->money($purchase->discount),
            'other_costs_total' => $this->money($purchase->other_costs_total ?? $purchase->other_costs ?? 0),
            'paid_amount' => $this->money($purchase->paid_amount),
            'debt_amount' => $this->money($purchase->debt_amount),
            'payment_method' => $purchase->payment_method,
            'note' => $purchase->note,
            'items' => $purchase->items->map(fn ($item) => [
                'sku' => $item->product?->sku ?? $item->product_code,
                'name' => $item->product?->name ?? $item->product_name,
                'quantity' => (int) $item->quantity,
                'price' => $this->money($item->price),
                'discount' => $this->money($item->discount ?? 0),
                'subtotal' => $this->money($item->subtotal),
                'unit_cost_allocated' => $item->unit_cost_allocated !== null ? $this->money($item->unit_cost_allocated) : null,
                'warranty_months' => $item->warranty_months !== null ? (int) $item->warranty_months : null,
            ])->values()->all(),
            'created_at' => $this->time($purchase->created_at),
            'updated_at' => $this->time($purchase->updated_at),
        ])->values()->all());
    }

    // ───────────────────────── Repairs / tasks ─────────────────────────

    public function repairs(array $filters): array
    {
        $query = Task::query()->repairs()->with([
            'product' => fn ($product) => $product->withTrashed()->select(['id', 'sku', 'name']),
            'serialImei:id,serial_number',
            'customer:id,code,name,phone',
            'assignedEmployee:id,name',
            'invoice:id,code',
            'creator:id,name',
            'parts.product' => fn ($product) => $product->withTrashed()->select(['id', 'sku', 'name']),
        ]);
        if (! empty($filters['status'])) {
            $query->where('tasks.status', (string) $filters['status']);
        }

        return $this->paginate($query, 'tasks', $filters, 'COALESCE(tasks.received_at, tasks.created_at)', fn (Collection $tasks) => $tasks->map(fn (Task $task) => [
            'id' => $task->id,
            'code' => $task->code,
            'title' => $task->title,
            'status' => $task->status,
            'status_label' => $task->status_label,
            'sub_status' => $task->sub_status,
            'priority' => $task->priority,
            'product' => $task->product ? ['sku' => $task->product->sku, 'name' => $task->product->name] : null,
            'serial' => $task->serialImei?->serial_number,
            'customer' => $task->customer
                ? $this->customerRef($task->customer)
                : ($task->customer_name || $task->customer_phone ? ['code' => null, 'name' => $task->customer_name, 'phone' => $task->customer_phone] : null),
            'issue_description' => $task->issue_description,
            'assigned_employee' => $task->assignedEmployee?->name,
            'invoice_code' => $task->invoice?->code,
            'warranty_policy' => $task->warranty_policy,
            'original_cost' => $this->money($task->original_cost ?? 0),
            'parts_cost' => $this->money($task->parts_cost ?? 0),
            'total_cost' => $this->money($task->total_cost ?? 0),
            'labor_fee' => $this->money($task->labor_fee ?? 0),
            'parts_total' => $this->money($task->parts_total ?? 0),
            'total_amount' => $this->money($task->total_amount ?? 0),
            'paid_amount' => $this->money($task->paid_amount ?? 0),
            'debt_amount' => $this->money($task->debt_amount ?? 0),
            'warranty_covered_amount' => $this->money($task->warranty_covered_amount ?? 0),
            'customer_payable_amount' => $this->money($task->customer_payable_amount ?? 0),
            'parts' => $task->parts->map(fn ($part) => [
                'sku' => $part->product?->sku,
                'name' => $part->product?->name,
                'quantity' => (int) $part->quantity,
                'unit_cost' => $this->money($part->unit_cost ?? 0),
                'sale_price' => $this->money($part->sale_price ?? 0),
                'total_cost' => $this->money($part->total_cost ?? 0),
                'direction' => $part->direction,
            ])->values()->all(),
            'received_at' => $this->time($this->date($task->received_at)),
            'deadline' => $this->time($this->date($task->deadline)),
            'returned_at' => $this->time($this->date($task->returned_at)),
            'completed_at' => $this->time($this->date($task->completed_at)),
            'cancelled_at' => $this->time($this->date($task->cancelled_at)),
            'created_by_name' => $this->aliasUserName($task->creator?->name),
            'created_at' => $this->time($task->created_at),
            'updated_at' => $this->time($task->updated_at),
        ])->values()->all());
    }

    public function tasks(array $filters): array
    {
        $query = Task::query()->with([
            'category:id,name',
            'assignedEmployee:id,name',
            'assignments.employee:id,name',
            'creator:id,name',
        ]);
        if (! empty($filters['type'])) {
            $query->where('tasks.type', (string) $filters['type']);
        }
        if (! empty($filters['status'])) {
            $query->where('tasks.status', (string) $filters['status']);
        }

        return $this->paginate($query, 'tasks', $filters, 'tasks.created_at', fn (Collection $tasks) => $tasks->map(fn (Task $task) => [
            'id' => $task->id,
            'code' => $task->code,
            'type' => $task->type,
            'title' => $task->title,
            'status' => $task->status,
            'status_label' => $task->status_label,
            'priority' => $task->priority,
            'progress' => $task->progress !== null ? (int) $task->progress : null,
            'category' => $task->category?->name,
            'assigned_employee' => $task->assignedEmployee?->name,
            'assignees' => $task->assignments->map(fn ($assignment) => [
                'name' => $assignment->employee?->name,
                'status' => $assignment->status,
                'assigned_at' => $this->time($this->date($assignment->assigned_at)),
            ])->values()->all(),
            'deadline' => $this->time($this->date($task->deadline)),
            'created_by_name' => $this->aliasUserName($task->creator?->name),
            'created_by_raw_name' => $task->creator?->name,
            'created_at' => $this->time($task->created_at),
            'completed_at' => $this->time($this->date($task->completed_at)),
            'cancelled_at' => $this->time($this->date($task->cancelled_at)),
            'updated_at' => $this->time($task->updated_at),
        ])->values()->all());
    }

    // ───────────────────────── Cash flows ─────────────────────────

    public function cashflows(array $filters): array
    {
        $since = $this->since($filters);
        // Incremental sync also returns soft-deleted vouchers so they can be dropped.
        $query = $since ? CashFlow::query()->withTrashed() : CashFlow::query();
        if (! empty($filters['type'])) {
            $query->where('cash_flows.type', (string) $filters['type']);
        }
        if (! empty($filters['status'])) {
            $filters['status'] === 'active'
                ? $query->active()
                : $query->where('cash_flows.status', (string) $filters['status']);
        }

        return $this->paginate($query, 'cash_flows', $filters, 'COALESCE(cash_flows.time, cash_flows.created_at)', fn (Collection $rows) => $rows->map(fn (CashFlow $cashFlow) => [
            'id' => $cashFlow->id,
            'code' => $cashFlow->code,
            'type' => $cashFlow->type,
            'amount' => $this->money($cashFlow->amount),
            'time' => $this->time($this->date($cashFlow->time ?? $cashFlow->created_at)),
            'category' => $cashFlow->category,
            'target' => [
                'type' => $cashFlow->target_type,
                'id' => $cashFlow->target_id !== null ? (int) $cashFlow->target_id : null,
                'name' => $cashFlow->target_name,
            ],
            'reference_type' => $cashFlow->reference_type,
            'reference_code' => $cashFlow->reference_code,
            'payment_method' => $cashFlow->payment_method,
            'accounting_result' => $cashFlow->accounting_result !== null ? (bool) $cashFlow->accounting_result : null,
            'status' => $cashFlow->status ?: 'active',
            'is_cancelled' => $cashFlow->status === 'cancelled' || $cashFlow->deleted_at !== null,
            'description' => $cashFlow->description,
            'deleted' => $cashFlow->deleted_at !== null,
            'created_at' => $this->time($cashFlow->created_at),
            'updated_at' => $this->time($cashFlow->updated_at),
        ])->values()->all());
    }

    // ───────────────────────── Customer debts ─────────────────────────

    /**
     * Customers with a non-zero debt. Two figures, same labels as the screens:
     *  - receivable_gross: nợ phải thu CHƯA bù trừ (as Tổng quan / Dashboard)
     *  - receivable_net:   nợ phải thu ĐÃ bù trừ công nợ NCC/ứng trước (as Khách hàng page)
     */
    public function customerDebts(array $filters): array
    {
        $query = Customer::query()
            ->where('customers.is_customer', true)
            ->whereNull('customers.merged_into_id')
            ->where(function (Builder $debt) {
                $debt->where('customers.debt_amount', '!=', 0);
                if (Schema::hasColumn('customers', 'supplier_debt_amount')) {
                    $debt->orWhere(fn (Builder $supplier) => $supplier->where('customers.is_supplier', true)
                        ->where('customers.supplier_debt_amount', '!=', 0));
                }
            });
        if (! empty($filters['customer_code'])) {
            $query->where('customers.code', (string) $filters['customer_code']);
        }

        return $this->paginate($query, 'customers', $filters, null, fn (Collection $customers) => $customers->map(function (Customer $customer) {
            $aliases = PartnerDebtDisplayBalance::aliases($customer);

            return [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'is_supplier' => (bool) $customer->is_supplier,
                'receivable_gross' => $this->money($aliases['customer_receivable_balance']),
                'receivable_gross_label' => 'Nợ phải thu (chưa bù trừ)',
                'supplier_payable' => $this->money($aliases['supplier_payable_balance']),
                'receivable_net' => $this->money($aliases['customer_screen_debt']),
                'receivable_net_label' => 'Nợ phải thu (đã bù trừ công nợ NCC/ứng trước)',
                'stored_debt_amount' => $this->money($customer->debt_amount),
                'total_spent' => $this->money($customer->total_spent),
                'updated_at' => $this->time($customer->updated_at),
            ];
        })->values()->all(), ['customer_debt_labels' => [
            'receivable_gross' => 'Nợ phải thu (chưa bù trừ) — giống thẻ Nợ phải thu ở Tổng quan',
            'receivable_net' => 'Nợ phải thu (đã bù trừ công nợ NCC/ứng trước) — giống trang Khách hàng',
        ]]);
    }

    // ───────────────────────── Overview ─────────────────────────

    /**
     * Báo cáo Tổng quan for a period: MetricService (the single source used
     * by the Dashboard and Báo cáo > Tổng quan kinh doanh) plus the Dashboard's
     * own expense / purchase / debt figures applied to the requested period.
     */
    public function overview(array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : Carbon::now()->startOfMonth();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : Carbon::now()->endOfDay();
        if ($from->gt($to)) {
            throw new InventoryApiException('VALIDATION_FAILED', 'Ngày bắt đầu (from) phải trước ngày kết thúc (to).', 422);
        }

        $metrics = MetricService::compute($from, $to);

        $expenses = CashFlow::active()->cashImpacting()->where('type', 'payment')
            ->whereBetween('created_at', [$from, $to])
            ->where(fn ($q) => $q->where('category', '!=', 'Chi tiền trả NCC')->orWhereNull('category'))
            ->sum('amount');
        $receipts = CashFlow::active()->cashImpacting()->where('type', 'receipt')
            ->whereNotIn('category', ['Thu nợ khách hàng', 'Điều chỉnh công nợ'])
            ->whereBetween('created_at', [$from, $to])->sum('amount');
        $payments = CashFlow::active()->cashImpacting()->where('type', 'payment')
            ->whereBetween('created_at', [$from, $to])->sum('amount');
        $purchases = Purchase::query()->whereBetween('created_at', [$from, $to])->where('status', 'completed')->sum('total_amount');
        $returns = OrderReturn::query()->whereBetween('created_at', [$from, $to])->sum('total');
        $newCustomers = Customer::query()->whereBetween('created_at', [$from, $to])->count();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'invoice_count' => (int) $metrics['invoice_count'],
            'return_count' => (int) $metrics['return_count'],
            'gross_revenue' => $this->money($metrics['gross_revenue']),
            'invoice_discount' => $this->money($metrics['invoice_discount']),
            'return_value' => $this->money($metrics['return_value']),
            'net_revenue' => $this->money($metrics['net_revenue']),
            'cogs_sold' => $this->money($metrics['cogs_sold']),
            'cogs_returned' => $this->money($metrics['cogs_returned']),
            'cogs_net' => $this->money($metrics['cogs_net']),
            'gross_profit' => $this->money($metrics['gross_profit']),
            'expenses' => $this->money($expenses),
            'cash_receipts' => $this->money($receipts),
            'cash_payments' => $this->money($payments),
            'purchase_total' => $this->money($purchases),
            'return_total' => $this->money($returns),
            'new_customers' => (int) $newCustomers,
            // Snapshot at request time (not period based), as on the Dashboard.
            'customer_receivable_unoffset' => $this->money(Customer::where('debt_amount', '>', 0)->sum('debt_amount')),
            'customer_receivable_unoffset_label' => 'Nợ phải thu (chưa bù trừ)',
            'supplier_payable' => $this->money(Customer::where('is_supplier', true)->where('supplier_debt_amount', '>', 0)->sum('supplier_debt_amount')),
            'generated_at' => $this->time(now()),
        ];
    }

    // ───────────────────────── Helpers ─────────────────────────

    /**
     * @param  callable(Collection): array  $transform
     */
    private function paginate(Builder $query, string $table, array $filters, ?string $businessDateExpr, callable $transform, array $extraMeta = []): array
    {
        $watermark = now();
        $limit = $this->limit($filters, self::MAX_LIMIT);
        $since = $this->since($filters);

        $query->select("{$table}.*");
        if ($since) {
            $query->whereRaw("COALESCE({$table}.updated_at, {$table}.created_at) >= ?", [$since->format('Y-m-d H:i:s')]);
        }
        if ($businessDateExpr !== null && ! empty($filters['from'])) {
            $query->whereRaw("{$businessDateExpr} >= ?", [Carbon::parse($filters['from'])->startOfDay()->format('Y-m-d H:i:s')]);
        }
        if ($businessDateExpr !== null && ! empty($filters['to'])) {
            $query->whereRaw("{$businessDateExpr} <= ?", [Carbon::parse($filters['to'])->endOfDay()->format('Y-m-d H:i:s')]);
        }

        $this->addSyncKey($query, $table);
        $paginator = $query->orderBy('sync_updated_at')->orderBy("{$table}.id")
            ->cursorPaginate($limit, ['*'], 'cursor', $this->cursor($filters));

        return [
            'data' => $transform(collect($paginator->items())),
            'meta' => $this->meta($paginator->nextCursor()?->encode(), $paginator->hasMorePages(), $limit, $since, $watermark) + $extraMeta,
        ];
    }

    /**
     * @return array<int, string> invoice id => aliased seller display name
     */
    private function invoiceSellerNames(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $resolver = SellerResolver::forStaffReports();
        $keys = $resolver->invoiceSellerMap(Invoice::query()->whereIn('id', $invoiceIds));
        $meta = $resolver->sellerMeta(array_values($keys));

        return collect($keys)
            ->map(fn (string $key) => $key === 'unknown' ? null : ($meta[$key]['name'] ?? null))
            ->all();
    }

    /**
     * Staff aliases for creator snapshots (e.g. the "Claude AI" account is
     * displayed as the staff it works for). Display only.
     */
    private function aliasUserName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }
        if ($this->userNameAliases === null) {
            $aliases = (array) config('reports.staff_aliases', []);
            $this->userNameAliases = [];
            if ($aliases !== []) {
                $names = User::query()
                    ->whereIn('id', array_unique(array_merge(array_keys($aliases), array_values($aliases))))
                    ->pluck('name', 'id');
                foreach ($aliases as $source => $target) {
                    if (isset($names[(int) $source], $names[(int) $target])) {
                        $this->userNameAliases[$names[(int) $source]] = $names[(int) $target];
                    }
                }
            }
        }

        return $this->userNameAliases[$name] ?? $name;
    }

    private function customerRef(?Customer $customer): ?array
    {
        return $customer ? [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
        ] : null;
    }

    /**
     * @return array<int, string> serial id => serial number
     */
    private function serialNumbers(Collection $ids): array
    {
        $ids = $ids->map(fn ($id) => (int) $id)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : SerialImei::query()->whereIn('id', $ids->all())->pluck('serial_number', 'id')->all();
    }

    private function splitSerials(?string $serial): array
    {
        return collect(explode(',', (string) $serial))->map(fn ($value) => trim($value))->filter()->values()->all();
    }

    private function isCancelled(?string $status): bool
    {
        return in_array(mb_strtolower(trim((string) $status)), ['đã hủy', 'cancelled', 'canceled', 'void'], true);
    }

    private function date(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface ? $value : Carbon::parse($value);
    }

    private function money(mixed $value): int
    {
        return (int) round((float) $value);
    }
}
