<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->string('description')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // Seed default roles
        $now = now();
        \Illuminate\Support\Facades\DB::table('roles')->insert([
            [
                'name'         => 'super_admin',
                'display_name' => 'Quản trị hệ thống',
                'description'  => 'Toàn quyền trong hệ thống',
                'permissions'  => json_encode(['*']),
                'is_system'    => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'name'         => 'branch_admin',
                'display_name' => 'Quản trị chi nhánh',
                'description'  => 'Quản lý toàn bộ hoạt động chi nhánh',
                'permissions'  => json_encode([
                    'dashboard.view',
                    'products.view','products.create','products.edit','products.delete','products.import','products.export',
                    'price_settings.view','price_settings.edit','price_settings.import','price_settings.export',
                    'warranties.view','warranties.edit','warranties.print','warranties.export',
                    'serials.view','serials.create','serials.edit','serials.delete',
                    'stock_transfers.view','stock_transfers.create','stock_transfers.print','stock_transfers.export',
                    'stock_takes.view','stock_takes.create','stock_takes.print','stock_takes.export',
                    'damages.view','damages.create','damages.print','damages.export',
                    'suppliers.view','suppliers.create','suppliers.import','suppliers.export',
                    'purchase_orders.view','purchase_orders.create','purchase_orders.print','purchase_orders.export',
                    'purchases.view','purchases.create','purchases.print','purchases.export',
                    'orders.view','orders.create','orders.edit','orders.print','orders.export',
                    'invoices.view','invoices.create','invoices.delete','invoices.print','invoices.export',
                    'returns.view','returns.create','returns.print','returns.export',
                    'customers.view','customers.create','customers.edit','customers.delete','customers.import','customers.export',
                    'customers.debt_view','customers.debt_payment','customers.debt_adjust',
                    'pos.use',
                    'cash_flows.view','cash_flows.create','cash_flows.edit','cash_flows.delete','cash_flows.print','cash_flows.import','cash_flows.export',
                    'employees.view','employees.create','employees.edit','employees.delete','employees.import','employees.export',
                    'schedules.view','schedules.manage',
                    'attendance.view','attendance.manage',
                    'paysheets.view','paysheets.create','paysheets.manage','paysheets.print','paysheets.export',
                    'payroll_settings.view','payroll_settings.manage',
                    'workday_settings.view','workday_settings.manage',
                    'attendance_settings.view','attendance_settings.manage',
                    'attendance_devices.view','attendance_devices.manage',
                    'repairs.view','repairs.create','repairs.assign','repairs.complete','repairs.manage_parts',
                    'repair_performance.view','repair_tiers.manage',
                    'settings.view','settings.manage','settings.categories','settings.brands','settings.units',
                    'settings.attributes','settings.locations','settings.other_fees','settings.bank_accounts',
                ]),
                'is_system'    => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'name'         => 'warehouse_staff',
                'display_name' => 'Nhân viên kho',
                'description'  => 'Quản lý kho hàng, nhập hàng',
                'permissions'  => json_encode([
                    'dashboard.view',
                    'products.view',
                    'serials.view',
                    'stock_transfers.view','stock_transfers.create','stock_transfers.print',
                    'stock_takes.view','stock_takes.create','stock_takes.print',
                    'damages.view','damages.create','damages.print',
                    'suppliers.view',
                    'purchases.view','purchases.create','purchases.print',
                    'purchase_orders.view','purchase_orders.create',
                    'customers.view',
                ]),
                'is_system'    => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'name'         => 'cashier',
                'display_name' => 'Thu ngân',
                'description'  => 'Bán hàng tại quầy',
                'permissions'  => json_encode([
                    'pos.use',
                    'orders.view','orders.create',
                    'invoices.view','invoices.create',
                    'returns.view','returns.create',
                    'customers.view','customers.create',
                    'products.view',
                ]),
                'is_system'    => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
