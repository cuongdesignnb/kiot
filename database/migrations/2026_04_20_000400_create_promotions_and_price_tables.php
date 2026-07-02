<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->default('invoice_discount');
            // invoice_discount, product_discount, gift_item
            $table->string('status')->default('draft');
            // draft, active, expired, disabled
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();

            $table->string('condition_type')->default('none');
            // none, min_amount, min_qty
            $table->decimal('condition_value', 15, 2)->default(0);

            $table->string('discount_type')->default('percent');
            // percent, fixed
            $table->decimal('discount_value', 15, 2)->default(0);

            $table->unsignedBigInteger('target_product_id')->nullable();
            $table->unsignedBigInteger('gift_product_id')->nullable();

            $table->integer('max_usage')->nullable();
            $table->integer('usage_count')->default(0);

            $table->boolean('allow_stacking')->default(false);

            $table->json('branch_scope')->nullable();
            $table->json('customer_group_scope')->nullable();

            $table->text('note')->nullable();

            $table->foreign('target_product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('gift_product_id')->references('id')->on('products')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('discount_amount', 15, 2)->default(0);

            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('price_tables', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('inactive');
            // applied, inactive, expired
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();

            $table->string('formula_type')->default('fixed');
            // fixed, percent_base (e.g., base - 10%)
            $table->decimal('formula_value', 10, 2)->default(0);

            $table->boolean('auto_update_from_base')->default(false);
            $table->integer('rounding')->nullable();
            // null = no rounding, 100, 1000, etc.

            $table->boolean('restrict_items')->default(false);

            $table->json('branch_scope')->nullable();
            $table->json('customer_group_scope')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('price_table_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('price_table_id');
            $table->unsignedBigInteger('product_id');

            $table->decimal('base_price', 15, 2)->default(0);
            $table->decimal('table_price', 15, 2)->default(0);

            $table->foreign('price_table_id')->references('id')->on('price_tables')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->timestamps();
            $table->unique(['price_table_id', 'product_id']);
        });

        // Add promotion/price table refs to invoices and orders
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('promotion_id')->nullable()->after('order_id');
            $table->decimal('promotion_discount', 15, 2)->default(0)->after('promotion_id');
            $table->unsignedBigInteger('price_table_id')->nullable()->after('promotion_discount');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('promotion_id')->nullable()->after('status');
            $table->decimal('promotion_discount', 15, 2)->default(0)->after('promotion_id');
            $table->unsignedBigInteger('price_table_id')->nullable()->after('promotion_discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['promotion_id', 'promotion_discount', 'price_table_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['promotion_id', 'promotion_discount', 'price_table_id']);
        });
        Schema::dropIfExists('price_table_items');
        Schema::dropIfExists('price_tables');
        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('promotions');
    }
};
