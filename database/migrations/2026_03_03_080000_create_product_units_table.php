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
        if (Schema::hasTable('product_units'))
            return;
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('unit_name');
            $table->decimal('conversion_rate', 15, 4)->default(1);
            $table->decimal('retail_price', 15, 2)->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->boolean('is_base_unit')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
