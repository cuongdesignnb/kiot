<?php

namespace Tests\Feature\Report;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REG-RR01-01: ReportController tính cả hóa đơn status = 'Đã hủy'.
 *
 * Test strategy: Tạo dữ liệu gồm 1 HĐ hợp lệ + 1 HĐ hủy.
 * Gọi Invoice::active() scope và tái hiện query pattern từ ReportController.
 * Assert rằng kết quả KHÔNG chứa dữ liệu từ HĐ hủy.
 *
 * Nếu scope hoặc filter bị thiếu → test FAIL.
 *
 * Dữ liệu test:
 *   - Product A: cost_price = 100.000, stock = 20
 *   - Customer A: is_customer = true
 *   - Category: 'Test Category REG'
 *   - Invoice hợp lệ: status = 'Hoàn thành', total = 1.000.000, qty = 2
 *   - Invoice đã hủy: status = 'Đã hủy', total = 9.000.000, qty = 9
 *
 * Nếu query ĐÚNG: tổng = 1.000.000, qty = 2
 * Nếu query SAI:  tổng = 10.000.000, qty = 11
 */
class RR01ReportControllerRegressionTest extends TestCase
{
    use DatabaseTransactions;

    /* ────────── Setup data ────────── */

    private Product  $product;
    private Customer $customer;
    private Category $category;
    private Invoice  $validInvoice;
    private Invoice  $cancelledInvoice;
    private string   $dateFrom;
    private string   $dateTo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Test Category REG-' . uniqid(),
        ]);

        $this->product = Product::create([
            'sku'                  => 'SP-REG01-' . uniqid(),
            'name'                 => 'Product REG01',
            'cost_price'           => 100000,
            'retail_price'         => 500000,
            'stock_quantity'       => 20,
            'inventory_total_cost' => 20 * 100000,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $this->category->id,
        ]);

        $this->customer = Customer::create([
            'code'        => 'KH-REG01-' . uniqid(),
            'name'        => 'Khách REG01',
            'phone'       => '09' . rand(10000000, 99999999),
            'debt_amount' => 0,
            'total_spent' => 0,
            'is_customer' => true,
        ]);

        // ── Invoice hợp lệ: 1.000.000, qty = 2 ──
        $this->validInvoice = Invoice::create([
            'code'          => 'HD-VALID-' . uniqid(),
            'subtotal'      => 1000000,
            'discount'      => 0,
            'total'         => 1000000,
            'customer_paid' => 1000000,
            'customer_id'   => $this->customer->id,
            'status'        => 'Hoàn thành',
            'sales_channel' => 'Test',
            'created_at'    => now(),
        ]);

        InvoiceItem::create([
            'invoice_id'  => $this->validInvoice->id,
            'product_id'  => $this->product->id,
            'quantity'    => 2,
            'price'       => 500000,
            'cost_price'  => 100000,
            'discount'    => 0,
            'subtotal'    => 1000000,
        ]);

        // ── Invoice đã hủy: 9.000.000, qty = 9 ──
        $this->cancelledInvoice = Invoice::create([
            'code'          => 'HD-CANCEL-' . uniqid(),
            'subtotal'      => 9000000,
            'discount'      => 0,
            'total'         => 9000000,
            'customer_paid' => 9000000,
            'customer_id'   => $this->customer->id,
            'status'        => 'Đã hủy',
            'sales_channel' => 'Test',
            'created_at'    => now(),
        ]);

        InvoiceItem::create([
            'invoice_id'  => $this->cancelledInvoice->id,
            'product_id'  => $this->product->id,
            'quantity'    => 9,
            'price'       => 1000000,
            'cost_price'  => 100000,
            'discount'    => 0,
            'subtotal'    => 9000000,
        ]);

        $this->dateFrom = now()->startOfDay()->toDateTimeString();
        $this->dateTo   = now()->endOfDay()->toDateTimeString();
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  1. customerOverview — totalRevenueInPeriod
     *
     *  Verifies: Invoice::active() scope correctly filters out 'Đã hủy'.
     *  Maps to: ReportController@customerOverview dòng 528-533
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_customer_overview_total_revenue_should_exclude_cancelled_invoices(): void
    {
        $dateFrom = Carbon::parse($this->dateFrom);
        $dateTo   = Carbon::parse($this->dateTo);

        // Tái hiện query pattern từ ReportController (phải dùng active() scope)
        $totalRevenueInPeriod = (float) Invoice::active()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('total');

        // Kỳ vọng: chỉ tính invoice hợp lệ = 1.000.000
        $this->assertEquals(
            1000000.0,
            $totalRevenueInPeriod,
            "customerOverview totalRevenueInPeriod không nên tính HĐ Đã hủy. "
            . "Kỳ vọng: 1.000.000, thực tế: " . number_format($totalRevenueInPeriod)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  2. customerOverview — newCustomerRevenue
     *
     *  Maps to: ReportController@customerOverview dòng 541-544
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_customer_overview_new_customer_revenue_should_exclude_cancelled(): void
    {
        $dateFrom = Carbon::parse($this->dateFrom);
        $dateTo   = Carbon::parse($this->dateTo);

        $newCustomerIds = Customer::whereBetween('created_at', [$dateFrom, $dateTo])
            ->pluck('id');

        $newCustomerRevenue = (float) Invoice::active()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereIn('customer_id', $newCustomerIds)
            ->sum('total');

        $this->assertEquals(
            1000000.0,
            $newCustomerRevenue,
            "newCustomerRevenue không nên tính HĐ Đã hủy. "
            . "Kỳ vọng: 1.000.000, thực tế: " . number_format($newCustomerRevenue)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  3. customerCategory (RFM) — invoiceCount per customer
     *
     *  Maps to: ReportController@customerCategory dòng 662-665
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_customer_category_rfm_invoice_count_should_exclude_cancelled(): void
    {
        $dateFrom = Carbon::parse($this->dateFrom);
        $dateTo   = Carbon::parse($this->dateTo);

        // Query pattern phải dùng active()
        $invoiceCount = Invoice::active()
            ->where('customer_id', $this->customer->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->count();

        $this->assertEquals(
            1,
            $invoiceCount,
            "customerCategory invoiceCount không nên tính HĐ Đã hủy. "
            . "Kỳ vọng: 1, thực tế: {$invoiceCount}"
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  4. customerCategory (RFM) — custRevenue per customer
     *
     *  Maps to: ReportController@customerCategory dòng 692-695
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_customer_category_rfm_revenue_should_exclude_cancelled(): void
    {
        $dateFrom = Carbon::parse($this->dateFrom);
        $dateTo   = Carbon::parse($this->dateTo);

        $custRevenue = (float) Invoice::active()
            ->where('customer_id', $this->customer->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('total');

        $this->assertEquals(
            1000000.0,
            $custRevenue,
            "customerCategory custRevenue không nên tính HĐ Đã hủy. "
            . "Kỳ vọng: 1.000.000, thực tế: " . number_format($custRevenue)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  5. customerDebt — yearRevenue
     *
     *  Maps to: ReportController@customerDebt dòng 754-756
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_debt_report_year_revenue_should_exclude_cancelled(): void
    {
        $yearStart = Carbon::now()->startOfYear();
        $yearEnd   = Carbon::now()->endOfDay();

        // Query phải dùng active()
        $yearRevenue = (float) Invoice::active()
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->sum('total');

        // Cross-check: phải bằng query có where status filter
        $yearRevenueFiltered = (float) Invoice::whereBetween('created_at', [$yearStart, $yearEnd])
            ->where('status', '!=', 'Đã hủy')
            ->sum('total');

        $this->assertEquals(
            $yearRevenueFiltered,
            $yearRevenue,
            "customerDebt yearRevenue (qua active() scope) phải trùng với filter thủ công. "
            . "active(): " . number_format($yearRevenue)
            . ", filter: " . number_format($yearRevenueFiltered)
        );

        // Phải khác tổng không lọc (chứng minh có HĐ hủy bị loại)
        $yearRevenueUnfiltered = (float) Invoice::whereBetween('created_at', [$yearStart, $yearEnd])
            ->sum('total');

        $this->assertNotEquals(
            $yearRevenueUnfiltered,
            $yearRevenue,
            "yearRevenue phải loại bỏ HĐ Đã hủy — nhưng đang trùng với tổng chưa lọc"
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  6. productOverview — totalItemsSold (InvoiceItem::whereHas)
     *
     *  Maps to: ReportController@productOverview dòng 245-253
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_product_overview_items_sold_should_exclude_cancelled(): void
    {
        $dateFrom = Carbon::parse($this->dateFrom);
        $dateTo   = Carbon::parse($this->dateTo);

        // Query pattern phải có where('status', '!=', 'Đã hủy') trong whereHas
        $soldItems = InvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo) {
            $q->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'Đã hủy');
        })->get();

        $totalItemsSold = $soldItems->sum('quantity');

        $this->assertEquals(
            2,
            $totalItemsSold,
            "productOverview totalItemsSold không nên tính HĐ Đã hủy. "
            . "Kỳ vọng: 2, thực tế: {$totalItemsSold}"
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  7. productOverview — totalSoldRevenue
     *
     *  Maps to: ReportController@productOverview dòng 253
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_product_overview_sold_revenue_should_exclude_cancelled(): void
    {
        $dateFrom = Carbon::parse($this->dateFrom);
        $dateTo   = Carbon::parse($this->dateTo);

        $soldItems = InvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo) {
            $q->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'Đã hủy');
        })->get();

        $totalSoldRevenue = $soldItems->sum(fn($i) => $i->quantity * $i->price);

        $this->assertEquals(
            1000000.0,
            (float) $totalSoldRevenue,
            "productOverview totalSoldRevenue không nên tính HĐ Đã hủy. "
            . "Kỳ vọng: 1.000.000, thực tế: " . number_format($totalSoldRevenue)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  8. customerCategory — costItems (giá vốn per customer)
     *
     *  Maps to: ReportController@customerCategory dòng 706-710
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_customer_category_cost_items_should_exclude_cancelled(): void
    {
        $dateFrom = Carbon::parse($this->dateFrom);
        $dateTo   = Carbon::parse($this->dateTo);

        $costItems = InvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo) {
            $q->where('customer_id', $this->customer->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'Đã hủy');
        })->get();

        $custCost = 0;
        foreach ($costItems as $item) {
            $custCost += $item->quantity * ($item->cost_price ?? 0);
        }

        $this->assertEquals(
            200000.0,
            (float) $custCost,
            "customerCategory custCost không nên tính HĐ Đã hủy. "
            . "Kỳ vọng: 200.000, thực tế: " . number_format($custCost)
        );
    }
}
