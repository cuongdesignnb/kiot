<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'debt_offsets';

    private const CHECKS = [
        'do_workflow_status_chk',
        'do_amount_pair_chk',
        'do_amount_positive_chk',
        'do_amount_equal_chk',
        'do_rejection_reason_chk',
        'do_idempotency_nonempty_chk',
    ];

    public function up(): void
    {
        if (! $this->supportsChecks()) {
            return;
        }

        try {
            DB::statement(<<<'SQL'
                ALTER TABLE `debt_offsets`
                ADD CONSTRAINT `do_workflow_status_chk`
                CHECK (
                    `workflow_status` IS NULL
                    OR `workflow_status` IN (
                        'draft',
                        'pending_approval',
                        'approved',
                        'applied',
                        'rejected',
                        'void',
                        'reversed'
                    )
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE `debt_offsets`
                ADD CONSTRAINT `do_amount_pair_chk`
                CHECK (
                    (`customer_amount` IS NULL AND `supplier_amount` IS NULL)
                    OR
                    (`customer_amount` IS NOT NULL AND `supplier_amount` IS NOT NULL)
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE `debt_offsets`
                ADD CONSTRAINT `do_amount_positive_chk`
                CHECK (
                    (`customer_amount` IS NULL AND `supplier_amount` IS NULL)
                    OR
                    (`customer_amount` > 0 AND `supplier_amount` > 0)
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE `debt_offsets`
                ADD CONSTRAINT `do_amount_equal_chk`
                CHECK (
                    (`customer_amount` IS NULL AND `supplier_amount` IS NULL)
                    OR
                    (
                        `customer_amount` = `supplier_amount`
                        AND `customer_amount` = `amount`
                        AND `supplier_amount` = `amount`
                    )
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE `debt_offsets`
                ADD CONSTRAINT `do_rejection_reason_chk`
                CHECK (
                    `workflow_status` IS NULL
                    OR `workflow_status` <> 'rejected'
                    OR (
                        `rejection_reason` IS NOT NULL
                        AND CHAR_LENGTH(TRIM(`rejection_reason`)) > 0
                    )
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE `debt_offsets`
                ADD CONSTRAINT `do_idempotency_nonempty_chk`
                CHECK (
                    `idempotency_key` IS NULL
                    OR CHAR_LENGTH(TRIM(`idempotency_key`)) > 0
                )
            SQL);
        } catch (Throwable $e) {
            $this->dropAddedChecks();

            throw $e;
        }
    }

    public function down(): void
    {
        $this->dropAddedChecks();
    }

    private function dropAddedChecks(): void
    {
        if (! $this->supportsChecks() || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (array_reverse(self::CHECKS) as $check) {
            if (! $this->checkExists($check)) {
                continue;
            }

            $dropKeyword = DB::connection()->getDriverName() === 'mariadb'
                ? 'DROP CONSTRAINT'
                : 'DROP CHECK';

            DB::statement("ALTER TABLE `debt_offsets` {$dropKeyword} `{$check}`");
        }
    }

    private function checkExists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }

    private function supportsChecks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
