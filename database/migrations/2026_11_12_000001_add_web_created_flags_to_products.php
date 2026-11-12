<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products the Website PC order import creates on the fly when an order line
 * carries a SKU that KIOT does not know yet. They start with stock 0 and no
 * cost price, so they are flagged "Chưa có giá vốn" (cost_pending) until a
 * purchase receipt or a cost price > 0 lands on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'created_from_web_at')) {
                $table->timestamp('created_from_web_at')->nullable();
            }
            if (! Schema::hasColumn('products', 'cost_pending')) {
                $table->boolean('cost_pending')->default(false)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'cost_pending')) {
                $table->dropIndex(['cost_pending']);
                $table->dropColumn('cost_pending');
            }
            if (Schema::hasColumn('products', 'created_from_web_at')) {
                $table->dropColumn('created_from_web_at');
            }
        });
    }
};
