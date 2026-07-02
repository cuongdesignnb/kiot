<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 24.4A — Customer Groups master data + customers.created_by
 *
 * 1. Creates customer_groups table (KiotViet-style group management).
 * 2. Adds customers.created_by FK to track who created the customer.
 *
 * No backfill. Legacy customers.customer_group string stays intact.
 * Master groups are independent — 24.4B will optionally link them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. customer_groups master table ──
        if (!Schema::hasTable('customer_groups')) {
            Schema::create('customer_groups', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->nullable()->unique();
                $table->string('name')->unique();
                $table->string('discount_type', 20)->nullable(); // 'amount' | 'percent'
                $table->decimal('discount_value', 15, 2)->default(0);
                $table->text('note')->nullable();
                $table->text('description')->nullable();
                $table->json('conditions')->nullable(); // auto-assign conditions (config only in 24.4A)
                $table->string('update_mode', 30)->default('none'); // none | add_matching | refresh_matching
                $table->boolean('auto_update')->default(false);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // ── 2. customers.created_by ──
        if (!Schema::hasColumn('customers', 'created_by')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreignId('created_by')->nullable()->after('branch_id')
                    ->constrained('users')->nullOnDelete();
                $table->index('created_by');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_groups');

        if (Schema::hasColumn('customers', 'created_by')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            });
        }
    }
};
