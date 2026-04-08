<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payslip_adjustments') && !Schema::hasColumn('payslip_adjustments', 'meta')) {
            Schema::table('payslip_adjustments', function (Blueprint $table) {
                $table->json('meta')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payslip_adjustments', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
