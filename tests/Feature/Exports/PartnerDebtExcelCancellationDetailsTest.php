<?php

namespace Tests\Feature\Exports;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Services\Exports\PartnerDebtExportDocumentResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartnerDebtExcelCancellationDetailsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_cancellation_resolves_original_purchase_details_without_new_document(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-CANCEL-'.uniqid(),
            'name' => 'Cancellation supplier',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_supplier' => true,
            'is_customer' => false,
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-CANCEL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'total_amount' => 1000,
            'discount' => 0,
            'paid_amount' => 0,
            'debt_amount' => 1000,
            'status' => 'cancelled',
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_name' => 'Cancellation detail product',
            'product_code' => 'CANCEL-SKU',
            'quantity' => 1,
            'price' => 1000,
            'discount' => 0,
            'subtotal' => 1000,
        ]);

        $entry = [
            'event_identity' => 'supplier|purchases|'.$purchase->id.'|purchase_cancel_reversal|payable',
            'event_kind' => 'purchase_cancel_reversal',
            'reference_type' => 'Purchase',
            'reference_id' => $purchase->id,
            'reference_code' => 'HUY-'.$purchase->code,
            'reversal_of' => 'supplier|purchases|'.$purchase->id.'|purchase|payable',
        ];
        $resolver = new PartnerDebtExportDocumentResolver;
        $resolver->preload([$entry], 'supplier');
        $identity = $resolver->resolve($entry);
        $lines = $resolver->loadDetailLines($entry, 'supplier');

        $this->assertTrue($identity['is_cancellation']);
        $this->assertSame($purchase->id, $identity['document_id']);
        $this->assertSame($purchase->id, $identity['original_document_id']);
        $this->assertCount(1, $lines);
        $this->assertSame('CANCEL-SKU', $lines[0]['code']);
    }
}
