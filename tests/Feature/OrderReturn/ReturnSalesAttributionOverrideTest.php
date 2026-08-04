<?php

namespace Tests\Feature\OrderReturn;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\User;
use App\Support\Reports\SellerResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReturnSalesAttributionOverrideTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Employee $sellerA;

    private Employee $sellerB;

    private Customer $customer;

    private Product $product;

    private Invoice $invoice;

    private OrderReturn $return;

    private CashFlow $cashFlow;

    private SerialImei $serial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Return attribution admin',
            'email' => 'return-attribution-admin-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
        $this->sellerA = $this->employee('Seller A');
        $this->sellerB = $this->employee('Seller B');
        $this->customer = Customer::create([
            'code' => 'KH-RETURN-'.uniqid(),
            'name' => 'Customer return attribution',
            'phone' => '090'.random_int(1000000, 9999999),
            'debt_amount' => 120000,
            'total_spent' => 880000,
        ]);
        $this->product = Product::create([
            'sku' => 'RETURN-ATTR-'.uniqid(),
            'name' => 'Return attribution product',
            'cost_price' => 40000,
            'retail_price' => 100000,
            'stock_quantity' => 8,
            'inventory_total_cost' => 320000,
            'is_active' => true,
            'has_serial' => false,
            'category_id' => Category::firstOrCreate(['name' => 'Return attribution category'])->id,
        ]);
        $this->invoice = Invoice::create([
            'code' => 'HD-RETURN-ATTR-'.uniqid(),
            'customer_id' => $this->customer->id,
            'created_by' => $this->sellerA->id,
            'seller_name' => $this->sellerA->name,
            'created_by_name' => 'Invoice creator',
            'subtotal' => 100000,
            'discount' => 0,
            'total' => 100000,
            'status' => 'Hoàn thành',
        ]);
        InvoiceItem::create([
            'invoice_id' => $this->invoice->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100000,
            'cost_price' => 40000,
            'subtotal' => 100000,
        ]);
        $this->return = OrderReturn::create([
            'code' => 'TH-RETURN-ATTR-'.uniqid(),
            'invoice_id' => $this->invoice->id,
            'customer_id' => $this->customer->id,
            'status' => 'Đã trả',
            'subtotal' => 100000,
            'total' => 100000,
            'paid_to_customer' => 0,
            'created_by_name' => 'Return creator',
        ]);
        ReturnItem::create([
            'return_id' => $this->return->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100000,
            'cost_price' => 40000,
            'import_price' => 40000,
        ]);
        $this->serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-RETURN-ATTR-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => 40000,
            'original_cost' => 40000,
        ]);
        $this->cashFlow = CashFlow::create([
            'code' => 'PC-RETURN-ATTR-'.uniqid(),
            'type' => 'payment',
            'amount' => 100000,
            'time' => now(),
            'target_type' => 'Khach hang',
            'target_id' => $this->customer->id,
            'target_name' => $this->customer->name,
            'reference_type' => 'OrderReturn',
            'reference_code' => $this->return->code,
            'description' => 'Return attribution financial snapshot',
            'status' => 'completed',
        ]);
    }

    public function test_migration_adds_only_nullable_sales_attribution_metadata_to_returns(): void
    {
        foreach ([
            'sales_attribution_employee_id',
            'sales_attribution_name',
            'sales_attribution_reason',
            'sales_attribution_updated_by',
            'sales_attribution_updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('returns', $column), "Missing returns.{$column}");
        }

        $branchAdmin = Role::query()->where('name', 'branch_admin')->firstOrFail();
        $this->assertContains('returns.sales_attribution.edit', $branchAdmin->permissions);
    }

    public function test_override_moves_only_return_report_attribution_and_preserves_financial_inventory_contract(): void
    {
        $before = [
            'invoice' => $this->invoice->fresh()->only(['id', 'created_by', 'seller_name', 'total']),
            'return' => $this->returnDocumentSnapshot($this->return),
            'customer' => $this->customer->fresh()->only(['debt_amount', 'total_spent']),
            'product' => $this->product->fresh()->only(['stock_quantity', 'inventory_total_cost', 'cost_price']),
            'return_item' => $this->return->items()->first()->only(['product_id', 'quantity', 'price', 'cost_price', 'serial_ids']),
            'serial' => $this->serial->fresh()->only(['status', 'invoice_id', 'sold_at', 'cost_price', 'original_cost']),
            'cash_flow' => $this->cashFlowSnapshot($this->cashFlow),
        ];

        $response = $this->actingAs($this->admin)->patchJson(
            route('returns.update-sales-attribution', $this->return),
            [
                'sales_attribution_employee_id' => $this->sellerB->id,
                'reason' => 'Serial đã được bán lại bởi nhân viên này.',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('return.id', $this->return->id)
            ->assertJsonPath('return.sales_attribution_employee_id', $this->sellerB->id)
            ->assertJsonPath('return.effective_sales_attribution_name', $this->sellerB->name)
            ->assertJsonPath('return.is_sales_attribution_overridden', true);

        $this->return->refresh();
        $this->assertSame($this->sellerB->id, $this->return->sales_attribution_employee_id);
        $this->assertSame($this->sellerB->name, $this->return->sales_attribution_name);
        $this->assertSame($this->admin->id, $this->return->sales_attribution_updated_by);
        $this->assertSame('Serial đã được bán lại bởi nhân viên này.', $this->return->sales_attribution_reason);
        $this->assertNotNull($this->return->sales_attribution_updated_at);
        $this->assertSame($before['invoice'], $this->invoice->fresh()->only(array_keys($before['invoice'])));
        $this->assertSame($before['return'], $this->returnDocumentSnapshot($this->return));
        $this->assertSame($before['customer'], $this->customer->fresh()->only(array_keys($before['customer'])));
        $this->assertSame($before['product'], $this->product->fresh()->only(array_keys($before['product'])));
        $this->assertSame($before['return_item'], $this->return->items()->first()->only(array_keys($before['return_item'])));
        $this->assertSame($before['serial'], $this->serial->fresh()->only(array_keys($before['serial'])));
        $this->assertSame($before['cash_flow'], $this->cashFlowSnapshot($this->cashFlow));

        $log = ActivityLog::query()
            ->where('action', ActivityLog::ACTION_RETURN_SALES_ATTRIBUTION_UPDATE)
            ->where('subject_id', $this->return->id)
            ->firstOrFail();
        $this->assertSame($this->return->code, $log->properties['return_code']);
        $this->assertSame($this->sellerA->id, $log->properties['original_seller']['employee_id']);
        $this->assertSame($this->sellerB->id, $log->properties['new']['employee_id']);
        $this->assertSame('Serial đã được bán lại bởi nhân viên này.', $log->properties['reason']);
        $this->assertFalse($log->properties['financial_mutation']);
        $this->assertFalse($log->properties['inventory_mutation']);
    }

    public function test_resolver_and_employee_report_filter_transfer_return_from_original_seller_to_override_employee(): void
    {
        $this->actingAs($this->admin)->patchJson(route('returns.update-sales-attribution', $this->return), [
            'sales_attribution_employee_id' => $this->sellerB->id,
            'reason' => 'Phân bổ doanh số trả hàng cho người bán lại.',
        ])->assertOk();

        $resolver = app(SellerResolver::class);
        $map = $resolver->returnSellerMap(OrderReturn::query()->whereKey($this->return->id));
        $this->assertSame("employee:{$this->sellerB->id}", $map[$this->return->id]);
        $this->assertSame(0, $resolver->filterReturnsBySeller(OrderReturn::query(), "employee:{$this->sellerA->id}")->count());
        $this->assertSame(1, $resolver->filterReturnsBySeller(OrderReturn::query(), "employee:{$this->sellerB->id}")->count());

        $response = $this->actingAs($this->admin)->get('/reports/employees?concern=sales&view=report');
        $response->assertOk();
        $rows = collect($response->viewData('page')['props']['reportRows']);
        $rowA = $rows->firstWhere('seller_key', "employee:{$this->sellerA->id}");
        $rowB = $rows->firstWhere('seller_key', "employee:{$this->sellerB->id}");
        $this->assertSame(0, (int) $rowA['returns']);
        $this->assertSame(100000, (int) $rowB['returns']);
        $this->assertSame(100000, (int) $rowA['revenue']);
        $this->assertSame(0, (int) $rowB['revenue']);
        $this->assertSame(100000, (int) $rowB['children'][0]['returns']);

        $profit = $this->actingAs($this->admin)->get('/reports/employees?concern=profit&view=report');
        $profit->assertOk();
        $profitRows = collect($profit->viewData('page')['props']['reportRows']);
        $profitA = $profitRows->firstWhere('seller_key', "employee:{$this->sellerA->id}");
        $profitB = $profitRows->firstWhere('seller_key', "employee:{$this->sellerB->id}");
        $this->assertSame(0, (int) $profitA['return_value']);
        $this->assertSame(100000, (int) $profitB['return_value']);
        $this->assertSame(40000, (int) $profitA['total_cogs']);
        $this->assertSame(-40000, (int) $profitB['total_cogs']);
        $fixtureProfitRows = collect([$profitA, $profitB]);
        $this->assertSame(100000, (int) $fixtureProfitRows->sum('revenue_after_discount'));
        $this->assertSame(100000, (int) $fixtureProfitRows->sum('return_value'));
    }

    public function test_reset_returns_to_original_seller_and_same_payload_does_not_create_a_second_activity_log(): void
    {
        $payload = [
            'sales_attribution_employee_id' => $this->sellerB->id,
            'reason' => 'Phân bổ doanh số trả hàng cho người bán lại.',
        ];
        $this->actingAs($this->admin)->patchJson(route('returns.update-sales-attribution', $this->return), $payload)->assertOk();
        $firstLogCount = ActivityLog::where('action', ActivityLog::ACTION_RETURN_SALES_ATTRIBUTION_UPDATE)->count();

        $this->actingAs($this->admin)->patchJson(route('returns.update-sales-attribution', $this->return), $payload)->assertOk();
        $this->assertSame($firstLogCount, ActivityLog::where('action', ActivityLog::ACTION_RETURN_SALES_ATTRIBUTION_UPDATE)->count());

        $this->actingAs($this->admin)->patchJson(route('returns.update-sales-attribution', $this->return), [
            'sales_attribution_employee_id' => null,
            'reason' => 'Khôi phục theo người bán hóa đơn gốc.',
        ])->assertOk()->assertJsonPath('return.is_sales_attribution_overridden', false);

        $this->return->refresh();
        $this->assertNull($this->return->sales_attribution_employee_id);
        $this->assertNull($this->return->sales_attribution_name);
        $this->assertSame("employee:{$this->sellerA->id}", app(SellerResolver::class)
            ->returnSellerMap(OrderReturn::query()->whereKey($this->return->id))[$this->return->id]);

        $rows = collect($this->actingAs($this->admin)
            ->get('/reports/employees?concern=sales&view=report')
            ->assertOk()
            ->viewData('page')['props']['reportRows']);
        $rowA = $rows->firstWhere('seller_key', "employee:{$this->sellerA->id}");
        $rowB = $rows->firstWhere('seller_key', "employee:{$this->sellerB->id}");
        $this->assertSame(100000, (int) $rowA['returns']);
        $this->assertTrue($rowB === null || (int) $rowB['returns'] === 0);
    }

    public function test_override_keeps_the_return_in_its_original_report_month(): void
    {
        $returnDate = Carbon::parse('2026-07-11 09:58:00');
        $this->return->forceFill(['created_at' => $returnDate, 'updated_at' => $returnDate])->save();

        $this->actingAs($this->admin)->patchJson(route('returns.update-sales-attribution', $this->return), [
            'sales_attribution_employee_id' => $this->sellerB->id,
            'reason' => 'Giu ky bao cao theo ngay phieu tra hang.',
        ])->assertOk();

        $rows = collect($this->actingAs($this->admin)
            ->get('/reports/employees?concern=sales&view=report&period=custom&date_from=2026-07-01&date_to=2026-07-31')
            ->assertOk()
            ->viewData('page')['props']['reportRows']);
        $rowB = $rows->firstWhere('seller_key', "employee:{$this->sellerB->id}");

        $this->assertSame(100000, (int) $rowB['returns']);
        $this->assertSame('2026-07-11', $rowB['children'][0]['date']);
    }

    public function test_inactive_cancelled_and_unauthorized_requests_are_rejected_without_mutation(): void
    {
        $inactive = $this->employee('Inactive seller', false);
        $original = $this->return->only(['sales_attribution_employee_id', 'sales_attribution_name', 'sales_attribution_reason']);

        $this->actingAs($this->admin)->patchJson(route('returns.update-sales-attribution', $this->return), [
            'sales_attribution_employee_id' => $inactive->id,
            'reason' => 'Không thể chọn nhân viên đã ngừng hoạt động.',
        ])->assertStatus(422)->assertJsonValidationErrors('sales_attribution_employee_id');
        $this->assertSame($original, $this->return->fresh()->only(array_keys($original)));

        $this->return->update(['status' => 'Đã hủy']);
        $this->actingAs($this->admin)->patchJson(route('returns.update-sales-attribution', $this->return), [
            'sales_attribution_employee_id' => $this->sellerB->id,
            'reason' => 'Không thể đổi phiếu trả đã hủy.',
        ])->assertStatus(422)->assertJsonValidationErrors('sales_attribution_employee_id');
        $this->assertSame($original, $this->return->fresh()->only(array_keys($original)));

        $role = Role::firstOrCreate(
            ['name' => 'return-attribution-viewer-'.uniqid()],
            ['display_name' => 'Return attribution viewer', 'permissions' => ['returns.view']],
        );
        $viewer = User::create([
            'name' => 'Return attribution viewer',
            'email' => 'return-attribution-viewer-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);
        $this->actingAs($viewer)->patchJson(route('returns.update-sales-attribution', $this->return), [
            'sales_attribution_employee_id' => $this->sellerB->id,
            'reason' => 'Không có quyền điều chỉnh doanh số trả hàng.',
        ])->assertForbidden();
    }

    public function test_reason_validation_is_vietnamese_and_snapshot_fallback_keeps_legacy_attribution(): void
    {
        $this->actingAs($this->admin)->patchJson(route('returns.update-sales-attribution', $this->return), [
            'sales_attribution_employee_id' => $this->sellerB->id,
            'reason' => 'ngắn',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('reason')
            ->assertJsonPath('errors.reason.0', 'Lý do điều chỉnh phải có ít nhất 5 ký tự.');

        $this->return->forceFill([
            'sales_attribution_employee_id' => null,
            'sales_attribution_name' => 'Người chịu doanh số legacy',
        ])->save();
        $map = app(SellerResolver::class)->returnSellerMap(OrderReturn::query()->whereKey($this->return->id));
        $this->assertSame('snapshot:Người chịu doanh số legacy', $map[$this->return->id]);
    }

    private function employee(string $name, bool $isActive = true): Employee
    {
        return Employee::create([
            'code' => 'NV-RETURN-ATTR-'.uniqid(),
            'name' => $name,
            'is_active' => $isActive,
        ]);
    }

    private function returnDocumentSnapshot(OrderReturn $return): array
    {
        $fresh = $return->fresh();
        $fields = [
            'invoice_id', 'customer_id', 'status', 'subtotal', 'total', 'paid_to_customer', 'created_at', 'updated_at',
        ];
        if (Schema::hasColumn('returns', 'return_date')) {
            $fields[] = 'return_date';
        }
        $snapshot = $fresh->only($fields);
        $snapshot['created_at'] = $fresh->created_at?->toISOString();
        $snapshot['updated_at'] = $fresh->updated_at?->toISOString();

        return $snapshot;
    }

    private function cashFlowSnapshot(CashFlow $cashFlow): array
    {
        $fresh = $cashFlow->fresh();
        $snapshot = $fresh->only([
            'type', 'amount', 'time', 'target_type', 'target_id', 'reference_type', 'reference_code', 'status',
        ]);
        $snapshot['time'] = $fresh->time?->toISOString();

        return $snapshot;
    }
}
