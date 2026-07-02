<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Sổ cái tồn kho (stock movements ledger).
 *
 * Mỗi lần SP IN/OUT/ADJUST đều ghi 1 row → cho phép:
 *  - Truy vết lịch sử chuyển động của từng SKU/serial
 *  - Đối soát BCTC theo kỳ
 *  - Reconstruct giá vốn bình quân tại thời điểm
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();

            // Đối tượng dịch chuyển
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('serial_imei_id')->nullable()->constrained('serial_imeis')->nullOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Loại dịch chuyển
            // in_purchase | out_invoice | in_invoice_return | out_purchase_return
            // adjust_in   | adjust_out  | transfer_in       | transfer_out
            // repair_in   | repair_out
            $t->string('type', 32);

            // Số lượng (luôn dương). Hướng quy ước qua `direction`
            $t->integer('qty')->default(0);
            $t->enum('direction', ['in', 'out'])->index();

            // Giá vốn đơn vị tại thời điểm dịch chuyển
            $t->decimal('unit_cost', 18, 0)->default(0);
            // Tổng giá vốn = qty * unit_cost (tiện cho aggregate)
            $t->decimal('total_cost', 18, 0)->default(0);

            // Số dư SAU khi áp dụng dịch chuyển (theo product, snapshot lúc ghi)
            $t->integer('balance_qty')->default(0);
            $t->decimal('balance_cost', 18, 0)->default(0);

            // Tham chiếu chứng từ gốc (polymorphic)
            $t->string('ref_type', 64)->nullable(); // App\Models\Purchase, Invoice, ...
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('ref_code', 64)->nullable(); // mã chứng từ để show nhanh

            // Người thực hiện
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $t->text('note')->nullable();
            $t->timestamp('moved_at')->nullable()->index();
            $t->timestamps();

            $t->index(['product_id', 'moved_at']);
            $t->index(['ref_type', 'ref_id']);
            $t->index(['type', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
