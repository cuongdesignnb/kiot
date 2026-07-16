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
            Schema::create('partner_debt_integrity_incidents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('partner_id');
                $table->string('role', 24);
                $table->string('status', 24)->default('open');
                $table->string('classification', 64);
                $table->string('severity', 16);
                $table->decimal('customer_difference', 15, 2)->default(0.00);
                $table->decimal('supplier_difference', 15, 2)->default(0.00);
                $table->char('fingerprint', 64);
                $table->json('evidence')->nullable();
                $table->dateTime('first_detected_at', 6);
                $table->dateTime('last_detected_at', 6);
                $table->unsignedInteger('occurrence_count')->default(1);
                $table->unsignedInteger('last_event_sequence')->default(0);
                $table->unsignedBigInteger('acknowledged_by')->nullable();
                $table->dateTime('acknowledged_at', 6)->nullable();
                $table->text('acknowledgment_note')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->dateTime('resolved_at', 6)->nullable();
                $table->text('resolution_note')->nullable();
                $table->unsignedBigInteger('suppressed_by')->nullable();
                $table->text('suppression_reason')->nullable();
                $table->dateTime('suppressed_until', 6)->nullable();
                $table->char('baseline_run_id', 36)->nullable();
                $table->dateTime('baseline_cutoff_at', 6)->nullable();
                $table->char('baseline_checksum', 64)->nullable();
                $table->timestamps(6);

                $table->unique(
                    ['partner_id', 'role', 'fingerprint'],
                    'pdii_partner_role_fingerprint_uq'
                );
                $table->index(
                    ['status', 'classification', 'last_detected_at'],
                    'pdii_status_classification_detected_idx'
                );
                $table->index(['partner_id', 'status'], 'pdii_partner_status_idx');
                $table->index(['status', 'suppressed_until'], 'pdii_status_suppressed_until_idx');

                $table->foreign('partner_id', 'pdii_partner_fk')
                    ->references('id')->on('customers')->restrictOnDelete();
                $table->foreign('acknowledged_by', 'pdii_acknowledged_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('resolved_by', 'pdii_resolved_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('suppressed_by', 'pdii_suppressed_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });

            $this->addChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('partner_debt_integrity_incidents');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_debt_integrity_incidents');
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incidents`
            ADD CONSTRAINT `pdii_role_chk`
            CHECK (`role` IN ('customer_only', 'supplier_only', 'dual_role'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incidents`
            ADD CONSTRAINT `pdii_status_chk`
            CHECK (`status` IN ('open', 'acknowledged', 'resolved', 'suppressed'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incidents`
            ADD CONSTRAINT `pdii_occurrence_chk`
            CHECK (`occurrence_count` >= 1)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incidents`
            ADD CONSTRAINT `pdii_detected_range_chk`
            CHECK (`first_detected_at` <= `last_detected_at`)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incidents`
            ADD CONSTRAINT `pdii_classification_nonempty_chk`
            CHECK (CHAR_LENGTH(TRIM(`classification`)) > 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incidents`
            ADD CONSTRAINT `pdii_severity_nonempty_chk`
            CHECK (CHAR_LENGTH(TRIM(`severity`)) > 0)
        SQL);
    }
};
