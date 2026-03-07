<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addForeignIfMissing('invoice_items', 'invoice_id', 'invoices', 'cascade');
        $this->addForeignIfMissing('invoice_items', 'product_id', 'products', 'set null');

        $this->addForeignIfMissing('stock_transfer_items', 'stock_transfer_id', 'stock_transfers', 'cascade');
        $this->addForeignIfMissing('stock_transfer_items', 'product_id', 'products', 'cascade');

        $this->addForeignIfMissing('purchase_order_items', 'purchase_order_id', 'purchase_orders', 'cascade');
        $this->addForeignIfMissing('purchase_order_items', 'product_id', 'products', 'cascade');

        $this->addForeignIfMissing('employees', 'branch_id', 'branches', 'set null');
        $this->addForeignIfMissing('employees', 'department_id', 'departments', 'set null');
        $this->addForeignIfMissing('employees', 'job_title_id', 'job_titles', 'set null');

        $this->addForeignIfMissing('employee_salary_components', 'employee_id', 'employees', 'cascade');

        $this->addForeignIfMissing('employee_salary_settings', 'employee_id', 'employees', 'cascade');
        $this->addForeignIfMissing('employee_salary_settings', 'salary_template_id', 'salary_templates', 'set null');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('invoice_items', 'invoice_items_invoice_id_foreign');
        $this->dropForeignIfExists('invoice_items', 'invoice_items_product_id_foreign');

        $this->dropForeignIfExists('stock_transfer_items', 'stock_transfer_items_stock_transfer_id_foreign');
        $this->dropForeignIfExists('stock_transfer_items', 'stock_transfer_items_product_id_foreign');

        $this->dropForeignIfExists('purchase_order_items', 'purchase_order_items_purchase_order_id_foreign');
        $this->dropForeignIfExists('purchase_order_items', 'purchase_order_items_product_id_foreign');

        $this->dropForeignIfExists('employees', 'employees_branch_id_foreign');
        $this->dropForeignIfExists('employees', 'employees_department_id_foreign');
        $this->dropForeignIfExists('employees', 'employees_job_title_id_foreign');

        $this->dropForeignIfExists('employee_salary_components', 'employee_salary_components_employee_id_foreign');

        $this->dropForeignIfExists('employee_salary_settings', 'employee_salary_settings_employee_id_foreign');
        $this->dropForeignIfExists('employee_salary_settings', 'employee_salary_settings_salary_template_id_foreign');
    }

    private function addForeignIfMissing(string $tableName, string $columnName, string $referencedTable, string $onDelete): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasTable($referencedTable) || !Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        $foreignKeyName = $tableName . '_' . $columnName . '_foreign';
        if ($this->foreignKeyExists($tableName, $foreignKeyName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName, $referencedTable, $onDelete) {
            $foreign = $table->foreign($columnName)->references('id')->on($referencedTable);

            if ($onDelete === 'set null') {
                $foreign->nullOnDelete();
                return;
            }

            $foreign->cascadeOnDelete();
        });
    }

    private function dropForeignIfExists(string $tableName, string $foreignKeyName): void
    {
        if (!Schema::hasTable($tableName) || !$this->foreignKeyExists($tableName, $foreignKeyName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($foreignKeyName) {
            $table->dropForeign($foreignKeyName);
        });
    }

    private function foreignKeyExists(string $tableName, string $foreignKeyName): bool
    {
        $databaseName = DB::getDatabaseName();

        $result = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_TYPE = ?
               AND CONSTRAINT_NAME = ?
             LIMIT 1',
            [$databaseName, $tableName, 'FOREIGN KEY', $foreignKeyName]
        );

        return $result !== null;
    }
};
