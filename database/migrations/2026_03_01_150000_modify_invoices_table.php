<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('branch_id')->nullable()->constrained()->onDelete('set null');
            $table->string('created_by_name')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('sales_channel')->default('Trực tiếp');
            $table->string('price_book_name')->default('Bảng giá chung');
            $table->string('status')->default('Hoàn thành'); // Đang xử lý, Hoàn thành, Không giao được, Đã hủy
            $table->decimal('other_fees', 15, 2)->default(0);
            $table->text('note')->nullable();

            // Giao hàng
            $table->boolean('is_delivery')->default(false);
            $table->string('delivery_partner')->nullable();
            $table->string('tracking_code')->nullable();
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('cod_amount', 15, 2)->default(0);
            $table->string('receiver_name')->nullable();
            $table->string('receiver_phone')->nullable();
            $table->string('receiver_address')->nullable();
            $table->string('receiver_ward')->nullable();
            $table->string('receiver_district')->nullable();
            $table->string('receiver_city')->nullable();
            $table->decimal('weight', 8, 2)->default(0);
            $table->string('length')->nullable();
            $table->string('width')->nullable();
            $table->string('height')->nullable();
            $table->string('delivery_service')->nullable();
            $table->timestamp('expected_delivery_date')->nullable();
            $table->text('delivery_note')->nullable();

            // Payment tracking
            $table->string('payment_method')->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn([
                'customer_id',
                'branch_id',
                'created_by_name',
                'seller_name',
                'sales_channel',
                'price_book_name',
                'status',
                'other_fees',
                'note',
                'is_delivery',
                'delivery_partner',
                'tracking_code',
                'delivery_fee',
                'cod_amount',
                'receiver_name',
                'receiver_phone',
                'receiver_address',
                'receiver_ward',
                'receiver_district',
                'receiver_city',
                'weight',
                'length',
                'width',
                'height',
                'delivery_service',
                'expected_delivery_date',
                'delivery_note',
                'payment_method'
            ]);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['discount', 'subtotal']);
        });
    }
};
