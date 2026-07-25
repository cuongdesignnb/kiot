<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('code', 100)->nullable()->after('name');
            $table->string('slug')->nullable()->after('code');
            $table->boolean('is_active')->default(true)->after('slug');
            // No equivalent publishing flag exists in the current schema. Keep
            // existing and new groups private until an operator opts them in.
            $table->boolean('show_on_pc_website')->default(false)->after('is_active');
            $table->softDeletes();

            $table->unique('code', 'categories_code_unique');
            $table->index(['updated_at', 'id'], 'categories_sync_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_sync_cursor_index');
            $table->dropUnique('categories_code_unique');
            $table->dropSoftDeletes();
            $table->dropColumn(['code', 'slug', 'is_active', 'show_on_pc_website']);
        });
    }
};
