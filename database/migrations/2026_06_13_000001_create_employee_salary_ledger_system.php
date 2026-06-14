<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('salary_balance_cache', 18, 0)->default(0)->after('balance');
            $table->timestamp('salary_balance_calculated_at')->nullable()->after('salary_balance_cache');
        });

        Schema::table('paysheets', function (Blueprint $table) {
            $table->string('payment_status')->default('unpaid')->after('status');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->string('payment_status')->default('unpaid')->after('remaining');
            $table->decimal('applied_advance', 18, 0)->default(0)->after('paid_amount');
        });

        Schema::table('paysheet_payments', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('id');
            $table->string('status')->default('active')->after('amount');
            $table->foreignId('cash_flow_id')->nullable()->after('status')->constrained('cash_flows')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancel_reason')->nullable()->after('cancelled_at');
            $table->string('idempotency_key')->nullable()->unique()->after('cancel_reason');
        });

        Schema::table('cash_flows', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancel_reason')->nullable()->after('cancelled_at');
        });

        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 0);
            $table->decimal('applied_amount', 18, 0)->default(0);
            $table->decimal('remaining_amount', 18, 0);
            $table->timestamp('advance_date');
            $table->string('payment_method');
            $table->string('status')->default('active');
            $table->text('note');
            $table->foreignId('cash_flow_id')->nullable()->constrained('cash_flows')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'advance_date']);
        });

        Schema::create('salary_advance_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('paysheet_id')->constrained()->restrictOnDelete();
            $table->foreignId('payslip_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 0);
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['salary_advance_id', 'payslip_id']);
        });

        Schema::create('employee_salary_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paysheet_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('original_entry_id')->nullable()->constrained('employee_salary_ledger_entries')->restrictOnDelete();
            $table->string('code');
            $table->string('type');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('amount', 18, 0);
            $table->decimal('balance_after', 18, 0)->default(0);
            $table->boolean('is_effective')->default(true);
            $table->string('status')->default('valid');
            $table->timestamp('event_at');
            $table->string('payment_method')->nullable();
            $table->text('note')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['employee_id', 'is_effective', 'event_at', 'id'], 'salary_ledger_employee_effective_event_idx');
            $table->index(['reference_type', 'reference_id', 'type'], 'salary_ledger_reference_type_idx');
            $table->unique(['original_entry_id', 'type'], 'salary_ledger_single_reversal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_ledger_entries');
        Schema::dropIfExists('salary_advance_applications');
        Schema::dropIfExists('salary_advances');

        Schema::table('cash_flows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });

        Schema::table('paysheet_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_flow_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropUnique(['code']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['code', 'status', 'cancelled_at', 'cancel_reason', 'idempotency_key']);
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'applied_advance']);
        });

        Schema::table('paysheets', function (Blueprint $table) {
            $table->dropColumn('payment_status');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['salary_balance_cache', 'salary_balance_calculated_at']);
        });
    }
};
