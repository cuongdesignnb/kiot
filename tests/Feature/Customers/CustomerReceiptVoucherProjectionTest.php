<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\CustomerPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CustomerReceiptVoucherProjectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_auto_allocations_remain_internal_while_timeline_and_exports_show_one_exact_receipt(): void
    {
        $customer = Customer::create([
            'code' => 'KH-SYNTH-RECEIPT-'.uniqid(),
            'name' => 'Synthetic Receipt Customer',
            'phone' => '0900000001',
            'debt_amount' => 4_500_000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
        $firstInvoice = $this->invoice($customer, 'HD-SYNTH-RECEIPT-A', 1_250_000, '2026-01-10 09:00:00');
        $secondInvoice = $this->invoice($customer, 'HD-SYNTH-RECEIPT-B', 3_250_000, '2026-01-11 09:00:00');

        $payment = app(CustomerPaymentService::class)->collect(
            $customer,
            4_500_000,
            'auto',
            [],
            'Synthetic multi-invoice receipt',
            Carbon::parse('2026-01-15 10:00:00'),
            null,
        );

        $this->assertSame(2, CustomerPaymentAllocation::query()
            ->where('cash_flow_id', $payment['cash_flow_id'])
            ->count());
        $this->assertSame(1_250_000.0, (float) $firstInvoice->fresh()->customer_paid);
        $this->assertSame(3_250_000.0, (float) $secondInvoice->fresh()->customer_paid);

        $timeline = app(CustomerDebtDocumentTimelineService::class)->build($customer->fresh());
        $receiptRows = collect($timeline['entries'])
            ->where('code', $payment['cash_flow_code'])
            ->values();

        $this->assertCount(1, $receiptRows);
        $row = $receiptRows->sole();
        $this->assertSame(-4_500_000.0, (float) $row['display_effect']);
        $this->assertSame(4_500_000.0, (float) $row['payment_amount']);
        $this->assertSame(2, (int) $row['allocation_count']);
        $this->assertSame('Khách thanh toán', $row['display_type']);
        $this->assertNull($row['payment_for_code']);
        $this->assertEqualsCanonicalizing(
            [$firstInvoice->id, $secondInvoice->id],
            $row['allocation_invoice_ids'],
        );

        $actor = User::create([
            'name' => 'Synthetic Export Actor',
            'email' => 'synthetic-receipt-export-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
        $response = $this->actingAs($actor)->get(
            "/customers/{$customer->id}/export-debt?format=xlsx&date_preset=all",
        );
        $response->assertOk();
        $temporaryFile = tempnam(sys_get_temp_dir(), 'receipt-voucher-').'.xlsx';
        file_put_contents($temporaryFile, $response->streamedContent() ?: $response->getContent());

        try {
            $rows = IOFactory::load($temporaryFile)
                ->getSheetByName('CNCT')
                ->toArray(null, true, false, true);
        } finally {
            @unlink($temporaryFile);
        }

        $exportedReceiptRows = collect($rows)
            ->filter(fn (array $exportRow): bool => ($exportRow['B'] ?? null) === $payment['cash_flow_code'])
            ->values();
        $this->assertCount(1, $exportedReceiptRows);
        $this->assertEquals(4_500_000, (float) ($exportedReceiptRows->sole()['K'] ?? 0));

        $csvResponse = $this->actingAs($actor)->get("/customers/{$customer->id}/export-debt");
        $csvResponse->assertOk();
        $csv = $csvResponse->streamedContent() ?: $csvResponse->getContent();
        $this->assertSame(1, substr_count($csv, $payment['cash_flow_code']));
    }

    private function invoice(Customer $customer, string $code, float $total, string $time): Invoice
    {
        return Invoice::create([
            'code' => $code.'-'.strtoupper(substr(uniqid(), -5)),
            'customer_id' => $customer->id,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'transaction_date' => Carbon::parse($time),
            'created_at' => Carbon::parse($time),
            'updated_at' => Carbon::parse($time),
        ]);
    }
}
