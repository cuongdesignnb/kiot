<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_allocation_reversals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('allocation_id');
            $table->decimal('amount', 15, 2);
            $table->string('idempotency_key', 191);
            $table->unsignedBigInteger('operation_id');
            $table->text('reason');
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at', 6);
            $table->timestamps(6);

            $table->unique('allocation_id', 'cpar_allocation_uq');
            $table->unique('idempotency_key', 'cpar_idempotency_uq');
            $table->index('operation_id', 'cpar_operation_idx');
            $table->index(['reversed_at', 'id'], 'cpar_reversed_at_idx');

            $table->foreign('allocation_id', 'cpar_allocation_fk')
                ->references('id')->on('customer_payment_allocations')->restrictOnDelete();
            $table->foreign('operation_id', 'cpar_operation_fk')
                ->references('id')->on('partner_debt_operations')->restrictOnDelete();
            $table->foreign('reversed_by', 'cpar_reversed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        try {
            $this->addChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('customer_payment_allocation_reversals');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocation_reversals');
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `customer_payment_allocation_reversals`
            ADD CONSTRAINT `cpar_amount_positive_chk`
            CHECK (`amount` > 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `customer_payment_allocation_reversals`
            ADD CONSTRAINT `cpar_reason_nonempty_chk`
            CHECK (CHAR_LENGTH(TRIM(`reason`)) > 0)
        SQL);
    }
};
