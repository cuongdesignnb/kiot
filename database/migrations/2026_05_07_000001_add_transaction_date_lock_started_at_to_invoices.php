<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 24.3 — Add transaction_date + lock_started_at to invoices.
 *
 * - transaction_date: ngày bán thực tế (business date), dùng cho reports/dashboard.
 * - lock_started_at : thời điểm nhập chứng từ vào hệ thống, dùng cho time lock 24h.
 *
 * Nullable → existing rows fallback created_at.
 * Không backfill dữ liệu cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'transaction_date')) {
                $table->timestamp('transaction_date')->nullable()->after('sale_time')->index();
            }
            if (!Schema::hasColumn('invoices', 'lock_started_at')) {
                $table->timestamp('lock_started_at')->nullable()->after('transaction_date')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'transaction_date')) {
                $table->dropColumn('transaction_date');
            }
            if (Schema::hasColumn('invoices', 'lock_started_at')) {
                $table->dropColumn('lock_started_at');
            }
        });
    }
};
