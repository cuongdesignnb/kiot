<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_debt_operation_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->unsignedBigInteger('partner_id');
            $table->string('participant_role', 32);
            $table->string('effect_role', 16)->nullable();
            $table->decimal('customer_delta', 15, 2)->nullable();
            $table->decimal('supplier_delta', 15, 2)->nullable();
            $table->timestamps(6);

            $table->unique(
                ['operation_id', 'partner_id', 'participant_role'],
                'pdop_op_partner_role_uq'
            );
            $table->index(['partner_id', 'operation_id'], 'pdop_partner_operation_idx');
            $table->index(['operation_id', 'effect_role'], 'pdop_operation_effect_idx');

            $table->foreign('operation_id', 'pdop_operation_fk')
                ->references('id')->on('partner_debt_operations')->restrictOnDelete();
            $table->foreign('partner_id', 'pdop_partner_fk')
                ->references('id')->on('customers')->restrictOnDelete();
        });

        try {
            $this->addMySqlChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('partner_debt_operation_participants');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_debt_operation_participants');
    }

    private function addMySqlChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_operation_participants`
            ADD CONSTRAINT `pdop_effect_shape_chk`
            CHECK (
                (
                    `effect_role` IS NULL
                    AND `customer_delta` IS NULL
                    AND `supplier_delta` IS NULL
                )
                OR
                (
                    `effect_role` IS NOT NULL
                    AND
                    (
                        (
                            `effect_role` = 'customer'
                            AND `customer_delta` IS NOT NULL
                            AND `supplier_delta` IS NULL
                        )
                        OR
                        (
                            `effect_role` = 'supplier'
                            AND `customer_delta` IS NULL
                            AND `supplier_delta` IS NOT NULL
                        )
                        OR
                        (
                            `effect_role` = 'both'
                            AND `customer_delta` IS NOT NULL
                            AND `supplier_delta` IS NOT NULL
                        )
                        OR
                        (
                            `effect_role` = 'none'
                            AND `customer_delta` = 0.00
                            AND `supplier_delta` = 0.00
                        )
                    )
                )
            )
        SQL);
    }
};
