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
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerDebtExcelExportService
{
    public const SHEET_NAME = 'CNCT';

    public const REPORT_TITLE = 'CÔNG NỢ CHI TIẾT KHÁCH HÀNG';

    private const HEADERS = [
        'Thời gian',
        'Mã',
        'Diễn giải',
        'ĐVT',
        'SL',
        'Đơn giá',
        'Giảm giá',
        'VAT',
        'Giá bán/trả',
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

    /** @var array<int,array<string,mixed>> */
    private array $entries;

    /** @var array<string,bool> */
    private array $columns = [];

    private PartnerDebtExportDocumentResolver $documents;

    private PartnerDebtExportEffectResolver $effects;

    private PartnerDebtExportRunningBalanceResolver $runningBalances;

    public function __construct(
        private Customer $customer,
        array $entries,
        private ?Carbon $from,
        private ?Carbon $to,
        private bool $includeDetail,
        array $selectedColumns,
        private float $openingAdjustment = 0.0,
    ) {
        $this->entries = $entries;
        $this->documents = new PartnerDebtExportDocumentResolver;
        $this->effects = new PartnerDebtExportEffectResolver;
        $this->runningBalances = new PartnerDebtExportRunningBalanceResolver;

        foreach (['unit', 'quantity', 'unit_price', 'discount', 'vat', 'cost', 'line_total', 'note'] as $column) {
            $this->columns[$column] = in_array($column, $selectedColumns, true);
        }
    }

    public function download(string $filename): StreamedResponse
    {
        $writer = new Xlsx($this->build());

        return response()->stream(static function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function build(): Spreadsheet
    {
        $this->documents->preload($this->entries);
        $this->entries = $this->runningBalances->project(
            $this->entries,
            'customer',
            $this->effects,
            $this->documents,
            $this->openingAdjustment,
        );
        $inWindow = array_values(array_filter($this->entries, fn (array $entry) => $this->isInWindow($entry)));
        $opening = $this->computeOpeningDebt();
        $debit = 0.0;
        $credit = 0.0;

        foreach ($inWindow as $entry) {
            $effect = $this->entryEffect($entry);
            if ($effect > 0) {
                $debit += $effect;
            } elseif ($effect < 0) {
                $credit += abs($effect);
            }
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);

        foreach (self::COL_WIDTHS as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 1;
        $row = $this->writeStoreHeader($sheet, $row);
        $row = $this->writeTitle($sheet, $row);
        $row = $this->writeCustomerAndSummary($sheet, $row, $opening, $debit, $credit, $opening + $debit - $credit);
        $headerRow = $this->writeTableHeader($sheet, $row) - 1;
        $sheet->freezePane('A'.($headerRow + 1));

        $lastBodyRow = $this->writeRows($sheet, $headerRow + 1, $inWindow);
        $this->applyTableBorders($sheet, $headerRow, $lastBodyRow);
        $this->writeFooter($sheet, $lastBodyRow + 3);

        return $spreadsheet;
    }

    private function writeStoreHeader($sheet, int $row): int
    {
        $name = $this->storeName();
        $sheet->setCellValue('A'.$row, $name);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(13);

        return $row + 2;
    }

    private function writeTitle($sheet, int $row): int
    {
        $sheet->setCellValue('A'.$row, self::REPORT_TITLE);
        $sheet->mergeCells('A'.$row.':N'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $rangeText = 'Toàn thời gian';
        if ($this->from && $this->to) {
            $rangeText = sprintf('Từ ngày %s đến ngày %s', $this->from->format('d/m/Y'), $this->to->format('d/m/Y'));
        }

        $sheet->setCellValue('A'.$row, $rangeText);
        $sheet->mergeCells('A'.$row.':N'.$row);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A'.$row)->getFont()->setItalic(true);

        return $row + 2;
    }

    private function writeCustomerAndSummary($sheet, int $row, float $opening, float $debit, float $credit, float $closing): int
    {
        $sheet->setCellValue('A'.$row, 'Khách hàng:');
        $sheet->setCellValue('B'.$row, $this->customer->name ?? '');
        $sheet->getStyle('A'.$row.':B'.$row)->getFont()->setBold(true);
        $sheet->setCellValue('I'.$row, 'Nợ đầu kỳ:');
        $this->writeDebitCreditPair($sheet, $row, $opening);
        $row++;

        $sheet->setCellValue('A'.$row, 'Mã KH:');
        $sheet->setCellValue('B'.$row, $this->customer->code ?? '');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true);
        $sheet->setCellValue('I'.$row, 'Phát sinh trong kỳ:');
        $sheet->setCellValue('K'.$row, $debit);
        $sheet->setCellValue('L'.$row, $credit);
        $this->styleMoneyPair($sheet, $row);
        $row++;

        $sheet->setCellValue('A'.$row, 'Điện thoại:');
        $sheet->setCellValue('B'.$row, $this->customer->phone ?? '');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true);
        $sheet->setCellValue('I'.$row, 'Nợ cuối kỳ:');
        $this->writeDebitCreditPair($sheet, $row, $closing);

        return $row + 2;
    }

    private function writeDebitCreditPair($sheet, int $row, float $value): void
    {
        if ($value >= 0) {
            $sheet->setCellValue('K'.$row, $value);
        } else {
            $sheet->setCellValue('L'.$row, abs($value));
        }
        $this->styleMoneyPair($sheet, $row);
    }

    private function styleMoneyPair($sheet, int $row): void
    {
        $sheet->getStyle('I'.$row)->getFont()->setBold(true);
        $sheet->getStyle('K'.$row.':L'.$row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('K'.$row.':L'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function writeTableHeader($sheet, int $row): int
    {
        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $header);
        }

        $range = 'A'.$row.':N'.$row;
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7EEF7');
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        return $row + 1;
    }

    private function writeRows($sheet, int $startRow, array $entries): int
    {
        $row = $startRow;
        foreach ($entries as $entry) {
            $effect = $this->entryEffect($entry);
            $when = $this->formatEntryTime($entry);

            $sheet->setCellValue('A'.$row, $when);
            $sheet->setCellValue('B'.$row, $entry['code'] ?? '');
            $sheet->setCellValue('C'.$row, $this->documents->contextLabel($entry, $this->entryLabel($entry)));
            if ($effect > 0) {
                $sheet->setCellValue('K'.$row, $effect);
            } elseif ($effect < 0) {
                $sheet->setCellValue('L'.$row, abs($effect));
            }
            $sheet->setCellValue('M'.$row, $this->entryRunningBalance($entry));
            $sheet->setCellValue('N'.$row, $this->documents->contextNote($entry));
            $sheet->getStyle('B'.$row.':C'.$row)->getFont()->setBold(true);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K'.$row.':M'.$row)->getNumberFormat()->setFormatCode('#,##0');
            $row++;

            if ($this->includeDetail) {
                foreach ($this->loadDetailLines($entry) as $line) {
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
                    $sheet->getStyle('E'.$row.':M'.$row)->getNumberFormat()->setFormatCode('#,##0');
                    $row++;
                }
            }
        }

        return $row - 1;
    }

    private function loadDetailLines(array $entry): array
    {
        return $this->documents->loadDetailLines($entry);
    }

    private function applyTableBorders($sheet, int $headerRow, int $lastBodyRow): void
    {
        if ($lastBodyRow < $headerRow) {
            $lastBodyRow = $headerRow;
        }

        $whole = 'A'.$headerRow.':N'.$lastBodyRow;
        $sheet->getStyle($whole)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
        if ($lastBodyRow > $headerRow) {
            $sheet->getStyle('A'.($headerRow + 1).':N'.$lastBodyRow)
                ->getBorders()
                ->getBottom()
                ->setBorderStyle(Border::BORDER_HAIR);
        }
        $sheet->getStyle($whole)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_MEDIUM);
        $sheet->getStyle('A'.$headerRow.':N'.$headerRow)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
    }

    private function writeFooter($sheet, int $row): void
    {
        $now = Carbon::now();
        $sheet->setCellValue('J'.$row, sprintf('Ngày %d tháng %d năm %d', $now->day, $now->month, $now->year));
        $sheet->mergeCells('J'.$row.':N'.$row);
        $sheet->getStyle('J'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('J'.$row)->getFont()->setItalic(true);
        $row += 2;

        foreach ([
            ['cell' => 'A', 'range' => 'A%d:B%d', 'label' => 'Khách hàng'],
            ['cell' => 'F', 'range' => 'F%d:G%d', 'label' => 'Người lập biểu'],
            ['cell' => 'J', 'range' => 'J%d:N%d', 'label' => 'TM Công ty'],
        ] as $block) {
            $range = sprintf($block['range'], $row, $row);
            $sheet->setCellValue($block['cell'].$row, $block['label']);
            $sheet->mergeCells($range);
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $row++;

        foreach ([
            ['cell' => 'A', 'range' => 'A%d:B%d'],
            ['cell' => 'F', 'range' => 'F%d:G%d'],
            ['cell' => 'J', 'range' => 'J%d:N%d'],
        ] as $block) {
            $range = sprintf($block['range'], $row, $row);
            $sheet->setCellValue($block['cell'].$row, '(Ký, họ tên)');
            $sheet->mergeCells($range);
            $sheet->getStyle($range)->getFont()->setItalic(true);
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    private function isInWindow(array $entry): bool
    {
        if (! $this->from && ! $this->to) {
            return true;
        }

        $ts = $this->entryDate($entry);
        if (! $ts) {
            return false;
        }

        if ($this->from && $ts->lessThan($this->from)) {
            return false;
        }

        return ! ($this->to && $ts->greaterThan($this->to));
    }

    private function computeOpeningDebt(): float
    {
        if (! $this->from) {
            return $this->openingAdjustment;
        }

        $opening = $this->openingAdjustment;
        foreach ($this->entries as $entry) {
            $ts = $this->entryDate($entry);
            if ($ts && $ts->lessThan($this->from)) {
                $opening += $this->entryEffect($entry);
            }
        }

        return $opening;
    }

    private function entryDate(array $entry): ?Carbon
    {
        return $this->entryTime($entry);
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

    private function entryEffect(array $entry): float
    {
        return $this->effects->resolveForExport($entry, 'customer', $this->documents);
    }

    private function entryRunningBalance(array $entry): float
    {
        return $this->runningBalances->resolve($entry, 'customer');
    }

    private function entryLabel(array $entry): string
    {
        foreach (['type', 'type_label', 'display_type', 'document_type', 'label', 'badge_label'] as $key) {
            if (array_key_exists($key, $entry) && $entry[$key] !== null && $entry[$key] !== '') {
                return (string) $entry[$key];
            }
        }

        return match ((string) ($entry['event_kind'] ?? $entry['reference_type'] ?? '')) {
            'customer_sale', 'Invoice' => 'Bán hàng',
            'invoice_payment', 'invoice_payment_fallback', 'customer_payment', 'CashFlow' => 'Thanh toán',
            'sales_return', 'OrderReturn' => 'Trả hàng bán',
            'refund' => 'Hoàn tiền khách',
            default => '',
        };
    }

    private function storeName(): string
    {
        $name = config('app.name');

        return is_string($name) && $name !== '' && strcasecmp($name, 'Laravel') !== 0
            ? $name
            : 'LAPTOPPLUS.VN';
    }
}
