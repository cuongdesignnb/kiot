<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'debt_offsets';

    private const COLUMNS = [
        'workflow_status',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'applied_at',
        'idempotency_key',
        'approval_operation_id',
        'apply_operation_id',
        'reversal_operation_id',
        'customer_amount',
        'supplier_amount',
        'source_references',
        'reverses_debt_offset_id',
    ];

    public function up(): void
    {
        try {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('workflow_status', 32)->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->dateTime('requested_at', 6)->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->dateTime('approved_at', 6)->nullable();
                $table->unsignedBigInteger('rejected_by')->nullable();
                $table->dateTime('rejected_at', 6)->nullable();
                $table->text('rejection_reason')->nullable();
                $table->dateTime('applied_at', 6)->nullable();
                $table->string('idempotency_key', 191)->nullable();
                $table->unsignedBigInteger('approval_operation_id')->nullable();
                $table->unsignedBigInteger('apply_operation_id')->nullable();
                $table->unsignedBigInteger('reversal_operation_id')->nullable();
                $table->decimal('customer_amount', 15, 2)->nullable();
                $table->decimal('supplier_amount', 15, 2)->nullable();
                $table->json('source_references')->nullable();
                $table->unsignedBigInteger('reverses_debt_offset_id')->nullable();
            });
        } catch (Throwable $e) {
            $this->dropAddedColumns();

            throw $e;
        }
    }

    public function down(): void
    {
        $this->dropAddedColumns();
    }

    private function dropAddedColumns(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $existing = array_values(array_filter(
            self::COLUMNS,
            fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }
};
