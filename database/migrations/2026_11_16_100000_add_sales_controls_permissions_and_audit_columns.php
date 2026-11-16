<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 10 đợt 4 — Kiot kiểm soát (items 30–32).
 *
 * 1. activity_logs gets `reason`, `document_type`, `document_code` so the
 *    edit/delete/cancel journal can be filtered by document and reason.
 * 2. The two new permissions are granted to EVERY existing role, so nothing
 *    changes at deploy. The shop then unticks them for the sales role.
 *    Users without a role and roles with '*' already pass every permission.
 * 3. The item-32 control settings are seeded ON (visible in Thiết lập).
 *    Existing rows are never overwritten.
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = ['cost_profit.view', 'invoices.modify_old'];

    private const SETTINGS = [
        ['key' => 'sales_require_seller', 'value' => '1', 'type' => 'boolean'],
        ['key' => 'sales_require_line_price', 'value' => '1', 'type' => 'boolean'],
        ['key' => 'purchase_require_line_cost', 'value' => '1', 'type' => 'boolean'],
        ['key' => 'sales_warn_below_cost', 'value' => '1', 'type' => 'boolean'],
        ['key' => 'invoice_old_lock_days', 'value' => '0', 'type' => 'number'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('activity_logs', 'reason')) {
                    $table->text('reason')->nullable();
                }
                if (! Schema::hasColumn('activity_logs', 'document_type')) {
                    $table->string('document_type', 40)->nullable()->index();
                }
                if (! Schema::hasColumn('activity_logs', 'document_code')) {
                    $table->string('document_code', 100)->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('roles')) {
            foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
                $permissions = json_decode((string) $role->permissions, true);
                $permissions = is_array($permissions) ? $permissions : [];
                if (in_array('*', $permissions, true)) {
                    continue;
                }
                $merged = array_values(array_unique([...$permissions, ...self::NEW_PERMISSIONS]));
                if ($merged !== $permissions) {
                    DB::table('roles')->where('id', $role->id)->update([
                        'permissions' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('settings')) {
            foreach (self::SETTINGS as $setting) {
                DB::table('settings')->insertOrIgnore(array_merge($setting, [
                    'group' => 'sales_controls',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                foreach (['document_code', 'document_type'] as $column) {
                    if (Schema::hasColumn('activity_logs', $column)) {
                        $table->dropIndex([$column]);
                    }
                }
            });
            Schema::table('activity_logs', function (Blueprint $table) {
                foreach (['reason', 'document_type', 'document_code'] as $column) {
                    if (Schema::hasColumn('activity_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        // Permissions and settings are left in place: removing them on
        // rollback could silently widen or narrow what a role may do.
    }
};
