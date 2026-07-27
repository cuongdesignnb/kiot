<?php

namespace Tests\Feature\Products;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class P0ProductDeletionSafetyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_audit_deletions_command_is_registered_by_application_bootstrap(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey('products:audit-deletions', $commands);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'P0 Product Delete Admin',
            'email' => 'p0-product-delete-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'P0-DELETE-'.uniqid(),
            'name' => 'P0 product deletion safety',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 0,
            'is_active' => true,
        ], $attributes));
    }

    public function test_single_delete_is_blocked_when_product_has_stock_and_is_audited(): void
    {
        $admin = $this->admin();
        $product = $this->product(['stock_quantity' => 1]);

        $response = $this->actingAs($admin)
            ->withHeaders([
                'X-Request-ID' => 'req-p0-stock-blocked',
                'User-Agent' => 'P0DeletionSafetyTest/1.0',
            ])
            ->delete(route('products.destroy', $product));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Không thể xóa hàng hóa đã phát sinh tồn kho hoặc chứng từ. Hãy sử dụng chức năng Ngừng kinh doanh.');
        $this->assertNull($product->fresh()->deleted_at);

        $log = ActivityLog::where('action', ActivityLog::ACTION_PRODUCT_DELETE_BLOCKED)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('req-p0-stock-blocked', $log->properties['request_id']);
        $this->assertSame('products.destroy', $log->properties['route']);
        $this->assertSame('product_controller.destroy', $log->properties['source']);
        $this->assertSame([$product->id], $log->properties['product_ids']);
        $this->assertSame([$product->sku], $log->properties['product_skus']);
        $this->assertSame('blocked', $log->properties['result']);
        $this->assertContains('stock_quantity_nonzero', $log->properties['reasons'][(string) $product->id]);
    }

    public function test_single_delete_is_blocked_when_product_has_serial(): void
    {
        $admin = $this->admin();
        $product = $this->product(['has_serial' => true]);
        SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'P0-SERIAL-'.uniqid(),
            'status' => 'in_stock',
        ]);

        $this->actingAs($admin)->delete(route('products.destroy', $product))->assertRedirect();

        $this->assertNull($product->fresh()->deleted_at);
        $log = ActivityLog::where('action', ActivityLog::ACTION_PRODUCT_DELETE_BLOCKED)->latest('id')->firstOrFail();
        $this->assertContains('serial_count_nonzero', $log->properties['reasons'][(string) $product->id]);
    }

    public function test_bulk_delete_preflight_is_atomic_when_one_product_has_business_history(): void
    {
        $admin = $this->admin();
        $clean = $this->product();
        $withHistory = $this->product();
        DB::table('stock_movements')->insert([
            'product_id' => $withHistory->id,
            'type' => 'adjust_in',
            'qty' => 1,
            'direction' => 'in',
            'unit_cost' => 0,
            'total_cost' => 0,
            'balance_qty' => 1,
            'balance_cost' => 0,
            'moved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('products.bulk-destroy'), [
            'product_ids' => [$clean->id, $withHistory->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertNull($clean->fresh()->deleted_at);
        $this->assertNull($withHistory->fresh()->deleted_at);
    }

    public function test_clean_zero_stock_product_can_be_deleted_and_success_is_audited(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $this->actingAs($admin)->delete(route('products.destroy', $product))->assertRedirect();

        $this->assertNotNull($product->fresh()->deleted_at);
        $log = ActivityLog::where('action', ActivityLog::ACTION_PRODUCT_DELETE)->latest('id')->firstOrFail();
        $this->assertSame('success', $log->properties['result']);
        $this->assertSame([], $log->properties['reasons']);
    }

    public function test_audit_deletions_json_has_required_fields_and_performs_no_writes(): void
    {
        $product = $this->product(['stock_quantity' => 3]);
        $product->delete();
        $writes = [];

        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $exitCode = Artisan::call('products:audit-deletions', [
            '--from' => now()->subMinute()->toDateTimeString(),
            '--to' => now()->addMinute()->toDateTimeString(),
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $row = collect($payload)->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame([
            'id', 'sku', 'name', 'deleted_at', 'stock_quantity', 'serial_count',
            'purchase_count', 'invoice_count', 'order_count', 'movement_count',
            'has_business_history', 'restore_candidate', 'restore_reason',
        ], array_keys($row));
        $this->assertSame([], $writes);
    }
}
