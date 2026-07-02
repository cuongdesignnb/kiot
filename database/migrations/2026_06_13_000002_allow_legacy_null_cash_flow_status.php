<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_flows', function (Blueprint $table) {
            $table->string('status')->nullable()->default('active')->change();
        });
    }

    public function down(): void
    {
        Schema::table('cash_flows', function (Blueprint $table) {
            $table->string('status')->nullable(false)->default('active')->change();
        });
    }
};
