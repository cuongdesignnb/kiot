<?php

namespace Tests\Feature\Exports;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\Debt\PartnerDebtRoleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PartnerDebtExcelRealPipelineContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_real_dual_role_purchase_discount_uses_net_effect_running_balance_and_summary(): void
    {
        $actor = $this->actor();
        $partner = $this->dualRolePartner();
        $product = $this->product();
        $purchase = $this->purchase($partner, $product, [
            'code' => 'PN-REAL-DISCOUNT',
            'total_amount' => 1000,
            'discount' => 100,
            'paid_amount' => 0,
            'debt_amount' => 900,
            'status' => 'completed',
        ]);

        $supplierRows = $this->rows(
            "/api/suppliers/{$partner->id}/export-debt?format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=line_total",
            $actor,
        );
        $customerRows = $this->rows(
            "/customers/{$partner->id}/export-debt?format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=line_total",
            $actor,
        );

        $supplierParent = $this->rowByCode($supplierRows, $purchase->code);
        $customerParent = $this->rowByCode($customerRows, $purchase->code);

        self::assertSame(900.0, (float) ($supplierParent['K'] ?? 0));
        self::assertSame(900.0, (float) ($supplierParent['M'] ?? 0));
        self::assertSame(900.0, (float) ($customerParent['L'] ?? 0));
        self::assertSame(-900.0, (float) ($customerParent['M'] ?? 0));
        self::assertSame(900.0, $this->closing($supplierRows));
        self::assertSame(-900.0, $this->closing($customerRows));
        self::assertSame(1, $this->countCode($supplierRows, $product->sku));
        self::assertSame(1, $this->countCode($customerRows, $product->sku));
    }

    public function test_real_pipeline_purchase_cancellation_reverses_net_discount_once_and_keeps_details(): void
    {
        $actor = $this->actor();
        $partner = $this->dualRolePartner();
        $product = $this->product();
        $purchase = $this->purchase($partner, $product, [
            'code' => 'PN-REAL-CANCEL',
            'total_amount' => 1000,
            'discount' => 100,
            'paid_amount' => 0,
            'debt_amount' => 900,
            'status' => 'cancelled',
            'cancelled_at' => Carbon::parse('2026-08-12 10:02:00'),
        ]);
        $purchase->created_at = Carbon::parse('2026-08-12 10:01:00');
        $purchase->updated_at = Carbon::parse('2026-08-12 10:02:00');
        $purchase->save();

        $supplierRows = $this->rows(
            "/api/suppliers/{$partner->id}/export-debt?format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=line_total",
            $actor,
        );
        $customerRows = $this->rows(
            "/customers/{$partner->id}/export-debt?format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=line_total",
            $actor,
        );

        $supplierPurchase = $this->rowByCode($supplierRows, $purchase->code);
        $supplierCancel = $this->rowByCode($supplierRows, 'HUY-'.$purchase->code);
        $customerPurchase = $this->rowByCode($customerRows, $purchase->code);
        $customerCancel = $this->rowByCode($customerRows, 'HUY-'.$purchase->code);

        self::assertSame(900.0, (float) ($supplierPurchase['M'] ?? 0));
        self::assertSame(900.0, (float) ($supplierCancel['L'] ?? 0));
        self::assertSame(0.0, (float) ($supplierCancel['M'] ?? 0));
        self::assertSame(-900.0, (float) ($customerPurchase['M'] ?? 0));
        self::assertSame(900.0, (float) ($customerCancel['K'] ?? 0));
        self::assertSame(0.0, (float) ($customerCancel['M'] ?? 0));
        self::assertSame(0.0, $this->closing($supplierRows));
        self::assertSame(0.0, $this->closing($customerRows));
        self::assertSame(2, $this->countCode($supplierRows, $product->sku));
        self::assertSame(2, $this->countCode($customerRows, $product->sku));
    }

    public function test_real_supplier_payment_export_preserves_method_and_single_linked_purchase_code(): void
    {
        $actor = $this->actor();
        $supplier = Customer::create([
            'code' => 'NCC-REAL-PAYMENT',
            'name' => 'Real payment supplier',
            'is_supplier' => true,
            'is_customer' => false,
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-TEST',
            'supplier_id' => $supplier->id,
            'total_amount' => 1000,
            'discount' => 0,
            'paid_amount' => 200,
            'debt_amount' => 800,
            'status' => 'completed',
        ]);
        CashFlow::create([
            'code' => 'PC-TEST',
            'type' => 'payment',
            'amount' => 200,
            'target_type' => PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES[0],
            'target_id' => $supplier->id,
            'target_name' => $supplier->name,
            'reference_type' => 'Purchase',
            'reference_code' => $purchase->code,
            'payment_method' => 'bank_transfer',
            'status' => 'active',
            'time' => Carbon::parse('2026-08-12 10:03:00'),
        ]);

        $rows = $this->rows(
            "/api/suppliers/{$supplier->id}/export-debt?format=xlsx&date_preset=all&include_detail=1&columns[]=quantity",
            $actor,
        );
        $paymentRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['B'] ?? '') === 'PC-TEST',
        ));

        self::assertCount(1, $paymentRows);
        $label = (string) ($paymentRows[0]['C'] ?? '');
        self::assertStringContainsString('Thanh to', $label);
        self::assertStringContainsString('Chuy', $label);
        self::assertStringContainsString('PN-TEST', $label);
        self::assertSame(1, substr_count($label, 'PN-TEST'));
        self::assertStringNotContainsString('PC-TEST', $label);
        self::assertSame(200.0, (float) ($paymentRows[0]['L'] ?? 0));
        self::assertSame(0, $this->countCode($rows, 'SKU-REAL-PIPELINE'));
    }

    private function actor(): User
    {
        return User::create([
            'name' => 'Real pipeline QA',
            'email' => 'real-pipeline-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function dualRolePartner(): Customer
    {
        return Customer::create([
            'code' => 'DUAL-REAL-'.uniqid(),
            'name' => 'Dual real pipeline partner',
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'SKU-REAL-PIPELINE',
            'name' => 'Real pipeline product',
            'type' => 'standard',
            'cost_price' => 500,
            'retail_price' => 1000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function purchase(Customer $partner, Product $product, array $overrides): Purchase
    {
        $purchase = Purchase::create(array_merge([
            'code' => 'PN-REAL-'.uniqid(),
            'supplier_id' => $partner->id,
            'total_amount' => 1000,
            'discount' => 0,
            'paid_amount' => 0,
            'debt_amount' => 1000,
            'status' => 'completed',
        ], $overrides));
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => 1,
            'price' => 1000,
            'discount' => 0,
            'subtotal' => 1000,
        ]);

        return $purchase;
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $uri, User $actor): array
    {
        $response = $this->actingAs($actor)->get($uri);
        $response->assertOk();
        $body = $response->streamedContent() ?: $response->getContent();
        $path = tempnam(sys_get_temp_dir(), 'partner-debt-real-').'.xlsx';
        file_put_contents($path, $body);
        try {
            return IOFactory::load($path)->getSheetByName('CNCT')->toArray(null, true, false, true);
        } finally {
            @unlink($path);
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function rowByCode(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if (($row['B'] ?? '') === $code) {
                return $row;
            }
        }

        self::fail('Expected workbook row not found: '.$code);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function countCode(array $rows, string $code): int
    {
        return count(array_filter($rows, static fn (array $row): bool => ($row['B'] ?? '') === $code));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function closing(array $rows): float
    {
        foreach ($rows as $row) {
            if (str_contains((string) ($row['I'] ?? ''), 'cu')) {
                return (float) ($row['K'] ?? 0) - (float) ($row['L'] ?? 0);
            }
        }

        self::fail('Closing summary row not found');
    }
}
