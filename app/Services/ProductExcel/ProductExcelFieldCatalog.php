<?php

namespace App\Services\ProductExcel;

use App\Models\User;

class ProductExcelFieldCatalog
{
    public function groups(): array
    {
        return [
            'basic' => 'Thông tin cơ bản',
            'price_stock' => 'Giá bán & Tồn kho',
            'unit' => 'Đơn vị tính',
            'attribute' => 'Thuộc tính',
            'serial' => 'Serial/IMEI',
            'point' => 'Điểm thưởng',
            'other' => 'Thông tin khác',
            'warranty' => 'Bảo hành, bảo trì',
        ];
    }

    public function fields(): array
    {
        return [
            $this->field('sku', 'Mã hàng', 'basic', true, true, true, true, null, 'low', 'Nếu import rỗng thì backend tự sinh.'),
            $this->field('name', 'Tên hàng', 'basic', true, true, true, true, null, 'low', 'Bắt buộc khi import.', true),
            $this->field('type', 'Loại', 'basic', true, true, true, true, null, 'low', 'Mặc định standard.'),
            $this->field('category', 'Nhóm hàng', 'basic', true, true, true, true, null, 'medium', 'Phase 1 chỉ map nhóm hàng đã có.'),
            $this->field('brand', 'Thương hiệu', 'basic', true, true, true, true, null, 'medium', 'Phase 1 chỉ map thương hiệu đã có.'),
            $this->field('barcode', 'Mã vạch', 'basic', true, true, true, true, null, 'medium', 'Dùng để nhận diện trùng dữ liệu.'),
            $this->field('cost_price', 'Giá vốn', 'price_stock', true, true, true, false, 'products.view_cost_price', 'high', 'Không cập nhật giá vốn hàng cũ trong phase 1.'),
            $this->field('retail_price', 'Giá bán', 'price_stock', true, true, true, true, null, 'medium', 'Mặc định 0 khi tạo mới.'),
            $this->field('stock_quantity', 'Tồn kho', 'price_stock', true, true, true, false, null, 'high', 'Chỉ áp dụng khi tạo hàng mới, không tạo stock movement.'),
            $this->field('min_stock', 'Định mức tồn ít nhất', 'price_stock', true, true, true, false),
            $this->field('max_stock', 'Định mức tồn nhiều nhất', 'price_stock', true, true, true, false),
            $this->field('unit_name', 'Đơn vị tính', 'unit', true, false, false, false, null, 'medium', 'Phase 1 chỉ export đơn vị cơ sở.'),
            $this->field('has_serial', 'Sử dụng IMEI', 'serial', true, true, false, false, null, 'high', 'Không tạo danh sách serial/IMEI từ file import.'),
            $this->field('allow_point_accumulation', 'Tích điểm', 'point', true, true, false, false),
            $this->field('sell_directly', 'Được bán trực tiếp', 'other', true, true, false, false),
            $this->field('is_active', 'Trạng thái', 'other', true, true, true, true),
            $this->field('weight', 'Trọng lượng', 'other', true, true, false, false),
            $this->field('location', 'Vị trí', 'other', true, true, false, false),
            $this->field('description', 'Mô tả', 'other', true, true, true, false, null, 'low', 'Có thể cập nhật hàng cũ nếu bật option.'),
            $this->field('warranty_months', 'Thời gian bảo hành', 'warranty', true, true, false, false),
        ];
    }

    public function visibleFor(?User $user): array
    {
        return array_values(array_filter($this->fields(), fn (array $field) => $this->allowed($field, $user)));
    }

    public function exportableFor(?User $user): array
    {
        return array_values(array_filter($this->visibleFor($user), fn (array $field) => $field['exportable']));
    }

    public function importableFor(?User $user): array
    {
        return array_values(array_filter($this->visibleFor($user), fn (array $field) => $field['importable']));
    }

    public function selectedExportFields(array $requested, ?User $user): array
    {
        $fields = $this->exportableFor($user);
        $allowed = array_column($fields, null, 'key');
        $requested = array_values(array_unique(array_filter($requested)));

        if ($requested === []) {
            $requested = array_values(array_map(
                fn (array $field) => $field['key'],
                array_filter($fields, fn (array $field) => $field['default_export'])
            ));
        }

        $selected = array_values(array_filter($requested, fn (string $key) => isset($allowed[$key])));

        return $selected !== [] ? $selected : ['sku', 'name'];
    }

    public function labelsByKey(?User $user = null): array
    {
        return array_column($this->visibleFor($user), 'label', 'key');
    }

    public function payload(?User $user): array
    {
        return [
            'groups' => $this->groups(),
            'fields' => $this->visibleFor($user),
        ];
    }

    private function allowed(array $field, ?User $user): bool
    {
        return empty($field['permission']) || ($user && $user->hasPermission($field['permission']));
    }

    private function field(
        string $key,
        string $label,
        string $group,
        bool $exportable,
        bool $importable,
        bool $defaultExport,
        bool $defaultImport,
        ?string $permission = null,
        string $risk = 'low',
        string $note = '',
        bool $requiredForImport = false
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'exportable' => $exportable,
            'importable' => $importable,
            'default_export' => $defaultExport,
            'default_import' => $defaultImport,
            'required_for_import' => $requiredForImport,
            'permission' => $permission,
            'risk' => $risk,
            'note' => $note,
        ];
    }
}
