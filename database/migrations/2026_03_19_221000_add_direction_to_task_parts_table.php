<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_parts', function (Blueprint $table) {
            $table->string('direction', 10)->default('export')->after('notes')
                  ->comment('export = xuất kho lắp vào máy, import = bóc từ máy nhập kho');
        });
    }

    public function down(): void
    {
        Schema::table('task_parts', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
