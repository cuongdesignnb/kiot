<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->timestamp('sold_at')->nullable()->after('status');
            $table->unsignedBigInteger('invoice_id')->nullable()->after('sold_at');
            $table->timestamp('warranty_expires_at')->nullable()->after('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->dropColumn(['sold_at', 'invoice_id', 'warranty_expires_at']);
        });
    }
};
