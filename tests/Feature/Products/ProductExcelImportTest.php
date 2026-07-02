<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductExcelImportTest extends TestCase
{
    use DatabaseTransactions;

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => null]);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'role-import-' . uniqid(),
            'display_name' => 'Import role',
            'permissions' => $permissions,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'SP-IM-' . uniqid(),
            'name' => 'Product Import',
            'type' => 'standard',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 10,
            'is_active' => true,
            'has_serial' => false,
        ], $attrs));
    }

    private function upload(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '1', $header);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1) . ($rowIndex + 2), $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'product-import-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_import_preview_accepts_file_with_name_only(): void
    {
        $response = $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(['Tên hàng'], [['Chivas 10']]),
        ]);

        $response->assertOk()
            ->assertJsonPath('valid_rows', 1)
            ->assertJsonPath('will_create', 1);
        $this->assertDatabaseMissing('products', ['name' => 'Chivas 10']);
    }

    public function test_import_commit_creates_product_with_name_only(): void
    {
        $response = $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Tên hàng'], [['Chivas 10']]),
        ]);

        $response->assertOk()->assertJsonPath('will_create', 1);

        $product = Product::where('name', 'Chivas 10')->firstOrFail();
        $this->assertNotEmpty($product->sku);
        $this->assertSame('standard', $product->type);
        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertTrue((bool) $product->is_active);
    }

    public function test_import_create_only_rejects_duplicate_sku(): void
    {
        $existing = $this->product(['sku' => 'SP001', 'name' => 'Old name']);

        $response = $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng'], [['SP001', 'Old name']]),
        ]);

        $response->assertOk()->assertJsonPath('error_rows', 1);
        $this->assertSame('Old name', $existing->fresh()->name);
    }

    public function test_import_does_not_update_existing_stock_quantity(): void
    {
        $product = $this->product(['sku' => 'SP-STOCK', 'stock_quantity' => 10]);

        $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng', 'Tồn kho'], [['SP-STOCK', $product->name, 999]]),
            'update_stock' => true,
        ])->assertOk();

        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    public function test_import_does_not_update_existing_cost_price(): void
    {
        $product = $this->product(['sku' => 'SP-COST', 'cost_price' => 100000]);

        $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng', 'Giá vốn'], [['SP-COST', $product->name, 999999]]),
            'update_cost_price' => true,
        ])->assertOk();

        $this->assertSame(100000, (int) $product->fresh()->cost_price);
    }

    public function test_import_preview_does_not_write_database(): void
    {
        $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(['Tên hàng'], [['Preview only']]),
        ])->assertOk();

        $this->assertDatabaseMissing('products', ['name' => 'Preview only']);
    }

    public function test_user_without_products_import_permission_gets_403(): void
    {
        $actor = $this->userWith(['products.view']);

        $this->actingAs($actor)->withHeader('Accept', 'application/json')->post('/products/import-preview', [
            'file' => $this->upload(['Tên hàng'], [['No permission']]),
        ])->assertForbidden();

        $this->actingAs($actor)->withHeader('Accept', 'application/json')->post('/products/import-commit', [
            'file' => $this->upload(['Tên hàng'], [['No permission']]),
        ])->assertForbidden();
    }

    public function test_import_money_parser_accepts_vietnamese_format(): void
    {
        $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Tên hàng', 'Giá bán'], [['Money product', '1.500.000']]),
        ])->assertOk();

        $this->assertSame(1500000, (int) Product::where('name', 'Money product')->firstOrFail()->retail_price);
    }

    public function test_import_boolean_parser_accepts_co_khong(): void
    {
        $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Tên hàng', 'Được bán trực tiếp', 'Sử dụng IMEI'], [['Bool product', 'Có', 'Không']]),
        ])->assertOk();

        $product = Product::where('name', 'Bool product')->firstOrFail();
        $this->assertTrue((bool) $product->sell_directly);
        $this->assertFalse((bool) $product->has_serial);
    }

    public function test_import_invalid_type_returns_row_error(): void
    {
        $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(['Tên hàng', 'Loại'], [['Bad type', 'invalid']]),
        ])->assertOk()->assertJsonPath('error_rows', 1);
    }

    public function test_import_preview_returns_detailed_error_log_for_invalid_type(): void
    {
        $response = $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(
                ['Mã hàng', 'Tên hàng', 'Loại'],
                [['APTET12', 'Appleton Estate Rare Blend 12Y', 'Single Malt Scotch Whisky']]
            ),
        ]);

        $response->assertOk();
        $response->assertJsonPath('error_rows', 1);
        $response->assertJsonPath('error_logs.0.field', 'type');
        $response->assertJsonPath('error_logs.0.field_label', 'Loại');
        $response->assertJsonPath('error_logs.0.value', 'Single Malt Scotch Whisky');
        $response->assertJsonPath('error_logs.0.message', 'Loại hàng không hợp lệ.');
        $response->assertJsonPath('error_groups.0.message', 'Loại hàng không hợp lệ.');
        $response->assertJsonPath('error_groups.0.count', 1);
    }

    public function test_import_preview_returns_detailed_error_log_for_missing_name(): void
    {
        $response = $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(
                ['Mã hàng', 'Tên hàng'],
                [['PVN200', '']]
            ),
        ]);

        $response->assertOk();
        $response->assertJsonPath('error_rows', 1);
        $response->assertJsonPath('error_logs.0.field', 'name');
        $response->assertJsonPath('error_logs.0.field_label', 'Tên hàng');
        $response->assertJsonPath('error_logs.0.message', 'Thiếu Tên hàng.');
        $response->assertJsonPath('error_groups.0.message', 'Thiếu Tên hàng.');
        $response->assertJsonPath('error_groups.0.count', 1);
    }

    public function test_import_preview_with_errors_does_not_write_database(): void
    {
        $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(
                ['Mã hàng', 'Tên hàng', 'Loại'],
                [['APTET12', 'Appleton Estate Rare Blend 12Y', 'Single Malt Scotch Whisky']]
            ),
        ])->assertOk();

        $this->assertDatabaseMissing('products', [
            'sku' => 'APTET12',
        ]);
    }

    public function test_import_duplicate_sku_different_name_errors_by_default(): void
    {
        $this->product(['sku' => 'SP001', 'name' => 'Tên cũ']);

        $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng'], [['SP001', 'Tên mới']]),
        ])->assertOk()->assertJsonPath('error_rows', 1);
    }

    public function test_import_duplicate_sku_different_name_can_replace_name_when_enabled(): void
    {
        $product = $this->product(['sku' => 'SP001', 'name' => 'Tên cũ', 'stock_quantity' => 10, 'cost_price' => 100000]);

        $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng', 'Tồn kho', 'Giá vốn'], [['SP001', 'Tên mới', 999, 999999]]),
            'duplicate_name_strategy' => 'replace_name',
        ])->assertOk()->assertJsonPath('will_update', 1);

        $fresh = $product->fresh();
        $this->assertSame('Tên mới', $fresh->name);
        $this->assertSame(10, (int) $fresh->stock_quantity);
        $this->assertSame(100000, (int) $fresh->cost_price);
    }

    public function test_import_duplicate_barcode_different_sku_errors_by_default(): void
    {
        $this->product(['sku' => 'SP001', 'barcode' => 'BC001']);

        $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(['Mã hàng', 'Mã vạch', 'Tên hàng'], [['SP999', 'BC001', 'Name']]),
        ])->assertOk()->assertJsonPath('error_rows', 1);
    }

    public function test_import_duplicate_barcode_different_sku_can_replace_sku_when_enabled(): void
    {
        $product = $this->product(['sku' => 'SP001', 'barcode' => 'BC001']);

        $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Mã hàng', 'Mã vạch', 'Tên hàng'], [['SP999', 'BC001', $product->name]]),
            'duplicate_barcode_sku_strategy' => 'replace_sku',
        ])->assertOk()->assertJsonPath('will_update', 1);

        $this->assertSame('SP999', $product->fresh()->sku);
    }

    public function test_import_update_stock_option_does_not_update_existing_stock_in_phase_1(): void
    {
        $product = $this->product(['sku' => 'SP-STOCK2', 'stock_quantity' => 10]);

        $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng', 'Tồn kho'], [['SP-STOCK2', $product->name, 999]]),
            'update_stock' => true,
        ])->assertOk()->assertJsonPath('warning_rows', 1);

        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    public function test_import_update_cost_option_does_not_update_existing_cost_in_phase_1(): void
    {
        $product = $this->product(['sku' => 'SP-COST2', 'cost_price' => 100000]);

        $this->actingAs($this->admin())->post('/products/import-preview', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng', 'Giá vốn'], [['SP-COST2', $product->name, 999999]]),
            'update_cost_price' => true,
        ])->assertOk()->assertJsonPath('warning_rows', 1);

        $this->assertSame(100000, (int) $product->fresh()->cost_price);
    }

    public function test_import_update_description_updates_existing_description_when_enabled(): void
    {
        $product = $this->product(['sku' => 'SP-DESC', 'description' => 'Mô tả cũ']);

        $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng', 'Mô tả'], [['SP-DESC', $product->name, 'Mô tả mới']]),
            'update_description' => true,
        ])->assertOk()->assertJsonPath('will_update', 1);

        $this->assertSame('Mô tả mới', $product->fresh()->description);
    }

    public function test_import_update_description_skips_existing_description_by_default(): void
    {
        $product = $this->product(['sku' => 'SP-DESC2', 'description' => 'Mô tả cũ']);

        $this->actingAs($this->admin())->post('/products/import-commit', [
            'file' => $this->upload(['Mã hàng', 'Tên hàng', 'Mô tả'], [['SP-DESC2', $product->name, 'Mô tả mới']]),
        ])->assertOk();

        $this->assertSame('Mô tả cũ', $product->fresh()->description);
    }
}
