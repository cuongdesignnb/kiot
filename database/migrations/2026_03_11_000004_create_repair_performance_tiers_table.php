<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('repair_performance_tiers', function (Blueprint $table) {
            $table->id();
            $table->integer('min_percent');
            $table->integer('max_percent');
            $table->integer('salary_percent')->comment('% lương được hưởng');
            $table->string('label')->nullable()->comment('Xuất sắc, Tốt, Khá...');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed default tiers
        DB::table('repair_performance_tiers')->insert([
            ['min_percent' => 0, 'max_percent' => 49, 'salary_percent' => 50, 'label' => 'Yếu', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['min_percent' => 50, 'max_percent' => 69, 'salary_percent' => 70, 'label' => 'Trung bình', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['min_percent' => 70, 'max_percent' => 79, 'salary_percent' => 80, 'label' => 'Khá', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['min_percent' => 80, 'max_percent' => 89, 'salary_percent' => 90, 'label' => 'Tốt', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['min_percent' => 90, 'max_percent' => 100, 'salary_percent' => 100, 'label' => 'Xuất sắc', 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_performance_tiers');
    }
};
