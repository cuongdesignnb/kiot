<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_repairs', function (Blueprint $table) {
            $table->date('deadline')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('device_repairs', function (Blueprint $table) {
            $table->dropColumn('deadline');
        });
    }
};
