<?php

namespace Tests\Feature\Supplier;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * REG-RR01-02: SupplierController dual-role invoice query thiếu lọc 'Đã hủy'.
 *
 * Vấn đề: SupplierController@debtTransactions dòng 331-333:
 *   $invoices = Invoice::where('customer_id', $id)
 *       ->orderBy('created_at', 'desc')
 *       ->get([...]);
 *
 * Query này KHÔNG lọc status != 'Đã hủy' → HĐ hủy tính vào sổ cái công nợ NCC.
 *
 * Route: GET /api/suppliers/{id}/debt-transactions
 * Method: SupplierController@debtTransactions
 *
 * Dữ liệu:
 *   - Dual-role entity: is_supplier = true, is_customer = true
 *   - Invoice hợp lệ: status = 'Hoàn thành', total = 1.000.000
 *   - Invoice đã hủy: status = 'Đã hủy', total = 9.000.000
 *
 * Kỳ vọng: ledger chỉ chứa 1 entry invoice (HĐ hợp lệ), supplier_effect = -1.000.000
 * Nếu sai: ledger chứa 2 entry invoice, supplier_effect tổng = -10.000.000
 */
class RR01SupplierDualRoleRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private function createAdmin(): User
    {
        return User::create([
            'name'     => 'Admin Test REG02',
            'email'    => 'admin-reg02-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function createDualRoleEntity(): Customer
    {
        return Customer::create([
            'code'                 => 'DR-REG02-' . uniqid(),
            'name'                 => 'Dual-role Partner REG02',
            'phone'                => '09' . rand(10000000, 99999999),
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'total_spent'          => 0,
            'total_bought'         => 0,
            'is_customer'          => true,
            'is_supplier'          => true,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  1. Dual-role debt ledger không nên chứa invoice đã hủy
     *
     *  Gọi API: GET /api/suppliers/{id}/debt-transactions
     *  Kiểm tra response JSON entries: chỉ HĐ hợp lệ, không có HĐ hủy.
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_supplier_debt_ledger_should_not_include_cancelled_invoices(): void
    {
        $admin = $this->createAdmin();
        $entity = $this->createDualRoleEntity();

        // Invoice hợp lệ
        $validInvoice = Invoice::create([
            'code'          => 'HD-DR-VALID-' . uniqid(),
            'subtotal'      => 1000000,
            'discount'      => 0,
            'total'         => 1000000,
            'customer_paid' => 1000000,
            'customer_id'   => $entity->id,
            'status'        => 'Hoàn thành',
            'created_at'    => now(),
        ]);

        // Invoice đã hủy
        $cancelledInvoice = Invoice::create([
            'code'          => 'HD-DR-CANCEL-' . uniqid(),
            'subtotal'      => 9000000,
            'discount'      => 0,
            'total'         => 9000000,
            'customer_paid' => 9000000,
            'customer_id'   => $entity->id,
            'status'        => 'Đã hủy',
            'created_at'    => now(),
        ]);

        // Gọi API debt-transactions
        $response = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$entity->id}/debt-transactions");

        $response->assertOk();
        $data = $response->json();

        // Tìm entries có type = 'sale' (invoice bán hàng cho dual-role)
        $saleEntries = collect($data['entries'] ?? $data)
            ->filter(fn($e) => ($e['type'] ?? '') === 'sale');

        // Kỳ vọng: chỉ có 1 entry sale (HĐ hợp lệ)
        $this->assertCount(
            1,
            $saleEntries,
            "Ledger NCC dual-role phải chỉ có 1 entry sale (HĐ hợp lệ), "
            . "thực tế: {$saleEntries->count()} entries. "
            . "HĐ Đã hủy đang bị tính vào sổ cái."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  2. supplier_effect tổng cho invoices phải loại trừ HĐ hủy
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_supplier_debt_ledger_effect_should_exclude_cancelled(): void
    {
        $admin = $this->createAdmin();
        $entity = $this->createDualRoleEntity();

        Invoice::create([
            'code'          => 'HD-DR-VALID2-' . uniqid(),
            'subtotal'      => 1000000,
            'discount'      => 0,
            'total'         => 1000000,
            'customer_paid' => 0,
            'customer_id'   => $entity->id,
            'status'        => 'Hoàn thành',
            'created_at'    => now(),
        ]);

        Invoice::create([
            'code'          => 'HD-DR-CANCEL2-' . uniqid(),
            'subtotal'      => 9000000,
            'discount'      => 0,
            'total'         => 9000000,
            'customer_paid' => 0,
            'customer_id'   => $entity->id,
            'status'        => 'Đã hủy',
            'created_at'    => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$entity->id}/debt-transactions");

        $response->assertOk();
        $data = $response->json();

        $saleEntries = collect($data['entries'] ?? $data)
            ->filter(fn($e) => ($e['type'] ?? '') === 'sale');

        $totalSupplierEffect = $saleEntries->sum('supplier_effect');

        // Kỳ vọng: chỉ -1.000.000 (từ HĐ hợp lệ)
        // Nếu sai: -10.000.000 (tính cả HĐ hủy)
        $this->assertEquals(
            -1000000.0,
            (float) $totalSupplierEffect,
            "supplier_effect tổng từ sale entries phải là -1.000.000, "
            . "thực tế: " . number_format($totalSupplierEffect)
            . ". HĐ Đã hủy đang ảnh hưởng công nợ NCC."
        );
    }
}
