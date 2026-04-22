<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes to accelerate the unified sidebar-filter queries
 * used across Index screens (branch/date/status/customer/supplier/creator + sort).
 *
 * Safe-guarded: each index is only created when the table and every column
 * exist and the index name is not already present.
 */
return new class extends Migration {
    /**
     * Map of table => list of composite index column sets.
     * Keep each set short (2-3 cols) to stay useful across variants of the filter query.
     */
    private array $plan = [
        'invoices' => [
            ['branch_id', 'sale_time'],
            ['customer_id', 'sale_time'],
            ['employee_id', 'sale_time'],
            ['created_by', 'created_at'],
        ],
        'orders' => [
            ['branch_id', 'created_at'],
            ['status', 'created_at'],
            ['customer_id', 'created_at'],
            ['created_by', 'created_at'],
        ],
        'purchases' => [
            ['branch_id', 'created_at'],
            ['status', 'created_at'],
            ['supplier_id', 'created_at'],
            ['created_by', 'created_at'],
        ],
        'purchase_orders' => [
            ['branch_id', 'created_at'],
            ['status', 'created_at'],
            ['supplier_id', 'created_at'],
            ['created_by', 'created_at'],
        ],
        'purchase_returns' => [
            ['branch_id', 'created_at'],
            ['status', 'created_at'],
            ['supplier_id', 'created_at'],
        ],
        'returns' => [
            ['branch_id', 'return_date'],
            ['status', 'return_date'],
            ['customer_id', 'return_date'],
        ],
        'damages' => [
            ['branch_id', 'created_at'],
            ['status', 'created_at'],
        ],
        'stock_takes' => [
            ['branch_id', 'created_at'],
            ['status', 'created_at'],
        ],
        'stock_transfers' => [
            ['from_branch_id', 'created_at'],
            ['to_branch_id', 'created_at'],
            ['status', 'created_at'],
        ],
        'cash_flows' => [
            ['type', 'time'],
            ['status', 'time'],
            ['bank_account_id', 'time'],
            ['branch_id', 'time'],
        ],
        'customers' => [
            ['branch_id', 'created_at'],
            ['type', 'created_at'],
            ['customer_group', 'created_at'],
        ],
        'employees' => [
            ['branch_id', 'created_at'],
            ['department_id', 'created_at'],
            ['job_title_id', 'created_at'],
        ],
        'suppliers' => [
            ['branch_id', 'created_at'],
            ['supplier_group', 'created_at'],
        ],
        'warranties' => [
            ['customer_id', 'purchase_date'],
            ['product_id', 'purchase_date'],
        ],
        'users' => [
            ['status', 'created_at'],
            ['branch_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->plan as $table => $sets) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table, $sets) {
                foreach ($sets as $cols) {
                    // Only add when every column exists on the table
                    foreach ($cols as $col) {
                        if (! Schema::hasColumn($table, $col)) {
                            continue 2;
                        }
                    }
                    $indexName = $this->indexName($table, $cols);
                    if ($this->indexExists($table, $indexName)) {
                        continue;
                    }
                    $blueprint->index($cols, $indexName);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->plan as $table => $sets) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table, $sets) {
                foreach ($sets as $cols) {
                    $indexName = $this->indexName($table, $cols);
                    if ($this->indexExists($table, $indexName)) {
                        $blueprint->dropIndex($indexName);
                    }
                }
            });
        }
    }

    private function indexName(string $table, array $cols): string
    {
        // Match Laravel's default naming pattern: <table>_<col1>_<col2>_index
        return $table . '_' . implode('_', $cols) . '_idx';
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        try {
            if ($driver === 'sqlite') {
                $row = Schema::getConnection()->selectOne(
                    "SELECT name FROM sqlite_master WHERE type='index' AND name = ?",
                    [$indexName]
                );
                return $row !== null;
            }
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $row = Schema::getConnection()->selectOne(
                    'SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                    [$table, $indexName]
                );
                return $row !== null;
            }
            if ($driver === 'pgsql') {
                $row = Schema::getConnection()->selectOne(
                    'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                    [$table, $indexName]
                );
                return $row !== null;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }
};
