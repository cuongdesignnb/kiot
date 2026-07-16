<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_allocation_reversals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('allocation_id');
            $table->decimal('amount', 15, 2);
            $table->string('idempotency_key', 191);
            $table->unsignedBigInteger('operation_id');
            $table->text('reason');
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at', 6);
            $table->timestamps(6);

            $table->unique('allocation_id', 'spar_allocation_uq');
            $table->unique('idempotency_key', 'spar_idempotency_uq');
            $table->index('operation_id', 'spar_operation_idx');
            $table->index(['reversed_at', 'id'], 'spar_reversed_at_idx');

            $table->foreign('allocation_id', 'spar_allocation_fk')
                ->references('id')->on('supplier_payment_allocations')->restrictOnDelete();
            $table->foreign('operation_id', 'spar_operation_fk')
                ->references('id')->on('partner_debt_operations')->restrictOnDelete();
            $table->foreign('reversed_by', 'spar_reversed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        try {
            $this->addChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('supplier_payment_allocation_reversals');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocation_reversals');
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `supplier_payment_allocation_reversals`
            ADD CONSTRAINT `spar_amount_positive_chk`
            CHECK (`amount` > 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `supplier_payment_allocation_reversals`
            ADD CONSTRAINT `spar_reason_nonempty_chk`
            CHECK (CHAR_LENGTH(TRIM(`reason`)) > 0)
        SQL);
    }
};
