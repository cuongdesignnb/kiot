<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add last_purchase_price to products
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('last_purchase_price', 15, 2)->default(0)->after('cost_price')->comment('Giá nhập cuối');
        });

        // Enhance price_books with KiotViet fields
        Schema::table('price_books', function (Blueprint $table) {
            $table->enum('status', ['active', 'inactive'])->default('active')->after('is_active')->comment('Trạng thái: Áp dụng / Chưa áp dụng');
            // Formula fields
            $table->string('formula_base')->nullable()->after('note')->comment('Cơ sở công thức: cost_price, retail_price, hoặc tên bảng giá khác');
            $table->string('formula_operator')->nullable()->after('formula_base')->comment('+ hoặc -');
            $table->decimal('formula_value', 15, 2)->default(0)->after('formula_operator');
            $table->boolean('formula_is_percent')->default(false)->after('formula_value');
            // Scope fields
            $table->enum('scope_branch', ['all', 'specific'])->default('all')->after('formula_is_percent');
            $table->json('branch_ids')->nullable()->after('scope_branch');
            $table->enum('scope_customer_group', ['all', 'specific'])->default('all')->after('branch_ids');
            $table->json('customer_group_ids')->nullable()->after('scope_customer_group');
            // Cashier rule
            $table->enum('cashier_rule', ['allow_add', 'only_in_book'])->default('allow_add')->after('customer_group_ids')->comment('allow_add=Cho thêm HH không có, only_in_book=Chỉ HH trong bảng giá');
            $table->boolean('cashier_warn_not_in_book')->default(false)->after('cashier_rule');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('last_purchase_price');
        });

        Schema::table('price_books', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'formula_base', 'formula_operator', 'formula_value',
                'formula_is_percent', 'scope_branch', 'branch_ids',
                'scope_customer_group', 'customer_group_ids',
                'cashier_rule', 'cashier_warn_not_in_book'
            ]);
        });
    }
};
