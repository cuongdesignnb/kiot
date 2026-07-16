<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_debt_operations', function (Blueprint $table) {
            $table->id();
            $table->char('operation_uuid', 36);
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('operation_type', 64);
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('request_hash_version')->default(1);
            $table->string('status', 24)->default('pending');
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('reverses_operation_id')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('attempt_count')->default(1);
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->dateTime('initiated_at', 6);
            $table->dateTime('committed_at', 6)->nullable();
            $table->dateTime('failed_at', 6)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique('operation_uuid', 'pdo_operation_uuid_uq');
            $table->unique(['operation_type', 'idempotency_key'], 'pdo_type_idempotency_uq');
            $table->index(['partner_id', 'initiated_at'], 'pdo_partner_initiated_idx');
            $table->index(['source_type', 'source_id'], 'pdo_source_idx');
            $table->unique('reverses_operation_id', 'pdo_reverses_uq');

            $table->foreign('partner_id', 'pdo_partner_fk')
                ->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('reverses_operation_id', 'pdo_reverses_fk')
                ->references('id')->on('partner_debt_operations')->restrictOnDelete();
            $table->foreign('initiated_by', 'pdo_initiated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        try {
            $this->addMySqlChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('partner_debt_operations');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_debt_operations');
    }

    private function addMySqlChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_operations`
            ADD CONSTRAINT `pdo_status_chk`
            CHECK (`status` IN ('pending', 'committed', 'reversed', 'failed'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_operations`
            ADD CONSTRAINT `pdo_source_pair_chk`
            CHECK (
                (`source_type` IS NULL AND `source_id` IS NULL)
                OR
                (`source_type` IS NOT NULL AND `source_id` IS NOT NULL)
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_operations`
            ADD CONSTRAINT `pdo_attempt_chk`
            CHECK (`attempt_count` >= 1)
        SQL);
    }
};
