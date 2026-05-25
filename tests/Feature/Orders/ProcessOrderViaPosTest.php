<?php

namespace Tests\Feature\Orders;

use App\Http\Controllers\OrderController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItemSerial;
use App\Models\Order;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\CashFlow;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProcessOrderViaPosTest extends TestCase
{
    use DatabaseTransactions;

    private User     $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin POS',
            'email'    => 'admin-pos-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->customer = Customer::create([
            'code'        => 'KH-POS-' . uniqid(),
            'name'        => 'KH POS ' . uniqid(),
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-pos-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat POS']);
        return Product::create([
            'sku'                  => 'PROD-POS-' . uniqid(),
            'name'                 => 'Product POS',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $category->id,
        ]);
    }

    private function makeOrder(Product $product, int $qty, float $price): Order
    {
        $order = Order::create([
            'code'             => 'DH-POS-' . uniqid(),
            'customer_id'      => $this->customer->id,
            'status'           => 'draft',
            'total_price'      => $qty * $price,
            'discount'         => 0,
            'other_fees'       => 0,
            'total_payment'    => $qty * $price,
            'amount_paid'      => 0,
            'created_by_name'  => 'Admin',
            'assigned_to_name' => 'Admin',
            'price_book_name'  => 'Bảng giá chung',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'qty'        => $qty,
            'price'      => $price,
            'discount'   => 0,
            'subtotal'   => $qty * $price,
        ]);
        return $order;
    }

    public function test_pos_payload_returns_correct_data_and_does_not_modify_db(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $order = $this->makeOrder($product, 3, 200000);

        $this->actingAs($this->admin);
        $response = $this->getJson(route('orders.pos-payload', $order));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('order.code', $order->code);
        $response->assertJsonPath('order.items.0.product_id', $product->id);
        $response->assertJsonPath('order.items.0.qty', 3);

        $order->refresh();
        $this->assertSame('draft', $order->status);
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
    }

    public function test_pos_payload_rejects_completed_or_invalid_orders(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $order = $this->makeOrder($product, 3, 200000);

        $this->actingAs($this->admin);

        $order->update(['status' => 'completed']);
        $response = $this->getJson(route('orders.pos-payload', $order));
        $response->assertStatus(422);

        $order->update(['status' => 'cancelled']);
        $response = $this->getJson(route('orders.pos-payload', $order));
        $response->assertStatus(422);
    }

    public function test_pos_processing_creates_invoice_completes_order_correctly(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $order = $this->makeOrder($product, 2, 200000); 

        $this->actingAs($this->admin);

        $payload = [
            'from_pos' => true,
            'amount_paid' => 400000,
            'payment_method' => 'cash',
            'delivery' => [
                'is_delivery' => true,
                'delivery_mode' => 'partner',
                'delivery_partner' => 'GHN',
                'receiver_name' => 'John Doe',
                'receiver_phone' => '0987654321',
                'receiver_address' => '123 Test St',
                'delivery_fee' => 30000,
                'cod_amount' => 0,
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]
            ]
        ];

        $response = $this->postJson(route('orders.process', $order), $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame(400000.0, (float) $order->amount_paid);
        $this->assertTrue((bool)$order->is_delivery);
        $this->assertSame('GHN', $order->delivery_partner);
        $this->assertSame('John Doe', $order->receiver_name);

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('Hoàn thành', $invoice->status);
        $this->assertSame(400000.0, (float) $invoice->customer_paid);
        $this->assertTrue((bool)$invoice->is_delivery);
        $this->assertSame('GHN', $invoice->delivery_partner);

        $product->refresh();
        $this->assertSame(8, (int) $product->stock_quantity);
    }

    public function test_pos_processing_rejects_quantity_mismatch_in_phase_1(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $order = $this->makeOrder($product, 2, 200000);

        $this->actingAs($this->admin);

        $payload = [
            'from_pos' => true,
            'amount_paid' => 400000,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3, 
                ]
            ]
        ];

        $response = $this->postJson(route('orders.process', $order), $payload);
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        $payload['items'][0]['product_id'] = $product->id + 999;
        $response = $this->postJson(route('orders.process', $order), $payload);
        $response->assertStatus(422);
    }

    public function test_pos_processing_serial_override(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $serialA = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-A-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        $serialB = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-B-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        $order = $this->makeOrder($product, 1, 8000000);
        $order->items()->first()->update(['serial_ids' => [$serialA->id]]);

        $this->actingAs($this->admin);

        $payload = [
            'from_pos' => true,
            'amount_paid' => 8000000,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'serial_ids' => [$serialB->id] 
                ]
            ]
        ];

        $response = $this->postJson(route('orders.process', $order), $payload);
        $response->assertStatus(200);

        $serialA->refresh();
        $serialB->refresh();

        $this->assertSame('in_stock', $serialA->status);
        $this->assertSame('sold', $serialB->status);
    }

    public function test_pos_processing_deposits_cash_flow_limit(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $order = $this->makeOrder($product, 2, 200000); 
        $order->update(['amount_paid' => 150000]); 

        $this->actingAs($this->admin);

        $payload = [
            'from_pos' => true,
            'amount_paid' => 250000, 
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ]
            ]
        ];

        $response = $this->postJson(route('orders.process', $order), $payload);
        $response->assertStatus(200);

        $order->refresh();
        $this->assertSame(400000.0, (float) $order->amount_paid);

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertSame(400000.0, (float) $invoice->customer_paid);

        $newCashFlows = CashFlow::where('reference_code', $invoice->code)->get();
        $this->assertCount(1, $newCashFlows);
        $this->assertSame(250000.0, (float) $newCashFlows->first()->amount);
    }
}
