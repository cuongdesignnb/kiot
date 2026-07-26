<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_clients', function (Blueprint $table) {
            $table->foreignId('pc_product_price_book_id')
                ->nullable()
                ->after('default_branch_id')
                ->constrained('price_books')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integration_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pc_product_price_book_id');
        });
    }
};
