<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['general', 'repair'])->default('general');
            $table->string('description')->nullable();
            $table->string('color', 7)->nullable()->comment('Hex color, e.g. #FF5733');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed defaults
        \DB::table('task_categories')->insert([
            ['name' => 'Sửa chữa thiết bị', 'type' => 'repair',  'color' => '#3B82F6', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Lắp đặt',           'type' => 'general', 'color' => '#10B981', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bảo trì',           'type' => 'general', 'color' => '#F59E0B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tư vấn',            'type' => 'general', 'color' => '#8B5CF6', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Khác',              'type' => 'general', 'color' => '#6B7280', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('task_categories');
    }
};
