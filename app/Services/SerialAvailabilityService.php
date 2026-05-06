<?php

namespace App\Services;

use App\Models\SerialImei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Serial/IMEI Availability Contract — Step 22.2A
 *
 * Một service duy nhất quyết định serial nào "khả dụng để bán".
 * Schema-tolerant: tự dò cột invoice_id/sold_at/purchase_return_id (legacy có thể thiếu).
 * Status-tolerant: chấp nhận cả `in_stock` chuẩn lẫn `available`/`ready`/NULL legacy.
 *
 * KHÔNG tự sửa data. KHÔNG tự chọn serial. Chỉ filter & validate.
 */
class SerialAvailabilityService
{
    /**
     * Status được coi là sellable.
     *
     * Schema thực tế: serial_imeis.status là ENUM('in_stock','sold','returning','warranty','defective','returned')
     * NOT NULL DEFAULT 'in_stock'. Chỉ 'in_stock' là sellable.
     *
     * Thêm các alias 'available'/'ready' để phòng trường hợp tương lai mở rộng ENUM.
     */
    public const SELLABLE_STATUSES = ['in_stock', 'available', 'ready'];

    /**
     * Status không sellable (đã bán / trả / bảo hành / lỗi / đã hoàn).
     */
    public const BLOCKED_STATUSES = [
        'sold',
        'returning',
        'warranty',
        'defective',
        'returned',
        'used_for_repair',
        'dismantled',
        'in_transit',
        // Future:
        'damaged',
        'cancelled',
        'returned_to_supplier',
        'refunded',
        'reserved',
    ];

    /**
     * repair_status không sellable.
     */
    public const BLOCKED_REPAIR_STATUSES = ['not_started', 'repairing'];

    /**
     * Cache schema column existence (tránh hỏi Schema mỗi request).
     *
     * @var array<string,bool>
     */
    protected static array $columnCache = [];

    /**
     * Trả về Builder serial sellable cho 1 product, đã apply mọi rule.
     */
    public function querySellableForProduct(int $productId): Builder
    {
        $q = SerialImei::query()->where('product_id', $productId);

        // Status: sellable hoặc NULL (legacy), nhưng KHÔNG nằm trong blocked set.
        $q->where(function (Builder $q) {
            $q->whereIn('status', self::SELLABLE_STATUSES)
              ->orWhereNull('status');
        });
        $q->whereNotIn('status', self::BLOCKED_STATUSES);

        // repair_status: NULL hoặc không thuộc blocked.
        $q->where(function (Builder $q) {
            $q->whereNull('repair_status')
              ->orWhereNotIn('repair_status', self::BLOCKED_REPAIR_STATUSES);
        });

        // Các cột legacy có thể thiếu — chỉ apply nếu schema có.
        if (self::hasColumn('invoice_id')) {
            $q->whereNull('invoice_id');
        }
        if (self::hasColumn('sold_at')) {
            $q->whereNull('sold_at');
        }
        if (self::hasColumn('purchase_return_id')) {
            $q->whereNull('purchase_return_id');
        }

        return $q;
    }

    /**
     * Kiểm tra 1 serial cụ thể có sellable cho product hay không.
     */
    public function isSellable(SerialImei $serial, int $productId): bool
    {
        if ((int) $serial->product_id !== $productId) {
            return false;
        }

        $status = $serial->status;
        if ($status !== null) {
            if (in_array($status, self::BLOCKED_STATUSES, true)) {
                return false;
            }
            if (! in_array($status, self::SELLABLE_STATUSES, true)) {
                return false;
            }
        }

        if ($serial->repair_status !== null
            && in_array($serial->repair_status, self::BLOCKED_REPAIR_STATUSES, true)) {
            return false;
        }

        if (self::hasColumn('invoice_id') && ! empty($serial->invoice_id)) {
            return false;
        }
        if (self::hasColumn('sold_at') && ! empty($serial->sold_at)) {
            return false;
        }
        if (self::hasColumn('purchase_return_id') && ! empty($serial->purchase_return_id)) {
            return false;
        }

        return true;
    }

    /**
     * Đếm bao nhiêu serial trong $serialIds thực sự sellable cho product.
     */
    public function countSellable(array $serialIds, int $productId): int
    {
        if (empty($serialIds)) {
            return 0;
        }

        return $this->querySellableForProduct($productId)
            ->whereIn('id', $serialIds)
            ->count();
    }

    /**
     * Lấy ID các serial KHÔNG sellable trong tập input (để báo lỗi rõ).
     *
     * @param  array<int>  $serialIds
     * @return array<int>
     */
    public function findBlockedIds(array $serialIds, int $productId): array
    {
        if (empty($serialIds)) {
            return [];
        }

        $okIds = $this->querySellableForProduct($productId)
            ->whereIn('id', $serialIds)
            ->pluck('id')
            ->all();

        return array_values(array_diff(
            array_map('intval', $serialIds),
            array_map('intval', $okIds),
        ));
    }

    /**
     * Chuẩn hóa serial cho response API. Đính kèm flag legacy.
     *
     * @return array{id:int, serial_number:string|null, status:string|null, repair_status:string|null, cost_price:float|null, label:string, is_legacy_status:bool}
     */
    public function normalizeForResponse(SerialImei $serial): array
    {
        return [
            'id'               => (int) $serial->id,
            'serial_number'    => $serial->serial_number,
            'status'           => $serial->status,
            'repair_status'    => $serial->repair_status,
            'cost_price'       => $serial->cost_price !== null ? (float) $serial->cost_price : null,
            'label'            => (string) ($serial->serial_number ?? ('#' . $serial->id)),
            'is_legacy_status' => $this->isLegacyStatus($serial),
        ];
    }

    /**
     * Serial được coi "legacy status" khi status NULL hoặc dùng alias cũ
     * (`available`/`ready`) — UI có thể hiển thị badge "Dữ liệu cũ".
     */
    public function isLegacyStatus(SerialImei $serial): bool
    {
        if ($serial->status === null) {
            return true;
        }

        return in_array($serial->status, ['available', 'ready'], true);
    }

    /**
     * Schema-aware column check (cached).
     */
    protected static function hasColumn(string $column): bool
    {
        if (! array_key_exists($column, self::$columnCache)) {
            self::$columnCache[$column] = Schema::hasColumn('serial_imeis', $column);
        }

        return self::$columnCache[$column];
    }

    /**
     * Reset cache — dùng cho test khi migrate thay đổi schema giữa chừng.
     */
    public static function clearSchemaCache(): void
    {
        self::$columnCache = [];
    }
}
