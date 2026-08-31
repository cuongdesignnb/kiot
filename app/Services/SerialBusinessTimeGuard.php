<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItemSerial;
use App\Models\SerialImei;
use App\Support\BusinessDateTime;
use App\Support\Status\BusinessStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the displayed business timeline of a Serial/IMEI physically possible.
 *
 * A normal sale may be entered after a return, but its business time must not
 * be backdated before that return.  Otherwise reports sorted by business time
 * show the same serial sold twice before it was returned, which makes COGS
 * replay ambiguous even though the live stock state was valid.
 */
class SerialBusinessTimeGuard
{
    /**
     * @param  iterable<int, SerialImei>  $serials
     */
    public function assertNewSaleCanUseBusinessTime(iterable $serials, CarbonInterface|string $saleBusinessTime): void
    {
        $serials = collect($serials)
            ->filter(fn ($serial) => $serial instanceof SerialImei)
            ->keyBy(fn (SerialImei $serial) => (int) $serial->id);

        if ($serials->isEmpty()) {
            return;
        }

        $saleTime = $this->asCarbon($saleBusinessTime);
        $latestReturns = $this->latestCompletedReturnBusinessTimes($serials->keys()->map(fn ($id) => (int) $id)->all());
        $violations = [];

        foreach ($serials as $serialId => $serial) {
            $returnTime = $latestReturns->get((int) $serialId);
            if (! $returnTime instanceof Carbon || $saleTime->gt($returnTime)) {
                continue;
            }

            $violations[] = sprintf(
                'Serial/IMEI %s đã được trả hàng lúc %s. Ngày bán phải sau thời điểm này; không được lùi ngày bán về trước hoặc trùng thời điểm trả gần nhất.',
                $serial->serial_number,
                $returnTime->format('d/m/Y H:i'),
            );
        }

        if ($violations !== []) {
            throw ValidationException::withMessages(['items' => $violations]);
        }
    }

    /**
     * Once a serial invoice has a return or a later completed sale, changing
     * only its document time can silently invert the lifecycle.  A dedicated
     * adjustment flow is required instead of rewriting history in place.
     */
    public function assertInvoiceDateMayChange(Invoice $invoice): void
    {
        $invoiceItemIds = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($invoiceItemIds === []) {
            return;
        }

        $serialIds = InvoiceItemSerial::query()
            ->whereIn('invoice_item_id', $invoiceItemIds)
            ->whereNotNull('serial_imei_id')
            ->pluck('serial_imei_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($serialIds === []) {
            return;
        }

        $hasCompletedReturn = $this->returnRowsForInvoiceItemIds($invoiceItemIds)
            ->contains(fn ($row) => BusinessStatus::isReturnCompleted($row->return_status));

        $otherCompletedSales = DB::table('invoice_item_serials as links')
            ->join('invoice_items as items', 'items.id', '=', 'links.invoice_item_id')
            ->join('invoices as invoices', 'invoices.id', '=', 'items.invoice_id')
            ->whereIn('links.serial_imei_id', $serialIds)
            ->where('invoices.id', '!=', $invoice->id)
            ->get(['invoices.status'])
            ->contains(fn ($row) => BusinessStatus::isCompleted($row->status));

        if (! $hasCompletedReturn && ! $otherCompletedSales) {
            return;
        }

        throw ValidationException::withMessages([
            'transaction_date' => 'Không thể đổi ngày hóa đơn có Serial/IMEI đã phát sinh trả hàng hoặc bán lại. Hãy dùng phiếu điều chỉnh để giữ đúng lịch sử tồn kho và giá vốn.',
        ]);
    }

    /**
     * @param  array<int, int>  $serialIds
     * @return Collection<int, Carbon>
     */
    private function latestCompletedReturnBusinessTimes(array $serialIds): Collection
    {
        $sourceInvoiceItemIds = InvoiceItemSerial::query()
            ->whereIn('serial_imei_id', $serialIds)
            ->pluck('invoice_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($sourceInvoiceItemIds === []) {
            return collect();
        }

        $latest = collect();
        foreach ($this->returnRowsForInvoiceItemIds($sourceInvoiceItemIds) as $row) {
            if (! BusinessStatus::isReturnCompleted($row->return_status)) {
                continue;
            }

            $returnTime = $this->returnBusinessTime($row);
            if (! $returnTime) {
                continue;
            }

            foreach ($this->serialIdsFromReturn($row->serial_ids) as $serialId) {
                if (! in_array($serialId, $serialIds, true)) {
                    continue;
                }

                $current = $latest->get($serialId);
                if (! $current instanceof Carbon || $returnTime->gt($current)) {
                    $latest->put($serialId, $returnTime);
                }
            }
        }

        return $latest;
    }

    /**
     * @param  array<int, int>  $invoiceItemIds
     */
    private function returnRowsForInvoiceItemIds(array $invoiceItemIds): Collection
    {
        $returnDateSelect = Schema::hasColumn('returns', 'return_date')
            ? 'returns.return_date as return_date'
            : DB::raw('NULL as return_date');

        return DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->whereIn('return_items.invoice_item_id', $invoiceItemIds)
            ->get([
                'return_items.invoice_item_id',
                'return_items.serial_ids',
                'returns.status as return_status',
                'returns.created_at',
                $returnDateSelect,
            ]);
    }

    private function returnBusinessTime(object $row): ?Carbon
    {
        return BusinessDateTime::nullable($row->return_date ?? null)
            ?? BusinessDateTime::nullable($row->created_at ?? null);
    }

    /**
     * @return array<int, int>
     */
    private function serialIdsFromReturn(mixed $serialIds): array
    {
        $decoded = json_decode((string) $serialIds, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $decoded))));
    }

    private function asCarbon(CarbonInterface|string $value): Carbon
    {
        return $value instanceof CarbonInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);
    }
}
