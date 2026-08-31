<?php

namespace App\Services;

use App\Support\BusinessDateTime;
use App\Support\Status\BusinessStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only inspector for the full sale -> return -> resale lifecycle of a
 * serial.  Multiple completed invoice links are legitimate after a return;
 * they must never be called duplicate without checking the return evidence.
 */
class SerialLifecycleInspectionService
{
    public const BACKDATED_RESALE = 'backdated_resale';

    public const ORDERED_RESALE_HISTORY = 'ordered_resale_history';

    public const RECORDED_TIME_UNKNOWN = 'recorded_time_unknown';

    public const UNRESOLVED_MULTIPLE_COMPLETED_SALES = 'unresolved_multiple_completed_sales';

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function inspectProduct(int $productId): Collection
    {
        return $this->inspectSales($this->completedSaleLinks($productId));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function inspectAll(): Collection
    {
        return $this->inspectSales($this->completedSaleLinks());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function inspectSerial(int $serialId): ?array
    {
        $sales = $this->completedSaleLinks(null, $serialId);
        if ($sales->isEmpty()) {
            return null;
        }

        $inspections = $this->inspectSales($sales);

        return $inspections->first();
    }

    /**
     * @param  Collection<int, object>  $sales
     * @return Collection<int, array<string, mixed>>
     */
    private function inspectSales(Collection $sales): Collection
    {
        $salesBySerial = $sales
            ->groupBy(fn (object $sale) => (int) $sale->serial_id)
            ->filter(fn (Collection $links) => $links->pluck('invoice_id')->unique()->count() > 1);

        if ($salesBySerial->isEmpty()) {
            return collect();
        }

        $sourceItemIds = $salesBySerial
            ->flatten(1)
            ->pluck('invoice_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $returnRows = $this->completedReturnRows($sourceItemIds);
        $returnLogTimes = $this->returnRecordedTimes($returnRows->pluck('return_id')->unique()->all());

        return $salesBySerial
            ->map(function (Collection $serialSales, int|string $serialId) use ($returnRows, $returnLogTimes): array {
                return $this->inspectOne(
                    (int) $serialId,
                    $serialSales,
                    $returnRows,
                    $returnLogTimes,
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function completedSaleLinks(?int $productId = null, ?int $serialId = null): Collection
    {
        $businessTime = Schema::hasColumn('invoices', 'transaction_date')
            ? 'COALESCE(invoices.transaction_date, invoices.created_at)'
            : 'invoices.created_at';
        $hasLockStartedAt = Schema::hasColumn('invoices', 'lock_started_at');
        $recordedTime = $hasLockStartedAt
            ? 'COALESCE(invoices.lock_started_at, invoices.created_at)'
            : 'invoices.created_at';

        $query = DB::table('invoice_item_serials')
            ->join('serial_imeis', 'serial_imeis.id', '=', 'invoice_item_serials.serial_imei_id')
            ->join('invoice_items', 'invoice_items.id', '=', 'invoice_item_serials.invoice_item_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->select([
                'invoice_item_serials.id as link_id',
                'invoice_item_serials.serial_imei_id as serial_id',
                'serial_imeis.serial_number',
                'serial_imeis.product_id',
                'invoice_items.id as invoice_item_id',
                'invoices.id as invoice_id',
                'invoices.code as invoice_code',
                'invoices.status as invoice_status',
                'invoices.created_at as invoice_created_at',
            ])
            ->selectRaw($businessTime.' as business_time')
            ->selectRaw($recordedTime.' as recorded_time')
            ->selectRaw($hasLockStartedAt
                ? 'CASE WHEN invoices.lock_started_at IS NULL THEN 0 ELSE 1 END as has_recorded_time'
                : '0 as has_recorded_time');

        if ($productId !== null) {
            $query->where('serial_imeis.product_id', $productId);
        }

        if ($serialId !== null) {
            $query->where('invoice_item_serials.serial_imei_id', $serialId);
        }

        return $query->orderBy('invoice_item_serials.serial_imei_id')
            ->orderBy('invoice_item_serials.id')
            ->get()
            ->filter(fn (object $sale) => BusinessStatus::isCompleted($sale->invoice_status))
            ->values();
    }

    /**
     * @param  array<int, int>  $invoiceItemIds
     * @return Collection<int, object>
     */
    private function completedReturnRows(array $invoiceItemIds): Collection
    {
        if ($invoiceItemIds === []) {
            return collect();
        }

        $businessTime = Schema::hasColumn('returns', 'return_date')
            ? 'COALESCE(returns.return_date, returns.created_at)'
            : 'returns.created_at';
        $recordedTime = Schema::hasColumn('returns', 'recorded_at')
            ? 'returns.recorded_at'
            : 'NULL';

        return DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->whereIn('return_items.invoice_item_id', $invoiceItemIds)
            ->select([
                'return_items.id as return_item_id',
                'return_items.invoice_item_id',
                'return_items.serial_ids',
                'returns.id as return_id',
                'returns.code as return_code',
                'returns.status as return_status',
                'returns.created_at as return_created_at',
            ])
            ->selectRaw($businessTime.' as business_time')
            ->selectRaw($recordedTime.' as recorded_time')
            ->get()
            ->filter(fn (object $return) => BusinessStatus::isReturnCompleted($return->return_status))
            ->values();
    }

    /**
     * Older returns have no durable recorded_at. Their first return_create log
     * is a legacy creation trace only; new rows use returns.recorded_at.
     *
     * @param  array<int, int|string>  $returnIds
     * @return Collection<int, Carbon>
     */
    private function returnRecordedTimes(array $returnIds): Collection
    {
        if ($returnIds === []) {
            return collect();
        }

        return DB::table('activity_logs')
            ->where('action', 'return_create')
            ->whereIn('subject_id', $returnIds)
            ->selectRaw('subject_id, MIN(created_at) as recorded_at')
            ->groupBy('subject_id')
            ->get()
            ->mapWithKeys(function (object $row): array {
                return [(int) $row->subject_id => Carbon::parse($row->recorded_at)];
            });
    }

    /**
     * @param  Collection<int, object>  $sales
     * @param  Collection<int, object>  $allReturns
     * @param  Collection<int, Carbon>  $returnLogTimes
     * @return array<string, mixed>
     */
    private function inspectOne(int $serialId, Collection $sales, Collection $allReturns, Collection $returnLogTimes): array
    {
        $orderedSales = $sales
            ->map(fn (object $sale) => $this->normaliseSale($sale))
            ->sort($this->compareRecordedEvents(...))
            ->values();
        $returns = $allReturns
            ->filter(fn (object $return) => in_array($serialId, $this->serialIds($return->serial_ids), true))
            ->map(fn (object $return) => $this->normaliseReturn($return, $returnLogTimes))
            ->sort($this->compareRecordedEvents(...))
            ->values();

        $cycles = [];
        $usedReturnIds = [];
        $classification = self::ORDERED_RESALE_HISTORY;

        for ($index = 1; $index < $orderedSales->count(); $index++) {
            $sale = $orderedSales[$index];
            $priorItemIds = $orderedSales
                ->slice(0, $index)
                ->pluck('invoice_item_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $candidates = $returns
                ->filter(function (array $return) use ($priorItemIds, $usedReturnIds, $sale): bool {
                    return in_array((int) $return['invoice_item_id'], $priorItemIds, true)
                        && ! in_array((int) $return['return_id'], $usedReturnIds, true)
                        && $return['recorded_time']->lt($sale['recorded_time']);
                })
                ->values();

            if ($candidates->isEmpty()) {
                $hasReturnEvidence = $returns->contains(fn (array $return) => in_array((int) $return['invoice_item_id'], $priorItemIds, true));
                $classification = $this->raiseClassification(
                    $classification,
                    $hasReturnEvidence
                        ? self::RECORDED_TIME_UNKNOWN
                        : self::UNRESOLVED_MULTIPLE_COMPLETED_SALES,
                );
                $cycles[] = [
                    'sale_code' => $sale['invoice_code'],
                    'return_code' => null,
                    'business_order_valid' => null,
                ];

                continue;
            }

            $return = $candidates->last();
            $usedReturnIds[] = (int) $return['return_id'];
            $recordedEvidenceKnown = $sale['recorded_time_source'] !== 'created_at'
                && $return['recorded_time_source'] !== 'created_at';
            $businessOrderValid = $sale['business_time']->gt($return['business_time']);

            if (! $recordedEvidenceKnown) {
                $classification = $this->raiseClassification($classification, self::RECORDED_TIME_UNKNOWN);
            }
            if ($recordedEvidenceKnown && ! $businessOrderValid) {
                $classification = $this->raiseClassification($classification, self::BACKDATED_RESALE);
            }

            $cycles[] = [
                'sale_code' => $sale['invoice_code'],
                'return_code' => $return['return_code'],
                'business_order_valid' => $businessOrderValid,
                'sale_business_time' => $sale['business_time']->toDateTimeString(),
                'return_business_time' => $return['business_time']->toDateTimeString(),
                'sale_recorded_time' => $sale['recorded_time']->toDateTimeString(),
                'return_recorded_time' => $return['recorded_time']->toDateTimeString(),
            ];
        }

        return [
            'serial_id' => $serialId,
            'serial_number' => (string) $orderedSales->first()['serial_number'],
            'product_id' => (int) $orderedSales->first()['product_id'],
            'classification' => $classification,
            // Any serial with more than one completed sale needs a dedicated
            // historical snapshot workflow; the moving-average command must
            // never recalculate it from the current serial cost.
            'rebuild_safe' => false,
            'invoice_codes' => $orderedSales->pluck('invoice_code')->implode(', '),
            'cycles' => $cycles,
            'message' => $this->messageFor($classification, $serialId, $orderedSales, $cycles),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseSale(object $sale): array
    {
        $hasRecordedTime = property_exists($sale, 'has_recorded_time') && (bool) $sale->has_recorded_time
            && $sale->recorded_time !== null;

        return [
            'link_id' => (int) $sale->link_id,
            'serial_id' => (int) $sale->serial_id,
            'serial_number' => (string) $sale->serial_number,
            'product_id' => (int) $sale->product_id,
            'invoice_item_id' => (int) $sale->invoice_item_id,
            'invoice_id' => (int) $sale->invoice_id,
            'invoice_code' => (string) $sale->invoice_code,
            'business_time' => BusinessDateTime::nullable($sale->business_time) ?? Carbon::parse($sale->invoice_created_at),
            'recorded_time' => BusinessDateTime::nullable($sale->recorded_time) ?? Carbon::parse($sale->invoice_created_at),
            'recorded_time_source' => $hasRecordedTime ? 'lock_started_at' : 'created_at',
        ];
    }

    /**
     * @param  Collection<int, Carbon>  $returnLogTimes
     * @return array<string, mixed>
     */
    private function normaliseReturn(object $return, Collection $returnLogTimes): array
    {
        $recordedAt = BusinessDateTime::nullable($return->recorded_time)
            ?? $returnLogTimes->get((int) $return->return_id)
            ?? BusinessDateTime::nullable($return->return_created_at)
            ?? now();

        return [
            'return_id' => (int) $return->return_id,
            'return_code' => (string) $return->return_code,
            'invoice_item_id' => (int) $return->invoice_item_id,
            'business_time' => BusinessDateTime::nullable($return->business_time) ?? Carbon::parse($return->return_created_at),
            'recorded_time' => $recordedAt,
            'recorded_time_source' => $return->recorded_time !== null
                ? 'recorded_at'
                : ($returnLogTimes->has((int) $return->return_id) ? 'activity_log' : 'created_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareRecordedEvents(array $left, array $right): int
    {
        return $left['recorded_time']->format('Y-m-d H:i:s.u') <=> $right['recorded_time']->format('Y-m-d H:i:s.u')
            ?: (($left['link_id'] ?? $left['return_id']) <=> ($right['link_id'] ?? $right['return_id']));
    }

    /**
     * @return array<int, int>
     */
    private function serialIds(mixed $serialIds): array
    {
        $decoded = json_decode((string) $serialIds, true);

        return is_array($decoded)
            ? array_values(array_unique(array_filter(array_map('intval', $decoded))))
            : [];
    }

    private function raiseClassification(string $current, string $candidate): string
    {
        $priority = [
            self::ORDERED_RESALE_HISTORY => 0,
            self::RECORDED_TIME_UNKNOWN => 1,
            self::BACKDATED_RESALE => 2,
            self::UNRESOLVED_MULTIPLE_COMPLETED_SALES => 3,
        ];

        return ($priority[$candidate] ?? 0) > ($priority[$current] ?? 0)
            ? $candidate
            : $current;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sales
     * @param  array<int, array<string, mixed>>  $cycles
     */
    private function messageFor(string $classification, int $serialId, Collection $sales, array $cycles): string
    {
        $codes = $sales->pluck('invoice_code')->implode(', ');

        return match ($classification) {
            self::BACKDATED_RESALE => sprintf(
                'Serial #%d có vòng đời trả rồi bán lại hợp lệ theo lúc ghi nhận, nhưng hóa đơn bán lại bị lùi ngày chứng từ (%s). Không tự rebuild giá vốn lịch sử.',
                $serialId,
                $codes,
            ),
            self::RECORDED_TIME_UNKNOWN => sprintf(
                'Serial #%d có nhiều lần bán và có dấu vết trả hàng, nhưng thiếu thời điểm ghi nhận đáng tin cậy để xác nhận thứ tự. Không tự rebuild giá vốn lịch sử.',
                $serialId,
            ),
            self::UNRESOLVED_MULTIPLE_COMPLETED_SALES => sprintf(
                'Serial #%d gắn với nhiều hóa đơn hoàn thành (%s) nhưng không có bằng chứng trả đúng Serial giữa các lần bán. Cần rà soát thủ công.',
                $serialId,
                $codes,
            ),
            default => sprintf(
                'Serial #%d có lịch sử trả rồi bán lại. Đây không phải bán trùng, nhưng không tự rebuild giá vốn lịch sử để bảo toàn snapshot từng lần bán.',
                $serialId,
            ),
        };
    }
}
