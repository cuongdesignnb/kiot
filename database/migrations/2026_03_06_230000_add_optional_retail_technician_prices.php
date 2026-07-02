<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('price_books', 'enable_retail_price') || !Schema::hasColumn('price_books', 'enable_technician_price')) {
            $hasAnchor = Schema::hasColumn('price_books', 'cashier_warn_not_in_book');

            Schema::table('price_books', function (Blueprint $table) use ($hasAnchor) {
                if (!Schema::hasColumn('price_books', 'enable_retail_price')) {
                    $column = $table->boolean('enable_retail_price')->default(false);
                    if ($hasAnchor) {
                        $column->after('cashier_warn_not_in_book');
                    }
                }

                if (!Schema::hasColumn('price_books', 'enable_technician_price')) {
                    $column = $table->boolean('enable_technician_price')->default(false);
                    if (Schema::hasColumn('price_books', 'enable_retail_price')) {
                        $column->after('enable_retail_price');
                    }
                }
            });
        }

        if (!Schema::hasColumn('price_book_products', 'retail_price') || !Schema::hasColumn('price_book_products', 'technician_price')) {
            Schema::table('price_book_products', function (Blueprint $table) {
                if (!Schema::hasColumn('price_book_products', 'retail_price')) {
                    $column = $table->decimal('retail_price', 15, 2)->nullable();
                    if (Schema::hasColumn('price_book_products', 'price')) {
                        $column->after('price');
                    }
                }

                if (!Schema::hasColumn('price_book_products', 'technician_price')) {
                    $column = $table->decimal('technician_price', 15, 2)->nullable();
                    if (Schema::hasColumn('price_book_products', 'retail_price')) {
                        $column->after('retail_price');
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('price_book_products', 'retail_price') || Schema::hasColumn('price_book_products', 'technician_price')) {
            Schema::table('price_book_products', function (Blueprint $table) {
                $dropColumns = [];
                if (Schema::hasColumn('price_book_products', 'retail_price')) {
                    $dropColumns[] = 'retail_price';
                }
                if (Schema::hasColumn('price_book_products', 'technician_price')) {
                    $dropColumns[] = 'technician_price';
                }
                if (!empty($dropColumns)) {
                    $table->dropColumn($dropColumns);
                }
            });
        }

        if (Schema::hasColumn('price_books', 'enable_retail_price') || Schema::hasColumn('price_books', 'enable_technician_price')) {
            Schema::table('price_books', function (Blueprint $table) {
                $dropColumns = [];
                if (Schema::hasColumn('price_books', 'enable_retail_price')) {
                    $dropColumns[] = 'enable_retail_price';
                }
                if (Schema::hasColumn('price_books', 'enable_technician_price')) {
                    $dropColumns[] = 'enable_technician_price';
                }
                if (!empty($dropColumns)) {
                    $table->dropColumn($dropColumns);
                }
            });
        }
    }
};
