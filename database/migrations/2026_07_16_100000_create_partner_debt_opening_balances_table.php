<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::create('partner_debt_opening_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->string('role', 16);
                $table->unsignedInteger('version');
                $table->dateTime('cutoff_at', 6);
                $table->string('business_timezone', 64)->default('Asia/Ho_Chi_Minh');
                $table->decimal('amount', 15, 2);
                $table->string('source_document_uri', 500);
                $table->char('source_checksum', 64);
                $table->string('status', 24)->default('draft');
                $table->unsignedTinyInteger('active_guard')
                    ->nullable()
                    ->storedAs("CASE WHEN `status` = 'active' THEN 1 ELSE NULL END");
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->unsignedBigInteger('activated_by')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->dateTime('approved_at', 6)->nullable();
                $table->dateTime('activated_at', 6)->nullable();
                $table->dateTime('reversed_at', 6)->nullable();
                $table->dateTime('rejected_at', 6)->nullable();
                $table->unsignedBigInteger('approval_operation_id')->nullable();
                $table->unsignedBigInteger('activation_operation_id')->nullable();
                $table->unsignedBigInteger('reversal_operation_id')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('note')->nullable();
                $table->timestamps(6);

                $table->unique(
                    ['partner_id', 'role', 'cutoff_at', 'version'],
                    'pdob_partner_role_cutoff_version_uq'
                );
                $table->unique(
                    ['partner_id', 'role', 'source_checksum'],
                    'pdob_partner_role_checksum_uq'
                );
                $table->unique(
                    ['partner_id', 'role', 'active_guard'],
                    'pdob_partner_role_active_uq'
                );
                $table->index(['status', 'cutoff_at'], 'pdob_status_cutoff_idx');

                $table->foreign('partner_id', 'pdob_partner_fk')
                    ->references('id')->on('customers')->restrictOnDelete();
                $table->foreign('created_by', 'pdob_created_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('approved_by', 'pdob_approved_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('activated_by', 'pdob_activated_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('reversed_by', 'pdob_reversed_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('approval_operation_id', 'pdob_approval_operation_fk')
                    ->references('id')->on('partner_debt_operations')->restrictOnDelete();
                $table->foreign('activation_operation_id', 'pdob_activation_operation_fk')
                    ->references('id')->on('partner_debt_operations')->restrictOnDelete();
                $table->foreign('reversal_operation_id', 'pdob_reversal_operation_fk')
                    ->references('id')->on('partner_debt_operations')->restrictOnDelete();
            });

            $this->addChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('partner_debt_opening_balances');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_debt_opening_balances');
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_opening_balances`
            ADD CONSTRAINT `pdob_role_chk`
            CHECK (`role` IN ('customer', 'supplier'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_opening_balances`
            ADD CONSTRAINT `pdob_status_chk`
            CHECK (`status` IN ('draft', 'rejected', 'approved', 'active', 'reversed', 'void'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_opening_balances`
            ADD CONSTRAINT `pdob_version_chk`
            CHECK (`version` >= 1)
        SQL);
    }
};
