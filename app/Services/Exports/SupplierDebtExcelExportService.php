<?php

namespace App\Services\Exports;

use App\Models\Customer;
use App\Models\InvoiceItem;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturnItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * HOTFIX 24.17B — Render KiotViet-style "Công nợ chi tiết nhà cung cấp"
 * workbook from a precomputed full-ledger entry list.
 *
 * Important: the caller must hand in entries that already carry the
 * correct `debt_remain` (computed on the full ledger, not on the
 * filtered slice). This service only renders — it does NOT recompute
 * balances. That preserves the HOTFIX 24.14/24.15/24.17 contract that
 * `debt_remain` stays anchored to the chronological full ledger.
 */
class SupplierDebtExcelExportService
{
    /** @var array<int,array<string,mixed>> */
    private array $entries;
    private Customer $supplier;
    private ?Carbon $from;
    private ?Carbon $to;
    /** @var array<string,bool> */
    private array $columns;
    private bool $includeDetail;

    public const SHEET_NAME    = 'CNCT';
    public const REPORT_TITLE  = 'Công nợ chi tiết nhà cung cấp';

    private const HEADERS = [
        'Thời gian',
        'Mã',
        'Diễn giải',
        'ĐVT',
        'SL',
        'Đơn giá',
        'Giảm giá',
        'VAT',
        'Giá nhập/trả',
        'Thành tiền',
        'Ghi nợ',
        'Ghi có',
    ];

    private const COL_WIDTHS = [
        'A' => 14, 'B' => 18, 'C' => 35, 'D' => 10, 'E' => 8, 'F' => 14,
        'G' => 14, 'H' => 12, 'I' => 14, 'J' => 14, 'K' => 15, 'L' => 15,
    ];

    public function __construct(
        array $entries,
        Customer $supplier,
        ?Carbon $from,
        ?Carbon $to,
        bool $includeDetail,
        array $selectedColumns
    ) {
        $this->entries       = $entries;
        $this->supplier      = $supplier;
        $this->from          = $from;
        $this->to            = $to;
        $this->includeDetail = $includeDetail;
        // Detail-column toggles control whether each per-line cell is
        // populated; the column itself stays on the sheet so the
        // KiotViet-style layout (Thời gian → Ghi có) is consistent.
        $allCols = ['unit','quantity','unit_price','discount','vat','cost','line_total','note'];
        $this->columns = [];
        foreach ($allCols as $c) {
            $this->columns[$c] = in_array($c, $selectedColumns, true);
        }
    }

    public function download(string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = $this->build();
        $writer = new Xlsx($spreadsheet);

        $callback = function () use ($writer) {
            $writer->save('php://output');
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    public function build(): Spreadsheet
    {
        // Filter entries into the display window — but compute opening
        // / period totals against the FULL ledger we were handed.
        $inWindow = array_values(array_filter($this->entries, fn($e) => $this->isInWindow($e)));
        $opening  = $this->computeOpeningDebt();
        $debit    = 0; $credit = 0;
        foreach ($inWindow as $e) {
            $eff = (float) ($e['supplier_effect'] ?? 0);
            if ($eff > 0) $debit  += $eff;
            if ($eff < 0) $credit += -$eff;
        }
        $closing = $opening + $debit - $credit;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);

        // Apply column widths up front.
        foreach (self::COL_WIDTHS as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $row = 1;
        $row = $this->writeStoreHeader($sheet, $row);
        $row = $this->writeTitle($sheet, $row);
        $row = $this->writeSupplierAndSummary($sheet, $row, $opening, $debit, $credit, $closing);
        $row = $this->writeTableHeader($sheet, $row);
        $sheet->freezePane('A' . ($row));
        $this->writeRows($sheet, $row, $inWindow);

        return $spreadsheet;
    }

    private function writeStoreHeader($sheet, int $row): int
    {
        $store = $this->resolveStoreInfo();
        $sheet->setCellValue('A' . $row, $store['name']);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(13);
        $row++;
        if (!empty($store['address'])) {
            $sheet->setCellValue('A' . $row, 'Địa chỉ: ' . $store['address']);
            $row++;
        }
        if (!empty($store['phone'])) {
            $sheet->setCellValue('A' . $row, 'Điện thoại: ' . $store['phone']);
            $row++;
        }
        return $row + 1;
    }

    private function writeTitle($sheet, int $row): int
    {
        $sheet->setCellValue('A' . $row, self::REPORT_TITLE);
        $sheet->mergeCells('A' . $row . ':L' . $row);
        $sheet->getStyle('A' . $row)
            ->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A' . $row)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $rangeText = 'Toàn thời gian';
        if ($this->from && $this->to) {
            $rangeText = sprintf(
                'Từ ngày %s đến ngày %s',
                $this->from->format('d/m/Y'),
                $this->to->format('d/m/Y')
            );
        }
        $sheet->setCellValue('A' . $row, $rangeText);
        $sheet->mergeCells('A' . $row . ':L' . $row);
        $sheet->getStyle('A' . $row)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . $row)
            ->getFont()->setItalic(true);
        return $row + 2;
    }

    private function writeSupplierAndSummary($sheet, int $row, float $opening, float $debit, float $credit, float $closing): int
    {
        $sheet->setCellValue('A' . $row, 'Nhà cung cấp:');
        $sheet->setCellValue('B' . $row, $this->supplier->name ?? '');
        $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);

        $sheet->setCellValue('I' . $row, 'Nợ đầu kỳ:');
        $sheet->setCellValue('K' . $row, $opening);
        $sheet->getStyle('I' . $row)->getFont()->setBold(true);
        $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $row++;

        $sheet->setCellValue('A' . $row, 'Mã NCC:');
        $sheet->setCellValue('B' . $row, $this->supplier->code ?? '');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);

