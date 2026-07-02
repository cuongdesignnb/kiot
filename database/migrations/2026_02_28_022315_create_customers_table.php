<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // KH000...
            $table->string('name');
            $table->string('phone')->nullable();
            $table->enum('type', ['individual', 'company'])->default('individual');
            $table->enum('gender', ['none', 'male', 'female'])->default('none');
            $table->string('email')->nullable();
            $table->string('facebook')->nullable();
            $table->text('address')->nullable();
            $table->date('birthday')->nullable();
            $table->text('note')->nullable();
            $table->string('tax_code')->nullable();

            // Tài chính (cơ bản)
            $table->decimal('debt_amount', 15, 2)->default(0); // Nợ hiện tại
            $table->decimal('total_spent', 15, 2)->default(0); // Tổng bán
            $table->decimal('total_returns', 15, 2)->default(0); // Trả hàng

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
