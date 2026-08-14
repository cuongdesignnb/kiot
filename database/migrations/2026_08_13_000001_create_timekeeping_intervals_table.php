<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timekeeping_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timekeeping_record_id')
                ->constrained('timekeeping_records')
                ->cascadeOnDelete();
            $table->foreignId('employee_work_schedule_id')
                ->nullable()
                ->constrained('employee_work_schedules')
                ->nullOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->unsignedTinyInteger('slot')->default(1);
            $table->dateTime('scheduled_start_at')->nullable();
            $table->dateTime('scheduled_end_at')->nullable();
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->string('source')->default('device');
            $table->string('status')->default('complete');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index(['employee_id', 'work_date']);
            $table->index(['timekeeping_record_id', 'slot']);
        });

        Schema::table('timekeeping_records', function (Blueprint $table) {
            if (! Schema::hasColumn('timekeeping_records', 'regular_minutes')) {
                $table->unsignedInteger('regular_minutes')->nullable()->after('worked_minutes');
            }
            if (! Schema::hasColumn('timekeeping_records', 'needs_review')) {
                $table->boolean('needs_review')->default(false)->after('regular_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timekeeping_records', function (Blueprint $table) {
            if (Schema::hasColumn('timekeeping_records', 'needs_review')) {
                $table->dropColumn('needs_review');
            }
            if (Schema::hasColumn('timekeeping_records', 'regular_minutes')) {
                $table->dropColumn('regular_minutes');
            }
        });

        Schema::dropIfExists('timekeeping_intervals');
    }
};
