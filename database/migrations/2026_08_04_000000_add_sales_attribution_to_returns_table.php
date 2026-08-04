<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (! Schema::hasColumn('returns', 'sales_attribution_employee_id')) {
                $table->foreignId('sales_attribution_employee_id')
                    ->nullable()
                    ->constrained('employees')
                    ->nullOnDelete();
                $table->index('sales_attribution_employee_id', 'returns_sales_attribution_employee_id_index');
            }

            if (! Schema::hasColumn('returns', 'sales_attribution_name')) {
                $table->string('sales_attribution_name')->nullable();
            }

            if (! Schema::hasColumn('returns', 'sales_attribution_reason')) {
                $table->text('sales_attribution_reason')->nullable();
            }

            if (! Schema::hasColumn('returns', 'sales_attribution_updated_by')) {
                $table->foreignId('sales_attribution_updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('returns', 'sales_attribution_updated_at')) {
                $table->timestamp('sales_attribution_updated_at')->nullable();
            }
        });

        // Root users and wildcard super-admin roles already pass every permission.
        // The repository's default non-wildcard management role is branch_admin;
        // operational/cashier roles intentionally do not receive this sensitive edit.
        Role::query()->where('name', 'branch_admin')->each(function (Role $role): void {
            $permissions = is_array($role->permissions) ? $role->permissions : [];
            if (in_array('returns.sales_attribution.edit', $permissions, true)) {
                return;
            }

            $role->permissions = array_values(array_unique([
                ...$permissions,
                'returns.sales_attribution.edit',
            ]));
            $role->save();
        });
    }

    public function down(): void
    {
        Role::query()->where('name', 'branch_admin')->each(function (Role $role): void {
            $permissions = is_array($role->permissions) ? $role->permissions : [];
            $role->permissions = array_values(array_filter(
                $permissions,
                fn (string $permission): bool => $permission !== 'returns.sales_attribution.edit',
            ));
            $role->save();
        });

        Schema::table('returns', function (Blueprint $table) {
            if (Schema::hasColumn('returns', 'sales_attribution_employee_id')) {
                $table->dropForeign(['sales_attribution_employee_id']);
                $table->dropIndex('returns_sales_attribution_employee_id_index');
                $table->dropColumn('sales_attribution_employee_id');
            }

            if (Schema::hasColumn('returns', 'sales_attribution_updated_by')) {
                $table->dropForeign(['sales_attribution_updated_by']);
                $table->dropColumn('sales_attribution_updated_by');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('returns', 'sales_attribution_name') ? 'sales_attribution_name' : null,
                Schema::hasColumn('returns', 'sales_attribution_reason') ? 'sales_attribution_reason' : null,
                Schema::hasColumn('returns', 'sales_attribution_updated_at') ? 'sales_attribution_updated_at' : null,
            ]));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
