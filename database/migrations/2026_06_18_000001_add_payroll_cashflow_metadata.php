<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_flows', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_flows', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('bank_account_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('cash_flows', 'idempotency_key')) {
                $table->string('idempotency_key')->nullable()->unique()->after('cancel_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_flows', function (Blueprint $table) {
            if (Schema::hasColumn('cash_flows', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
            if (Schema::hasColumn('cash_flows', 'idempotency_key')) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
