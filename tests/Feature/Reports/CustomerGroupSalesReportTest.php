<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerGroupSalesReportTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin CG',
            'email' => 'admin-cg-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'CG-' . uniqid(),
            'name' => 'Product CG',
            'cost_price' => 400000,
            'retail_price' => 1000000,
            'stock_quantity' => 100,
            'inventory_total_cost' => 40000000,
            'has_serial' => false,
            'is_active' => true,
        ]);
    }

    public function test_invoice_snapshots_customer_group_and_report_keeps_history_after_customer_group_change(): void
    {
        $admin = $this->admin();
        $customer = Customer::create([
            'code' => 'KH-CG-' . uniqid(),
            'name' => 'Customer CG',
            'phone' => '090' . rand(1000000, 9999999),
            'customer_group' => 'VIP A',
            'is_customer' => true,
        ]);
        $product = $this->product();

        $this->actingAs($admin)->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'subtotal' => 1000000,
            'discount' => 100000,
            'total' => 900000,
            'customer_paid' => 500000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 1000000,
                'discount' => 100000,
            ]],
        ])->assertRedirect();

        $invoice = Invoice::where('customer_id', $customer->id)->latest('id')->first();
        $this->assertSame('VIP A', $invoice->customer_group_name);

        $customer->update(['customer_group' => 'VIP B']);

        $res = $this->actingAs($admin)->get('/reports/sales?concern=customer_group_revenue&view=report&period=custom&date_from=2026-01-01&date_to=2030-12-31');
        $res->assertOk();
        $rows = collect($res->viewData('page')['props']['chartData']['rows']);

        $vipA = $rows->firstWhere('name', 'VIP A');
        $this->assertNotNull($vipA);
        $this->assertSame(900000.0, (float) $vipA['net_revenue']);
        $this->assertSame(400000.0, (float) $vipA['debt']);
        $this->assertNull($rows->firstWhere('name', 'VIP B'));
    }

    public function test_customer_group_profit_report_subtracts_returns_from_original_invoice_group(): void
    {
        $admin = $this->admin();
        $customer = Customer::create([
            'code' => 'KH-CG-RET-' . uniqid(),
            'name' => 'Customer Return CG',
            'phone' => '091' . rand(1000000, 9999999),
            'customer_group' => 'Wholesale',
            'is_customer' => true,
        ]);
        $product = $this->product();

        $invoice = Invoice::create([
            'code' => 'HD-CG-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_group_name' => 'Wholesale',
            'subtotal' => 2000000,
            'discount' => 0,
            'total' => 2000000,
            'customer_paid' => 2000000,
            'status' => 'Ho?n th?nh',
            'created_at' => '2026-05-10 10:00:00',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 1000000,
            'cost_price' => 400000,
        ]);

        $customer->update(['customer_group' => 'Retail']);

        $return = OrderReturn::create([
            'code' => 'TH-CG-' . uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'subtotal' => 1000000,
            'discount' => 0,
            'fee' => 0,
            'total' => 1000000,
            'status' => 'Ho?n th?nh',
            'created_at' => '2026-05-11 10:00:00',
        ]);
        ReturnItem::create([
            'return_id' => $return->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 1000000,
            'cost_price' => 400000,
            'import_price' => 400000,
        ]);

        $res = $this->actingAs($admin)->get('/reports/sales?concern=customer_group_profit&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $rows = collect($res->viewData('page')['props']['chartData']['rows']);
        $row = $rows->firstWhere('name', 'Wholesale');

        $this->assertNotNull($row);
        $this->assertSame(1000000.0, (float) $row['net_revenue']);
        $this->assertSame(400000.0, (float) $row['cogs_net']);
        $this->assertSame(600000.0, (float) $row['gross_profit']);
        $this->assertNull($rows->firstWhere('name', 'Retail'));
    }
}
