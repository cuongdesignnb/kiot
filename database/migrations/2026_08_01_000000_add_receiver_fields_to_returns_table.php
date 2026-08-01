<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (! Schema::hasColumn('returns', 'received_by_employee_id')) {
                $table->foreignId('received_by_employee_id')
                    ->nullable()
                    ->after('created_by_name')
                    ->constrained('employees')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('returns', 'received_by_name')) {
                $table->string('received_by_name')
                    ->nullable()
                    ->after('received_by_employee_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (Schema::hasColumn('returns', 'received_by_employee_id')) {
                $table->dropForeign(['received_by_employee_id']);
                $table->dropColumn('received_by_employee_id');
            }

            if (Schema::hasColumn('returns', 'received_by_name')) {
                $table->dropColumn('received_by_name');
            }
        });
    }
};
