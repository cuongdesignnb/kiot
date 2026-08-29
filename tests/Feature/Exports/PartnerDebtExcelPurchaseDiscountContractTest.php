<?php

namespace Tests\Feature\Exports;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Services\Exports\CustomerDebtExcelExportService;
use App\Services\Exports\SupplierDebtExcelExportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartnerDebtExcelPurchaseDiscountContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_discount_is_applied_once_to_parent_detail_and_summary(): void
    {
        $partner = Customer::create([
            'code' => 'DUAL-DISCOUNT-'.uniqid(),
            'name' => 'Discount parity partner',
            'is_customer' => true,
            'is_supplier' => true,
        ]);
        $product = Product::create([
            'sku' => 'SKU-DISCOUNT-'.uniqid(),
            'name' => 'Discount product',
            'type' => 'standard',
            'cost_price' => 800,
            'retail_price' => 1000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-DISCOUNT-'.uniqid(), 'supplier_id' => $partner->id,
            'total_amount' => 1000, 'discount' => 100, 'paid_amount' => 0,
            'debt_amount' => 900, 'status' => 'completed',
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_code' => $product->sku,
            'quantity' => 1, 'price' => 1000, 'discount' => 0, 'subtotal' => 1000,
        ]);

        $entry = [
            'event_identity' => 'dual|purchases|'.$purchase->id.'|purchase|payable',
            'event_kind' => 'purchase', 'reference_type' => 'Purchase',
            'reference_id' => $purchase->id, 'code' => $purchase->code,
            'type_label' => 'Nhập hàng', 'customer_display_effect' => 1000,
            'supplier_display_effect' => -1000,
            'customer_display_running_balance' => 900,
            'supplier_display_running_balance' => -900,
        ];
        $customerRows = (new CustomerDebtExcelExportService($partner, [$entry], null, null, true, [
            'quantity', 'unit_price', 'discount', 'line_total',
        ]))->build()->getActiveSheet()->toArray(null, true, false, true);
        $supplierRows = (new SupplierDebtExcelExportService([$entry], $partner, null, null, true, [
            'quantity', 'unit_price', 'discount', 'line_total',
        ]))->build()->getActiveSheet()->toArray(null, true, false, true);

        $customerParent = $this->rowByCode($customerRows, $purchase->code);
        $supplierParent = $this->rowByCode($supplierRows, $purchase->code);
        self::assertSame(900.0, (float) ($customerParent['J'] ?? 0));
        self::assertSame(900.0, (float) ($supplierParent['K'] ?? 0));
        self::assertSame(900.0, $this->summary($customerRows));
        self::assertSame(-900.0, $this->summary($supplierRows));
        self::assertSame(1000.0, $this->detailTotal($customerRows, $product->sku));
        self::assertSame(-100.0, $this->detailTotalByLabel($customerRows, 'Giảm giá hóa đơn'));
        self::assertSame(900.0, $this->detailTotal($supplierRows, $product->sku) + $this->detailTotalByLabel($supplierRows, 'Giảm giá hóa đơn'));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function rowByCode(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if (($row['B'] ?? '') === $code) {
                return $row;
            }
        }
        self::fail('row not found: '.$code);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function summary(array $rows): float
    {
        foreach ($rows as $row) {
            if (($row['H'] ?? '') === 'Nợ cuối kỳ:') {
                return (float) ($row['J'] ?? 0) - (float) ($row['K'] ?? 0);
            }
        }
        self::fail('summary not found');
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function detailTotal(array $rows, string $code): float
    {
        foreach ($rows as $row) {
            if (($row['B'] ?? '') === $code) {
                return (float) ($row['I'] ?? 0);
            }
        }
        self::fail('detail not found: '.$code);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function detailTotalByLabel(array $rows, string $label): float
    {
        foreach ($rows as $row) {
            if (($row['C'] ?? '') === $label) {
                return (float) ($row['I'] ?? 0);
            }
        }
        self::fail('detail label not found: '.$label);
    }
}
