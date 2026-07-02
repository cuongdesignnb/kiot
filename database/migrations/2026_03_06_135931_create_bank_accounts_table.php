<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_number');
            $table->string('bank_name');
            $table->string('account_holder');
            $table->enum('type', ['bank', 'ewallet'])->default('bank');
            $table->enum('scope', ['system', 'branch'])->default('system');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
