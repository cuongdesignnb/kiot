<?php

namespace Tests\Feature\Costing;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Services\InvoiceSaleService;
use App\Services\SerialBusinessTimeGuard;
use App\Services\SerialLifecycleInspectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SerialLifecycleInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_testing_database_includes_the_return_recorded_time_schema(): void
    {
        $this->assertTrue(
            Schema::hasColumn('returns', 'recorded_at'),
            sprintf(
                'expected returns.recorded_at; database=%s; base=%s',
                config('database.connections.'.config('database.default').'.database'),
                base_path(),
            ),
        );
    }

    public function test_actual_return_then_backdated_resale_is_not_called_a_duplicate_sale(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product);
        [, $firstItem] = $this->sale($product, $serial, '2026-07-01 09:00:00', '2026-07-01 09:00:05');
        $this->returnSerial($product, $serial, $firstItem, '2026-07-08 15:14:05', '2026-07-08 15:14:05');
        $this->sale($product, $serial, '2026-07-08 15:12:00', '2026-07-08 15:15:37');

        $inspection = app(SerialLifecycleInspectionService::class)->inspectProduct($product->id)->sole();

        $this->assertSame(SerialLifecycleInspectionService::BACKDATED_RESALE, $inspection['classification']);
        $this->assertFalse($inspection['rebuild_safe']);
        $this->assertStringContainsString('trả rồi bán lại hợp lệ theo lúc ghi nhận', $inspection['message']);

        $exitCode = Artisan::call('serials:audit-invoice-links', ['--product' => $product->sku]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('Backdated resale: 1', $output);
        $this->assertStringContainsString('Unresolved multiple completed sales: 0', $output);
    }

    public function test_ordered_resale_is_not_a_duplicate_but_is_excluded_from_automatic_rebuild(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product);
        [, $firstItem] = $this->sale($product, $serial, '2026-07-01 09:00:00', '2026-07-01 09:00:05');
        $this->returnSerial($product, $serial, $firstItem, '2026-07-08 15:14:05', '2026-07-08 15:14:05');
        $this->sale($product, $serial, '2026-07-08 15:15:00', '2026-07-08 15:15:37');

        $inspection = app(SerialLifecycleInspectionService::class)->inspectProduct($product->id)->sole();

        $this->assertSame(SerialLifecycleInspectionService::ORDERED_RESALE_HISTORY, $inspection['classification']);
        $this->assertFalse($inspection['rebuild_safe']);
        $this->assertStringContainsString('Đây không phải bán trùng', $inspection['message']);
    }

    public function test_missing_explicit_return_evidence_is_classified_for_manual_review(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product);
        $this->sale($product, $serial, '2026-07-01 09:00:00', '2026-07-01 09:00:05');
        $this->sale($product, $serial, '2026-07-08 15:15:00', '2026-07-08 15:15:37');

        $inspection = app(SerialLifecycleInspectionService::class)->inspectProduct($product->id)->sole();

        $this->assertSame(SerialLifecycleInspectionService::UNRESOLVED_MULTIPLE_COMPLETED_SALES, $inspection['classification']);
        $this->assertStringContainsString('không có bằng chứng trả đúng Serial', $inspection['message']);
    }

    public function test_business_time_guard_rejects_a_sale_at_or_before_the_last_explicit_return(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, ['status' => 'in_stock']);
        [$firstInvoice, $firstItem] = $this->sale($product, $serial, '2026-08-21 09:00:00', '2026-08-21 09:00:05');
        $this->returnSerial($product, $serial, $firstItem, '2026-08-21 10:54:19', '2026-08-21 10:54:19');

        $guard = app(SerialBusinessTimeGuard::class);

        try {
            $guard->assertNewSaleCanUseBusinessTime([$serial], Carbon::parse('2026-08-21 10:54:19'));
            $this->fail('A sale at the same business time as the return must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $guard->assertNewSaleCanUseBusinessTime([$serial], Carbon::parse('2026-08-21 10:54:20'));

        $this->expectException(ValidationException::class);
        $guard->assertInvoiceDateMayChange($firstInvoice);
    }

    public function test_sale_service_blocks_a_backdated_resale_without_partial_mutation(): void
    {
        $product = $this->serialProduct([
            'stock_quantity' => 1,
            'inventory_total_cost' => 4_400_000,
            'cost_price' => 4_400_000,
        ]);
        $serial = $this->serial($product, ['status' => 'sold']);
        [, $firstItem] = $this->sale($product, $serial, '2026-08-21 09:00:00', '2026-08-21 09:00:05');
        $this->returnSerial($product, $serial, $firstItem, '2026-08-21 10:54:19', '2026-08-21 10:54:19');
        $serial->update(['status' => 'in_stock', 'invoice_id' => null, 'sold_at' => null]);

        $customer = Customer::create([
            'code' => 'KH-LIFE-'.uniqid(),
            'name' => 'Khách lifecycle serial',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true,
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
        $invoiceCountBefore = Invoice::query()->count();

        try {
            app(InvoiceSaleService::class)->createSale([
                'customer_id' => $customer->id,
                'subtotal' => 8_000_000,
                'discount' => 0,
                'total' => 8_000_000,
                'customer_paid' => 8_000_000,
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 8_000_000,
                    'discount' => 0,
                    'serial_ids' => [$serial->id],
                ]],
            ], [
                'allow_oversell' => false,
                'default_status' => 'Hoàn thành',
                'code_prefix' => 'HD-LIFE-BLOCK-',
                'transaction_date' => '2026-08-21 10:54:19',
            ]);
            $this->fail('A sale at the same business time as the return must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame($invoiceCountBefore, Invoice::query()->count());
        $this->assertSame('in_stock', $serial->fresh()->status);
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_all_apply_preflight_keeps_every_product_unchanged_when_any_product_has_hard_error(): void
    {
        $safeProduct = $this->serialProduct([
            'stock_quantity' => 1,
            'inventory_total_cost' => 9_999_999,
            'cost_price' => 9_999_999,
        ]);
        $this->serial($safeProduct, ['status' => 'in_stock', 'cost_price' => 4_400_000]);

        $unsafeProduct = $this->serialProduct();
        $unsafeSerial = $this->serial($unsafeProduct, ['status' => 'sold']);
        $this->sale($unsafeProduct, $unsafeSerial, '2026-07-01 09:00:00', '2026-07-01 09:00:05');
        $this->sale($unsafeProduct, $unsafeSerial, '2026-07-08 15:15:00', '2026-07-08 15:15:37');

        $exitCode = Artisan::call('costing:rebuild-moving-avg', ['--all' => true, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('APPLY BLOCKED', $output);

        $safeProduct->refresh();
        $this->assertSame(9_999_999.0, (float) $safeProduct->cost_price);
        $this->assertSame(9_999_999.0, (float) $safeProduct->inventory_total_cost);
    }

    private function serialProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'SER-LIFE-'.uniqid(),
            'name' => 'Serial lifecycle product',
            'stock_quantity' => 0,
            'cost_price' => 0,
            'inventory_total_cost' => 0,
            'retail_price' => 8_000_000,
            'has_serial' => true,
        ], $overrides));
    }

    private function serial(Product $product, array $overrides = []): SerialImei
    {
        return SerialImei::create(array_merge([
            'product_id' => $product->id,
            'serial_number' => 'LIFE-'.uniqid(),
            'status' => 'sold',
            'cost_price' => 4_400_000,
            'original_cost' => 4_400_000,
        ], $overrides));
    }

    /**
     * @return array{0: Invoice, 1: InvoiceItem}
     */
    private function sale(Product $product, SerialImei $serial, string $businessTime, string $recordedTime): array
    {
        $businessAt = Carbon::parse($businessTime);
        $recordedAt = Carbon::parse($recordedTime);
        $invoice = Invoice::create([
            'code' => 'HD-LIFE-'.uniqid(),
            'status' => 'Hoàn thành',
            'subtotal' => 8_000_000,
            'total' => 8_000_000,
            'customer_paid' => 8_000_000,
            'transaction_date' => $businessAt,
            'lock_started_at' => $recordedAt,
            'created_at' => $businessAt,
            'updated_at' => $recordedAt,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 8_000_000,
            'cost_price' => 4_400_000,
            'subtotal' => 8_000_000,
        ]);
        InvoiceItemSerial::create([
            'invoice_item_id' => $item->id,
            'serial_imei_id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'cost_price' => 4_400_000,
        ]);

        return [$invoice, $item];
    }

    private function returnSerial(Product $product, SerialImei $serial, InvoiceItem $originalItem, string $businessTime, string $recordedTime): OrderReturn
    {
        $businessAt = Carbon::parse($businessTime);
        $recordedAt = Carbon::parse($recordedTime);
        $return = OrderReturn::create([
            'code' => 'TH-LIFE-'.uniqid(),
            'invoice_id' => $originalItem->invoice_id,
            'status' => 'Đã trả',
            'subtotal' => 8_000_000,
            'total' => 8_000_000,
            'recorded_at' => $recordedAt,
            'created_at' => $businessAt,
            'updated_at' => $recordedAt,
        ]);
        ReturnItem::create([
            'return_id' => $return->id,
            'product_id' => $product->id,
            'invoice_item_id' => $originalItem->id,
            'quantity' => 1,
            'price' => 8_000_000,
            'cost_price' => 4_400_000,
            'serial_ids' => [$serial->id],
        ]);

        return $return;
    }
}
