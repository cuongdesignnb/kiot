<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->json('other_costs')->nullable()->after('discount');
            $table->decimal('other_costs_total', 15, 2)->default(0)->after('other_costs');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['other_costs', 'other_costs_total']);
        });
    }
};
