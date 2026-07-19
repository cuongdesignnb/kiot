<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('external_source', 50)->nullable()->after('id')->index();
            $table->string('external_order_id')->nullable()->after('external_source');
            $table->string('external_order_code')->nullable()->after('external_order_id')->index();
            $table->string('external_payment_method')->nullable()->after('external_order_code');
            $table->string('external_payment_status')->nullable()->after('external_payment_method');
            $table->char('integration_payload_hash', 64)->nullable()->after('external_payment_status');
            $table->timestamp('integration_received_at')->nullable()->after('integration_payload_hash');

            $table->unique(['external_source', 'external_order_id'], 'orders_external_source_order_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_external_source_order_unique');
            $table->dropIndex(['external_source']);
            $table->dropIndex(['external_order_code']);
            $table->dropColumn([
                'external_source',
                'external_order_id',
                'external_order_code',
                'external_payment_method',
                'external_payment_status',
                'integration_payload_hash',
                'integration_received_at',
            ]);
        });
    }
};
