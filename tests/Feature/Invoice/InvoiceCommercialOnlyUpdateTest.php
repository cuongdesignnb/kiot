<?php

namespace Tests\Feature\Invoice;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warranty;
use App\Services\InvoiceSaleService;
use App\Services\InvoiceUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceCommercialOnlyUpdateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_price_only_update_for_sold_out_serial_invoice_never_replays_inventory(): void
    {
        [$invoice, $product, $customer, $serials] = $this->soldOutSerialInvoice();
        $item = $invoice->items()->firstOrFail();
        $before = $this->commercialSnapshot($invoice, $product, $serials);

        app(InvoiceUpdateService::class)->updateInvoice($invoice, $this->payload($invoice, [[
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 3600000,
            'discount' => 0,
            'serial_ids' => collect($serials)->pluck('id')->all(),
        ]], [
            'subtotal' => 10800000,
            'total' => 10800000,
        ]), []);

        $invoice->refresh();
        $product->refresh();
        $customer->refresh();
        $updatedItem = $invoice->items()->firstOrFail();

        $this->assertSame(10800000.0, (float) $invoice->total);
        $this->assertSame($before['stock'], (float) $product->stock_quantity);
        $this->assertSame($before['inventory_total_cost'], (float) $product->inventory_total_cost);
        $this->assertSame($before['product_cost'], (float) $product->cost_price);
        $this->assertSame($before['item_cost'], (float) $updatedItem->cost_price);
        $this->assertSame($before['stock_movements'], StockMovement::where('ref_id', $invoice->id)->count());
        $this->assertSame($before['warranties'], Warranty::where('invoice_code', $invoice->code)->count());
        $this->assertSame(10800000.0, (float) $customer->debt_amount);

        foreach ($serials as $serial) {
            $serial->refresh();
            $this->assertSame('sold', $serial->status);
            $this->assertSame($invoice->id, $serial->invoice_id);
            $this->assertSame($before['serials'][$serial->id]['sold_at'], optional($serial->sold_at)->toDateTimeString());
            $this->assertSame($before['serials'][$serial->id]['sold_cost_price'], (float) $serial->sold_cost_price);
        }
    }

    public function test_payment_only_update_is_idempotent_and_does_not_duplicate_cashflow_or_debt(): void
    {
        [$invoice, $product, $customer, $serials] = $this->soldOutSerialInvoice(300000, 0);
        $item = $invoice->items()->firstOrFail();
        $payload = $this->payload($invoice, [[
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 100000,
            'discount' => 0,
            'serial_ids' => collect($serials)->pluck('id')->all(),
        ]], ['customer_paid' => 150000]);
        $key = 'invoice-commercial-payment-retry-001';

        app(InvoiceUpdateService::class)->updateInvoice($invoice, $payload, ['idempotency_key' => $key]);
        $cashFlowsAfterFirst = CashFlow::active()->where('reference_code', $invoice->code)->count();
        $debtAfterFirst = (float) $customer->fresh()->debt_amount;
        app(InvoiceUpdateService::class)->updateInvoice($invoice->fresh(), $payload, ['idempotency_key' => $key]);

        $this->assertSame($cashFlowsAfterFirst, CashFlow::active()->where('reference_code', $invoice->code)->count());
        $this->assertSame($debtAfterFirst, (float) $customer->fresh()->debt_amount);
        $this->assertSame(0.0, (float) $product->fresh()->stock_quantity);
    }

    public function test_customer_and_seller_only_update_keeps_inventory_and_creator_snapshot_intact(): void
    {
        [$invoice, $product, $oldCustomer, $serials] = $this->soldOutSerialInvoice();
        $newCustomer = $this->customer();
        $seller = Employee::create([
            'code' => 'NV-COM-'.uniqid(),
            'name' => 'Người bán mới',
            'is_active' => true,
        ]);
        $item = $invoice->items()->firstOrFail();
        $creatorSnapshot = $invoice->created_by_name;
        $beforeStock = (float) $product->stock_quantity;

        app(InvoiceUpdateService::class)->updateInvoice($invoice, $this->payload($invoice, [[
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 100000,
            'discount' => 0,
            'serial_ids' => collect($serials)->pluck('id')->all(),
        ]], [
            'customer_id' => $newCustomer->id,
            'seller_employee_id' => $seller->id,
        ]), []);

        $invoice->refresh();
        $this->assertSame($newCustomer->id, $invoice->customer_id);
        $this->assertSame($seller->id, $invoice->created_by);
        $this->assertSame($seller->name, $invoice->seller_name);
        $this->assertSame($creatorSnapshot, $invoice->created_by_name);
        $this->assertSame($beforeStock, (float) $product->fresh()->stock_quantity);
        $this->assertSame(0.0, (float) $oldCustomer->fresh()->debt_amount);
        $this->assertSame(300000.0, (float) $newCustomer->fresh()->debt_amount);
    }

    public function test_change_plan_distinguishes_commercial_and_inventory_identity_changes(): void
    {
        [$invoice, $product, , $serials] = $this->soldOutSerialInvoice();
        $item = $invoice->items()->firstOrFail();
        $service = app(InvoiceUpdateService::class);

        $pricePlan = $service->buildChangePlan($invoice, $this->payload($invoice, [[
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 3600000,
            'discount' => 0,
            'serial_ids' => collect($serials)->pluck('id')->all(),
        ]]));
        $quantityPlan = $service->buildChangePlan($invoice, $this->payload($invoice, [[
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 100000,
            'discount' => 0,
            'serial_ids' => collect($serials)->take(2)->pluck('id')->all(),
        ]], ['subtotal' => 200000, 'total' => 200000]));

        $this->assertTrue($pricePlan['only_commercial_changed']);
        $this->assertFalse($pricePlan['requires_inventory_replay']);
        $this->assertTrue($quantityPlan['requires_inventory_replay']);
        $this->assertTrue($quantityPlan['quantity_changed']);
        $this->assertTrue($quantityPlan['serial_changed']);
    }

    public function test_cancelled_invoice_is_rejected_without_any_mutation(): void
    {
        [$invoice, $product, , $serials] = $this->soldOutSerialInvoice();
        $item = $invoice->items()->firstOrFail();
        $invoice->update(['status' => 'Đã hủy']);
        $beforeStock = (float) $product->stock_quantity;

        try {
            app(InvoiceUpdateService::class)->updateInvoice($invoice, $this->payload($invoice, [[
                'invoice_item_id' => $item->id,
                'product_id' => $product->id,
                'quantity' => 3,
                'price' => 3600000,
                'discount' => 0,
                'serial_ids' => collect($serials)->pluck('id')->all(),
            ]]));
            $this->fail('Cancelled invoice update must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('Hóa đơn đã hủy, không thể chỉnh sửa.', $exception->errors()['invoice'][0]);
        }

        $this->assertSame($beforeStock, (float) $product->fresh()->stock_quantity);
        $this->assertSame('Đã hủy', $invoice->fresh()->status);
    }

    public function test_commercial_update_uses_stable_item_ids_for_duplicate_product_lines(): void
    {
        [$invoice, $product, $first, $second] = $this->duplicateProductInvoice();
        $beforeStock = (float) $product->stock_quantity;

        app(InvoiceUpdateService::class)->updateInvoice($invoice, $this->payload($invoice, [
            ['invoice_item_id' => $first->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 150000, 'discount' => 0, 'serial_ids' => []],
            ['invoice_item_id' => $second->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 200000, 'discount' => 0, 'serial_ids' => []],
        ], ['subtotal' => 350000, 'total' => 350000]), []);

        $this->assertSame(150000.0, (float) $first->fresh()->price);
        $this->assertSame(200000.0, (float) $second->fresh()->price);
        $this->assertSame($beforeStock, (float) $product->fresh()->stock_quantity);
    }

    public function test_foreign_or_duplicate_item_id_is_rejected_without_mutation(): void
    {
        [$invoice, $product, $first, $second] = $this->duplicateProductInvoice();
        [, , $foreignItem] = $this->duplicateProductInvoice();
        $beforeStock = (float) $product->stock_quantity;

        foreach ([
            [
                ['invoice_item_id' => $foreignItem->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 150000, 'discount' => 0, 'serial_ids' => []],
                ['invoice_item_id' => $second->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 200000, 'discount' => 0, 'serial_ids' => []],
            ],
            [
                ['invoice_item_id' => $first->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 150000, 'discount' => 0, 'serial_ids' => []],
                ['invoice_item_id' => $first->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 200000, 'discount' => 0, 'serial_ids' => []],
            ],
        ] as $items) {
            try {
                app(InvoiceUpdateService::class)->updateInvoice($invoice->fresh(['items']), $this->payload($invoice, $items), []);
                $this->fail('Invalid invoice item IDs must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame(100000.0, (float) $first->fresh()->price);
        $this->assertSame(200000.0, (float) $second->fresh()->price);
        $this->assertSame($beforeStock, (float) $product->fresh()->stock_quantity);
    }

    public function test_legacy_payload_without_item_id_requires_one_unambiguous_candidate(): void
    {
        [$singleInvoice, $singleProduct] = $this->soldOutSerialInvoice();
        $singleItem = $singleInvoice->items()->firstOrFail();
        $singlePayload = $this->payload($singleInvoice, [[
            'product_id' => $singleProduct->id,
            'quantity' => 3,
            'price' => 110000,
            'discount' => 0,
            'serial_ids' => $singleItem->serials()->pluck('serial_imei_id')->all(),
        ]], ['subtotal' => 330000, 'total' => 330000]);

        $this->assertTrue(app(InvoiceUpdateService::class)
            ->buildChangePlan($singleInvoice->fresh(['items']), $singlePayload)['only_commercial_changed']);

        [$duplicateInvoice, $duplicateProduct, $first, $second] = $this->duplicateProductInvoice();
        try {
            app(InvoiceUpdateService::class)->updateInvoice($duplicateInvoice, $this->payload($duplicateInvoice, [
                ['product_id' => $duplicateProduct->id, 'quantity' => 1, 'price' => 150000, 'discount' => 0, 'serial_ids' => []],
                ['product_id' => $duplicateProduct->id, 'quantity' => 1, 'price' => 200000, 'discount' => 0, 'serial_ids' => []],
            ], ['subtotal' => 350000, 'total' => 350000]), []);
            $this->fail('Ambiguous legacy item matching must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.invoice_item_id', $exception->errors());
        }
    }

    public function test_legacy_serials_are_hydrated_for_edit_and_keep_commercial_path(): void
    {
        [$invoice, $product, , $serials] = $this->soldOutSerialInvoice();
        $item = $invoice->items()->firstOrFail();
        InvoiceItemSerial::query()->where('invoice_item_id', $item->id)->delete();
        $user = $this->userWithPermission(['orders.create']);

        $this->actingAs($user)->get('/orders/create?action=edit&invoice_id='.$invoice->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Create')
                ->where('invoice.items.0.edit_serials.0.id', $serials[0]->id)
                ->where('invoice.items.0.edit_serials.0.status', 'sold'));

        $plan = app(InvoiceUpdateService::class)->buildChangePlan($invoice->fresh(['items']), $this->payload($invoice, [[
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 3600000,
            'discount' => 0,
            'serial_ids' => collect($serials)->pluck('id')->all(),
        ]], ['subtotal' => 10800000, 'total' => 10800000]));

        $this->assertTrue($plan['only_commercial_changed']);
        $this->assertFalse($plan['requires_inventory_replay']);

        $serialBefore = collect($serials)->mapWithKeys(fn (SerialImei $serial) => [$serial->id => [
            'status' => $serial->fresh()->status,
            'invoice_id' => $serial->fresh()->invoice_id,
            'sold_cost_price' => (float) $serial->fresh()->sold_cost_price,
        ]])->all();
        app(InvoiceUpdateService::class)->updateInvoice($invoice->fresh(['items']), $this->payload($invoice, [[
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 3600000,
            'discount' => 0,
            'serial_ids' => collect($serials)->pluck('id')->all(),
        ]], ['subtotal' => 10800000, 'total' => 10800000]), []);

        $this->assertSame(0.0, (float) $product->fresh()->stock_quantity);
        foreach ($serialBefore as $serialId => $before) {
            $fresh = SerialImei::query()->findOrFail($serialId);
            $this->assertSame($before['status'], $fresh->status);
            $this->assertSame($before['invoice_id'], $fresh->invoice_id);
            $this->assertSame($before['sold_cost_price'], (float) $fresh->sold_cost_price);
        }
    }

    public function test_ambiguous_legacy_serial_mapping_is_rejected_without_mutation(): void
    {
        [$invoice, $product, $first, $second] = $this->duplicateProductInvoice(true);
        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'IMEI-AMB-'.uniqid(),
            'status' => 'sold',
            'invoice_id' => $invoice->id,
            'cost_price' => 500000,
            'sold_cost_price' => 500000,
        ]);
        $beforeStock = (float) $product->stock_quantity;

        try {
            app(InvoiceUpdateService::class)->buildChangePlan($invoice->fresh(['items']), $this->payload($invoice, [
                ['invoice_item_id' => $first->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 100000, 'discount' => 0, 'serial_ids' => []],
                ['invoice_item_id' => $second->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 200000, 'discount' => 0, 'serial_ids' => []],
            ]));
            $this->fail('Ambiguous legacy serial mapping must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('Không xác định được Serial/IMEI của từng dòng hóa đơn.', $exception->errors()['items'][0]);
        }

        $this->assertSame('sold', $serial->fresh()->status);
        $this->assertSame($beforeStock, (float) $product->fresh()->stock_quantity);
    }

    private function soldOutSerialInvoice(float $total = 300000, float $paid = 0): array
    {
        $product = Product::create([
            'sku' => 'SP26072953949-'.uniqid(),
            'name' => 'Máy serial commercial update',
            'cost_price' => 500000,
            'retail_price' => 100000,
            'stock_quantity' => 3,
            'inventory_total_cost' => 1500000,
            'is_active' => true,
            'has_serial' => true,
        ]);
        $serials = collect(range(1, 3))->map(fn () => SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'IMEI-COM-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => 500000,
        ]))->all();
        $customer = $this->customer();
        $invoice = app(InvoiceSaleService::class)->createSale([
            'customer_id' => $customer->id,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'customer_paid' => $paid,
            'payment_method' => 'Tiền mặt',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'price' => $total / 3,
                'discount' => 0,
                'serial_ids' => collect($serials)->pluck('id')->all(),
            ]],
        ], [
            'allow_oversell' => false,
            'validate_stock_setting' => true,
            'default_status' => 'Hoàn thành',
        ]);

        return [$invoice->fresh(['items.serials']), $product->fresh(), $customer->fresh(), $serials];
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'KH-COM-'.uniqid(),
            'name' => 'Khách commercial update',
            'phone' => '09'.random_int(10000000, 99999999),
            'debt_amount' => 0,
            'total_spent' => 0,
            'is_customer' => true,
        ]);
    }

    private function duplicateProductInvoice(bool $serialProduct = false): array
    {
        $product = Product::create([
            'sku' => 'SP-COM-DUP-'.uniqid(),
            'name' => 'Sản phẩm dòng trùng',
            'cost_price' => 500000,
            'retail_price' => 200000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'is_active' => true,
            'has_serial' => $serialProduct,
        ]);
        $customer = $this->customer();
        $invoice = Invoice::create([
            'code' => 'HD-COM-DUP-'.uniqid(),
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'subtotal' => 300000,
            'discount' => 0,
            'total' => 300000,
            'customer_paid' => 0,
            'payment_method' => 'Tiền mặt',
        ]);
        $first = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100000,
            'discount' => 0,
            'subtotal' => 100000,
            'cost_price' => 500000,
        ]);
        $second = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 200000,
            'discount' => 0,
            'subtotal' => 200000,
            'cost_price' => 500000,
        ]);
        $customer->update(['debt_amount' => 300000]);

        return [$invoice->fresh(['items']), $product, $first, $second];
    }

    private function userWithPermission(array $permissions): User
    {
        $name = 'role-commercial-edit-'.uniqid();
        $role = Role::create(['name' => $name, 'display_name' => $name, 'permissions' => $permissions]);

        return User::create([
            'name' => 'Commercial edit user',
            'email' => 'commercial-edit-'.uniqid().'@test.local',
            'password' => bcrypt('pw'),
            'role_id' => $role->id,
        ]);
    }

    private function payload(Invoice $invoice, array $items, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $invoice->customer_id,
            'subtotal' => (float) $invoice->subtotal,
            'discount' => (float) $invoice->discount,
            'total' => (float) $invoice->total,
            'customer_paid' => (float) $invoice->customer_paid,
            'payment_method' => $invoice->payment_method,
            'items' => $items,
        ], $overrides);
    }

    private function commercialSnapshot(Invoice $invoice, Product $product, array $serials): array
    {
        return [
            'stock' => (float) $product->stock_quantity,
            'inventory_total_cost' => (float) $product->inventory_total_cost,
            'product_cost' => (float) $product->cost_price,
            'item_cost' => (float) $invoice->items()->firstOrFail()->cost_price,
            'stock_movements' => StockMovement::where('ref_id', $invoice->id)->count(),
            'warranties' => Warranty::where('invoice_code', $invoice->code)->count(),
            'serials' => collect($serials)->mapWithKeys(function (SerialImei $serial) {
                $serial->refresh();

                return [$serial->id => [
                    'sold_at' => optional($serial->sold_at)->toDateTimeString(),
                    'sold_cost_price' => (float) $serial->sold_cost_price,
                ]];
            })->all(),
        ];
    }
}
