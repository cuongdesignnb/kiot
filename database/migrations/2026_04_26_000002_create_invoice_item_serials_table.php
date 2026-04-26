<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('invoice_item_serials')) {
            return;
        }

        Schema::create('invoice_item_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
            $table->foreignId('serial_imei_id')->nullable()->constrained('serial_imeis')->nullOnDelete();
            $table->string('serial_number')->index();
            $table->decimal('cost_price', 18, 0)->default(0)
                ->comment('Giá vốn đích danh của serial này tại thời điểm bán');
            $table->timestamps();

            $table->index(['invoice_item_id', 'serial_imei_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_item_serials');
    }
};
