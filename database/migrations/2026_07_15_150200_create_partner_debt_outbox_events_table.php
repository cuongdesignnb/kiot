<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_debt_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 36);
            $table->unsignedBigInteger('operation_id');
            $table->string('aggregate_type', 64);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 128);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->dateTime('occurred_at', 6);
            $table->dateTime('next_attempt_at', 6);
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('locked_at', 6)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->string('locked_by', 191)->nullable();
            $table->char('claim_token', 36)->nullable();
            $table->dateTime('published_at', 6)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->dateTime('dead_lettered_at', 6)->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps(6);

            $table->unique('event_uuid', 'pdoe_event_uuid_uq');
            $table->index(
                ['status', 'next_attempt_at', 'lease_expires_at', 'id'],
                'pdoe_due_claim_idx'
            );
            $table->index(['operation_id', 'event_type'], 'pdoe_operation_event_idx');
            $table->index(['published_at', 'id'], 'pdoe_published_idx');

            $table->foreign('operation_id', 'pdoe_operation_fk')
                ->references('id')->on('partner_debt_operations')->restrictOnDelete();
            $table->foreign('resolved_by', 'pdoe_resolved_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        try {
            $this->addMySqlChecks();
        } catch (Throwable $e) {
            Schema::dropIfExists('partner_debt_outbox_events');

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_debt_outbox_events');
    }

    private function addMySqlChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_outbox_events`
            ADD CONSTRAINT `pdoe_status_chk`
            CHECK (`status` IN (
                'pending',
                'publishing',
                'retry',
                'published',
                'dead_letter',
                'resolved'
            ))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `partner_debt_outbox_events`
            ADD CONSTRAINT `pdoe_schema_version_chk`
            CHECK (`schema_version` >= 1)
        SQL);
    }
};
