<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE serial_imeis MODIFY COLUMN status ENUM('in_stock','sold','returning','warranty','defective','returned') NOT NULL DEFAULT 'in_stock'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE serial_imeis MODIFY COLUMN status ENUM('in_stock','sold','returning','warranty','defective') NOT NULL DEFAULT 'in_stock'");
    }
};
