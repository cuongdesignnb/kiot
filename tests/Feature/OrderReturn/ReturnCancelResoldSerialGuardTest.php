<?php

namespace Tests\Feature\OrderReturn;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
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
