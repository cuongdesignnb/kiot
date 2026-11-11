<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The warranty package chosen for one line (POS picker or the website order),
 * by the name of one of the product's warranty_policies. Null keeps the
 * product's default package.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['order_items', 'invoice_items'] as $table) {
            if (! Schema::hasColumn($table, 'warranty_policy')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->string('warranty_policy', 120)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['order_items', 'invoice_items'] as $table) {
            if (Schema::hasColumn($table, 'warranty_policy')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('warranty_policy'));
            }
        }
    }
};
