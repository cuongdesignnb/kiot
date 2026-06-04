<?php

namespace Tests\Feature\Invoice;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceUpdateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InvoiceTransactionDateTimeUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('order_change_time', 99999);

        $this->product = Product::create([
            'sku' => 'SP-DATETIME-' . uniqid(),
            'name' => 'Invoice datetime product',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 10,
            'inventory_total_cost' => 1000000,
            'is_active' => true,
            'has_serial' => false,
        ]);

        $this->customer = Customer::create([
            'code' => 'KH-DATETIME-' . uniqid(),
            'name' => 'Invoice datetime customer',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'total_spent' => 0,
            'is_customer' => true,
        ]);
    }

    public function test_same_day_time_change_updates_transaction_date_without_created_at(): void
    {
        $invoice = $this->invoiceAt('2026-06-03 14:21:00');

        $this->updateInvoiceDate($invoice, '2026-06-03 09:00:00');

        $invoice->refresh();
        $this->assertSame('2026-06-03 09:00:00', $this->formatTime($invoice->transaction_date));
        $this->assertSame('2026-06-03 14:21:00', $this->formatTime($invoice->created_at));
    }

    public function test_different_day_time_change_updates_transaction_date_without_created_at(): void
    {
        $invoice = $this->invoiceAt('2026-06-03 14:21:00');

        $this->updateInvoiceDate($invoice, '2026-05-24 16:17:00');

        $invoice->refresh();
        $this->assertSame('2026-05-24 16:17:00', $this->formatTime($invoice->transaction_date));
        $this->assertSame('2026-06-03 14:21:00', $this->formatTime($invoice->created_at));
    }

    public function test_cashflow_time_follows_invoice_transaction_date_but_created_at_does_not(): void
    {
        $invoice = $this->invoiceAt('2026-06-03 14:21:00');
        $cashFlow = CashFlow::create([
            'code' => 'PT-DATETIME-' . uniqid(),
            'type' => 'receipt',
            'amount' => 150000,
            'time' => Carbon::parse('2026-06-03 14:21:00'),
            'category' => 'Invoice receipt',
            'target_type' => 'Customer',
            'target_id' => $this->customer->id,
            'target_name' => $this->customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => $invoice->code,
            'description' => 'Invoice payment',
            'payment_method' => 'cash',
            'status' => 'active',
        ]);
        $cancelledCashFlow = CashFlow::create([
            'code' => 'PT-DATETIME-CANCEL-' . uniqid(),
            'type' => 'receipt',
            'amount' => 150000,
            'time' => Carbon::parse('2026-06-03 14:21:00'),
            'category' => 'Invoice receipt',
            'target_type' => 'Customer',
            'target_id' => $this->customer->id,
            'target_name' => $this->customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => $invoice->code,
            'description' => 'Cancelled invoice payment',
            'payment_method' => 'cash',
            'status' => 'cancelled',
        ]);
        $cashFlow->created_at = Carbon::parse('2026-06-03 14:22:00');
        $cashFlow->updated_at = Carbon::parse('2026-06-03 14:22:00');
        $cashFlow->save();
        $cancelledCashFlow->created_at = Carbon::parse('2026-06-03 14:23:00');
        $cancelledCashFlow->updated_at = Carbon::parse('2026-06-03 14:23:00');
        $cancelledCashFlow->save();

        $this->updateInvoiceDate($invoice, '2026-05-24 16:17:00');

        $cashFlow->refresh();
        $cancelledCashFlow->refresh();
        $this->assertSame('2026-05-24 16:17:00', $this->formatTime($cashFlow->time));
        $this->assertSame('2026-06-03 14:22:00', $this->formatTime($cashFlow->created_at));
        $this->assertSame('2026-06-03 14:21:00', $this->formatTime($cancelledCashFlow->time));
        $this->assertSame('2026-06-03 14:23:00', $this->formatTime($cancelledCashFlow->created_at));
    }

    public function test_build_change_plan_detects_same_day_time_change_as_date_only(): void
    {
        $invoice = $this->invoiceAt('2026-06-03 14:21:00');

        $plan = app(InvoiceUpdateService::class)->buildChangePlan(
            $invoice,
            $this->payloadFor($invoice, '2026-06-03 09:00:00')
        );

        $this->assertTrue($plan['date_changed']);
        $this->assertTrue($plan['only_date_changed']);
        $this->assertFalse($plan['content_changed']);
    }

    public function test_build_change_plan_ignores_equal_datetime_to_the_minute(): void
    {
        $invoice = $this->invoiceAt('2026-06-03 14:21:45');

        $plan = app(InvoiceUpdateService::class)->buildChangePlan(
            $invoice,
            $this->payloadFor($invoice, '2026-06-03 14:21:00')
        );

        $this->assertFalse($plan['date_changed']);
        $this->assertFalse($plan['only_date_changed']);
    }

    private function invoiceAt(string $time): Invoice
    {
        $at = Carbon::parse($time);
        $invoice = Invoice::create([
            'code' => 'HD-DATETIME-' . uniqid(),
            'subtotal' => 150000,
            'discount' => 0,
            'total' => 150000,
            'customer_paid' => 150000,
            'customer_id' => $this->customer->id,
            'status' => 'Hoàn thành',
            'sales_channel' => 'Test',
            'price_book_name' => 'Giá bán lẻ',
            'payment_method' => 'Tiền mặt',
            'transaction_date' => $at,
            'lock_started_at' => now(),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 150000,
            'cost_price' => 100000,
            'discount' => 0,
            'subtotal' => 150000,
            'note' => '',
        ]);

        return $invoice->refresh();
    }

    private function updateInvoiceDate(Invoice $invoice, string $newTime): void
    {
        app(InvoiceUpdateService::class)->updateInvoice(
            $invoice,
            $this->payloadFor($invoice, $newTime),
            [
                'user' => $this->userWithPermissions(),
                'transaction_date_change_reason' => 'Correct invoice business datetime',
            ]
        );
    }

    private function payloadFor(Invoice $invoice, string $transactionDate): array
    {
        return [
            'transaction_date' => $transactionDate,
            'customer_id' => $this->customer->id,
            'subtotal' => 150000,
            'discount' => 0,
            'total' => 150000,
            'customer_paid' => 150000,
            'payment_method' => $invoice->payment_method ?? 'Tiền mặt',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => 150000,
                'discount' => 0,
                'note' => '',
                'serial_ids' => [],
            ]],
        ];
    }

    private function userWithPermissions(): User
    {
        $name = 'invoice-datetime-' . uniqid();
        $role = Role::create([
            'name' => $name,
            'display_name' => $name,
            'permissions' => ['invoices.edit', 'invoices.change_transaction_date'],
        ]);

        return User::create([
            'name' => 'Invoice DateTime Admin',
            'email' => $name . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);
    }

    private function formatTime($value): string
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
