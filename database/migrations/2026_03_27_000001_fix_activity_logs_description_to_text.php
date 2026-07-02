<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE activity_logs MODIFY COLUMN description TEXT');
        }
        // SQLite does not support ALTER COLUMN — skip on local dev
        // The column type in production (MySQL) will be TEXT
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE activity_logs MODIFY COLUMN description VARCHAR(255)');
        }
    }
};

