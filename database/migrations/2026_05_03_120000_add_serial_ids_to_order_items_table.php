<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 22.1C — Order Serial Selector.
 *
 * Thêm cột serial_ids JSON nullable vào order_items để Order có thể lưu danh sách
 * Serial/IMEI mà user chọn ngay khi tạo đơn. Khi processOrder convert sang Invoice,
 * controller đã đọc $orderItem->serial_ids (RR-13 fail-safe) và đánh dấu sold đúng
 * những serial này.
 *
 * Pattern tham chiếu: return_items.serial_ids (2026_05_02_120000),
 *                     damage_items.serial_ids (2026_05_02_120100).
 *
 * Không lock/trừ serial ở thời điểm tạo Order — chỉ lưu lựa chọn của user.
 * Validate lại status=in_stock vào lúc processOrder để tránh race condition.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'serial_ids')) {
                $table->json('serial_ids')->nullable()->after('subtotal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'serial_ids')) {
                $table->dropColumn('serial_ids');
            }
        });
    }
};
