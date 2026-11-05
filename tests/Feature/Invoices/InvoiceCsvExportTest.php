<?php

namespace Tests\Feature\Invoices;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceCsvExportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_invoice_export_streams_rows_with_customer_and_respects_filters(): void
    {
        $admin = User::factory()->create();
        $marker = 'EXPCSV'.Str::upper(Str::random(6));
        $customer = Customer::create([
            'code' => 'KH-'.$marker,
            'name' => 'Khách Export '.$marker,
            'customer_group' => 'VIP',
            'is_customer' => true,
            'is_supplier' => false,
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
        ]);
        $withCustomer = Invoice::create([
            'code' => 'HD-'.$marker.'-1', 'customer_id' => $customer->id, 'subtotal' => 1_500_000,
            'total' => 1_500_000, 'customer_paid' => 1_000_000, 'status' => 'completed', 'note' => 'ghi chú '.$marker,
        ]);
        $walkIn = Invoice::create([
            'code' => 'HD-'.$marker.'-2', 'customer_id' => null, 'subtotal' => 200_000,
            'total' => 200_000, 'customer_paid' => 200_000, 'status' => 'completed',
        ]);
        $other = Invoice::create([
            'code' => 'HD-OTHER-'.Str::upper(Str::random(8)), 'customer_id' => null, 'subtotal' => 1,
            'total' => 1, 'customer_paid' => 1, 'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get('/invoices/export?keyword='.$marker);
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($csv, 3)))));
        $this->assertSame('Mã hóa đơn', $rows[0][0]);
        $byCode = collect(array_slice($rows, 1))->keyBy(0);

        $this->assertCount(2, $byCode);
        $this->assertSame($customer->name, $byCode[$withCustomer->code][2]);
        $this->assertSame('VIP', $byCode[$withCustomer->code][3]);
        $this->assertSame('1500000', rtrim(rtrim($byCode[$withCustomer->code][6], '0'), '.'));
        $this->assertSame('ghi chú '.$marker, $byCode[$withCustomer->code][8]);
        $this->assertSame('', $byCode[$walkIn->code][2]);
        $this->assertFalse($byCode->has($other->code));
        // Newest first, same as the invoice list.
        $this->assertSame($walkIn->code, $rows[1][0]);
    }
}
