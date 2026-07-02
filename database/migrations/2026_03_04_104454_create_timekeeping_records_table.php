<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('timekeeping_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('employee_work_schedule_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->date('work_date');
            $table->string('slot')->nullable();                    // "morning" / "afternoon" / "full"
            $table->dateTime('scheduled_start_at')->nullable();    // Giờ bắt đầu ca
            $table->dateTime('scheduled_end_at')->nullable();      // Giờ kết thúc ca
            $table->dateTime('check_in_at')->nullable();           // Giờ vào thực tế
            $table->dateTime('check_out_at')->nullable();          // Giờ ra thực tế
            $table->string('source')->default('none');             // 'device' | 'manual' | 'none'
            $table->string('attendance_type')->default('work');    // 'work' | 'leave_paid' | 'leave_unpaid'
            $table->boolean('manual_override')->default(false);    // true = không bị ghi đè khi recalculate
            $table->integer('late_minutes')->default(0);           // Số phút đi muộn
            $table->integer('early_minutes')->default(0);          // Số phút về sớm
            $table->integer('ot_minutes')->default(0);             // Số phút tăng ca
            $table->integer('worked_minutes')->default(0);         // Tổng phút làm việc
            $table->decimal('work_units', 3, 1)->default(0);       // Số công (0.5 / 1.0)
            $table->boolean('is_holiday')->default(false);
            $table->decimal('holiday_multiplier', 3, 1)->default(1.0);
            $table->json('raw')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timekeeping_records');
    }
};
