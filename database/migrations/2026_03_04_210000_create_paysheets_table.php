<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paysheets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                // BL000001
            $table->string('name');                           // Bảng lương tháng 1/2026
            $table->string('pay_period')->default('monthly'); // monthly, biweekly
            $table->date('period_start');                     // 01/01/2026
            $table->date('period_end');                       // 31/01/2026
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope')->default('all');          // all, custom
            $table->string('status')->default('draft');       // draft, calculating, calculated, locked, cancelled
            $table->decimal('total_salary', 18, 0)->default(0);
            $table->decimal('total_paid', 18, 0)->default(0);
            $table->decimal('total_remaining', 18, 0)->default(0);
            $table->integer('employee_count')->default(0);
            $table->string('created_by')->nullable();
            $table->string('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                // PL000001
            $table->foreignId('paysheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_salary', 18, 0)->default(0);
            $table->decimal('allowances', 18, 0)->default(0);
            $table->decimal('deductions', 18, 0)->default(0);
            $table->decimal('ot_pay', 18, 0)->default(0);
            $table->decimal('total_salary', 18, 0)->default(0);
            $table->decimal('paid_amount', 18, 0)->default(0);
            $table->decimal('remaining', 18, 0)->default(0);
            $table->float('work_units')->default(0);
            $table->float('paid_leave_units')->default(0);
            $table->integer('ot_minutes')->default(0);
            $table->json('details')->nullable();              // breakdown chi tiết
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('paysheet_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paysheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 0)->default(0);
            $table->string('method')->default('cash');        // cash, bank_transfer
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paysheet_payments');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('paysheets');
    }
};
