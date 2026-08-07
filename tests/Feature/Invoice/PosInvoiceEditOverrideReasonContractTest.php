<?php

namespace Tests\Feature\Invoice;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceUpdateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PosInvoiceEditOverrideReasonContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('order_change_time', 24);
        Setting::set('block_edit_cancel_einvoice', false);
    }

    public function test_edit_screen_receives_authoritative_server_policy_hints(): void
    {
        [$invoice] = $this->invoice();
        $user = $this->user(['orders.create', 'invoices.override_time_lock', 'invoices.change_transaction_date']);

        $this->actingAs($user)
            ->get("/orders/create?action=edit&invoice_id={$invoice->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Create')
                ->where('invoice.edit_policy.is_time_locked', true)
                ->where('invoice.edit_policy.order_change_time_hours', 24)
                ->where('invoice.edit_policy.can_override_time_lock', true)
                ->where('invoice.edit_policy.can_change_transaction_date', true)
                ->where('invoice.edit_policy.original_transaction_date', $invoice->transaction_date->toIso8601String()));
    }

    public function test_recent_invoice_edit_succeeds_without_an_override_reason(): void
    {
        Setting::set('order_change_time', 99999);
        [$invoice, $product] = $this->invoice();

        app(InvoiceUpdateService::class)->updateInvoice(
            $invoice,
            $this->payload($invoice, $product, 125000),
            ['user' => $this->user(['invoices.edit'])]
        );

        $this->assertSame(125000.0, (float) $invoice->fresh()->total);
    }

    public function test_locked_invoice_without_override_permission_is_rejected_without_mutation(): void
    {
        [$invoice, $product] = $this->invoice();
        $before = $this->snapshot($invoice, $product);

        $this->expectExceptionMessage('Cần quyền override.');
        try {
            app(InvoiceUpdateService::class)->updateInvoice(
                $invoice,
                $this->payload($invoice, $product, 125000),
                ['user' => $this->user(['invoices.edit'])]
            );
        } finally {
            $this->assertSame($before, $this->snapshot($invoice, $product));
        }
    }

    public function test_locked_invoice_requires_a_non_blank_override_reason_without_mutation(): void
    {
        [$invoice, $product] = $this->invoice();
        $before = $this->snapshot($invoice, $product);

        $this->expectExceptionMessage('Cần nhập lý do override');
        try {
            app(InvoiceUpdateService::class)->updateInvoice(
                $invoice,
                $this->payload($invoice, $product, 125000),
                ['user' => $this->user(['invoices.edit', 'invoices.override_time_lock']), 'time_lock_override_reason' => '   ']
            );
        } finally {
            $this->assertSame($before, $this->snapshot($invoice, $product));
        }
    }

    public function test_http_validation_rejects_a_too_short_time_lock_reason(): void
    {
        [$invoice, $product] = $this->invoice();
        $before = $this->snapshot($invoice, $product);
        $user = $this->user(['invoices.edit', 'invoices.override_time_lock']);

        $this->actingAs($user)
            ->put("/invoices/{$invoice->id}", array_merge($this->payload($invoice, $product, 125000), [
                'time_lock_override_reason' => 'abc',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('time_lock_override_reason');

        $this->assertSame($before, $this->snapshot($invoice, $product));
    }

    public function test_authorized_time_lock_override_updates_without_inventory_replay(): void
    {
        [$invoice, $product, $customer, $serial] = $this->invoice(withSerial: true);
        $before = $this->snapshot($invoice, $product, $serial);

        app(InvoiceUpdateService::class)->updateInvoice(
            $invoice,
            $this->payload($invoice, $product, 125000, $serial),
            [
                'user' => $this->user(['invoices.edit', 'invoices.override_time_lock']),
                'time_lock_override_reason' => 'Sửa đơn giá theo phiếu đã duyệt',
            ]
        );

        $this->assertSame(125000.0, (float) $invoice->fresh()->total);
        $this->assertSame($before['product_stock_quantity'], (float) $product->fresh()->stock_quantity);
        $this->assertSame($before['product_inventory_total_cost'], (float) $product->fresh()->inventory_total_cost);
        $this->assertSame($before['serial'], $this->serialSnapshot($serial));
        $this->assertSame($before['customer_debt_amount'], (float) $customer->fresh()->debt_amount);
        $this->assertTrue(ActivityLog::query()->where('action', 'invoice_update_time_lock_override')->exists());
    }

    public function test_seller_only_override_preserves_serial_and_inventory(): void
    {
        [$invoice, $product, , $serial] = $this->invoice(withSerial: true);
        $seller = Employee::create([
            'code' => 'NV-POS-'.uniqid(),
            'name' => 'Người bán QA POS',
            'is_active' => true,
        ]);
        $before = $this->snapshot($invoice, $product, $serial);
        $payload = $this->payload($invoice, $product, 100000, $serial);
        $payload['seller_employee_id'] = $seller->id;

        app(InvoiceUpdateService::class)->updateInvoice(
            $invoice,
            $payload,
            [
                'user' => $this->user(['invoices.edit', 'invoices.override_time_lock']),
                'time_lock_override_reason' => 'Sửa người bán theo chứng từ đã duyệt',
            ]
        );

        $this->assertSame($seller->id, $invoice->fresh()->created_by);
        $this->assertSame($before['product_stock_quantity'], (float) $product->fresh()->stock_quantity);
        $this->assertSame($before['product_inventory_total_cost'], (float) $product->fresh()->inventory_total_cost);
        $this->assertSame($before['serial'], $this->serialSnapshot($serial));
    }

    public function test_transaction_date_change_fails_closed_without_permission(): void
    {
        Setting::set('order_change_time', 99999);
        [$invoice, $product] = $this->invoice();
        $before = $this->snapshot($invoice, $product);

        $this->expectExceptionMessage('invoices.change_transaction_date');
        try {
            app(InvoiceUpdateService::class)->updateInvoice(
                $invoice,
                $this->payload($invoice, $product, 100000, null, now()->subDay()),
                ['user' => $this->user(['invoices.edit']), 'transaction_date_change_reason' => 'Sửa lại giờ giao dịch']
            );
        } finally {
            $this->assertSame($before, $this->snapshot($invoice, $product));
        }
    }

    public function test_transaction_date_change_requires_its_own_reason(): void
    {
        Setting::set('order_change_time', 99999);
        [$invoice, $product] = $this->invoice();
        $before = $this->snapshot($invoice, $product);

        $this->expectExceptionMessage('Cần nhập lý do đổi ngày hóa đơn');
        try {
            app(InvoiceUpdateService::class)->updateInvoice(
                $invoice,
                $this->payload($invoice, $product, 100000, null, now()->subDay()),
                ['user' => $this->user(['invoices.edit', 'invoices.change_transaction_date'])]
            );
        } finally {
            $this->assertSame($before, $this->snapshot($invoice, $product));
        }
    }

    public function test_dual_reason_flow_requires_two_separate_reasons(): void
    {
        [$invoice, $product] = $this->invoice();
        $before = $this->snapshot($invoice, $product);
        $user = $this->user(['invoices.edit', 'invoices.override_time_lock', 'invoices.change_transaction_date']);

        $this->expectExceptionMessage('Cần nhập lý do đổi ngày hóa đơn');
        try {
            app(InvoiceUpdateService::class)->updateInvoice(
                $invoice,
                $this->payload($invoice, $product, 125000, null, now()->subDay()),
                ['user' => $user, 'time_lock_override_reason' => 'Lý do quá hạn riêng biệt']
            );
        } finally {
            $this->assertSame($before, $this->snapshot($invoice, $product));
        }
    }

    public function test_dual_reason_flow_succeeds_only_when_both_reasons_are_present(): void
    {
        [$invoice, $product, , $serial] = $this->invoice(withSerial: true);
        $newDate = now()->subDays(2)->startOfMinute();
        $before = $this->snapshot($invoice, $product, $serial);

        app(InvoiceUpdateService::class)->updateInvoice(
            $invoice,
            $this->payload($invoice, $product, 125000, $serial, $newDate),
            [
                'user' => $this->user(['invoices.edit', 'invoices.override_time_lock', 'invoices.change_transaction_date']),
                'time_lock_override_reason' => 'Sửa đơn giá hóa đơn quá hạn',
                'transaction_date_change_reason' => 'Sửa đúng thời gian giao dịch',
            ]
        );

        $this->assertSame($newDate->format('Y-m-d H:i'), $invoice->fresh()->transaction_date->format('Y-m-d H:i'));
        $this->assertSame($before['product_stock_quantity'], (float) $product->fresh()->stock_quantity);
        $this->assertSame($before['product_inventory_total_cost'], (float) $product->fresh()->inventory_total_cost);
        $this->assertSame($before['serial'], $this->serialSnapshot($serial));
    }

    public function test_date_only_update_preserves_serial_debt_and_inventory(): void
    {
        Setting::set('order_change_time', 99999);
        [$invoice, $product, $customer, $serial] = $this->invoice(withSerial: true);
        $before = $this->snapshot($invoice, $product, $serial);
        $newDate = now()->subDay()->startOfMinute();

        app(InvoiceUpdateService::class)->updateInvoice(
            $invoice,
            $this->payload($invoice, $product, 100000, $serial, $newDate),
            [
                'user' => $this->user(['invoices.edit', 'invoices.change_transaction_date']),
                'transaction_date_change_reason' => 'Sửa đúng thời gian giao dịch',
            ]
        );

        $this->assertSame($before['product_stock_quantity'], (float) $product->fresh()->stock_quantity);
        $this->assertSame($before['product_inventory_total_cost'], (float) $product->fresh()->inventory_total_cost);
        $this->assertSame($before['serial'], $this->serialSnapshot($serial));
        $this->assertSame($before['customer_debt_amount'], (float) $customer->fresh()->debt_amount);
        $this->assertSame($before['customer_total_spent'], (float) $customer->fresh()->total_spent);
    }

    public function test_einvoice_block_is_not_bypassed_by_either_reason(): void
    {
        Setting::set('block_edit_cancel_einvoice', true);
        [$invoice, $product] = $this->invoice();
        // Some fresh schemas intentionally do not yet persist einvoice_code.
        // The update service policy only needs the model state, so keep this
        // contract test schema-neutral without adding a migration to this P0.
        $invoice->setAttribute('einvoice_code', 'EINV-'.uniqid());
        $before = $this->snapshot($invoice, $product);

        $this->expectExceptionMessage('Không thể sửa hóa đơn đã xuất hóa đơn điện tử.');
        try {
            app(InvoiceUpdateService::class)->updateInvoice(
                $invoice,
                $this->payload($invoice, $product, 125000, null, now()->subDay()),
                [
                    'user' => $this->user(['invoices.edit', 'invoices.override_time_lock', 'invoices.change_transaction_date']),
                    'time_lock_override_reason' => 'Lý do quá hạn riêng biệt',
                    'transaction_date_change_reason' => 'Lý do đổi ngày riêng biệt',
                ]
            );
        } finally {
            $this->assertSame($before, $this->snapshot($invoice, $product));
        }
    }

    public function test_pos_component_collects_separate_inline_reasons_without_browser_prompts(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Orders/Create.vue'));

        $this->assertStringContainsString('data-testid="invoice-edit-confirmation-modal"', $source);
        $this->assertStringContainsString('time_lock_override_reason', $source);
        $this->assertStringContainsString('transaction_date_change_reason', $source);
        $this->assertStringContainsString('payload.transaction_date = activeTab.value.orderDate', $source);
        $this->assertStringContainsString('role="alert"', $source);
        $this->assertStringNotContainsString('window.prompt', $source);
        $this->assertStringNotContainsString('window.alert', $source);
    }

    private function user(array $permissions): User
    {
        $name = 'pos-edit-reason-'.uniqid();
        $role = Role::create([
            'name' => $name,
            'display_name' => $name,
            'permissions' => $permissions,
        ]);

        return User::create([
            'name' => 'POS Edit Reason Tester',
            'email' => $name.'@test.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);
    }

    private function invoice(bool $withSerial = false): array
    {
        $customer = Customer::create([
            'code' => 'KH-POS-'.uniqid(),
            'name' => 'Khách hàng POS',
            'phone' => '09'.random_int(10000000, 99999999),
            'debt_amount' => 0,
            'total_spent' => 100000,
            'is_customer' => true,
        ]);
        $product = Product::create([
            'sku' => 'SP-POS-'.uniqid(),
            'name' => 'Sản phẩm POS',
            'cost_price' => 60000,
            'retail_price' => 100000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'is_active' => true,
            'has_serial' => $withSerial,
        ]);
        $lockedAt = now()->subHours(48)->startOfMinute();
        $invoice = Invoice::create([
            'code' => 'HD-POS-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 100000,
            'discount' => 0,
            'total' => 100000,
            'customer_paid' => 100000,
            'status' => 'Hoàn thành',
            'payment_method' => 'Tiền mặt',
            'sales_channel' => 'POS',
            'price_book_name' => 'Bảng giá chung',
            'transaction_date' => $lockedAt,
            'lock_started_at' => $lockedAt,
            'created_at' => $lockedAt,
            'updated_at' => $lockedAt,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100000,
            'cost_price' => 60000,
            'discount' => 0,
            'subtotal' => 100000,
        ]);
        $serial = null;
        if ($withSerial) {
            $serial = SerialImei::create([
                'product_id' => $product->id,
                'serial_number' => 'IMEI-POS-'.uniqid(),
                'status' => 'sold',
                'invoice_id' => $invoice->id,
                'cost_price' => 60000,
                'sold_cost_price' => 60000,
                'sold_at' => $lockedAt,
            ]);
        }

        return [$invoice->refresh(['items']), $product, $customer, $serial, $item];
    }

    private function payload(Invoice $invoice, Product $product, float $price, ?SerialImei $serial = null, ?Carbon $transactionDate = null): array
    {
        $payload = [
            'customer_id' => $invoice->customer_id,
            'subtotal' => $price,
            'discount' => 0,
            'total' => $price,
            'customer_paid' => $price,
            'payment_method' => $invoice->payment_method,
            'items' => [[
                'invoice_item_id' => $invoice->items->firstOrFail()->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => $price,
                'discount' => 0,
                'serial_ids' => $serial ? [$serial->id] : [],
            ]],
        ];
        if ($transactionDate) {
            $payload['transaction_date'] = $transactionDate->toDateTimeString();
        }

        return $payload;
    }

    private function snapshot(Invoice $invoice, Product $product, ?SerialImei $serial = null): array
    {
        $freshInvoice = $invoice->fresh();
        $customer = $freshInvoice->customer()->firstOrFail();

        return [
            'invoice_total' => (float) $freshInvoice->total,
            'invoice_transaction_date' => optional($freshInvoice->transaction_date)->toDateTimeString(),
            'product_stock_quantity' => (float) $product->fresh()->stock_quantity,
            'product_inventory_total_cost' => (float) $product->fresh()->inventory_total_cost,
            'customer_debt_amount' => (float) $customer->debt_amount,
            'customer_total_spent' => (float) $customer->total_spent,
            'serial' => $serial ? $this->serialSnapshot($serial) : null,
        ];
    }

    private function serialSnapshot(SerialImei $serial): array
    {
        $fresh = $serial->fresh();

        return [
            'status' => $fresh->status,
            'invoice_id' => $fresh->invoice_id,
            'sold_at' => optional($fresh->sold_at)->toDateTimeString(),
            'sold_cost_price' => (float) $fresh->sold_cost_price,
        ];
    }
}
