<?php

namespace Tests\Feature\Invoices;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.3C — Invoice Cancel Override Reason Modal.
 *
 * Locks in the time-lock + override-reason rules that the new cancel modal
 * must respect.  The frontend now collects time_lock_override_reason via a
 * proper modal instead of native window.confirm; backend behaviour is
 * unchanged but covered here to prevent regression.
 */
class Step243CInvoiceCancelOverrideModalTest extends TestCase
{
    use DatabaseTransactions;

    private function userWith(array $perms): User
    {
        $role = Role::create([
            'name'         => 'role243c-' . uniqid(),
            'display_name' => 'Test 24.3C',
            'permissions'  => $perms,
            'is_system'    => false,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'sku'                  => 'P243C-' . uniqid(),
            'name'                 => 'Product 24.3C',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 1000000,
            'is_active'            => true,
            'has_serial'           => false,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'code'        => 'KH243C-' . uniqid(),
            'name'        => 'KH 24.3C ' . uniqid(),
            'phone'       => '0904' . rand(100000, 999999),
            'debt_amount' => 0,
            'total_spent' => 0,
            'is_customer' => true,
        ]);
    }

    private function sellInvoice(User $admin, Customer $customer, Product $product, int $qty, float $price): Invoice
    {
        $this->actingAs($admin)->post(route('invoices.store'), [
            'customer_id'    => $customer->id,
            'subtotal'       => $qty * $price,
            'discount'       => 0,
            'total'          => $qty * $price,
            'customer_paid'  => 0,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'discount'   => 0,
            ]],
        ])->assertSessionDoesntHaveErrors();
        return Invoice::where('customer_id', $customer->id)->latest('id')->first();
    }

    /**
     * Force an invoice into "old" territory by backdating its lock_started_at
     * (and created_at as a fallback) past the order_change_time threshold.
     */
    private function ageInvoice(Invoice $invoice, int $hoursOld = 48): void
    {
        $past = Carbon::now()->subHours($hoursOld);
        $invoice->lock_started_at = $past;
        $invoice->created_at = $past;
        $invoice->save();
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-01: recent invoice — cancel succeeds without override reason
    // ─────────────────────────────────────────────────────────────────
    public function test_cancel_recent_invoice_does_not_require_override_reason(): void
    {
        Setting::set('order_change_time', 24);
        $admin = $this->userWith(['*']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $invoice = $this->sellInvoice($admin, $customer, $product, 1, 200000);

        $this->actingAs($admin)
            ->delete(route('invoices.destroy', $invoice->id))
            ->assertRedirect();

        $this->assertEquals('Đã hủy', $invoice->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-02: old invoice, user has cancel but NOT override — blocked
    // ─────────────────────────────────────────────────────────────────
    public function test_cancel_old_invoice_without_override_permission_is_blocked(): void
    {
        Setting::set('order_change_time', 24);

        $admin = $this->userWith(['*']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $invoice = $this->sellInvoice($admin, $customer, $product, 1, 200000);
        $this->ageInvoice($invoice, 48);

        // User without override permission.
        $user = $this->userWith(['invoices.view', 'invoices.cancel']);

        $this->actingAs($user)
            ->delete(route('invoices.destroy', $invoice->id), [
                'time_lock_override_reason' => 'Lý do hợp lệ override',
            ]);

        $this->assertNotEquals('Đã hủy', $invoice->fresh()->status, 'Invoice must remain un-cancelled when user lacks override permission.');
        // Verify the block came from the time-lock guard (controller), not the route middleware.
        $err = session()->get('error') ?? '';
        $this->assertStringContainsString('quyền override', $err, "Expected time-lock override error, got: {$err}");
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-03: old invoice, user has override but no reason — blocked
    // ─────────────────────────────────────────────────────────────────
    public function test_cancel_old_invoice_with_override_permission_requires_reason(): void
    {
        Setting::set('order_change_time', 24);

        $admin = $this->userWith(['*']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $invoice = $this->sellInvoice($admin, $customer, $product, 1, 200000);
        $this->ageInvoice($invoice, 48);

        $user = $this->userWith(['invoices.view', 'invoices.cancel', 'invoices.override_time_lock']);

        $this->actingAs($user)
            ->delete(route('invoices.destroy', $invoice->id))
            ->assertRedirect();

        $this->assertNotEquals('Đã hủy', $invoice->fresh()->status, 'Invoice must remain un-cancelled when reason is missing.');
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-04: old invoice + override permission + valid reason — succeeds
    // ─────────────────────────────────────────────────────────────────
    public function test_cancel_old_invoice_with_override_permission_and_reason_succeeds(): void
    {
        Setting::set('order_change_time', 24);

        $admin = $this->userWith(['*']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $invoice = $this->sellInvoice($admin, $customer, $product, 1, 200000);
        $this->ageInvoice($invoice, 48);

        $user = $this->userWith(['invoices.view', 'invoices.cancel', 'invoices.override_time_lock']);

        $this->actingAs($user)
            ->delete(route('invoices.destroy', $invoice->id), [
                'time_lock_override_reason' => 'Khách đến nhận hàng nhưng phát hiện sai sản phẩm — quá hạn nên cần override',
            ])
            ->assertRedirect();

        $this->assertEquals('Đã hủy', $invoice->fresh()->status);

        // ActivityLog override row must include the reason.
        $logged = ActivityLog::where('action', ActivityLog::ACTION_INVOICE_CANCEL_TIME_LOCK_OVERRIDE)
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($logged, 'Override cancel must produce an audit log row.');
        $this->assertNotEmpty($logged->properties['reason'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-05: reason shorter than 5 chars — blocked
    // ─────────────────────────────────────────────────────────────────
    public function test_cancel_override_reason_min_5_chars(): void
    {
        Setting::set('order_change_time', 24);

        $admin = $this->userWith(['*']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $invoice = $this->sellInvoice($admin, $customer, $product, 1, 200000);
        $this->ageInvoice($invoice, 48);

        $user = $this->userWith(['invoices.view', 'invoices.cancel', 'invoices.override_time_lock']);

        $this->actingAs($user)
            ->delete(route('invoices.destroy', $invoice->id), [
                'time_lock_override_reason' => 'abc',
            ]);

        $this->assertNotEquals('Đã hủy', $invoice->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-06: index() exposes cancel-policy props for the modal to read
    // ─────────────────────────────────────────────────────────────────
    public function test_invoices_index_exposes_cancel_policy_fields(): void
    {
        Setting::set('order_change_time', 24);

        $admin = $this->userWith(['*']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $recent = $this->sellInvoice($admin, $customer, $product, 1, 100000);
        $old = $this->sellInvoice($admin, $customer, $product, 1, 100000);
        $this->ageInvoice($old, 48);

        $res = $this->actingAs($admin)->get('/invoices');
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p
            ->where('invoices.data', function ($rows) use ($recent, $old) {
                $byId = collect($rows)->keyBy('id');
                $r = $byId[$recent->id] ?? null;
                $o = $byId[$old->id] ?? null;
                if (!$r || !$o) return false;
                if ($r['is_time_locked'] !== false) return false;
                if ($r['cancel_block_reason'] !== null) return false;
                if ($o['is_time_locked'] !== true) return false;
                // Admin has wildcard so override is allowed → no block reason on old either.
                if (!array_key_exists('requires_override_reason', $o)) return false;
                if (!$o['requires_override_reason']) return false;
                return true;
            })
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-07: e-invoice block applies even if override reason is supplied
    // (only meaningful when invoices.einvoice_code column exists)
    // ─────────────────────────────────────────────────────────────────
    public function test_einvoice_block_prevents_cancel_even_with_override_reason(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'einvoice_code')) {
            $this->markTestSkipped('einvoice_code column not present in this schema.');
        }

        Setting::set('order_change_time', 24);
        Setting::set('block_edit_cancel_einvoice', true);

        $admin = $this->userWith(['*']);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $invoice = $this->sellInvoice($admin, $customer, $product, 1, 200000);
        $invoice->einvoice_code = 'EI-' . uniqid();
        $invoice->save();

        $this->actingAs($admin)
            ->delete(route('invoices.destroy', $invoice->id), [
                'time_lock_override_reason' => 'Cố tình override để test guard',
            ]);

        $this->assertNotEquals('Đã hủy', $invoice->fresh()->status, 'E-invoice guard must take precedence over override reason.');

        // Restore setting for other tests.
        Setting::set('block_edit_cancel_einvoice', false);
    }
}
