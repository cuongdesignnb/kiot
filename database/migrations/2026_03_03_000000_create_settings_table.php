<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('group')->default('general');
                $table->string('type')->default('string');
                $table->timestamps();
            });
        }

        // Seed default settings based on images
        $defaults = [
            // Thông tin hàng hóa
            ['key' => 'product_barcode_auto', 'value' => '1', 'group' => 'product', 'type' => 'boolean'],
            ['key' => 'product_suggest_info', 'value' => '1', 'group' => 'product', 'type' => 'boolean'],
            ['key' => 'product_use_serial', 'value' => '1', 'group' => 'product', 'type' => 'boolean'],
            ['key' => 'product_multiple_units', 'value' => '1', 'group' => 'product', 'type' => 'boolean'],

            // Giá vốn, tồn kho
            ['key' => 'inventory_costing_method', 'value' => 'average', 'group' => 'inventory', 'type' => 'string'],
            ['key' => 'inventory_allow_oversell', 'value' => '0', 'group' => 'inventory', 'type' => 'boolean'],
            ['key' => 'inventory_check_by_branch', 'value' => '1', 'group' => 'inventory', 'type' => 'boolean'],

            // Khác
            ['key' => 'order_allow_change_time', 'value' => '1', 'group' => 'order', 'type' => 'boolean'],
        ];

        foreach ($defaults as $setting) {
            DB::table('settings')->insertOrIgnore(array_merge($setting, ['created_at' => now(), 'updated_at' => now()]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
