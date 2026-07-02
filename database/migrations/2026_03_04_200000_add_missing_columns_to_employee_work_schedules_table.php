<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_work_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_work_schedules', 'shift_id')) {
                $table->unsignedBigInteger('shift_id')->nullable()->after('branch_id');
            }
            if (!Schema::hasColumn('employee_work_schedules', 'slot')) {
                $table->unsignedTinyInteger('slot')->default(1)->after('work_date');
            }
            if (!Schema::hasColumn('employee_work_schedules', 'start_time')) {
                $table->string('start_time', 10)->nullable()->after('slot');
            }
            if (!Schema::hasColumn('employee_work_schedules', 'end_time')) {
                $table->string('end_time', 10)->nullable()->after('start_time');
            }
            if (!Schema::hasColumn('employee_work_schedules', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_work_schedules', function (Blueprint $table) {
            $table->dropColumn(['shift_id', 'slot', 'start_time', 'end_time', 'notes']);
        });
    }
};
