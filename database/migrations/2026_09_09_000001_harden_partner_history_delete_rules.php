<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private const REFERENCES = [
        ['customer_debts', 'customer_id', 'customer_debts_partner_history_fk', 'customer_debts_customer_id_foreign'],
        ['supplier_debt_transactions', 'supplier_id', 'supplier_debt_tx_partner_history_fk', 'supplier_debt_transactions_supplier_id_foreign'],
        ['debt_offsets', 'customer_id', 'debt_offsets_partner_history_fk', 'debt_offsets_customer_id_foreign'],
    ];

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::REFERENCES as [$table, $column, $restrictConstraint]) {
            $this->replaceDeleteRule($table, $column, 'RESTRICT', $restrictConstraint);
        }
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (array_reverse(self::REFERENCES) as [$table, $column, $restrictConstraint, $legacyConstraint]) {
            $this->replaceDeleteRule($table, $column, 'CASCADE', $legacyConstraint);
        }
    }

    private function replaceDeleteRule(
        string $table,
        string $column,
        string $deleteRule,
        string $desiredConstraint,
    ): void {
        $references = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join) {
                $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')
                    ->on('r.TABLE_NAME', '=', 'k.TABLE_NAME')
                    ->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
            })
            ->where('k.CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('k.TABLE_NAME', $table)
            ->where('k.COLUMN_NAME', $column)
            ->where('k.REFERENCED_TABLE_NAME', 'customers')
            ->select(['k.CONSTRAINT_NAME', 'r.DELETE_RULE'])
            ->get();

        if ($references->isEmpty()) {
            throw new RuntimeException("Missing customers foreign key for {$table}.{$column}.");
        }

        foreach ([$table, $column, $desiredConstraint] as $identifier) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
                throw new RuntimeException('Unsafe foreign-key identifier.');
            }
        }

        $desiredExists = $references->contains(function ($reference) use ($desiredConstraint, $deleteRule): bool {
            return (string) $reference->CONSTRAINT_NAME === $desiredConstraint
                && strtoupper((string) $reference->DELETE_RULE) === $deleteRule;
        });

        // Add the restrictive/recovery key first. A process interruption can
        // temporarily leave two keys, but never leaves the history unprotected.
        if (! $desiredExists) {
            DB::statement(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$desiredConstraint}` "
                ."FOREIGN KEY (`{$column}`) REFERENCES `customers` (`id`) ON DELETE {$deleteRule}"
            );
        }

        foreach ($references as $reference) {
            $constraint = (string) $reference->CONSTRAINT_NAME;
            if ($constraint === $desiredConstraint) {
                continue;
            }
            if (! preg_match('/^[A-Za-z0-9_]+$/', $constraint)) {
                throw new RuntimeException('Unsafe foreign-key identifier.');
            }

            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }
    }
};
