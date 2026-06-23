<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'purchases', 'damages'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'cancel_reason')) {
                    $table->text('cancel_reason')->nullable()->after('status');
                }
                if (! Schema::hasColumn($tableName, 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->after('cancel_reason')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn($tableName, 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'purchases', 'damages'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'cancelled_by')) {
                    $table->dropConstrainedForeignId('cancelled_by');
                }
                $dropColumns = [];
                foreach (['cancel_reason', 'cancelled_at'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $dropColumns[] = $column;
                    }
                }
                if ($dropColumns) {
                    $table->dropColumn($dropColumns);
                }
            });
        }
    }
};
