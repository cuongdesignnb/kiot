<?php

namespace Tests\Feature\Invoice;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceSerialVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Invoice serial visibility admin',
            'email' => 'invoice-serial-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    public function test_invoice_detail_customer_and_supplier_views_return_the_same_serial(): void
    {
        $customer = $this->customer(true);
        [$invoice, $item, $serial] = $this->invoiceWithLinkedSerial($customer);

        $detail = $this->actingAs($this->admin)->getJson("/invoices/{$invoice->id}/detail");
        $detail->assertOk()
            ->assertJsonPath('items.0.invoice_item_id', $item->id)
            ->assertJsonPath('items.0.serial', $serial->serial_number)
            ->assertJsonPath('items.0.serials.0.serial_number', $serial->serial_number)
            ->assertJsonPath('items.0.serial_count', 1);

        $customerDetail = $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-voucher-detail?code={$invoice->code}");
        $customerDetail->assertOk()
            ->assertJsonPath('data.items.0.serial', $serial->serial_number)
            ->assertJsonPath('data.items.0.serials.0.serial_number', $serial->serial_number);

        $supplierDetail = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$customer->id}/debt-voucher-detail?code={$invoice->code}");
        $supplierDetail->assertOk()
            ->assertJsonPath('data.items.0.serial', $serial->serial_number)
            ->assertJsonPath('data.items.0.serials.0.serial_number', $serial->serial_number);
    }

    public function test_invoice_show_index_and_print_include_serials(): void
    {
        $customer = $this->customer();
        [$invoice, , $serial] = $this->invoiceWithLinkedSerial($customer);

        $show = $this->actingAs($this->admin)->get("/invoices/{$invoice->id}/show");
        $show->assertOk();
        $show->assertInertia(function (Assert $page) use ($serial) {
            $page->where('invoice.items.0.serial', $serial->serial_number)
                ->where('invoice.items.0.serials.0.serial_number', $serial->serial_number)
                ->where('invoice.items.0.serial_count', 1);
        });

        $index = $this->actingAs($this->admin)->get('/invoices');
        $index->assertOk();
        $index->assertInertia(function (Assert $page) use ($serial) {
            $page->where('invoices.data.0.items.0.serial', $serial->serial_number)
                ->where('invoices.data.0.items.0.serial_count', 1);
        });

        $print = $this->actingAs($this->admin)->get("/invoices/{$invoice->id}/print");
        $print->assertOk();
        $print->assertSee('Serial/IMEI: '.$serial->serial_number, false);
    }

    public function test_legacy_direct_assignment_and_text_are_visible_without_guessing(): void
    {
        $customer = $this->customer();
        $product = $this->product('SP-SERIAL-DIRECT');
        $invoice = $this->invoice($customer, 'HD-SERIAL-DIRECT');
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 100000,
            'discount' => 0,
            'subtotal' => 200000,
        ]);

        foreach (['SERIAL-DIRECT-A', 'SERIAL-DIRECT-B'] as $serialNumber) {
            SerialImei::create([
                'product_id' => $product->id,
                'serial_number' => $serialNumber,
                'status' => 'sold',
                'invoice_id' => $invoice->id,
                'cost_price' => 50000,
            ]);
        }

        $direct = $this->actingAs($this->admin)->getJson("/invoices/{$invoice->id}/detail");
        $direct->assertOk()
            ->assertJsonPath('items.0.serial_count', 2)
            ->assertJsonPath('items.0.serials.0.serial_number', 'SERIAL-DIRECT-A')
            ->assertJsonPath('items.0.serials.1.serial_number', 'SERIAL-DIRECT-B');

        $textInvoice = $this->invoice($customer, 'HD-SERIAL-TEXT');
        InvoiceItem::create([
            'invoice_id' => $textInvoice->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 100000,
            'discount' => 0,
            'subtotal' => 200000,
            'serial' => "SERIAL-TEXT-A\nSERIAL-TEXT-B",
        ]);

        $text = $this->actingAs($this->admin)->getJson("/invoices/{$textInvoice->id}/detail");
        $text->assertOk()
            ->assertJsonPath('items.0.serial_count', 2)
            ->assertJsonPath('items.0.serials.0.serial_number', 'SERIAL-TEXT-A')
            ->assertJsonPath('items.0.serials.1.serial_number', 'SERIAL-TEXT-B');
    }

    public function test_direct_serials_are_not_assigned_to_duplicate_product_lines(): void
    {
        $customer = $this->customer();
        $product = $this->product('SP-SERIAL-AMBIGUOUS');
        $invoice = $this->invoice($customer, 'HD-SERIAL-AMBIGUOUS');

        foreach ([1, 2] as $quantity) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => 100000,
                'discount' => 0,
                'subtotal' => $quantity * 100000,
            ]);
        }

        foreach (['SERIAL-AMBIGUOUS-A', 'SERIAL-AMBIGUOUS-B', 'SERIAL-AMBIGUOUS-C'] as $serialNumber) {
            SerialImei::create([
                'product_id' => $product->id,
                'serial_number' => $serialNumber,
                'status' => 'sold',
                'invoice_id' => $invoice->id,
            ]);
        }

        $response = $this->actingAs($this->admin)->getJson("/invoices/{$invoice->id}/detail");
        $response->assertOk()
            ->assertJsonPath('items.0.serial_count', 0)
            ->assertJsonPath('items.1.serial_count', 0);
    }

    private function customer(bool $supplier = false): Customer
    {
        return Customer::create([
            'code' => 'KH-SERIAL-'.uniqid(),
            'name' => 'Serial visibility customer',
            'is_customer' => true,
            'is_supplier' => $supplier,
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
        ]);
    }

    private function product(string $sku): Product
    {
        return Product::create([
            'sku' => $sku.'-'.uniqid(),
            'name' => 'Serial visibility product',
            'cost_price' => 50000,
            'retail_price' => 100000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'is_active' => true,
            'has_serial' => true,
        ]);
    }

    private function invoice(Customer $customer, string $code): Invoice
    {
        return Invoice::create([
            'code' => $code.'-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 200000,
            'discount' => 0,
            'total' => 200000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'payment_method' => 'Tiền mặt',
        ]);
    }

    /** @return array{0: Invoice, 1: InvoiceItem, 2: SerialImei} */
    private function invoiceWithLinkedSerial(Customer $customer): array
    {
        $product = $this->product('SP-SERIAL-LINKED');
        $invoice = $this->invoice($customer, 'HD-SERIAL-LINKED');
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 200000,
            'discount' => 0,
            'subtotal' => 200000,
        ]);
        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SERIAL-LINKED-'.uniqid(),
            'status' => 'sold',
            'invoice_id' => $invoice->id,
            'cost_price' => 50000,
        ]);
        InvoiceItemSerial::create([
            'invoice_item_id' => $item->id,
            'serial_imei_id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'cost_price' => 50000,
        ]);

        return [$invoice, $item, $serial];
    }
}
