<?php

namespace Tests\Feature\Exports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\ReturnItem;
use App\Services\Exports\CustomerDebtExcelExportService;
use App\Services\Exports\SupplierDebtExcelExportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartnerDebtExcelCancellationMatrixTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_product_document_cancellations_keep_one_parent_and_details_in_both_orientations(): void
    {
        $partner = Customer::create([
            'code' => 'DUAL-CANCEL-'.uniqid(),
            'name' => 'Cancellation dual partner',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true,
            'is_supplier' => true,
        ]);
        $product = Product::create([
            'sku' => 'SKU-CANCEL-'.uniqid(),
            'name' => 'Cancellation matrix product',
            'type' => 'standard',
            'cost_price' => 100,
            'retail_price' => 200,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        ProductUnit::create([
            'product_id' => $product->id,
            'unit_name' => 'Cái',
            'conversion_rate' => 1,
            'is_base_unit' => true,
        ]);

        $purchase = Purchase::create([
            'code' => 'PN-CANCEL-'.uniqid(), 'supplier_id' => $partner->id,
            'total_amount' => 1000, 'discount' => 0, 'paid_amount' => 0,
            'debt_amount' => 1000, 'status' => 'cancelled', 'note' => 'purchase document note',
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_code' => $product->sku,
            'quantity' => 2, 'price' => 500, 'discount' => 0, 'subtotal' => 1000,
            'note' => 'purchase line note',
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'code' => 'PTN-CANCEL-'.uniqid(), 'supplier_id' => $partner->id,
            'total_amount' => 200, 'refund_amount' => 0, 'status' => 'cancelled',
        ]);
        PurchaseReturnItem::create([
            'purchase_return_id' => $purchaseReturn->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_code' => $product->sku,
            'quantity' => 1, 'price' => 200, 'subtotal' => 200, 'cost_price' => 100,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-CANCEL-'.uniqid(), 'customer_id' => $partner->id,
            'subtotal' => 300, 'total' => 300, 'customer_paid' => 0,
            'status' => 'Đã hủy',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'product_id' => $product->id,
            'quantity' => 1, 'price' => 300, 'subtotal' => 300, 'cost_price' => 100,
            'note' => 'invoice line note',
        ]);

        $salesReturn = OrderReturn::create([
            'code' => 'TH-CANCEL-'.uniqid(), 'customer_id' => $partner->id,
            'status' => 'cancelled', 'subtotal' => 400, 'total' => 400,
        ]);
        ReturnItem::create([
            'return_id' => $salesReturn->id, 'product_id' => $product->id,
            'quantity' => 1, 'price' => 400, 'subtotal' => 400, 'import_price' => 100,
        ]);

        $documents = [
            ['code' => 'HUY-'.$purchase->code, 'type' => 'Purchase', 'id' => $purchase->id, 'kind' => 'purchase_cancel_reversal', 'effect' => -1000],
            ['code' => 'HUY-'.$purchaseReturn->code, 'type' => 'PurchaseReturn', 'id' => $purchaseReturn->id, 'kind' => 'purchase_return_cancel_reversal', 'effect' => 200],
            ['code' => 'HUY-'.$invoice->code, 'type' => 'Invoice', 'id' => $invoice->id, 'kind' => 'invoice_cancel_reversal', 'effect' => 300],
            ['code' => 'HUY-'.$salesReturn->code, 'type' => 'OrderReturn', 'id' => $salesReturn->id, 'kind' => 'sales_return_cancel_reversal', 'effect' => -400],
        ];
        $entries = [];
        $customerRunning = 0.0;
        $supplierRunning = 0.0;
        foreach ($documents as $index => $document) {
            $customerRunning += $document['effect'];
            $supplierRunning -= $document['effect'];
            $entries[] = [
                'event_identity' => 'dual|'.strtolower($document['type']).'s|'.$document['id'].'|'.$document['kind'].'|receivable',
                'event_kind' => $document['kind'],
                'reference_type' => $document['type'],
                'reference_id' => $document['id'],
                'reference_code' => $document['code'],
                'code' => $document['code'],
                'type_label' => 'Hủy chứng từ',
                'customer_display_effect' => $document['effect'],
                'supplier_display_effect' => -$document['effect'],
                'customer_display_running_balance' => $customerRunning,
                'supplier_display_running_balance' => $supplierRunning,
                'created_at' => sprintf('2026-08-12 10:0%d:00', $index + 1),
            ];
        }

        $customerRows = (new CustomerDebtExcelExportService($partner, $entries, null, null, true, [
            'unit', 'quantity', 'unit_price', 'discount', 'vat', 'cost', 'line_total', 'note',
        ]))->build()->getActiveSheet()->toArray(null, true, false, true);
        $supplierRows = (new SupplierDebtExcelExportService($entries, $partner, null, null, true, [
            'unit', 'quantity', 'unit_price', 'discount', 'vat', 'cost', 'line_total', 'note',
        ]))->build()->getActiveSheet()->toArray(null, true, false, true);

        self::assertSame(4, $this->primaryRowCount($customerRows));
        self::assertSame(4, $this->primaryRowCount($supplierRows));
        self::assertSame(4, $this->detailRowCount($customerRows));
        self::assertSame(4, $this->detailRowCount($supplierRows));
        self::assertSame(4, $this->countSku($customerRows, $product->sku));
        self::assertSame(4, $this->countSku($supplierRows, $product->sku));
        self::assertSame('Cái', $this->firstDetailValue($customerRows, 'D'));
        self::assertSame('Cái', $this->firstDetailValue($supplierRows, 'D'));
        self::assertSame('', $this->firstDetailValue($customerRows, 'K'));
        self::assertSame('', $this->firstDetailValue($customerRows, 'L'));
        self::assertSame('', $this->firstDetailValue($customerRows, 'M'));
        self::assertSame('Số dư sau GD', $customerRows[10]['M'] ?? null);
        self::assertSame('Số dư sau GD', $supplierRows[10]['M'] ?? null);
        self::assertTrue($this->columnIsEmpty($customerRows, 'N'));
        self::assertTrue($this->columnIsEmpty($supplierRows, 'N'));
        self::assertSame('purchase document note', $purchase->fresh()->note);
        self::assertSame('invoice line note', InvoiceItem::findOrFail($invoice->items()->first()->id)->note);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function primaryRowCount(array $rows): int
    {
        return count(array_filter($rows, static fn (array $row): bool => str_starts_with((string) ($row['B'] ?? ''), 'HUY-')));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function detailRowCount(array $rows): int
    {
        return count(array_filter($rows, static fn (array $row): bool => str_starts_with((string) ($row['B'] ?? ''), 'SKU-CANCEL-')));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function countSku(array $rows, string $sku): int
    {
        return count(array_filter($rows, static fn (array $row): bool => ($row['B'] ?? '') === $sku));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function firstDetailValue(array $rows, string $column): mixed
    {
        foreach ($rows as $row) {
            if (str_starts_with((string) ($row['B'] ?? ''), 'SKU-CANCEL-')) {
                return $row[$column] ?? '';
            }
        }
        self::fail('detail row not found');
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function columnIsEmpty(array $rows, string $column): bool
    {
        foreach ($rows as $row) {
            if (($row[$column] ?? null) !== null && ($row[$column] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }
}
