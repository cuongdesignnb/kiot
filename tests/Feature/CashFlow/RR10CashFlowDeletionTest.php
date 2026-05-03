<?php

namespace Tests\Feature\CashFlow;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-10: CashFlow bị xóa khi hủy chứng từ — mất dấu vết dòng tiền.
 *
 * Vấn đề: PurchaseController, OrderReturnController, PurchaseReturnController
 * gọi CashFlow::where(...)->delete() (soft-delete) khi hủy chứng từ nhưng
 * KHÔNG set status='cancelled' trước.
 *
 * Kết quả:
 *   - CashFlow bị trashed nhưng status giữ nguyên 'active'
 *   - withTrashed() query thấy record nhưng status != 'cancelled'
 *   - scopeActive() chỉ lọc theo status, KHÔNG lọc trashed
 *   → Nếu ai đó restore CashFlow, nó sẽ lại tính vào báo cáo
 *
 * CashFlowController@destroy đã đúng (set status='cancelled' + soft-delete).
 * InvoiceController@cancel (RR-01) đã sửa đúng.
 *
 * Các controller còn lại THIẾU set status='cancelled':
 *   - PurchaseController@destroy dòng 710-712
 *   - OrderReturnController@cancel dòng 389-391
 *   - PurchaseReturnController@cancel dòng 474-476
 */