        $sheet->setCellValue('I' . $row, 'Phát sinh trong kỳ:');
        $sheet->setCellValue('J' . $row, $debit);
        $sheet->setCellValue('K' . $row, '');
        $sheet->setCellValue('L' . $row, $credit);
        $sheet->getStyle('I' . $row)->getFont()->setBold(true);
        $sheet->getStyle('J' . $row . ':L' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $row++;

        $sheet->setCellValue('A' . $row, 'Điện thoại:');
        $sheet->setCellValue('B' . $row, $this->supplier->phone ?? '');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);

        $sheet->setCellValue('I' . $row, 'Nợ cuối kỳ:');
        $sheet->setCellValue('K' . $row, $closing);
        $sheet->getStyle('I' . $row)->getFont()->setBold(true);
        $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0');
        return $row + 2;
    }

    private function writeTableHeader($sheet, int $row): int
    {
        foreach (self::HEADERS as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . $row, $h);
        }
        $range = 'A' . $row . ':L' . $row;
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E7EEF7');
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        return $row + 1;
    }

    private function writeRows($sheet, int $startRow, array $entries): void
    {
        $row = $startRow;
        foreach ($entries as $e) {
            $created = $e['created_at'] ?? $e['date'] ?? null;
            try {
                $whenStr = $created ? Carbon::parse($created)->format('d/m/Y H:i') : '';
            } catch (\Throwable $ex) {
                $whenStr = (string) $created;
            }
            $supplierEffect = (float) ($e['supplier_effect'] ?? 0);
            $debitVal       = $supplierEffect > 0 ? $supplierEffect : null;
            $creditVal      = $supplierEffect < 0 ? -$supplierEffect : null;

            // Document header row.
            $sheet->setCellValue('A' . $row, $whenStr);
            $sheet->setCellValue('B' . $row, $e['code'] ?? '');
            $sheet->setCellValue('C' . $row, $e['type_label'] ?? '');
            if ($debitVal !== null)  $sheet->setCellValue('K' . $row, $debitVal);
            if ($creditVal !== null) $sheet->setCellValue('L' . $row, $creditVal);
            $sheet->getStyle('B' . $row . ':C' . $row)->getFont()->setBold(true);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K' . $row . ':L' . $row)
                ->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('A' . $row . ':L' . $row)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
            $row++;

            if ($this->includeDetail) {
                foreach ($this->loadDetailLines($e) as $line) {
                    $sheet->setCellValue('B' . $row, $line['code'] ?? '');
                    $sheet->setCellValue('C' . $row, $line['name'] ?? '');
                    if ($this->columns['unit'])       $sheet->setCellValue('D' . $row, $line['unit'] ?? '');
                    if ($this->columns['quantity'])   $sheet->setCellValue('E' . $row, $line['quantity'] ?? '');
                    if ($this->columns['unit_price']) $sheet->setCellValue('F' . $row, $line['unit_price'] ?? '');
                    if ($this->columns['discount'])   $sheet->setCellValue('G' . $row, $line['discount'] ?? '');
                    if ($this->columns['vat'])        $sheet->setCellValue('H' . $row, $line['vat'] ?? '');
                    if ($this->columns['cost'])       $sheet->setCellValue('I' . $row, $line['cost'] ?? '');
                    if ($this->columns['line_total']) $sheet->setCellValue('J' . $row, $line['line_total'] ?? '');
                    $sheet->getStyle('C' . $row)->getFont()->setItalic(true);
                    $sheet->getStyle('E' . $row . ':J' . $row)
                        ->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle('A' . $row . ':L' . $row)->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_HAIR);
                    $row++;
                }
            }
        }
    }

    private function isInWindow(array $e): bool
    {
        if (!$this->from && !$this->to) return true;
        $created = $e['created_at'] ?? $e['date'] ?? null;
        if (!$created) return false;
        try {
            $ts = Carbon::parse($created);
        } catch (\Throwable $ex) {
            return false;
        }
        if ($this->from && $ts->lessThan($this->from)) return false;
        if ($this->to && $ts->greaterThan($this->to)) return false;
        return true;
    }

    /**
     * Opening debt = số dư ngay trước `from`. Nếu không có `from`
     * (toàn thời gian) → opening = 0 và toàn bộ ledger được tính vào
     * "phát sinh trong kỳ".
     *
     * Quan trọng: chỉ dùng `supplier_effect` của các entries ngoài
     * cửa sổ, KHÔNG đụng `debt_remain` đã có.
     */
    private function computeOpeningDebt(): float
    {
        if (!$this->from) return 0.0;
        $opening = 0.0;
        foreach ($this->entries as $e) {
            $created = $e['created_at'] ?? $e['date'] ?? null;
            if (!$created) continue;
            try {
                $ts = Carbon::parse($created);
            } catch (\Throwable $ex) {
                continue;
            }
            if ($ts->lessThan($this->from)) {
                $opening += (float) ($e['supplier_effect'] ?? 0);
            }
        }
        return $opening;
    }

    private function loadDetailLines(array $entry): array
    {
        $id = $entry['id'] ?? '';
        if (!is_string($id) || !str_contains($id, '-')) return [];
        [$prefix, $rawId] = explode('-', $id, 2);
        $rawId = (int) $rawId;
        if ($rawId <= 0) return [];

        if ($prefix === 'pur') {
            return PurchaseItem::where('purchase_id', $rawId)->get()
                ->map(fn($i) => [
                    'code'       => $i->product_code ?? '',
                    'name'       => $i->product_name ?? '',
                    'unit'       => '',
                    'quantity'   => $i->quantity ?? 0,
                    'unit_price' => $i->price ?? 0,
                    'discount'   => $i->discount ?? 0,
                    'vat'        => '',
                    'cost'       => $i->price ?? 0,
                    'line_total' => $i->subtotal ?? 0,
                ])->all();
        }

        if ($prefix === 'pret') {
            return PurchaseReturnItem::where('purchase_return_id', $rawId)->get()
                ->map(fn($i) => [
                    'code'       => $i->product_code ?? '',
                    'name'       => $i->product_name ?? '',
                    'unit'       => '',
                    'quantity'   => $i->quantity ?? 0,
                    'unit_price' => $i->price ?? 0,
                    'discount'   => '',
                    'vat'        => '',
                    'cost'       => $i->price ?? 0,
                    'line_total' => $i->subtotal ?? 0,
                ])->all();
        }

        if ($prefix === 'inv') {
            // HOTFIX 24.17C — invoice_items table only stores product_id +
            // quantity + price + cost_price + serial. Pull product code /
            // name via the relation so the Excel detail line is not blank.
            $items = InvoiceItem::with('product:id,sku,name')
                ->where('invoice_id', $rawId)
                ->get();
            return $items->map(function ($i) {
                $product  = $i->product;
                $code     = $product?->sku ?? '';
                $name     = $product?->name ?? '';
                // Serial is stored as plain text (often comma-separated for
                // multi-quantity sales) — append in italics-ish hint so the
                // operator can audit which physical unit left the warehouse.
                $serial   = trim((string) ($i->serial ?? ''));
                if ($serial !== '' && $name !== '') {
                    $name = $name . ' (' . $serial . ')';
                } elseif ($serial !== '' && $name === '') {
                    $name = $serial;
                }
                $quantity = (int) ($i->quantity ?? 0);
                $price    = (float) ($i->price ?? 0);
                // cost_price is the per-unit cost snapshot from sale time
                // (added by migration 2026_03_27). It's the closest analog
                // to "giá nhập/trả" so we surface it directly — fall back
                // to the selling price only if it's not stored.
                $costPrice = $i->cost_price !== null ? (float) $i->cost_price : $price;
                return [
                    'code'       => $code,
                    'name'       => $name,
                    'unit'       => '',
                    'quantity'   => $quantity,
                    'unit_price' => $price,
                    'discount'   => 0, // invoice_items has no per-line discount column
                    'vat'        => '',
                    'cost'       => $costPrice,
                    'line_total' => $price * $quantity,
                ];
            })->all();
        }

        return [];
    }

    private function resolveStoreInfo(): array
    {
        // No formal store-info config exists; fall back to APP_NAME (or the
        // legacy "Laptopplus.vn" used as a hard-coded branch label in
        // purchaseHistory) so we never emit fake production data.
        $appName = config('app.name');
        $name = (is_string($appName) && $appName !== '' && strcasecmp($appName, 'Laravel') !== 0)
            ? $appName
            : 'LAPTOPPLUS.VN';
        return [
            'name'    => $name,
            'address' => '',
            'phone'   => '',
        ];
    }
}
