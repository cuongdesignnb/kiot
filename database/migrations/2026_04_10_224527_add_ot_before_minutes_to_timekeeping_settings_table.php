<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('timekeeping_settings', 'ot_before_minutes')) return;
        Schema::table('timekeeping_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('ot_before_minutes')->default(1)->after('ot_after_minutes');
            // OT trước ca: tính làm thêm giờ khi nhân viên đến sớm >= X phút
        });
    }

    public function down(): void
    {
        Schema::table('timekeeping_settings', function (Blueprint $table) {
            $table->dropColumn('ot_before_minutes');
        });
    }
};
