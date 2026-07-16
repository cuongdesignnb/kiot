<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('supplier_id');
            $table->decimal('amount', 15, 2);
            $table->string('allocation_source', 16);
            $table->string('idempotency_key', 191);
            $table->unsignedBigInteger('operation_id');
            $table->dateTime('allocated_at', 6);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);

            $table->unique(['payment_id', 'purchase_id'], 'spa_payment_purchase_uq');
            $table->unique('idempotency_key', 'spa_idempotency_uq');
            $table->index(['supplier_id', 'purchase_id'], 'spa_supplier_purchase_idx');
            $table->index(['purchase_id', 'allocated_at', 'id'], 'spa_purchase_allocated_idx');
            $table->index('operation_id', 'spa_operation_idx');

            $table->foreign('payment_id', 'spa_payment_fk')
                ->references('id')->on('cash_flows')->restrictOnDelete();
            $table->foreign('purchase_id', 'spa_purchase_fk')
                ->references('id')->on('purchases')->restrictOnDelete();
            $table->foreign('supplier_id', 'spa_supplier_fk')
                ->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('operation_id', 'spa_operation_fk')
                ->references('id')->on('partner_debt_operations')->restrictOnDelete();
            $table->foreign('created_by', 'spa_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        try {
            $this->addChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('supplier_payment_allocations');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `supplier_payment_allocations`
            ADD CONSTRAINT `spa_amount_positive_chk`
            CHECK (`amount` > 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `supplier_payment_allocations`
            ADD CONSTRAINT `spa_source_chk`
            CHECK (`allocation_source` IN ('manual', 'auto'))
        SQL);
    }
};
