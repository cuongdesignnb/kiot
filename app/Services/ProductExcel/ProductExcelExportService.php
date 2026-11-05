<?php

namespace App\Services\ProductExcel;

use App\Models\Product;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExcelExportService
{
    public const SHEET_NAME = 'hang_hoa';

    public const TITLE = 'DANH SÁCH HÀNG HÓA';

    public const MAX_COLUMN_WIDTH = 60;

    public function __construct(private ProductExcelFieldCatalog $catalog) {}

    public function download(iterable $products, array $fieldKeys, string $filename): StreamedResponse
    {
        $writer = new Xlsx($this->build($products, $fieldKeys));

        return response()->stream(static function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function build(iterable $products, array $fieldKeys): Spreadsheet
    {
        $labels = $this->catalog->labelsByKey(auth()->user());
        $fieldKeys = array_values(array_filter($fieldKeys, fn (string $key) => isset($labels[$key])));
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($fieldKeys)));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);

        $sheet->setCellValue('A1', config('app.name') && config('app.name') !== 'Laravel' ? config('app.name') : 'KIOTVIET CLONE');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A3', self::TITLE);
        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $headerRow = 5;
        foreach ($fieldKeys as $index => $key) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column.$headerRow, $labels[$key]);
        }

        $headerRange = "A{$headerRow}:{$lastColumn}{$headerRow}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('1F2937');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7EEF7');
        $sheet->getStyle($headerRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter($headerRange);

        $columns = [];
        $widths = [];
        foreach ($fieldKeys as $index => $key) {
            $columns[$index] = Coordinate::stringFromColumnIndex($index + 1);
            $widths[$index] = mb_strlen((string) $labels[$key]);
        }

        // Write values only; styles are applied once per column range below.
        // Styling every cell and auto-sizing every column made large exports
        // slow enough to hit the web server timeout.
        $row = $headerRow + 1;
        foreach ($products as $product) {
            foreach ($fieldKeys as $index => $key) {
                $value = $this->valueFor($product, $key);
                $sheet->setCellValue($columns[$index].$row, $value);
                if ($widths[$index] < self::MAX_COLUMN_WIDTH) {
                    $widths[$index] = max($widths[$index], mb_strlen((string) $value));
                }
            }
            $row++;
        }

        $lastBodyRow = max($headerRow, $row - 1);
        if ($lastBodyRow > $headerRow) {
            foreach ($fieldKeys as $index => $key) {
                $this->styleBodyColumn($sheet, $columns[$index], $headerRow + 1, $lastBodyRow, $key);
            }
        }
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastBodyRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR);

        foreach ($fieldKeys as $index => $key) {
            $sheet->getColumnDimension($columns[$index])->setWidth(min(self::MAX_COLUMN_WIDTH, $widths[$index] + 2));
        }

        return $spreadsheet;
    }

    private function valueFor(Product $product, string $key): mixed
    {
        return match ($key) {
            'category' => $product->category?->name ?? '',
            'brand' => $product->brand?->name ?? '',
            'unit_name' => $product->units->firstWhere('is_base_unit', true)?->unit_name
                ?? $product->units->first()?->unit_name
                ?? '',
            'has_serial', 'allow_point_accumulation', 'sell_directly' => $product->{$key} ? 'Có' : 'Không',
            'is_active' => $product->is_active === false ? 'Ngừng kinh doanh' : 'Đang kinh doanh',
            default => $product->{$key} ?? '',
        };
    }

    private function styleBodyColumn($sheet, string $column, int $firstRow, int $lastRow, string $key): void
    {
        $range = "{$column}{$firstRow}:{$column}{$lastRow}";

        if (in_array($key, ['cost_price', 'retail_price'], true)) {
            $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            return;
        }

        if (in_array($key, ['stock_quantity', 'min_stock', 'max_stock', 'warranty_months'], true)) {
            $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            return;
        }

        if ($key === 'description') {
            $sheet->getStyle($range)->getAlignment()->setWrapText(true);
        }
    }
}
