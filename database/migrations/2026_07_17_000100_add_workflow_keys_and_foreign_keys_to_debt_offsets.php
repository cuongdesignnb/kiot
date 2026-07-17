<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'debt_offsets';

    private const FOREIGN_KEYS = [
        'do_requested_by_fk',
        'do_approved_by_fk',
        'do_rejected_by_fk',
        'do_approval_operation_fk',
        'do_apply_operation_fk',
        'do_reversal_operation_fk',
        'do_reverses_fk',
    ];

    private const INDEXES = [
        'do_requested_by_idx',
        'do_approved_by_idx',
        'do_rejected_by_idx',
        'do_approval_operation_idx',
        'do_apply_operation_idx',
        'do_reversal_operation_idx',
        'do_idempotency_uq',
        'do_reverses_uq',
    ];

    public function up(): void
    {
        try {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index('requested_by', 'do_requested_by_idx');
                $table->index('approved_by', 'do_approved_by_idx');
                $table->index('rejected_by', 'do_rejected_by_idx');
                $table->index('approval_operation_id', 'do_approval_operation_idx');
                $table->index('apply_operation_id', 'do_apply_operation_idx');
                $table->index('reversal_operation_id', 'do_reversal_operation_idx');
                $table->unique('idempotency_key', 'do_idempotency_uq');
                $table->unique('reverses_debt_offset_id', 'do_reverses_uq');

                $table->foreign('requested_by', 'do_requested_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('approved_by', 'do_approved_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('rejected_by', 'do_rejected_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('approval_operation_id', 'do_approval_operation_fk')
                    ->references('id')->on('partner_debt_operations')->restrictOnDelete();
                $table->foreign('apply_operation_id', 'do_apply_operation_fk')
                    ->references('id')->on('partner_debt_operations')->restrictOnDelete();
                $table->foreign('reversal_operation_id', 'do_reversal_operation_fk')
                    ->references('id')->on('partner_debt_operations')->restrictOnDelete();
                $table->foreign('reverses_debt_offset_id', 'do_reverses_fk')
                    ->references('id')->on(self::TABLE)->restrictOnDelete();
            });
        } catch (Throwable $e) {
            $this->dropAddedForeignKeysAndIndexes();

            throw $e;
        }
    }

    public function down(): void
    {
        $this->dropAddedForeignKeysAndIndexes();
    }

    private function dropAddedForeignKeysAndIndexes(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (array_reverse(self::FOREIGN_KEYS) as $foreignKey) {
            if ($this->constraintExists($foreignKey, 'FOREIGN KEY')) {
                DB::statement("ALTER TABLE `debt_offsets` DROP FOREIGN KEY `{$foreignKey}`");
            }
        }

        foreach (array_reverse(self::INDEXES) as $index) {
            if ($this->indexExists($index)) {
                DB::statement("ALTER TABLE `debt_offsets` DROP INDEX `{$index}`");
            }
        }
    }

    private function constraintExists(string $name, string $type): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', $type)
            ->exists();
    }

    private function indexExists(string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
