<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 50);
            $table->string('client_id', 100);
            $table->text('secret_encrypted')->nullable();
            $table->string('secret_fingerprint', 16)->nullable();
            $table->text('previous_secret_encrypted')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->foreignId('default_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('sales_channel')->default('Website PC');
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('timestamp_tolerance_seconds')->default(300);
            $table->unsignedInteger('nonce_ttl_seconds')->default(600);
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->unsignedInteger('reservation_ttl_minutes')->default(1440);
            $table->string('api_version', 20)->default('v1');
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_request_at')->nullable();
            $table->string('last_request_ip', 45)->nullable();
            $table->timestamp('secret_created_at')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'client_id'], 'integration_clients_provider_client_unique');
            $table->index(['provider', 'is_enabled'], 'integration_clients_provider_enabled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_clients');
    }
};