class RR10CashFlowDeletionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR10',
            'email'    => 'admin-rr10-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR10-01: Hủy Purchase — CashFlow phải có status='cancelled'
     *
     *  PurchaseController@destroy dòng 710-712:
     *    CashFlow::where('reference_type', 'Purchase')
     *        ->where('reference_code', $purchase->code)
     *        ->delete();
     *  → Chỉ soft-delete, KHÔNG set status.
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cancel_purchase_cashflow_must_set_status_cancelled(): void
    {
        $refCode = 'PUR-RR10-' . uniqid();

        $cashFlow = CashFlow::create([
            'code'           => 'CF-PUR-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 3000000,
            'time'           => now(),
            'category'       => 'Mua hàng',
            'reference_type' => 'Purchase',
            'reference_code' => $refCode,
            'description'    => 'Test RR10-01',
            'status'         => 'active',
        ]);

        $cashFlowId = $cashFlow->id;

        // Simulate PurchaseController@destroy (dòng 710-712) — chỉ gọi delete()
        CashFlow::where('reference_type', 'Purchase')
            ->where('reference_code', $refCode)
            ->delete();

        // Verify record vẫn tồn tại (SoftDeletes)
        $trashed = CashFlow::withTrashed()->find($cashFlowId);
        $this->assertNotNull($trashed, 'CashFlow phải còn trong DB (SoftDeletes)');
        $this->assertNotNull($trashed->deleted_at, 'CashFlow phải bị soft-delete');

        // PHẢI có status='cancelled'
        $this->assertEquals(
            'cancelled',
            $trashed->status,
            "CashFlow Purchase phải có status='cancelled' sau khi hủy. "
            . "Hiện tại: status = '{$trashed->status}'. "
            . "PurchaseController chỉ gọi ->delete() mà KHÔNG set status='cancelled' trước."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR10-02: Hủy OrderReturn — CashFlow phải có status='cancelled'
     *
     *  OrderReturnController@cancel dòng 389-391:
     *    CashFlow::where('reference_type', 'OrderReturn')
     *        ->where('reference_code', $return->code)
     *        ->delete();
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cancel_order_return_cashflow_must_set_status_cancelled(): void
    {
        $refCode = 'TH-RR10-' . uniqid();

        $cashFlow = CashFlow::create([
            'code'           => 'CF-OR-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 500000,
            'time'           => now(),
            'category'       => 'Trả hàng khách',
            'reference_type' => 'OrderReturn',
            'reference_code' => $refCode,
            'description'    => 'Test RR10-02',
            'status'         => 'active',
        ]);

        $cashFlowId = $cashFlow->id;

        // Simulate OrderReturnController@cancel (dòng 389-391)
        CashFlow::where('reference_type', 'OrderReturn')
            ->where('reference_code', $refCode)
            ->delete();

        $trashed = CashFlow::withTrashed()->find($cashFlowId);
        $this->assertNotNull($trashed, 'CashFlow phải còn trong DB (SoftDeletes)');

        $this->assertEquals(
            'cancelled',
            $trashed->status,
            "CashFlow OrderReturn phải có status='cancelled' sau khi hủy. "
            . "Hiện tại: status = '{$trashed->status}'. "
            . "OrderReturnController chỉ gọi ->delete() mà KHÔNG set status='cancelled'."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR10-03: Hủy PurchaseReturn — CashFlow phải có status='cancelled'
     *
     *  PurchaseReturnController@cancel dòng 474-476:
     *    CashFlow::where('reference_type', 'PurchaseReturn')
     *        ->where('reference_code', $purchaseReturn->code)
     *        ->delete();
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cancel_purchase_return_cashflow_must_set_status_cancelled(): void
    {
        $refCode = 'TN-RR10-' . uniqid();

        $cashFlow = CashFlow::create([
            'code'           => 'CF-PR-' . uniqid(),
            'type'           => 'receipt',
            'amount'         => 800000,
            'time'           => now(),
            'category'       => 'Trả hàng NCC',
            'reference_type' => 'PurchaseReturn',
            'reference_code' => $refCode,
            'description'    => 'Test RR10-03',
            'status'         => 'active',
        ]);

        $cashFlowId = $cashFlow->id;

        // Simulate PurchaseReturnController@cancel (dòng 474-476)
        CashFlow::where('reference_type', 'PurchaseReturn')
            ->where('reference_code', $refCode)
            ->delete();

        $trashed = CashFlow::withTrashed()->find($cashFlowId);
        $this->assertNotNull($trashed, 'CashFlow phải còn trong DB (SoftDeletes)');

        $this->assertEquals(
            'cancelled',
            $trashed->status,
            "CashFlow PurchaseReturn phải có status='cancelled' sau khi hủy. "
            . "Hiện tại: status = '{$trashed->status}'. "
            . "PurchaseReturnController chỉ gọi ->delete() mà KHÔNG set status='cancelled'."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR10-04: scopeActive() phải lọc đúng — không tính soft-deleted
     *  CashFlow có status vẫn 'active' (bug pattern)
     *
     *  Khi CashFlow bị soft-delete mà status giữ nguyên 'active',
     *  scopeActive() chỉ lọc status nên SẼ tính nhầm nếu ai đó
     *  dùng withTrashed().active().
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_scope_active_should_not_include_soft_deleted_without_status(): void
    {
        // CashFlow active
        $cf1 = CashFlow::create([
            'code'           => 'CF-RR10-ACT-' . uniqid(),
            'type'           => 'receipt',
            'amount'         => 1000000,
            'time'           => now(),
            'category'       => 'Thu tiền',
            'reference_type' => 'Test',
            'reference_code' => 'TEST-RR10-SCOPE',
            'description'    => 'Active CashFlow',
            'status'         => 'active',
        ]);

        // CashFlow soft-deleted nhưng status vẫn active (bug pattern khi chỉ gọi delete())
        $cf2 = CashFlow::create([
            'code'           => 'CF-RR10-DEL-' . uniqid(),
            'type'           => 'receipt',
            'amount'         => 9000000,
            'time'           => now(),
            'category'       => 'Thu tiền',
            'reference_type' => 'Test',
            'reference_code' => 'TEST-RR10-SCOPE',
            'description'    => 'Soft-deleted but status active',
            'status'         => 'active',
        ]);

        // Soft-delete cf2 WITHOUT status cancelled (simulates current bug)
        $cf2->delete();

        // Normal query hides trashed — OK
        $this->assertNull(CashFlow::find($cf2->id));

        // BUT withTrashed().active() would include cf2 because status is still 'active'
        $withTrashedActive = CashFlow::withTrashed()->active()
            ->where('reference_type', 'Test')
            ->where('reference_code', 'TEST-RR10-SCOPE')
            ->sum('amount');

        // Đây là lỗi tiềm ẩn: withTrashed + active lấy cả cf2 vì status vẫn 'active'
        $this->assertEquals(
            1000000,
            (float) $withTrashedActive,
            "withTrashed()->active() phải chỉ trả 1.000.000 (cf1). "
            . "Nếu = 10.000.000 thì CashFlow bị trashed nhưng status vẫn 'active' "
            . "→ cần set status='cancelled' trước khi soft-delete. "
            . "Thực tế: " . number_format($withTrashedActive)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR10-05: CashFlowController@destroy đã đúng — regression guard
     *
     *  CashFlowController@destroy (dòng 189-190):
     *    $cashFlow->update(['status' => 'cancelled']);
     *    $cashFlow->delete(); // soft-delete
     *  → Chuẩn.
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cashflow_controller_destroy_sets_cancelled_status(): void
    {
        $cashFlow = CashFlow::create([
            'code'           => 'CF-RR10-CTR-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 500000,
            'time'           => now(),
            'category'       => 'Chi phí',
            'reference_type' => 'Manual',
            'reference_code' => 'MANUAL-RR10',
            'description'    => 'Test RR10-05 controller destroy',
            'status'         => 'active',
        ]);

        $this->actingAs($this->admin)
             ->delete(route('cash_flows.destroy', $cashFlow->id));

        $trashed = CashFlow::withTrashed()->find($cashFlow->id);
        $this->assertNotNull($trashed, 'CashFlow phải còn sau destroy (SoftDeletes)');
        $this->assertNotNull($trashed->deleted_at, 'CashFlow phải bị soft-delete');
        $this->assertEquals(
            'cancelled',
            $trashed->status,
            "CashFlowController@destroy phải set status='cancelled'"
        );
    }
}
