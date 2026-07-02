<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'warranty_policy')) {
                $table->string('warranty_policy', 20)->nullable()->after('warranty_id')
                    ->comment('Step 23.8D: none | free_labor | free_parts | full_free');
            }
            if (!Schema::hasColumn('tasks', 'warranty_covered_amount')) {
                $table->decimal('warranty_covered_amount', 15, 2)->default(0)->after('debt_amount')
                    ->comment('Step 23.8D: số tiền được miễn theo chính sách bảo hành');
            }
            if (!Schema::hasColumn('tasks', 'customer_payable_amount')) {
                $table->decimal('customer_payable_amount', 15, 2)->default(0)->after('warranty_covered_amount')
                    ->comment('Step 23.8D: số tiền khách phải trả sau khi áp chính sách bảo hành');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            foreach (['warranty_policy', 'warranty_covered_amount', 'customer_payable_amount'] as $col) {
                if (Schema::hasColumn('tasks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
