<?php

namespace Tests\Feature\PcIntegration;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Warranty;
use App\Services\WarrantyGenerationService;
use Illuminate\Support\Str;

class PcWarrantyLookupTest extends PcIntegrationTestCase
{
    private const PATH = '/api/integrations/v1/pc/warranties';

    private const PACKAGES = [
        ['name' => 'Bảo hành 3 tháng', 'duration_value' => 3, 'duration_unit' => 'month', 'is_default' => true],
        ['name' => 'Bảo hành 12 tháng', 'duration_value' => 12, 'duration_unit' => 'month', 'is_default' => false],
    ];

    private function customer(string $phone): Customer
    {
        return Customer::create([
            'code' => 'KH-BH-'.Str::upper(Str::random(8)), 'name' => 'Nguyễn Văn Bảo Hành', 'phone' => $phone,
            'is_customer' => true, 'is_supplier' => false, 'debt_amount' => 0, 'supplier_debt_amount' => 0, 'status' => 'active',
        ]);
    }

    private function sale(Customer $customer, Product $product, string $serial, string $status = 'completed', int $months = 12): Invoice
    {
        $invoice = Invoice::create([
            'code' => 'HD-BH-'.Str::upper(Str::random(8)), 'customer_id' => $customer->id,
            'subtotal' => 900_000, 'total' => 900_000, 'customer_paid' => 900_000, 'status' => $status,
        ]);
        Warranty::create([
            'invoice_code' => $invoice->code, 'product_id' => $product->id, 'customer_name' => $customer->name,
            'serial_imei' => $serial, 'warranty_period' => $months,
            'purchase_date' => now()->subMonths(2), 'warranty_end_date' => now()->subMonths(2)->addMonths($months),
        ]);

        return $invoice;
    }

    private function lookup(array $query)
    {
        return $this->getJson(self::PATH.'?'.http_build_query($query), $this->signedHeaders('GET', self::PATH));
    }

    public function test_lookup_by_serial_or_by_phone_with_last_four_shows_only_safe_fields(): void
    {
        $product = $this->makeProduct(['name' => 'Dell Latitude 7420']);
        $customer = $this->customer('0987654992');
        $this->sale($customer, $product, 'SVTAG-9XK2');
        $this->sale($customer, $product, 'SVTAG-OLD1', 'Đã hủy');

        $row = $this->lookup(['serial' => 'svtag-9xk2'])->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('Dell Latitude 7420', $row['product_name']);
        $this->assertSame('SVTAG-9XK2', $row['serial']);
        $this->assertSame(12, $row['warranty_months']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('098****992', $row['customer_phone']);
        $this->assertGreaterThan(0, $row['days_left']);
        $this->assertArrayNotHasKey('customer_name', $row);
        $this->assertArrayNotHasKey('invoice_code', $row);

        // Phone needs the last 4 of the serial; the serial comes back masked.
        $this->lookup(['phone' => '+84 987 654 992', 'serial_last4' => '9XK2'])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.serial', '••••••9XK2');
        $this->lookup(['phone' => '0987654992', 'serial_last4' => 'AAAA'])->assertOk()->assertJsonCount(0, 'data');
        $this->lookup(['phone' => '0987654992'])->assertUnprocessable();

        // A cancelled invoice is not a sale.
        $this->lookup(['serial' => 'SVTAG-OLD1'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unsigned_requests_are_rejected(): void
    {
        $this->getJson(self::PATH.'?serial=ABCDEF')->assertUnauthorized();
    }

    public function test_chosen_package_sets_the_warranty_length(): void
    {
        $product = $this->makeProduct(['warranty_policies' => self::PACKAGES]);
        $customer = $this->customer('0912345678');
        $invoice = Invoice::create([
            'code' => 'HD-PKG-'.Str::upper(Str::random(8)), 'customer_id' => $customer->id,
            'subtotal' => 800_000, 'total' => 800_000, 'customer_paid' => 800_000, 'status' => 'completed',
        ]);
        $invoice->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 800_000, 'warranty_policy' => 'Bảo hành 12 tháng']);

        app(WarrantyGenerationService::class)->generateForInvoice($invoice->fresh());

        $this->assertSame(12, (int) Warranty::where('invoice_code', $invoice->code)->value('warranty_period'));
    }

    public function test_website_order_keeps_the_package_matching_its_months(): void
    {
        $product = $this->makeProduct(['warranty_policies' => self::PACKAGES]);
        $payload = $this->orderPayload($product);
        $payload['items'][0]['warranty_months'] = 12;

        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();

        $this->assertSame('Bảo hành 12 tháng', OrderItem::where('product_id', $product->id)->latest('id')->value('warranty_policy'));
    }
}
