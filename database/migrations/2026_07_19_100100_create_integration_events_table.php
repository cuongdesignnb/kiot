<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->string('event_id', 64)->nullable();
            $table->string('event_type', 50);
            $table->string('external_order_id')->nullable()->index();
            $table->string('idempotency_key')->nullable();
            $table->char('payload_hash', 64);
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('received')->index();
            $table->unsignedInteger('attempt_count')->default(1);
            $table->string('last_error_code', 100)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'event_id'], 'integration_events_source_event_unique');
            $table->unique(['source', 'idempotency_key'], 'integration_events_source_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
