<?php

namespace Tests\Feature\OrderReturn;

use App\Enums\ReturnStatus;
use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\PartnerDebtOperation;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReturnCancelResoldSerialGuardTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Return cancel guard admin',
            'email' => 'return-cancel-guard-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    public function test_cancel_blocks_a_returned_serial_that_was_resold_without_any_partial_mutation(): void
    {
        [$return, $product, $customer, $originalInvoice, $serials] = $this->scenario(1, false);
        $resale = Invoice::create([
            'code' => 'HD-RESOLD-'.uniqid(),
            'subtotal' => 100000,
            'total' => 100000,
            'status' => 'Hoàn thành',
        ]);
        $serials[0]->update(['status' => 'sold', 'invoice_id' => $resale->id, 'sold_at' => now()]);

        $before = $this->snapshot($return, $product, $customer, $serials);
        $response = $this->actingAs($this->admin)->postJson(route('returns.cancel', $return));

        $response->assertStatus(422)->assertJsonValidationErrors('serial_ids');
        $this->assertSame(
            "Không thể hủy phiếu trả vì Serial {$serials[0]->serial_number} đã được bán lại trên hóa đơn {$resale->code}.\nHãy dùng chức năng “Điều chỉnh người chịu doanh số trả hàng”.\nHệ thống chưa thay đổi tồn kho, công nợ hoặc Serial.",
            $response->json('errors.serial_ids.0'),
        );
        $this->assertSame($before, $this->snapshot($return, $product, $customer, $serials));
        $this->assertSame($resale->id, $serials[0]->fresh()->invoice_id);
        $this->assertNotSame($originalInvoice->id, $serials[0]->fresh()->invoice_id);
    }

    public function test_one_resold_serial_blocks_the_whole_return_with_multiple_serials(): void
    {
        [$return, $product, $customer, , $serials] = $this->scenario(2);
        $resale = Invoice::create([
            'code' => 'HD-RESOLD-MULTI-'.uniqid(),
            'subtotal' => 200000,
            'total' => 200000,
            'status' => 'Hoàn thành',
        ]);
        $serials[1]->update(['status' => 'sold', 'invoice_id' => $resale->id, 'sold_at' => now()]);

        $before = $this->snapshot($return, $product, $customer, $serials);
        $this->actingAs($this->admin)->postJson(route('returns.cancel', $return))
            ->assertStatus(422)
            ->assertJsonValidationErrors('serial_ids');

        $this->assertSame($before, $this->snapshot($return, $product, $customer, $serials));
    }

    public function test_cancel_still_succeeds_for_the_exact_returned_serial_in_stock(): void
    {
        [$return, $product, $customer, $originalInvoice, $serials] = $this->scenario(1, false);

        $this->actingAs($this->admin)->postJson(route('returns.cancel', $return))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Đã hủy', $return->fresh()->status);
        $this->assertSame('sold', $serials[0]->fresh()->status);
        $this->assertSame($originalInvoice->id, $serials[0]->fresh()->invoice_id);
        $this->assertLessThan(1, (float) $product->fresh()->stock_quantity);
        $this->assertSame(0, CashFlow::where('reference_type', 'OrderReturn')->where('reference_code', $return->code)->count());
        $this->assertTrue(ActivityLog::where('action', ActivityLog::ACTION_RETURN_CANCEL)->where('subject_id', $return->id)->exists());
        $this->assertGreaterThan(0, StockMovement::where('ref_id', $return->id)->count());
    }

    public function test_blocked_cancel_can_retry_with_the_same_idempotency_key_after_serial_is_safe_again(): void
    {
        [$return, $product, $customer, $originalInvoice, $serials] = $this->persistedCustomerReturnScenario();
        $resale = Invoice::create([
            'code' => 'HD-RESOLD-RETRY-'.uniqid(),
            'subtotal' => 100000,
            'total' => 100000,
        ]);
        $serials[0]->update(['status' => 'sold', 'invoice_id' => $resale->id, 'sold_at' => now()]);
        $idempotencyKey = 'return-cancel-safe-retry-'.uniqid();

        $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson(route('returns.cancel', $return))
            ->assertStatus(422)
            ->assertJsonValidationErrors('serial_ids');
        $this->assertSame(0, PartnerDebtOperation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->count());

        $serials[0]->update(['status' => 'in_stock', 'invoice_id' => null, 'sold_at' => null]);

        $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson(route('returns.cancel', $return))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(ReturnStatus::CANCELLED, $return->fresh()->status);
        $this->assertSame('sold', $serials[0]->fresh()->status);
        $this->assertSame($originalInvoice->id, $serials[0]->fresh()->invoice_id);
        $this->assertSame(1, PartnerDebtOperation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'committed')
            ->count());
        $this->assertSame(100000.0, (float) $customer->fresh()->debt_amount);
        $this->assertLessThan(1, (float) $product->fresh()->stock_quantity);
    }

    private function scenario(int $serialCount = 1, bool $withCustomer = true): array
    {
        $category = Category::firstOrCreate(['name' => 'Return cancel guard category']);
        $product = Product::create([
            'sku' => 'RETURN-CANCEL-GUARD-'.uniqid(),
            'name' => 'Return cancel guard serial product',
            'cost_price' => 40000,
            'retail_price' => 100000,
            'stock_quantity' => $serialCount,
            'inventory_total_cost' => $serialCount * 40000,
            'is_active' => true,
            'has_serial' => true,
            'category_id' => $category->id,
        ]);
        $customer = Customer::create([
            'code' => 'KH-CANCEL-GUARD-'.uniqid(),
            'name' => 'Customer cancel guard',
            'phone' => '091'.random_int(1000000, 9999999),
            'debt_amount' => -100000 * $serialCount,
            'total_spent' => 0,
        ]);
        $seller = Employee::create([
            'code' => 'NV-CANCEL-GUARD-'.uniqid(),
            'name' => 'Seller cancel guard',
            'is_active' => true,
        ]);
        $invoice = Invoice::create([
            'code' => 'HD-CANCEL-GUARD-'.uniqid(),
            'customer_id' => $withCustomer ? $customer->id : null,
            'created_by' => $seller->id,
            'seller_name' => $seller->name,
            'subtotal' => 100000 * $serialCount,
            'total' => 100000 * $serialCount,
            'status' => 'Hoàn thành',
        ]);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $serialCount,
            'price' => 100000,
            'cost_price' => 40000,
            'subtotal' => 100000 * $serialCount,
        ]);
        $serials = collect(range(1, $serialCount))->map(fn () => SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-CANCEL-GUARD-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => 40000,
            'original_cost' => 40000,
        ]));
        $return = OrderReturn::create([
            'code' => 'TH-CANCEL-GUARD-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $withCustomer ? $customer->id : null,
            'status' => 'Đã trả',
            'subtotal' => 100000 * $serialCount,
            'total' => 100000 * $serialCount,
            'paid_to_customer' => 0,
        ]);
        ReturnItem::create([
            'return_id' => $return->id,
            'invoice_item_id' => $invoiceItem->id,
            'product_id' => $product->id,
            'quantity' => $serialCount,
            'price' => 100000,
            'cost_price' => 40000,
            'import_price' => 40000,
            'serial_ids' => $serials->pluck('id')->all(),
        ]);

        return [$return, $product, $customer, $invoice, $serials->all()];
    }

    private function persistedCustomerReturnScenario(): array
    {
        $category = Category::firstOrCreate(['name' => 'Return cancel retry category']);
        $product = Product::create([
            'sku' => 'RETURN-CANCEL-RETRY-'.uniqid(),
            'name' => 'Return cancel retry serial product',
            'cost_price' => 40000,
            'retail_price' => 100000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'is_active' => true,
            'has_serial' => true,
            'category_id' => $category->id,
        ]);
        $customer = Customer::create([
            'code' => 'KH-CANCEL-RETRY-'.uniqid(),
            'name' => 'Customer cancel retry',
            'phone' => '092'.random_int(1000000, 9999999),
            'is_customer' => true,
            'debt_amount' => 100000,
            'total_spent' => 0,
        ]);
        $seller = Employee::create([
            'code' => 'NV-CANCEL-RETRY-'.uniqid(),
            'name' => 'Seller cancel retry',
            'is_active' => true,
        ]);
        $invoice = Invoice::create([
            'code' => 'HD-CANCEL-RETRY-'.uniqid(),
            'customer_id' => $customer->id,
            'created_by' => $seller->id,
            'seller_name' => $seller->name,
            'subtotal' => 100000,
            'total' => 100000,
        ]);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100000,
            'cost_price' => 40000,
            'subtotal' => 100000,
        ]);
        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-CANCEL-RETRY-'.uniqid(),
            'status' => 'sold',
            'invoice_id' => $invoice->id,
            'sold_at' => now(),
            'cost_price' => 40000,
            'original_cost' => 40000,
        ]);
        $receiver = Employee::create([
            'code' => 'NV-CANCEL-RETRY-RECEIVER-'.uniqid(),
            'name' => 'Receiver cancel retry',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id' => $invoice->id,
            'received_by_employee_id' => $receiver->id,
            'customer_id' => $customer->id,
            'subtotal' => 100000,
            'discount' => 0,
            'fee_type' => 'amount',
            'fee_value' => 0,
            'total' => 100000,
            'paid_to_customer' => 0,
            'items' => [[
                'product_id' => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty' => 1,
                'price' => 100000,
                'discount' => 0,
                'serial_ids' => [$serial->id],
            ]],
        ])->assertRedirect();

        $return = OrderReturn::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail();

        return [$return, $product, $customer, $invoice, [$serial]];
    }

    private function snapshot(OrderReturn $return, Product $product, Customer $customer, array $serials): array
    {
        return [
            'return' => $return->fresh()->only(['status', 'invoice_id', 'total', 'customer_id']),
            'product' => $product->fresh()->only(['stock_quantity', 'inventory_total_cost', 'cost_price']),
            'customer' => $customer->fresh()->only(['debt_amount', 'total_spent']),
            'serials' => collect($serials)->map(function (SerialImei $serial): array {
                $snapshot = $serial->fresh()->only(['status', 'invoice_id', 'sold_at', 'sold_cost_price']);
                $snapshot['sold_at'] = $serial->fresh()->sold_at?->toISOString();

                return $snapshot;
            })->all(),
            'stock_movement_count' => StockMovement::where('ref_type', OrderReturn::class)
                ->where('ref_id', $return->id)->count(),
            'cash_flow_count' => CashFlow::where('reference_type', 'OrderReturn')
                ->where('reference_code', $return->code)->count(),
            'cancel_log_count' => ActivityLog::where('action', ActivityLog::ACTION_RETURN_CANCEL)
                ->where('subject_id', $return->id)->count(),
        ];
    }
}
