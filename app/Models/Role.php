<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'permissions',
        'is_system',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system'   => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->permissions ?? [];
        if (in_array('*', $perms)) {
            return true;
        }
        return in_array($permission, $perms);
    }

    /**
     * Returns the full permissions map organized by Vietnamese category labels.
     * Used by the role editor UI.
     */
    public static function getPermissionsMap(): array
    {
        return [
            'Tổng quan' => [
                'dashboard.view' => 'Xem tổng quan',
            ],
            'Hàng hóa' => [
                '_sub' => [
                    'Hàng hóa' => [
                        'products.view'   => 'Xem danh sách hàng hóa',
                        'products.create' => 'Thêm hàng hóa',
                        'products.edit'   => 'Sửa hàng hóa',
                        'products.delete' => 'Xóa hàng hóa',
                        'products.import' => 'Import hàng hóa',
                        'products.export' => 'Xuất file hàng hóa',
                        'products.view_cost_price' => 'Xem giá vốn (giá nhập)',
                    ],
                    'Thiết lập giá' => [
                        'price_settings.view'   => 'Xem thiết lập giá',
                        'price_settings.edit'   => 'Chỉnh sửa giá',
                        'price_settings.import' => 'Import bảng giá',
                        'price_settings.export' => 'Xuất file bảng giá',
                    ],
                    'Bảo hành, bảo trì' => [
                        'warranties.view'   => 'Xem phiếu bảo hành',
                        'warranties.edit'   => 'Cập nhật bảo hành',
                        'warranties.print'  => 'In phiếu bảo hành',
                        'warranties.export' => 'Xuất file bảo hành',
                    ],
                    'Serial/IMEI' => [
                        'serials.view'   => 'Xem serial',
                        'serials.create' => 'Thêm serial',
                        'serials.edit'   => 'Sửa serial',
                        'serials.delete' => 'Xóa serial',
                    ],
                ],
            ],
            'Kho hàng' => [
                '_sub' => [
                    'Chuyển hàng' => [
                        'stock_transfers.view'    => 'Xem phiếu chuyển hàng',
                        'stock_transfers.create'  => 'Tạo phiếu chuyển hàng',
                        'stock_transfers.receive' => 'Nhận hàng chuyển kho (Step 24.0B)',
                        'stock_transfers.cancel'  => 'Hủy phiếu chuyển hàng (Step 24.0B)',
                        'stock_transfers.print'   => 'In phiếu chuyển hàng',
                        'stock_transfers.export'  => 'Xuất file chuyển hàng',
                    ],
                    'Kiểm kho' => [
                        'stock_takes.view'    => 'Xem phiếu kiểm kho',
                        'stock_takes.create'  => 'Tạo phiếu kiểm kho',
                        'stock_takes.balance' => 'Cân bằng kiểm kho (Step 24.0B)',
                        'stock_takes.cancel'  => 'Hủy phiếu kiểm kho (Step 24.0B)',
                        'stock_takes.print'   => 'In phiếu kiểm kho',
                        'stock_takes.export'  => 'Xuất file kiểm kho',
                    ],
                    'Xuất hủy' => [
                        'damages.view'   => 'Xem phiếu xuất hủy',
                        'damages.create' => 'Tạo phiếu xuất hủy',
                        'damages.cancel' => 'Hủy phiếu xuất hủy (Step 24.0B)',
                        'damages.print'  => 'In phiếu xuất hủy',
                        'damages.export' => 'Xuất file xuất hủy',
                    ],
                ],
            ],
            'Nhập hàng' => [
                '_sub' => [
                    'Nhà cung cấp' => [
                        'suppliers.view'   => 'Xem nhà cung cấp',
                        'suppliers.create' => 'Thêm nhà cung cấp',
                        'suppliers.edit'   => 'Sửa / Ngừng hoạt động NCC',
                        'suppliers.import' => 'Import NCC',
                        'suppliers.export' => 'Xuất file NCC',
                    ],
                    'Đặt hàng nhập' => [
                        'purchase_orders.view'   => 'Xem đặt hàng nhập',
                        'purchase_orders.create' => 'Tạo đặt hàng nhập',
                        'purchase_orders.print'  => 'In đặt hàng nhập',
                        'purchase_orders.export' => 'Xuất file đặt hàng nhập',
                    ],
                    'Nhập hàng' => [
                        'purchases.view'   => 'Xem phiếu nhập hàng',
                        'purchases.create' => 'Tạo phiếu nhập hàng',
                        'purchases.cancel' => 'Hủy phiếu nhập hàng (Step 24.0B)',
                        'purchases.return.create' => 'Tạo phiếu trả nhà cung cấp (Step 24.0B)',
                        'purchases.return.cancel' => 'Hủy phiếu trả nhà cung cấp (Step 24.0B)',
                        'purchases.print'  => 'In phiếu nhập hàng',
                        'purchases.export' => 'Xuất file nhập hàng',
                    ],
                ],
            ],
            'Đơn hàng' => [
                '_sub' => [
                    'Đặt hàng' => [
                        'orders.view'   => 'Xem đơn hàng',
                        'orders.create' => 'Tạo đơn hàng',
                        'orders.edit'   => 'Sửa đơn hàng',
                        'orders.print'  => 'In đơn hàng',
                        'orders.export' => 'Xuất file đơn hàng',
                    ],
                    'Hóa đơn' => [
                        'invoices.view'   => 'Xem hóa đơn',
                        'invoices.create' => 'Tạo hóa đơn',
                        'invoices.edit'   => 'Chỉnh sửa hóa đơn',
                        'invoices.delete' => 'Xóa hóa đơn',
                        'invoices.cancel' => 'Hủy hóa đơn (Step 24.0B)',
                        'invoices.override_time_lock' => 'Sửa/hủy hóa đơn quá hạn (Step 24.3)',
                        'invoices.change_transaction_date' => 'Đổi ngày hóa đơn (Step 24.3)',
                        'invoices.print'  => 'In hóa đơn',
                        'invoices.export' => 'Xuất file hóa đơn',
                    ],
                    'Trả hàng' => [
                        'returns.view'   => 'Xem phiếu trả hàng',
                        'returns.create' => 'Tạo phiếu trả hàng',
                        'returns.cancel' => 'Hủy phiếu trả hàng (Step 24.0B)',
                        'returns.print'  => 'In phiếu trả hàng',
                        'returns.export' => 'Xuất file trả hàng',
                    ],
                ],
            ],
            'Khách hàng' => [
                'customers.view'         => 'Xem khách hàng',
                'customers.create'       => 'Thêm khách hàng',
                'customers.edit'         => 'Sửa khách hàng',
                'customers.delete'       => 'Xóa khách hàng',
                'customers.import'       => 'Import khách hàng',
                'customers.export'       => 'Xuất file khách hàng',
                'customers.debt_view'    => 'Xem công nợ',
                'customers.debt_payment' => 'Thanh toán công nợ',
                'customers.debt_adjust'  => 'Điều chỉnh công nợ',
            ],
            'Bán hàng' => [
                'pos.use' => 'Sử dụng màn hình bán hàng (POS)',
            ],
            'Sổ quỹ' => [
                'cash_flows.view'   => 'Xem sổ quỹ',
                'cash_flows.create' => 'Tạo phiếu thu/chi',
                'cash_flows.edit'   => 'Sửa phiếu thu/chi',
                'cash_flows.delete' => 'Xóa phiếu thu/chi',
                'cash_flows.print'  => 'In phiếu thu/chi',
                'cash_flows.import' => 'Import sổ quỹ',
                'cash_flows.export' => 'Xuất file sổ quỹ',
            ],
            'Nhân viên' => [
                '_sub' => [
                    'Danh sách nhân viên' => [
                        'employees.view'   => 'Xem nhân viên',
                        'employees.create' => 'Thêm nhân viên',
                        'employees.edit'   => 'Sửa nhân viên',
                        'employees.delete' => 'Xóa nhân viên',
                        'employees.import' => 'Import nhân viên',
                        'employees.export' => 'Xuất file nhân viên',
                    ],
                    'Lịch làm việc, chấm công' => [
                        'schedules.view'   => 'Xem lịch làm việc',
                        'schedules.manage' => 'Quản lý lịch làm việc',
                        'attendance.view'   => 'Xem bảng chấm công',
                        'attendance.manage' => 'Quản lý chấm công',
                    ],
                    'Bảng tính lương, thanh toán lương' => [
                        'paysheets.view'   => 'Xem bảng lương',
                        'paysheets.create' => 'Tạo bảng lương',
                        'paysheets.manage' => 'Quản lý bảng lương (khóa, hủy, thanh toán)',
                        'paysheets.print'  => 'In bảng lương',
                        'paysheets.export' => 'Xuất file bảng lương',
                    ],
                    'Thiết lập tính lương' => [
                        'payroll_settings.view'   => 'Xem cài đặt lương',
                        'payroll_settings.manage' => 'Sửa cài đặt lương',
                    ],
                    'Thiết lập ca làm việc, tính công' => [
                        'workday_settings.view'      => 'Xem cài đặt ngày công',
                        'workday_settings.manage'    => 'Sửa cài đặt ngày công',
                        'attendance_settings.view'   => 'Xem cài đặt chấm công',
                        'attendance_settings.manage' => 'Sửa cài đặt chấm công',
                    ],
                    'Thiết lập máy chấm công' => [
                        'attendance_devices.view'   => 'Xem máy chấm công',
                        'attendance_devices.manage' => 'Quản lý máy chấm công',
                    ],
                ],
            ],
            'Công việc & Sửa chữa' => [
                'tasks.view'             => 'Xem công việc',
                'tasks.create'           => 'Tạo công việc',
                'tasks.create_external'  => 'Tạo phiếu sửa chữa khách ngoài (Step 24.0B)',
                'tasks.assign'           => 'Giao nhân viên',
                'tasks.complete'         => 'Hoàn thành công việc',
                'tasks.complete_external' => 'Hoàn thành sửa chữa khách ngoài (Step 24.0B)',
                'tasks.manage_parts'     => 'Quản lý linh kiện',
                'tasks.attach_warranty'  => 'Gắn bảo hành vào phiếu sửa chữa (Step 24.0B)',
                'tasks.apply_warranty_policy' => 'Áp chính sách miễn phí bảo hành (Step 24.0B)',
                'tasks.disassemble'      => 'Bóc tách linh kiện (Step 24.0B)',
                'tasks.manage_categories' => 'Quản lý danh mục công việc',
                'tasks.performance'      => 'Xem báo cáo năng suất',
                // Backward compat aliases
                'repairs.view'           => 'Xem phiếu sửa chữa (cũ)',
                'repairs.create'         => 'Tạo phiếu sửa chữa (cũ)',
                'repairs.assign'         => 'Giao nhân viên sửa chữa (cũ)',
                'repairs.complete'       => 'Hoàn thành sửa chữa (cũ)',
                'repairs.manage_parts'   => 'Quản lý linh kiện sửa chữa (cũ)',
                'repair_performance.view' => 'Xem báo cáo năng suất sửa chữa (cũ)',
                'repair_tiers.manage'    => 'Quản lý bậc năng suất',
            ],
            'Thiết lập' => [
                '_sub' => [
                    'Cửa hàng' => [
                        'settings.view'          => 'Xem thiết lập',
                        'settings.manage'        => 'Sửa thiết lập chung',
                        'settings.categories'    => 'Quản lý danh mục',
                        'settings.brands'        => 'Quản lý thương hiệu',
                        'settings.units'         => 'Quản lý đơn vị tính',
                        'settings.attributes'    => 'Quản lý thuộc tính',
                        'settings.locations'     => 'Quản lý vị trí',
                        'settings.other_fees'    => 'Quản lý thu khác',
                        'settings.bank_accounts' => 'Quản lý tài khoản ngân hàng',
                    ],
                    'Người dùng & Vai trò' => [
                        'users.view'   => 'Xem tài khoản người dùng',
                        'users.create' => 'Tạo tài khoản',
                        'users.edit'   => 'Sửa tài khoản',
                        'users.delete' => 'Xóa tài khoản',
                        'roles.view'   => 'Xem vai trò',
                        'roles.create' => 'Tạo vai trò',
                        'roles.edit'   => 'Sửa vai trò',
                        'roles.delete' => 'Xóa vai trò',
                    ],
                    'Hệ thống' => [
                        'system.audit.view' => 'Xem nhật ký hoạt động (Step 24.0B)',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get a flat list of all available permission keys.
     */
    public static function getAllPermissionKeys(): array
    {
        $keys = [];
        $map = static::getPermissionsMap();
        foreach ($map as $category => $items) {
            if (isset($items['_sub'])) {
                foreach ($items['_sub'] as $sub) {
                    foreach ($sub as $key => $label) {
                        $keys[] = $key;
                    }
                }
                // Also grab top-level items in the same category (if any besides _sub)
                foreach ($items as $key => $val) {
                    if ($key !== '_sub' && is_string($val)) {
                        $keys[] = $key;
                    }
                }
            } else {
                foreach ($items as $key => $label) {
                    $keys[] = $key;
                }
            }
        }
        return $keys;
    }
}
