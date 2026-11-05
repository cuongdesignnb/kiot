<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->comment('Ten token, VD: Laptop Plus - Google Sheet');
            $table->string('account_label', 120)->nullable()->comment('Tai khoan/doi tac su dung token');
            $table->string('token_prefix', 16)->comment('Phan dau token de nhan dien, khong phai secret');
            $table->char('token_hash', 64)->unique()->comment('SHA-256 cua token, khong luu token goc');
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->unsignedBigInteger('request_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_api_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_api_token_id')->nullable()->constrained('inventory_api_tokens')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path', 191);
            $table->json('query')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->string('outcome', 32);
            $table->unsignedInteger('result_count')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['inventory_api_token_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_api_access_logs');
        Schema::dropIfExists('inventory_api_tokens');
    }
};
