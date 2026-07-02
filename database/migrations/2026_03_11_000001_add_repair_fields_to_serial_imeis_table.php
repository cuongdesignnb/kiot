<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->enum('repair_status', ['not_started', 'repairing', 'ready'])
                ->nullable()->default(null)
                ->after('status')
                ->comment('Trạng thái sửa chữa (chỉ dùng khi module repair bật)');
            $table->decimal('cost_price', 18, 0)->default(0)
                ->after('repair_status')
                ->comment('Giá vốn riêng serial (cộng dồn khi lắp linh kiện)');
        });
    }

    public function down(): void
    {
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->dropColumn(['repair_status', 'cost_price']);
        });
    }
};
