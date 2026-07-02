<?php

namespace App\Services\ProductExcel;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExcelImportService
{
    private const VALID_TYPES = ['standard', 'service', 'combo', 'manufactured'];

    private const HEADER_ALIASES = [
        'sku' => ['ma hang', 'ma san pham', 'sku', 'mã hàng'],
        'name' => ['ten hang', 'ten san pham', 'name', 'tên hàng'],
        'type' => ['loai', 'loai hang', 'type', 'loại'],
        'category' => ['nhom hang', 'category', 'nhóm hàng'],
        'brand' => ['thuong hieu', 'brand', 'thương hiệu'],
        'barcode' => ['ma vach', 'barcode', 'mã vạch'],
        'cost_price' => ['gia von', 'cost price', 'giá vốn'],
        'retail_price' => ['gia ban', 'retail price', 'giá bán'],
        'stock_quantity' => ['ton kho', 'stock', 'tồn kho'],
        'min_stock' => ['dinh muc ton it nhat', 'min stock', 'định mức tồn ít nhất'],
        'max_stock' => ['dinh muc ton nhieu nhat', 'max stock', 'định mức tồn nhiều nhất'],
        'has_serial' => ['su dung imei', 'serial/imei', 'imei', 'sử dụng imei'],
        'allow_point_accumulation' => ['tich diem', 'diem thuong', 'tích điểm'],
        'sell_directly' => ['duoc ban truc tiep', 'ban truc tiep', 'được bán trực tiếp'],
        'is_active' => ['trang thai', 'status', 'trạng thái'],
        'weight' => ['trong luong', 'weight', 'trọng lượng'],
        'location' => ['vi tri', 'location', 'vị trí'],
        'description' => ['mo ta', 'description', 'mô tả'],
        'warranty_months' => ['thoi gian bao hanh', 'bao hanh', 'thời gian bảo hành'],
    ];

    public function __construct(private ProductExcelFieldCatalog $catalog)
    {
    }

    public function template(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(ProductExcelExportService::SHEET_NAME);

        $fields = array_values(array_filter(
            $this->catalog->importableFor(auth()->user()),
            fn (array $field) => $field['default_import'] || in_array($field['key'], ['retail_price', 'description'], true)
        ));

        foreach ($fields as $index => $field) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . '1', $field['label']);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->setCellValue('A2', 'Tên hàng mẫu');
        $sheet->setCellValue('C2', 'standard');
        $sheet->setCellValue('H2', 0);

        $writer = new Xlsx($spreadsheet);

