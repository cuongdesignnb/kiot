<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\ActivityLog;
use App\Models\Warranty;
use App\Models\Product;
use App\Models\Category;
use Carbon\Carbon;

/**
 * STEP 24.0C — ActivityLog standardization + viewer.
 */
class Step240CActivityLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'role-' . uniqid(),
            'display_name' => 'Test',
            'permissions' => $permissions,
            'is_system' => false,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin240c'], [
            'display_name' => 'Admin 240C',
            'permissions' => ['*'],
            'is_system' => true,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    // ═══ TC-01 ═══

    public function test_activity_log_constants_have_labels_and_icons(): void
    {
        $newActions = [
            ActivityLog::ACTION_RETURN_CANCEL,
            ActivityLog::ACTION_PURCHASE_RETURN_CREATE,
            ActivityLog::ACTION_PURCHASE_RETURN_CANCEL,
            ActivityLog::ACTION_DAMAGE_CREATE,
            ActivityLog::ACTION_DAMAGE_CANCEL,
            ActivityLog::ACTION_WARRANTY_UPDATE,
            ActivityLog::ACTION_TASK_WARRANTY_ATTACH,
            ActivityLog::ACTION_CUSTOMER_DEBT_PAYMENT,
            ActivityLog::ACTION_CUSTOMER_DEBT_ADJUST,
            ActivityLog::ACTION_CUSTOMER_DEBT_OFFSET,
        ];

        foreach ($newActions as $action) {
            $this->assertArrayHasKey($action, ActivityLog::ACTION_LABELS, "Missing label for {$action}");
            $this->assertArrayHasKey($action, ActivityLog::ACTION_ICONS, "Missing icon for {$action}");
        }

        // Test accessor on instance
        $log = ActivityLog::create([
            'action' => ActivityLog::ACTION_RETURN_CANCEL,
            'description' => 'Test',
            'ip_address' => '127.0.0.1',
        ]);
        $this->assertEquals('Hủy phiếu trả hàng', $log->action_label);
        $this->assertEquals('🚫', $log->action_icon);
    }

    // ═══ TC-02 ═══

    public function test_activity_log_log_captures_user_agent_if_column_exists(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        // Simulate request với User-Agent header
        $this->withHeader('User-Agent', 'TestClient/1.0');
        // Tạo log qua một call route ghi log (warranty update)
        $cat = Category::firstOrCreate(['name' => 'C']);
        $product = Product::create([
            'sku' => 'P-' . uniqid(), 'name' => 'P',
            'cost_price' => 1000, 'retail_price' => 1500,
            'stock_quantity' => 0, 'inventory_total_cost' => 0,
            'has_serial' => false, 'category_id' => $cat->id,
        ]);
        $w = Warranty::create([
            'invoice_code' => 'HD-' . uniqid(),
            'product_id' => $product->id,
            'warranty_period' => 12,
            'purchase_date' => Carbon::now(),
            'warranty_end_date' => Carbon::now()->addYear(),
        ]);

        $this->put("/warranties/{$w->id}", [
            'maintenance_note' => 'Updated note',
        ])->assertRedirect();

        $log = ActivityLog::where('action', ActivityLog::ACTION_WARRANTY_UPDATE)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->user_agent);
        $this->assertStringContainsString('TestClient', $log->user_agent);
    }

    // ═══ TC-03 ═══

    public function test_activity_logs_index_requires_auth(): void
    {
        $res = $this->get('/activity-logs');
        // Guest middleware redirect to login, not 200
        $this->assertContains($res->status(), [302, 401]);
    }

    // ═══ TC-04 ═══

    public function test_user_without_system_audit_view_cannot_access_activity_logs(): void
    {
        $user = $this->userWith(['tasks.view']);
        $this->actingAs($user);
        $res = $this->get('/activity-logs');
        // Web routes thường redirect (302) khi 403 — middleware check
        $this->assertContains($res->status(), [302, 403]);
    }

    // ═══ TC-05 ═══

    public function test_user_with_system_audit_view_can_access_activity_logs(): void
    {
        $user = $this->userWith(['system.audit.view']);
        $this->actingAs($user);
        $res = $this->get('/activity-logs');
        $res->assertOk();
    }

    // ═══ TC-06 ═══

    public function test_activity_logs_index_filters_by_action(): void
    {
        $user = $this->userWith(['system.audit.view']);
        $this->actingAs($user);

        ActivityLog::create(['action' => ActivityLog::ACTION_INVOICE_CREATE, 'description' => 'A', 'ip_address' => '1.1.1.1']);
        ActivityLog::create(['action' => ActivityLog::ACTION_RETURN_CANCEL, 'description' => 'B', 'ip_address' => '1.1.1.1']);

        $res = $this->getJson('/api/activity-logs?action=' . ActivityLog::ACTION_RETURN_CANCEL);
        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(ActivityLog::ACTION_RETURN_CANCEL, $data[0]['action']);
    }

    // ═══ TC-07 ═══

    public function test_activity_logs_index_filters_by_date_range(): void
    {
        $user = $this->userWith(['system.audit.view']);
        $this->actingAs($user);

        $old = ActivityLog::create([
            'action' => ActivityLog::ACTION_INVOICE_CREATE,
            'description' => 'Old',
            'ip_address' => '1.1.1.1',
        ]);
        $old->created_at = Carbon::now()->subDays(10);
        $old->save();

        ActivityLog::create([
            'action' => ActivityLog::ACTION_INVOICE_CREATE,
            'description' => 'New',
            'ip_address' => '1.1.1.1',
        ]);

        $from = Carbon::now()->subDays(5)->toDateString();
        $res = $this->getJson("/api/activity-logs?from={$from}");
        $res->assertOk();
        $data = $res->json('data');
        $descriptions = collect($data)->pluck('description')->all();
        $this->assertContains('New', $descriptions);
        $this->assertNotContains('Old', $descriptions);
    }

    // ═══ TC-08 ═══

    public function test_activity_logs_index_searches_description(): void
    {
        $user = $this->userWith(['system.audit.view']);
        $this->actingAs($user);

        ActivityLog::create(['action' => ActivityLog::ACTION_INVOICE_CREATE, 'description' => 'Hủy hóa đơn HD-12345', 'ip_address' => '1.1.1.1']);
        ActivityLog::create(['action' => ActivityLog::ACTION_INVOICE_CREATE, 'description' => 'Tạo phiếu trả hàng', 'ip_address' => '1.1.1.1']);

        $res = $this->getJson('/api/activity-logs?search=HD-12345');
        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertStringContainsString('HD-12345', $data[0]['description']);
    }

    // ═══ TC-09 ═══

    public function test_warranty_update_writes_activity_log(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $cat = Category::firstOrCreate(['name' => 'C']);
        $product = Product::create([
            'sku' => 'P-' . uniqid(), 'name' => 'P',
            'cost_price' => 1000, 'retail_price' => 1500,
            'stock_quantity' => 0, 'inventory_total_cost' => 0,
            'has_serial' => false, 'category_id' => $cat->id,
        ]);
        $w = Warranty::create([
            'invoice_code' => 'HD-' . uniqid(),
            'product_id' => $product->id,
            'warranty_period' => 12,
            'purchase_date' => Carbon::now(),
            'warranty_end_date' => Carbon::now()->addYear(),
            'maintenance_note' => 'Old note',
        ]);

        $this->put("/warranties/{$w->id}", [
            'maintenance_note' => 'New note',
        ])->assertRedirect();

        $log = ActivityLog::where('action', ActivityLog::ACTION_WARRANTY_UPDATE)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($w->id, $log->subject_id);
        $this->assertContains('maintenance_note', $log->properties['changed_fields'] ?? []);
    }

    // ═══ TC-10 ═══

    public function test_activity_log_action_types_endpoint_returns_label_icon_map(): void
    {
        $user = $this->userWith(['system.audit.view']);
        $this->actingAs($user);

        $res = $this->getJson('/api/activity-logs/action-types');
        $res->assertOk();
        $data = $res->json();
        $this->assertArrayHasKey(ActivityLog::ACTION_INVOICE_CREATE, $data);
        $this->assertArrayHasKey('label', $data[ActivityLog::ACTION_INVOICE_CREATE]);
        $this->assertArrayHasKey('icon', $data[ActivityLog::ACTION_INVOICE_CREATE]);
        $this->assertEquals('Tạo hóa đơn', $data[ActivityLog::ACTION_INVOICE_CREATE]['label']);
    }
}
