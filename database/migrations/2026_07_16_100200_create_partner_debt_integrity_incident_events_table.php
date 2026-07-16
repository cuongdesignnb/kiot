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
            Schema::create('partner_debt_integrity_incident_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('incident_id');
                $table->char('event_uuid', 36);
                $table->char('dedup_key', 64);
                $table->char('detection_run_id', 36)->nullable();
                $table->unsignedBigInteger('source_operation_id')->nullable();
                $table->unsignedInteger('event_sequence');
                $table->string('event_type', 24);
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24)->nullable();
                $table->string('classification', 64);
                $table->char('fingerprint', 64);
                $table->json('snapshot');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->dateTime('occurred_at', 6);
                $table->json('metadata')->nullable();
                $table->timestamps(6);

                $table->unique('event_uuid', 'pdiie_event_uuid_uq');
                $table->unique('dedup_key', 'pdiie_dedup_key_uq');
                $table->unique(['incident_id', 'event_sequence'], 'pdiie_incident_sequence_uq');
                $table->index(
                    ['detection_run_id', 'incident_id'],
                    'pdiie_detection_run_incident_idx'
                );
                $table->index(['incident_id', 'occurred_at', 'id'], 'pdiie_incident_occurred_idx');
                $table->index(['event_type', 'occurred_at'], 'pdiie_event_type_occurred_idx');

                $table->foreign('incident_id', 'pdiie_incident_fk')
                    ->references('id')->on('partner_debt_integrity_incidents')->restrictOnDelete();
                $table->foreign('source_operation_id', 'pdiie_source_operation_fk')
                    ->references('id')->on('partner_debt_operations')->restrictOnDelete();
                $table->foreign('actor_id', 'pdiie_actor_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });

            $this->addChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('partner_debt_integrity_incident_events');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_debt_integrity_incident_events');
    }

    private function addChecks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incident_events`
            ADD CONSTRAINT `pdiie_event_sequence_chk`
            CHECK (`event_sequence` >= 1)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incident_events`
            ADD CONSTRAINT `pdiie_event_type_chk`
            CHECK (`event_type` IN (
                'detected',
                'redetected',
                'acknowledged',
                'resolved',
                'reopened',
                'suppressed',
                'unsuppressed'
            ))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incident_events`
            ADD CONSTRAINT `pdiie_from_status_chk`
            CHECK (
                `from_status` IS NULL
                OR `from_status` IN ('open', 'acknowledged', 'resolved', 'suppressed')
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incident_events`
            ADD CONSTRAINT `pdiie_to_status_chk`
            CHECK (
                `to_status` IS NULL
                OR `to_status` IN ('open', 'acknowledged', 'resolved', 'suppressed')
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incident_events`
            ADD CONSTRAINT `pdiie_detection_run_chk`
            CHECK (
                `event_type` NOT IN ('detected', 'redetected', 'reopened')
                OR `detection_run_id` IS NOT NULL
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_integrity_incident_events`
            ADD CONSTRAINT `pdiie_classification_nonempty_chk`
            CHECK (CHAR_LENGTH(TRIM(`classification`)) > 0)
        SQL);
    }
};