        return response()->stream(static function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="mau_import_hang_hoa.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function preview(UploadedFile $file, array $options = []): array
    {
        [$headers, $rows] = $this->readRows($file);
        $mappedHeaders = $this->mapHeaders($headers);
        $items = [];

        foreach ($rows as $index => $row) {
            $items[] = $this->analyseRow($index + 2, $this->rowToData($row, $mappedHeaders), $options, false);
        }

        return $this->summary($items, $headers);
    }

    public function commit(UploadedFile $file, array $options = []): array
    {
        [$headers, $rows] = $this->readRows($file);
        $mappedHeaders = $this->mapHeaders($headers);
        $items = [];
        $created = [];
        $updated = [];

        DB::transaction(function () use ($rows, $mappedHeaders, $options, &$items, &$created, &$updated): void {
            foreach ($rows as $index => $row) {
                $item = $this->analyseRow($index + 2, $this->rowToData($row, $mappedHeaders), $options, true);

                if ($item['errors'] !== []) {
                    $items[] = $item;
                    continue;
                }

                if ($item['action'] === 'create') {
                    $product = Product::create($this->payloadForCreate($item['data']));
                    $item['product_id'] = $product->id;
                    $created[] = $product->id;
                } elseif ($item['action'] === 'update') {
                    $changes = $this->payloadForUpdate($item);
                    if ($changes !== []) {
                        Product::whereKey($item['existing_product']['id'])->update($changes);
                        $updated[] = $item['existing_product']['id'];
                    }
                }

                $items[] = $item;
            }
        });

        $summary = $this->summary($items, $headers);
        $summary['created_product_ids'] = $created;
        $summary['updated_product_ids'] = array_values(array_unique($updated));

        return $summary;
    }

    private function analyseRow(int $rowNumber, array $data, array $options, bool $commit): array
    {
        $warnings = [];
        $errors = [];
        $data = $this->normalizeData($data);

        if ($data['name'] === '') {
            $errors[] = 'Thiếu Tên hàng.';
        }

        if (!in_array($data['type'], self::VALID_TYPES, true)) {
            $errors[] = 'Loại hàng không hợp lệ.';
        }

        $existingBySku = $data['sku'] !== '' ? Product::where('sku', $data['sku'])->first() : null;
        $existingByBarcode = $data['barcode'] !== '' ? Product::where('barcode', $data['barcode'])->first() : null;
        $existing = $existingBySku ?: $existingByBarcode;
        $action = 'create';
        $willUpdateName = false;
        $willUpdateSku = false;
        $willUpdateDescription = false;

        if ($existing) {
            $action = 'error';

            if ($data['stock_quantity_provided']) {
                $warnings[] = 'Tồn kho trong file chỉ áp dụng khi tạo hàng mới. Không cập nhật tồn kho hàng cũ.';
            }
            if ($data['cost_price_provided']) {
                $warnings[] = 'Giá vốn sản phẩm cũ sẽ không bị cập nhật qua import này.';
            }

            if ($existingBySku && $data['name'] !== '' && $existingBySku->name !== $data['name']) {
                if (($options['duplicate_name_strategy'] ?? 'error') === 'replace_name') {
                    $action = 'update';
                    $willUpdateName = true;
                } else {
                    $errors[] = 'Trùng mã hàng/mã vạch nhưng khác tên hàng hóa.';
                }
            }

            if ($existingByBarcode && $data['sku'] !== '' && $existingByBarcode->sku !== $data['sku']) {
                if (($options['duplicate_barcode_sku_strategy'] ?? 'error') === 'replace_sku') {
                    if (Product::where('sku', $data['sku'])->whereKeyNot($existingByBarcode->id)->exists()) {
                        $errors[] = 'Mã hàng mới đã tồn tại.';
                    } else {
                        $action = 'update';
                        $willUpdateSku = true;
                    }
                } else {
                    $errors[] = 'Trùng mã vạch nhưng khác mã hàng.';
                }
            }

            if (($options['update_description'] ?? false) && $data['description'] !== '') {
                $action = 'update';
                $willUpdateDescription = true;
            }

            if (!$willUpdateName && !$willUpdateSku && !$willUpdateDescription && $errors === []) {
                $errors[] = 'Hàng hóa đã tồn tại. Phase 1 mặc định không cập nhật hàng cũ.';
            }
        }

        if (($options['update_stock'] ?? false) && $existing) {
            $warnings[] = 'Phase hiện tại không cập nhật tồn kho hàng cũ qua import hàng hóa.';
        }
        if (($options['update_cost_price'] ?? false) && $existing) {
            $warnings[] = 'Phase hiện tại không cập nhật giá vốn hàng cũ qua import hàng hóa.';
        }
        if ($data['has_serial']) {
            $warnings[] = 'Import này chỉ đánh dấu sản phẩm có quản lý serial, không tạo danh sách serial/IMEI.';
        }
        if ($data['sku'] === '' && !$existing) {
            $warnings[] = 'Mã hàng trống, backend sẽ tự sinh SKU khi xác nhận nhập.';
        }

        return [
            'row' => $rowNumber,
            'action' => $errors === [] ? $action : 'error',
            'data' => $data,
            'existing_product' => $existing ? [
                'id' => $existing->id,
                'sku' => $existing->sku,
                'barcode' => $existing->barcode,
                'name' => $existing->name,
            ] : null,
            'will_update_name' => $willUpdateName,
            'will_update_sku' => $willUpdateSku,
            'will_update_description' => $willUpdateDescription,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
            'warning_details' => $this->buildRowWarningDetails($rowNumber, $data, $warnings),
            'error_details' => $this->buildRowErrorDetails($rowNumber, $data, $errors),
        ];
    }

    private function payloadForCreate(array $data): array
    {
        $sku = $data['sku'] !== '' ? $data['sku'] : $this->generateSku();
        $barcode = $data['barcode'] !== '' ? $data['barcode'] : null;

        if ($barcode !== null && Product::where('barcode', $barcode)->exists()) {
            $barcode = null;
        }

        return [
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $data['name'],
            'type' => $data['type'],
            'category_id' => $data['category_id'],
            'brand_id' => $data['brand_id'],
            'cost_price' => $data['cost_price'],
            'retail_price' => $data['retail_price'],
            'stock_quantity' => $data['stock_quantity'],
            'min_stock' => $data['min_stock'],
            'max_stock' => $data['max_stock'],
            'has_serial' => $data['has_serial'],
            'is_active' => $data['is_active'],
            'allow_point_accumulation' => $data['allow_point_accumulation'],
            'sell_directly' => $data['sell_directly'],
            'weight' => $data['weight'] ?: null,
            'location' => $data['location'] ?: null,
            'description' => $data['description'] ?: null,
            'warranty_months' => $data['warranty_months'],
        ];
    }

    private function payloadForUpdate(array $item): array
    {
        $data = $item['data'];
        $changes = [];

        if ($item['will_update_name']) {
            $changes['name'] = $data['name'];
        }
        if ($item['will_update_sku']) {
            $changes['sku'] = $data['sku'];
        }
        if ($item['will_update_description']) {
            $changes['description'] = $data['description'];
        }

        return $changes;
    }

    private function normalizeData(array $data): array
    {
        $categoryName = trim((string) ($data['category'] ?? ''));
        $brandName = trim((string) ($data['brand'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));

        return [
            'sku' => trim((string) ($data['sku'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'type' => $type !== '' ? $type : 'standard',
            'category' => $categoryName,
            'category_id' => $categoryName !== '' ? Category::where('name', $categoryName)->value('id') : null,
            'brand' => $brandName,
            'brand_id' => $brandName !== '' ? Brand::where('name', $brandName)->value('id') : null,
            'barcode' => trim((string) ($data['barcode'] ?? '')),
            'cost_price' => $this->parseMoney($data['cost_price'] ?? 0),
            'cost_price_provided' => array_key_exists('cost_price', $data) && trim((string) $data['cost_price']) !== '',
            'retail_price' => $this->parseMoney($data['retail_price'] ?? 0),
            'stock_quantity' => $this->parseInt($data['stock_quantity'] ?? 0),
            'stock_quantity_provided' => array_key_exists('stock_quantity', $data) && trim((string) $data['stock_quantity']) !== '',
            'min_stock' => $this->parseInt($data['min_stock'] ?? 0),
            'max_stock' => $this->parseNullableInt($data['max_stock'] ?? null),
            'has_serial' => $this->parseBool($data['has_serial'] ?? false),
            'is_active' => $this->parseActive($data['is_active'] ?? true),
            'allow_point_accumulation' => $this->parseBool($data['allow_point_accumulation'] ?? true),
            'sell_directly' => $this->parseBool($data['sell_directly'] ?? true),
            'weight' => trim((string) ($data['weight'] ?? '')),
            'location' => trim((string) ($data['location'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'warranty_months' => $this->parseNullableInt($data['warranty_months'] ?? null),
        ];
    }

    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $sheetRows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        } else {
            $sheetRows = array_map('str_getcsv', file($file->getRealPath()));
        }

        $sheetRows = array_values(array_filter($sheetRows, fn (array $row) => $this->rowHasValue($row)));
        $headers = array_shift($sheetRows) ?: [];
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }

        return [$headers, $sheetRows];
    }

    private function rowToData(array $row, array $mappedHeaders): array
    {
        $data = [];
        foreach ($mappedHeaders as $index => $key) {
            if ($key !== null) {
                $data[$key] = $row[$index] ?? null;
            }
        }

        return $data;
    }

    private function mapHeaders(array $headers): array
    {
        return array_map(function ($header): ?string {
            $normalized = $this->normalizeHeader((string) $header);
            foreach (self::HEADER_ALIASES as $key => $aliases) {
                foreach ($aliases as $alias) {
                    if ($normalized === $this->normalizeHeader($alias)) {
                        return $key;
                    }
                }
            }

            return null;
        }, $headers);
    }

    private function summary(array $items, array $headers): array
    {
        $valid = array_filter($items, fn (array $item) => $item['errors'] === []);
        $errors = array_filter($items, fn (array $item) => $item['errors'] !== []);
        $warnings = array_filter($items, fn (array $item) => $item['warnings'] !== []);

        return [
            'headers' => $headers,
            'total_rows' => count($items),
            'valid_rows' => count($valid),
            'warning_rows' => count($warnings),
            'error_rows' => count($errors),
            'will_create' => count(array_filter($valid, fn (array $item) => $item['action'] === 'create')),
            'will_update' => count(array_filter($valid, fn (array $item) => $item['action'] === 'update')),
            'will_skip' => count(array_filter($items, fn (array $item) => $item['action'] === 'skip')),
            'rows' => array_slice($items, 0, 20),
            'error_logs' => $this->buildErrorLogs($items),
            'warning_logs' => $this->buildWarningLogs($items),
            'error_groups' => $this->groupErrors($items),
            'warning_groups' => $this->groupWarnings($items),
            'phase_policy' => [
                'updates_existing_stock' => false,
                'updates_existing_cost_price' => false,
                'creates_stock_movement' => false,
                'creates_serials' => false,
            ],
        ];
    }

    private function buildErrorLogs(array $items): array
    {
        $logs = [];

        foreach ($items as $item) {
            foreach (($item['error_details'] ?? []) as $detail) {
                $logs[] = $detail;
            }
        }

        return $logs;
    }

    private function buildWarningLogs(array $items): array
    {
        $logs = [];

        foreach ($items as $item) {
            foreach (($item['warning_details'] ?? []) as $detail) {
                $logs[] = $detail;
            }
        }

        return $logs;
    }

    private function groupErrors(array $items): array
    {
        return $this->groupMessages($this->buildErrorLogs($items));
    }

    private function groupWarnings(array $items): array
    {
        return $this->groupMessages($this->buildWarningLogs($items));
    }

    private function groupMessages(array $logs): array
    {
        $groups = [];

        foreach ($logs as $log) {
            $key = $log['message'] ?? 'Không xác định';

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'message' => $key,
                    'count' => 0,
                    'field' => $log['field'] ?? null,
                    'field_label' => $log['field_label'] ?? null,
                    'suggestion' => $log['suggestion'] ?? '',
                    'sample_rows' => [],
                ];
            }

            $groups[$key]['count']++;

            if (count($groups[$key]['sample_rows']) < 10) {
                $groups[$key]['sample_rows'][] = $log['row'];
            }
        }

        return array_values($groups);
    }

    private function buildRowErrorDetails(int $rowNumber, array $data, array $errors): array
    {
        $details = [];

        foreach (array_values(array_unique($errors)) as $message) {
            $details[] = $this->makeIssueDetail($rowNumber, $data, $message, 'error');
        }

        return $details;
    }

    private function buildRowWarningDetails(int $rowNumber, array $data, array $warnings): array
    {
        $details = [];

        foreach (array_values(array_unique($warnings)) as $message) {
            $details[] = $this->makeIssueDetail($rowNumber, $data, $message, 'warning');
        }

        return $details;
    }

    private function makeIssueDetail(int $rowNumber, array $data, string $message, string $level): array
    {
        [$field, $fieldLabel, $value, $suggestion] = $this->resolveIssueContext($data, $message);

        return [
            'level' => $level,
            'row' => $rowNumber,
            'sku' => $data['sku'] ?? '',
            'barcode' => $data['barcode'] ?? '',
            'name' => $data['name'] ?? '',
            'field' => $field,
            'field_label' => $fieldLabel,
            'value' => $value,
            'message' => $message,
            'suggestion' => $suggestion,
        ];
    }

    private function resolveIssueContext(array $data, string $message): array
    {
        if ($message === 'Thiếu Tên hàng.') {
            return [
                'name',
                'Tên hàng',
                $data['name'] ?? '',
                'Bổ sung tên hàng hoặc xóa dòng này khỏi file import.',
            ];
        }

        if ($message === 'Loại hàng không hợp lệ.') {
            return [
                'type',
                'Loại',
                $data['type'] ?? '',
                'Cột Loại chỉ nhận standard/service/combo/manufactured. Nếu giá trị này là nhóm hàng, hãy chuyển sang cột Nhóm hàng và để Loại trống hoặc nhập standard.',
            ];
        }

        if ($message === 'Trùng mã hàng/mã vạch nhưng khác tên hàng hóa.') {
            return [
                'sku',
                'Mã hàng',
                $data['sku'] ?? '',
                'Kiểm tra lại mã hàng/mã vạch. Nếu muốn cập nhật tên hàng cũ, chọn tùy chọn thay thế tên hàng trước khi kiểm tra file.',
            ];
        }

        if ($message === 'Trùng mã vạch nhưng khác mã hàng.') {
            return [
                'barcode',
                'Mã vạch',
                $data['barcode'] ?? '',
                'Kiểm tra lại mã vạch. Nếu muốn thay mã hàng cũ bằng mã hàng mới, chọn tùy chọn thay thế mã hàng trước khi kiểm tra file.',
            ];
        }

        if ($message === 'Mã hàng mới đã tồn tại.') {
            return [
                'sku',
                'Mã hàng',
                $data['sku'] ?? '',
                'Đổi mã hàng mới hoặc kiểm tra lại dữ liệu trùng trong hệ thống.',
            ];
        }

        if ($message === 'Hàng hóa đã tồn tại. Phase 1 mặc định không cập nhật hàng cũ.') {
            return [
                'sku',
                'Mã hàng',
                $data['sku'] ?? '',
                'Nếu muốn cập nhật dữ liệu hàng cũ, chọn đúng tùy chọn thay thế tên/mã hoặc cập nhật mô tả. Tồn kho và giá vốn hàng cũ vẫn không cập nhật qua import này.',
            ];
        }

        if (str_contains($message, 'Tồn kho')) {
            return [
                'stock_quantity',
                'Tồn kho',
                $data['stock_quantity'] ?? '',
                'Tồn kho hàng cũ không cập nhật qua import hàng hóa. Hãy dùng phiếu nhập hoặc kiểm kho nếu cần điều chỉnh tồn.',
            ];
        }

        if (str_contains($message, 'Giá vốn')) {
            return [
                'cost_price',
                'Giá vốn',
                $data['cost_price'] ?? '',
                'Giá vốn hàng cũ không cập nhật qua import hàng hóa. Giá vốn chỉ áp dụng khi tạo hàng mới, nếu file có cột Giá vốn và user có quyền.',
            ];
        }

        if (str_contains($message, 'serial') || str_contains($message, 'IMEI')) {
            return [
                'has_serial',
                'Serial/IMEI',
                $data['has_serial'] ?? '',
                'Import này chỉ đánh dấu sản phẩm có quản lý serial, không tạo danh sách serial/IMEI.',
            ];
        }

        return [
            null,
            null,
            '',
            'Kiểm tra lại dữ liệu dòng này theo file mẫu import.',
        ];
    }

    private function rowHasValue(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $from = ['á','à','ả','ã','ạ','ă','ắ','ằ','ẳ','ẵ','ặ','â','ấ','ầ','ẩ','ẫ','ậ','đ','é','è','ẻ','ẽ','ẹ','ê','ế','ề','ể','ễ','ệ','í','ì','ỉ','ĩ','ị','ó','ò','ỏ','õ','ọ','ô','ố','ồ','ổ','ỗ','ộ','ơ','ớ','ờ','ở','ỡ','ợ','ú','ù','ủ','ũ','ụ','ư','ứ','ừ','ử','ữ','ự','ý','ỳ','ỷ','ỹ','ỵ'];
        $to = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','d','e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y'];

        return preg_replace('/\s+/', ' ', str_replace($from, $to, $value));
    }

    private function parseMoney(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^\d\-]/', '', (string) $value);

        return $clean === '' ? 0.0 : (float) $clean;
    }

    private function parseInt(mixed $value): int
    {
        return max(0, (int) $this->parseMoney($value));
    }

    private function parseNullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->parseInt($value);
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = $this->normalizeHeader((string) $value);

        return in_array($normalized, ['1', 'true', 'yes', 'co', 'có', 'active', 'dang kinh doanh'], true);
    }

    private function parseActive(mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return true;
        }

        $normalized = $this->normalizeHeader((string) $value);

        return !in_array($normalized, ['0', 'false', 'no', 'khong', 'không', 'ngung kinh doanh'], true);
    }

    private function generateSku(): string
    {
        do {
            $sku = 'SP' . date('ymd') . str_pad((string) random_int(1000, 99999), 5, '0', STR_PAD_LEFT);
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }
}
