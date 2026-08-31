<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('returns', 'recorded_at')) {
            return;
        }

        Schema::table('returns', function (Blueprint $table): void {
            // `created_at` is legacy business time and may be deliberately
            // backdated.  Keep it untouched; this column records when the
            // return was actually committed from this release onward.
            $table->timestamp('recorded_at')->nullable()->after('created_at');
            $table->index('recorded_at', 'returns_recorded_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('returns', 'recorded_at')) {
            return;
        }

        Schema::table('returns', function (Blueprint $table): void {
            $table->dropIndex('returns_recorded_at_index');
            $table->dropColumn('recorded_at');
        });
    }
};
