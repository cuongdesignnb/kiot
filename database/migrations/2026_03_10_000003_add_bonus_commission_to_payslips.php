<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('bonus', 18, 0)->default(0)->after('base_salary');
            $table->decimal('commission', 18, 0)->default(0)->after('bonus');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['bonus', 'commission']);
        });
    }
};
