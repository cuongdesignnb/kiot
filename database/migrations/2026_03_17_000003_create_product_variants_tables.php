<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add sort_order to existing product_attributes if missing
        if (Schema::hasTable('product_attributes') && !Schema::hasColumn('product_attributes', 'sort_order')) {
            Schema::table('product_attributes', function (Blueprint $table) {
                $table->integer('sort_order')->default(0)->after('name');
            });
        }

        // Add sort_order to existing product_attribute_values if missing
        if (Schema::hasTable('product_attribute_values') && !Schema::hasColumn('product_attribute_values', 'sort_order')) {
            Schema::table('product_attribute_values', function (Blueprint $table) {
                $table->integer('sort_order')->default(0)->after('value');
            });
        }

        // Bảng biến thể sản phẩm
        if (!Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('sku')->nullable()->unique();
                $table->string('barcode')->nullable();
                $table->string('name');
                $table->decimal('cost_price', 15, 2)->default(0);
                $table->decimal('retail_price', 15, 2)->default(0);
                $table->integer('stock_quantity')->default(0);
                $table->string('image')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Pivot: variant ↔ attribute_value
        if (!Schema::hasTable('product_variant_attribute_values')) {
            Schema::create('product_variant_attribute_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->foreignId('attribute_value_id')->constrained('product_attribute_values')->cascadeOnDelete();
                $table->unique(['variant_id', 'attribute_value_id'], 'variant_attr_val_unique');
            });
        }

        // Add has_variants flag to products
        if (!Schema::hasColumn('products', 'has_variants')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('has_variants')->default(false)->after('has_serial');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
        Schema::dropIfExists('product_variants');
        if (Schema::hasColumn('products', 'has_variants')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('has_variants');
            });
        }
    }
};
