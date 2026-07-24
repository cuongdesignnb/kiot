<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_pairing_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_client_id')->constrained('integration_clients')->restrictOnDelete();
            $table->string('reference', 64)->unique();
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_by_ip', 45)->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['integration_client_id', 'expires_at'], 'pairing_tokens_client_expiry_index');
            $table->index(['expires_at', 'used_at'], 'pairing_tokens_expiry_used_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_pairing_tokens');
    }
};
