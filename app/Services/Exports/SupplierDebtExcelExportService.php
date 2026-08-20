<?php

namespace App\Services\Exports;

use App\Models\Customer;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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

    private PartnerDebtExportDocumentResolver $documents;

    private PartnerDebtExportEffectResolver $effects;

    private PartnerDebtExportRunningBalanceResolver $runningBalances;

    public const SHEET_NAME = 'CNCT';

    public const REPORT_TITLE = 'CÔNG NỢ CHI TIẾT NHÀ CUNG CẤP';

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
        'Số dư sau GD',
        'Ghi chú',
    ];

    private const COL_WIDTHS = [
        'A' => 14, 'B' => 18, 'C' => 35, 'D' => 10, 'E' => 8, 'F' => 14,
        'G' => 14, 'H' => 12, 'I' => 14, 'J' => 14, 'K' => 15, 'L' => 15,
        'M' => 16, 'N' => 28,
    ];

    public function __construct(
        array $entries,
        Customer $supplier,
        ?Carbon $from,
        ?Carbon $to,
        bool $includeDetail,
        array $selectedColumns,
        private float $openingAdjustment = 0.0,
    ) {
        $this->entries = $entries;
        $this->supplier = $supplier;
        $this->from = $from;
        $this->to = $to;
        $this->includeDetail = $includeDetail;
        $this->documents = new PartnerDebtExportDocumentResolver;
        $this->effects = new PartnerDebtExportEffectResolver;
        $this->runningBalances = new PartnerDebtExportRunningBalanceResolver;
        // Detail-column toggles control whether each per-line cell is
        // populated; the column itself stays on the sheet so the
        // KiotViet-style layout (Thời gian → Ghi có) is consistent.
        $allCols = ['unit', 'quantity', 'unit_price', 'discount', 'vat', 'cost', 'line_total', 'note'];
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
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function build(): Spreadsheet
    {
        $this->documents->preload($this->entries, 'supplier');
        $this->entries = $this->runningBalances->project(
            $this->entries,
            'supplier',
            $this->effects,
            $this->documents,
            $this->openingAdjustment,
        );

        // Filter entries into the display window — but compute opening
        // / period totals against the FULL ledger we were handed.
        // Use displayEffectFor() everywhere so document rows and period
        // totals share the canonical net supplier-payable effect. Legacy
        // gross purchase rows are still normalized by the export resolver.
        $inWindow = array_values(array_filter($this->entries, fn ($e) => $this->isInWindow($e)));
        $opening = $this->computeOpeningDebt();
        $debit = 0;
        $credit = 0;
        foreach ($inWindow as $e) {
            $eff = $this->displayEffectFor($e);
            if ($eff > 0) {
                $debit += $eff;
            }
            if ($eff < 0) {
                $credit += -$eff;
            }
        }
        $closing = $opening + $debit - $credit;

        $spreadsheet = new Spreadsheet;
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
        $headerRow = $this->writeTableHeader($sheet, $row) - 1; // row containing the header itself
        $sheet->freezePane('A'.($headerRow + 1));
        $lastBodyRow = $this->writeRows($sheet, $headerRow + 1, $inWindow);
        $this->applyTableBorders($sheet, $headerRow, $lastBodyRow);
        $this->writeFooter($sheet, $lastBodyRow + 3);

        return $spreadsheet;
    }

    /**
     * Resolve the canonical supplier effect used for Excel rendering.
     * Current purchase events already carry the net supplier-payable amount;
     * the resolver retains compatibility for historical gross fixtures.
     */
    private function displayEffectFor(array $entry): float
    {
        return $this->effects->resolveForExport($entry, 'supplier', $this->documents);
    }

    private function writeStoreHeader($sheet, int $row): int
    {
        $store = $this->resolveStoreInfo();
        $sheet->setCellValue('A'.$row, $store['name']);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(13);
        $row++;
        if (! empty($store['address'])) {
            $sheet->setCellValue('A'.$row, 'Địa chỉ: '.$store['address']);
            $row++;
        }
        if (! empty($store['phone'])) {
            $sheet->setCellValue('A'.$row, 'Điện thoại: '.$store['phone']);
            $row++;
        }

        return $row + 1;
    }

    private function writeTitle($sheet, int $row): int
    {
        $sheet->setCellValue('A'.$row, self::REPORT_TITLE);
        $sheet->mergeCells('A'.$row.':N'.$row);
        $sheet->getStyle('A'.$row)
            ->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A'.$row)
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
        $sheet->setCellValue('A'.$row, $rangeText);
        $sheet->mergeCells('A'.$row.':N'.$row);
        $sheet->getStyle('A'.$row)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A'.$row)
            ->getFont()->setItalic(true);

        return $row + 2;
    }

    private function writeSupplierAndSummary($sheet, int $row, float $opening, float $debit, float $credit, float $closing): int
    {
        // HOTFIX 24.17E — keep summary numerics under the same K/L
        // columns the document body uses (K = "Ghi nợ", L = "Ghi có").
        // Previously the period row pushed `debit` into column J
        // ("Thành tiền"), which read as "lệch cột" against the body.
        // Negative opening/closing balances surface in column L as a
        // positive number (credit-side), preserving sign without ever
        // emitting a "-1,234" in the debit column.

        $sheet->setCellValue('A'.$row, 'Nhà cung cấp:');
        $sheet->setCellValue('B'.$row, $this->supplier->name ?? '');
        $sheet->getStyle('A'.$row.':B'.$row)->getFont()->setBold(true);

        $sheet->setCellValue('I'.$row, 'Nợ đầu kỳ:');
        if ($opening >= 0) {
            $sheet->setCellValue('K'.$row, $opening);
        } else {
            $sheet->setCellValue('L'.$row, abs($opening));
        }
        $sheet->getStyle('I'.$row)->getFont()->setBold(true);
        $sheet->getStyle('K'.$row.':L'.$row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('K'.$row.':L'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;

        $sheet->setCellValue('A'.$row, 'Mã NCC:');
        $sheet->setCellValue('B'.$row, $this->supplier->code ?? '');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true);

        $sheet->setCellValue('I'.$row, 'Phát sinh trong kỳ:');
        $sheet->setCellValue('K'.$row, $debit);
        $sheet->setCellValue('L'.$row, $credit);
        $sheet->getStyle('I'.$row)->getFont()->setBold(true);
        $sheet->getStyle('K'.$row.':L'.$row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('K'.$row.':L'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;

        $sheet->setCellValue('A'.$row, 'Điện thoại:');
        $sheet->setCellValue('B'.$row, $this->supplier->phone ?? '');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true);

        $sheet->setCellValue('I'.$row, 'Nợ cuối kỳ:');
        if ($closing >= 0) {
            $sheet->setCellValue('K'.$row, $closing);
        } else {
            $sheet->setCellValue('L'.$row, abs($closing));
        }
        $sheet->getStyle('I'.$row)->getFont()->setBold(true);
        $sheet->getStyle('K'.$row.':L'.$row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('K'.$row.':L'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return $row + 2;
    }

    private function writeTableHeader($sheet, int $row): int
    {
        foreach (self::HEADERS as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col.$row, $h);
        }
        $range = 'A'.$row.':N'.$row;
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E7EEF7');
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        return $row + 1;
    }

    /**
     * HOTFIX 24.17F — returns the index of the LAST row written so the
     * caller can stamp outer table borders and place the footer.
     * Per-row border calls were removed here; borders are now applied
     * in one sweep by applyTableBorders() so the body reads cleanly.
     */
    private function writeRows($sheet, int $startRow, array $entries): int
    {
        $row = $startRow;
        foreach ($entries as $e) {
            $whenStr = $this->formatEntryTime($e);
            // Canonical net supplier-payable effect for purchase documents.
            $eff = $this->displayEffectFor($e);
            $debitVal = $eff > 0 ? $eff : null;
            $creditVal = $eff < 0 ? -$eff : null;

            // Document header row.
            $sheet->setCellValue('A'.$row, $whenStr);
            $sheet->setCellValue('B'.$row, $e['code'] ?? '');
            $sheet->setCellValue('C'.$row, $this->documents->contextLabel($e, $e['type_label'] ?? ''));
            if ($debitVal !== null) {
                $sheet->setCellValue('K'.$row, $debitVal);
            }
            if ($creditVal !== null) {
                $sheet->setCellValue('L'.$row, $creditVal);
            }
            $sheet->setCellValue('M'.$row, $this->entryRunningBalance($e));
            $sheet->setCellValue('N'.$row, $this->documents->contextNote($e));
            $sheet->getStyle('B'.$row.':C'.$row)->getFont()->setBold(true);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K'.$row.':M'.$row)
                ->getNumberFormat()->setFormatCode('#,##0');
            $row++;

            if ($this->includeDetail) {
                foreach ($this->loadDetailLines($e) as $line) {
                    $sheet->setCellValue('B'.$row, $line['code'] ?? '');
                    $sheet->setCellValue('C'.$row, $line['name'] ?? '');
                    if ($this->columns['unit']) {
                        $sheet->setCellValue('D'.$row, $line['unit'] ?? '');
                    }
                    if ($this->columns['quantity']) {
                        $sheet->setCellValue('E'.$row, $line['quantity'] ?? '');
                    }
                    if ($this->columns['unit_price']) {
                        $sheet->setCellValue('F'.$row, $line['unit_price'] ?? '');
                    }
                    if ($this->columns['discount']) {
                        $sheet->setCellValue('G'.$row, $line['discount'] ?? '');
                    }
                    if ($this->columns['vat']) {
                        $sheet->setCellValue('H'.$row, $line['vat'] ?? '');
                    }
                    if ($this->columns['cost']) {
                        $sheet->setCellValue('I'.$row, $line['cost'] ?? '');
                    }
                    if ($this->columns['line_total']) {
                        $sheet->setCellValue('J'.$row, $line['line_total'] ?? '');
                    }
                    if ($this->columns['note']) {
                        $sheet->setCellValue('N'.$row, $line['note'] ?? '');
                    }
                    $sheet->getStyle('C'.$row)->getFont()->setItalic(true);
                    $sheet->getStyle('E'.$row.':M'.$row)
                        ->getNumberFormat()->setFormatCode('#,##0');
                    $row++;
                }
            }
        }

        return $row - 1;
    }

    /**
     * HOTFIX 24.17F — clean border style. The 24.17B/C/D output stamped
     * `BORDER_THIN` on every cell of every row, which turned the body
     * into a grid of dark lines. New scheme:
     *  - outer medium border around the whole table (header + body),
     *  - a single hair line BELOW each body row (horizontal separator),
     *  - no vertical inner borders — column widths + alignment carry
     *    the layout instead.
     */
    private function applyTableBorders($sheet, int $headerRow, int $lastBodyRow): void
    {
        if ($lastBodyRow < $headerRow) {
            $lastBodyRow = $headerRow;
        }
        $whole = 'A'.$headerRow.':N'.$lastBodyRow;
        // Wipe whatever per-row borders were drawn earlier in the build
        // by re-applying NONE first.
        $sheet->getStyle($whole)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_NONE);

        // Hair horizontal separators between rows below the header.
        if ($lastBodyRow > $headerRow) {
            $bodyRange = 'A'.($headerRow + 1).':N'.$lastBodyRow;
            $sheet->getStyle($bodyRange)->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_HAIR);
        }

        // Outer medium frame + thicker line under the header row.
        $sheet->getStyle($whole)->getBorders()->getOutline()
            ->setBorderStyle(Border::BORDER_MEDIUM);
        $sheet->getStyle('A'.$headerRow.':N'.$headerRow)
            ->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM);
    }

    /**
     * HOTFIX 24.17F — KiotViet-style footer: export date on the right
     * and three signature blocks (Nhà cung cấp / Người lập biểu /
     * TM Công ty), each with a "(Ký, họ tên)" italic sub-row.
     */
    private function writeFooter($sheet, int $row): void
    {
        $now = Carbon::now();
        $dateText = sprintf('Ngày %d tháng %d năm %d', $now->day, $now->month, $now->year);
        $sheet->setCellValue('J'.$row, $dateText);
        $sheet->mergeCells('J'.$row.':N'.$row);
        $sheet->getStyle('J'.$row)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('J'.$row)->getFont()->setItalic(true);
        $row += 2;

        $signatureRow = $row;
        $blocks = [
            ['range' => 'A:B', 'merge' => 'A%d:B%d', 'label' => 'Nhà cung cấp'],
            ['range' => 'F:G', 'merge' => 'F%d:G%d', 'label' => 'Người lập biểu'],
            ['range' => 'J:N', 'merge' => 'J%d:N%d', 'label' => 'TM Công ty'],
        ];
        foreach ($blocks as $b) {
            $merge = sprintf($b['merge'], $signatureRow, $signatureRow);
            $sheet->setCellValue(explode(':', $merge)[0], $b['label']);
            $sheet->mergeCells($merge);
            $sheet->getStyle($merge)->getFont()->setBold(true);
            $sheet->getStyle($merge)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $signatureRow++;
        foreach ($blocks as $b) {
            $merge = sprintf($b['merge'], $signatureRow, $signatureRow);
            $sheet->setCellValue(explode(':', $merge)[0], '(Ký, họ tên)');
            $sheet->mergeCells($merge);
            $sheet->getStyle($merge)->getFont()->setItalic(true);
            $sheet->getStyle($merge)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    private function isInWindow(array $e): bool
    {
        if (! $this->from && ! $this->to) {
            return true;
        }
        $ts = $this->entryTime($e);
        if (! $ts) {
            return false;
        }
        if ($this->from && $ts->lessThan($this->from)) {
            return false;
        }
        if ($this->to && $ts->greaterThan($this->to)) {
            return false;
        }

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
        if (! $this->from) {
            return $this->openingAdjustment;
        }
        $opening = $this->openingAdjustment;
        foreach ($this->entries as $e) {
            $ts = $this->entryTime($e);
            if (! $ts) {
                continue;
            }
            if ($ts->lessThan($this->from)) {
                // HOTFIX 24.17F — same display lens as in-window debit
                // / credit so opening + period = closing internally.
                $opening += $this->displayEffectFor($e);
            }
        }

        return $opening;
    }

    private function entryTime(array $entry): ?Carbon
    {
        $raw = $entry['business_time']
            ?? $entry['display_time']
            ?? $entry['time']
            ?? $entry['transaction_date']
            ?? $entry['purchase_date']
            ?? $entry['return_date']
            ?? $entry['created_at']
            ?? $entry['date']
            ?? null;
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatEntryTime(array $entry): string
    {
        $raw = $entry['business_time']
            ?? $entry['display_time']
            ?? $entry['time']
            ?? $entry['transaction_date']
            ?? $entry['purchase_date']
            ?? $entry['return_date']
            ?? $entry['created_at']
            ?? $entry['date']
            ?? null;
        if (! $raw) {
            return '';
        }

        try {
            return Carbon::parse($raw)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return (string) $raw;
        }
    }

    private function loadDetailLines(array $entry): array
    {
        return $this->documents->loadDetailLines($entry, 'supplier');
    }

    private function entryRunningBalance(array $entry): float
    {
        return $this->runningBalances->resolve($entry, 'supplier');
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
            'name' => $name,
            'address' => '',
            'phone' => '',
        ];
    }
}
