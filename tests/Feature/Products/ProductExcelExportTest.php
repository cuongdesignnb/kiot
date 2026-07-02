<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProductExcelExportTest extends TestCase
{
    use DatabaseTransactions;

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'role-export-' . uniqid(),
            'display_name' => 'Export role',
            'permissions' => $permissions,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => null]);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'SP-EX-' . uniqid(),
            'name' => 'Product Export',
            'type' => 'standard',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 5,
            'is_active' => true,
            'has_serial' => false,
        ], $attrs));
    }

    private function workbook(string $query, ?User $actor = null): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $response = $this->actingAs($actor ?: $this->admin())->get('/products/export' . $query);
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'product-export-') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());

        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_user_with_products_export_permission_can_download_xlsx(): void
    {
        $this->product();
        $actor = $this->userWith(['products.export']);

        $response = $this->actingAs($actor)->get('/products/export?fields[]=sku&fields[]=name');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', strtolower($response->headers->get('Content-Type') ?? ''));
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_export_workbook_has_hang_hoa_sheet(): void
    {
        $this->product();

        $this->assertNotNull($this->workbook('?fields[]=sku&fields[]=name')->getSheetByName('hang_hoa'));
    }

    public function test_export_has_selected_headers_only(): void
    {
        $this->product();
        $sheet = $this->workbook('?fields[]=sku&fields[]=name')->getSheetByName('hang_hoa');
        $headers = $sheet->rangeToArray('A5:C5')[0];

        $this->assertSame('Mã hàng', $headers[0]);
        $this->assertSame('Tên hàng', $headers[1]);
        $this->assertEmpty($headers[2]);
    }

    public function test_export_default_fields_when_no_fields_given(): void
    {
        $this->product();
        $flat = implode(' ', $this->workbook('')->getSheetByName('hang_hoa')->rangeToArray('A5:Z5')[0]);

        $this->assertStringContainsString('Mã hàng', $flat);
        $this->assertStringContainsString('Tên hàng', $flat);
        $this->assertStringContainsString('Giá bán', $flat);
    }

    public function test_export_does_not_include_cost_price_without_permission(): void
    {
        $this->product();
        $actor = $this->userWith(['products.export']);
        $flat = implode(' ', $this->workbook('?fields[]=sku&fields[]=cost_price', $actor)->getSheetByName('hang_hoa')->rangeToArray('A5:Z5')[0]);

        $this->assertStringNotContainsString('Giá vốn', $flat);
    }

    public function test_export_includes_cost_price_with_permission(): void
    {
        $this->product();
        $actor = $this->userWith(['products.export', 'products.view_cost_price']);
        $flat = implode(' ', $this->workbook('?fields[]=sku&fields[]=cost_price', $actor)->getSheetByName('hang_hoa')->rangeToArray('A5:Z5')[0]);

        $this->assertStringContainsString('Giá vốn', $flat);
    }

    public function test_export_respects_product_filters(): void
    {
        $categoryA = Category::create(['name' => 'Cat A ' . uniqid()]);
        $categoryB = Category::create(['name' => 'Cat B ' . uniqid()]);
        $in = $this->product(['name' => 'Filtered in', 'category_id' => $categoryA->id]);
        $out = $this->product(['name' => 'Filtered out', 'category_id' => $categoryB->id]);

        $flat = implode(' ', array_merge(...$this->workbook('?fields[]=sku&fields[]=name&category_id=' . $categoryA->id)->getSheetByName('hang_hoa')->toArray()));

        $this->assertStringContainsString($in->name, $flat);
        $this->assertStringNotContainsString($out->name, $flat);
    }

    public function test_export_file_has_title_and_headers(): void
    {
        $this->product();
        $sheet = $this->workbook('?fields[]=sku&fields[]=name')->getSheetByName('hang_hoa');

        $this->assertSame('DANH SÁCH HÀNG HÓA', $sheet->getCell('A3')->getValue());
        $this->assertSame('Mã hàng', $sheet->getCell('A5')->getValue());
        $this->assertSame('Tên hàng', $sheet->getCell('B5')->getValue());
    }
}
