<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_debts', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id');      // FK khách hàng
            $table->string('ref_code')->nullable();         // Mã phiếu/đơn liên quan (ví dụ: mã hóa đơn, phiếu thu)
            $table->decimal('amount', 15, 2);               // Giá trị giao dịch (+/-)
            $table->decimal('debt_total', 15, 2);           // Tổng công nợ sau phát sinh này
            $table->string('note')->nullable();             // Ghi chú
            $table->unsignedBigInteger('created_by')->nullable(); // Người tạo
            $table->dateTime('recorded_at')->nullable();    // Ngày ghi nhận giao dịch công nợ
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_debts');
    }
};
