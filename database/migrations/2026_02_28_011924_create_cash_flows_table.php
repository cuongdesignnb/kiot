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
        Schema::create('cash_flows', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g., PT0001 (Thu), PC0001 (Chi)
            $table->enum('type', ['receipt', 'payment']); // receipt = Thu, payment = Chi
            $table->decimal('amount', 15, 2);
            $table->string('reference_type')->nullable(); // e.g., 'Invoice' 
            $table->string('reference_code')->nullable(); // e.g., 'HD0001'
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_flows');
    }
};
