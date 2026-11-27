<?php

namespace Tests\Feature\Reports;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Task;
use App\Support\Reports\MetricService;
use App\Support\Reports\PnlCashFlows;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Laptopplus 25/11: returned-goods cost, P&L cash flows, sale date, deadlines, supplier debt sort. */
class LaptopplusBatchNovemberTest extends TestCase
{
    use DatabaseTransactions;

    public function test_returned_goods_cost_uses_the_cost_snapshot_not_the_refund_price(): void
    {
        $product = $this->product(['cost_price' => 700000]);
        $day = Carbon::parse('2031-03-10 10:00:00');
        $return = OrderReturn::create(['code' => 'TH-NOV-'.uniqid(), 'status' => 'Hoàn thành', 'total' => 1000000, 'subtotal' => 1000000, 'created_at' => $day]);
        DB::table('return_items')->insert([
            ['return_id' => $return->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 1000000, 'discount' => 0, 'import_price' => 1000000, 'cost_price' => 600000, 'created_at' => $day, 'updated_at' => $day],
            // Legacy line without a cost snapshot: the product's cost is used.
            ['return_id' => $return->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 1000000, 'discount' => 0, 'import_price' => 1000000, 'cost_price' => 0, 'created_at' => $day, 'updated_at' => $day],
        ]);

        $metrics = MetricService::compute($day->copy()->startOfDay(), $day->copy()->endOfDay(), null);

        $this->assertEqualsWithDelta(1300000, $metrics['cogs_returned'], 0.01);
    }

    public function test_profit_reports_leave_out_sales_receipts_supplier_payments_and_fund_transfers(): void
    {
        $day = Carbon::parse('2031-04-15 09:00:00');
        $flows = [
            ['payment', 'Tiền điện', 500000],
            ['payment', 'Chi thanh toán NCC', 9000000],
            ['payment', 'Chuyển quỹ nội bộ', 4000000],
            ['payment', 'Chi phí khác', 100000],
            ['receipt', 'Thu tiền khách trả', 8000000],
            ['receipt', 'Thu tiền sửa chữa', 300000],
            ['receipt', 'Thanh lý tài sản', 250000],
        ];
        foreach ($flows as [$type, $category, $amount]) {
            CashFlow::create(['code' => 'CF-NOV-'.uniqid(), 'type' => $type, 'amount' => $amount, 'time' => $day, 'category' => $category, 'status' => 'active']);
        }
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $this->assertEqualsWithDelta(500000, (float) PnlCashFlows::expenses($from, $to)->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(100000, (float) PnlCashFlows::otherExpenses($from, $to)->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(250000, (float) PnlCashFlows::otherIncome($from, $to)->sum('amount'), 0.01);
    }

    public function test_reports_find_an_invoice_on_its_edited_sale_date(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD-NOV-'.uniqid(), 'status' => 'Hoàn thành', 'total' => 100000, 'subtotal' => 100000,
            'created_at' => Carbon::parse('2031-05-02 10:00:00'), 'transaction_date' => Carbon::parse('2031-05-01 18:00:00'),
        ]);

        $this->assertTrue(Invoice::businessBetween(Carbon::parse('2031-05-01 00:00:00'), Carbon::parse('2031-05-01 23:59:59'))->whereKey($invoice->id)->exists());
        $this->assertFalse(Invoice::businessBetween(Carbon::parse('2031-05-02 00:00:00'), Carbon::parse('2031-05-02 23:59:59'))->whereKey($invoice->id)->exists());
    }

    public function test_task_deadline_is_a_plain_date_in_json(): void
    {
        $task = new Task(['deadline' => '2031-06-20']);

        $this->assertSame('2031-06-20', $task->toArray()['deadline']);
    }

    public function test_supplier_list_sorts_on_the_debt_figure_it_shows(): void
    {
        $user = \App\Models\User::create([
            'name' => 'Admin NOV', 'email' => 'admin-nov-'.uniqid().'@test.local', 'password' => bcrypt('password'), 'role_id' => null,
        ]);
        $tag = 'NOV-SORT-'.uniqid();
        // Shown: 500k, 300k (800k owed - 500k they owe us), 100k
        $a = $this->supplier($tag.'-A', ['supplier_debt_amount' => 500000]);
        $b = $this->supplier($tag.'-B', ['supplier_debt_amount' => 800000, 'debt_amount' => 500000, 'is_customer' => true]);
        $c = $this->supplier($tag.'-C', ['supplier_debt_amount' => 100000]);

        $response = $this->actingAs($user)->get('/suppliers?search='.$tag.'&sort_by=supplier_debt_amount&sort_direction=desc');
        $response->assertOk();
        $ids = collect($response->original->getData()['page']['props']['suppliers']['data'] ?? [])->pluck('id')->all();

        $this->assertSame([$a->id, $b->id, $c->id], $ids);
    }

    private function supplier(string $code, array $attributes): Customer
    {
        return Customer::create(array_merge([
            'code' => $code, 'name' => $code, 'phone' => '09'.random_int(10000000, 99999999),
            'debt_amount' => 0, 'supplier_debt_amount' => 0, 'is_customer' => false, 'is_supplier' => true, 'status' => 'active',
        ], $attributes));
    }

    private function product(array $attributes = []): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat NOV']);

        return Product::create(array_merge([
            'sku' => 'SP-NOV-'.uniqid(), 'name' => 'Laptop NOV', 'cost_price' => 100000, 'retail_price' => 200000,
            'stock_quantity' => 0, 'is_active' => true, 'has_serial' => false, 'category_id' => $category->id,
        ], $attributes));
    }
}
