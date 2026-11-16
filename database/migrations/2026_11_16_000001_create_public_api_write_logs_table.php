<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_api_write_logs')) {
            return;
        }

        // Nhat ky moi lan goi 2 chuc nang GHI cua API Kiot (sua gia ban tho,
        // tao hoa don). Ghi ca lan thanh cong lan that bai; khong bao gio xoa.
        Schema::create('public_api_write_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_api_token_id')->nullable()->constrained('inventory_api_tokens')->nullOnDelete();
            $table->string('action', 64)->comment('technician_price.update | invoice.create');
            $table->string('target_type', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_ref', 191)->nullable()->comment('SKU hoac ma hoa don');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable()->comment('NULL = dang xu ly');
            $table->json('response')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();

            $table->unique(['inventory_api_token_id', 'idempotency_key'], 'public_api_write_logs_token_key_unique');
            $table->index(['action', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_api_write_logs');
    }
};
