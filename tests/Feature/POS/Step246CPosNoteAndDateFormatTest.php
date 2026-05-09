<?php

namespace Tests\Feature\POS;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\Order;

class Step246CPosNoteAndDateFormatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Test Admin']);

        // Product model has no factory — create via direct insert.
        $this->product = Product::create([
            'name'           => 'Test Product 246C',
            'sku'            => 'TP246C-' . time(),
            'barcode'        => null,
            'retail_price'   => 500000,
            'cost_price'     => 300000,
            'stock_quantity' => 100,
            'has_serial'     => false,
            'is_active'      => true,
            'category_id'    => null,
        ]);
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'subtotal'          => 500000,
            'discount'          => 0,
            'total'             => 500000,
            'customer_paid'     => 500000,
            'customer_id'       => null,
            'employee_id'       => null,
            'sale_time'         => '2026-05-08T10:56',
            'payment_method'    => 'cash',
            'bank_account_info' => null,
            'note'              => null,
            'items'             => [[
                'product_id' => $this->product->id,
                'quantity'   => 1,
                'price'      => 500000,
                'discount'   => 0,
                'serial_ids' => [],
            ]],
        ], $overrides);
    }

    /** @test */
    public function test_pos_checkout_saves_invoice_note()
    {
        $payload = $this->checkoutPayload([
            'note' => 'Khách yêu cầu giao buổi chiều',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/pos/checkout', $payload);

        $response->assertOk()->assertJson(['success' => true]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertStringContainsString('Khách yêu cầu giao buổi chiều', $invoice->note);
    }

    /** @test */
    public function test_pos_checkout_transfer_appends_bank_info_without_overwriting_user_note()
    {
        $payload = $this->checkoutPayload([
            'payment_method'    => 'transfer',
            'bank_account_info' => 'VCB 123456789',
            'note'              => 'Ghi chú A',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/pos/checkout', $payload);

        $response->assertOk()->assertJson(['success' => true]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        // Both user note and bank info must be present
        $this->assertStringContainsString('Ghi chú A', $invoice->note);
        $this->assertStringContainsString('Chuyển khoản: VCB 123456789', $invoice->note);
    }

    /** @test */
    public function test_pos_checkout_note_nullable()
    {
        $payload = $this->checkoutPayload([
            'note' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/pos/checkout', $payload);

        $response->assertOk()->assertJson(['success' => true]);
    }

    /** @test */
    public function test_pos_checkout_transfer_only_bank_note_when_user_note_empty()
    {
        $payload = $this->checkoutPayload([
            'payment_method'    => 'transfer',
            'bank_account_info' => 'ACB 987654321',
            'note'              => '',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/pos/checkout', $payload);

        $response->assertOk()->assertJson(['success' => true]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertStringContainsString('Chuyển khoản: ACB 987654321', $invoice->note);
        // Should not have an empty line before bank note
        $this->assertStringStartsWith('Chuyển khoản:', $invoice->note);
    }

    /** @test */
    public function test_pos_quick_order_saves_order_note()
    {
        $payload = [
            'subtotal'    => 500000,
            'discount'    => 0,
            'total'       => 500000,
            'customer_id' => null,
            'employee_id' => null,
            'sale_time'   => '2026-05-08T10:56',
            'note'        => 'Đặt trước cho khách VIP',
            'items'       => [[
                'product_id' => $this->product->id,
                'quantity'   => 1,
                'price'      => 500000,
            ]],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/pos/quick-order', $payload);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEquals('Đặt trước cho khách VIP', $order->note);
    }

    /** @test */
    public function test_pos_checkout_sale_time_canonical_parses_may_8_not_august_5()
    {
        $payload = $this->checkoutPayload([
            'sale_time' => '2026-05-08T10:56',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/pos/checkout', $payload);

        $response->assertOk()->assertJson(['success' => true]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);

        // The transaction_date (if column exists) or created_at should be May 8, not Aug 5
        $dateField = $invoice->transaction_date ?? $invoice->created_at;
        if ($dateField) {
            $date = \Carbon\Carbon::parse($dateField);
            $this->assertEquals(5, $date->month, 'Month should be May (5), not August');
            $this->assertEquals(8, $date->day, 'Day should be 8');
        }
    }

    /** @test */
    public function test_app_timezone_is_vietnam()
    {
        $this->assertEquals('Asia/Ho_Chi_Minh', config('app.timezone'));
    }
}
