<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CustomerDebt extends Model
{
    use HasFactory;

    // ====== DEBT TYPES (giống SupplierDebt) ======
    const TYPE_SALE       = 'sale';       // Bán hàng → tăng nợ
    const TYPE_PAYMENT    = 'payment';    // Thu tiền → giảm nợ
    const TYPE_RETURN     = 'return';     // Trả hàng → giảm nợ
    const TYPE_ADJUSTMENT = 'adjustment'; // Điều chỉnh ± nợ
    const TYPE_OFFSET     = 'offset';     // Cấn bằng với NCC

    protected $fillable = [
        'customer_id',
        'order_id',
        'order_return_id',
        'ref_code',
        'amount',
        'debt_total',
        'type',
        'note',
        'created_by',
        'recorded_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'debt_total' => 'decimal:2',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // ====== RELATIONSHIPS ======

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ====== BOOT — chỉ giữ deleted event ======

    protected static function boot()
    {
        parent::boot();

        static::deleted(function ($customerDebt) {
            $customerDebt->recalcCustomerTotalDebt();
        });
    }

    // ====== CORE METHOD: createDebtRecord — THREAD-SAFE ======

    /**
     * Tạo bản ghi công nợ KH và cập nhật Customer.total_debt
     * Dùng lockForUpdate() để tránh race condition (giống SupplierDebt)
     */
    public static function createDebtRecord(
        int $customerId,
        float $amount,
        string $type = self::TYPE_SALE,
        ?string $refCode = null,
        ?int $orderId = null,
        ?int $orderReturnId = null,
        ?string $note = null
    ): self {
        DB::beginTransaction();
        try {
            // Lock customer record
            $customer = Customer::lockForUpdate()->findOrFail($customerId);

            $oldDebtTotal = $customer->total_debt ?? 0;
            $newDebtTotal = $oldDebtTotal + $amount;

            // Auto generate ref_code
            if (empty($refCode)) {
                $refCode = 'CD' . now()->format('YmdHis') . rand(100, 999);
            }

            $debt = self::create([
                'customer_id'     => $customerId,
                'order_id'        => $orderId,
                'order_return_id' => $orderReturnId,
                'ref_code'        => $refCode,
                'amount'          => $amount,
                'debt_total'      => $newDebtTotal,
                'type'            => $type,
                'note'            => $note,
                'created_by'      => auth()->id(),
                'recorded_at'     => now(),
            ]);

            // Cập nhật Customer.total_debt
            $customer->update(['total_debt' => $newDebtTotal]);

            Log::info("CustomerDebt record created", [
                'customer_id'    => $customerId,
                'amount'         => $amount,
                'type'           => $type,
                'old_debt_total' => $oldDebtTotal,
                'new_debt_total' => $newDebtTotal,
                'ref_code'       => $refCode,
            ]);

            DB::commit();

            // Auto-offset nếu đối tác có liên kết (chỉ khi KHÔNG phải giao dịch offset)
            if (!in_array($type, [self::TYPE_OFFSET, self::TYPE_ADJUSTMENT])) {
                \App\Services\DebtOffsetService::tryAutoOffset('customer', $customerId);
            }

            return $debt;

        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Failed to create CustomerDebt record", [
                'customer_id' => $customerId,
                'amount'      => $amount,
                'type'        => $type,
                'error'       => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ====== HELPER METHODS ======

    /**
     * Tạo nợ từ bán hàng (amount dương)
     */
    public static function createSaleDebt(int $customerId, float $amount, int $orderId, ?string $note = null): self
    {
        return self::createDebtRecord(
            customerId: $customerId,
            amount: abs($amount),
            type: self::TYPE_SALE,
            orderId: $orderId,
            note: $note ?? "Công nợ từ đơn hàng"
        );
    }

    /**
     * Thu tiền → giảm nợ (amount âm)
     */
    public static function createPayment(int $customerId, float $amount, ?string $refCode = null, ?string $note = null): self
    {
        return self::createDebtRecord(
            customerId: $customerId,
            amount: -abs($amount),
            type: self::TYPE_PAYMENT,
            refCode: $refCode,
            note: $note ?? "Thanh toán"
        );
    }

    /**
     * Trả hàng → giảm nợ (amount âm)
     */
    public static function createReturnCredit(int $customerId, float $amount, ?int $orderId = null, ?int $orderReturnId = null, ?string $refCode = null, ?string $note = null): self
    {
        return self::createDebtRecord(
            customerId: $customerId,
            amount: -abs($amount),
            type: self::TYPE_RETURN,
            refCode: $refCode,
            orderId: $orderId,
            orderReturnId: $orderReturnId,
            note: $note ?? "Giảm nợ từ trả hàng"
        );
    }

    /**
     * Điều chỉnh ± nợ
     */
    public static function createAdjustment(int $customerId, float $amount, ?string $note = null, ?string $refCode = null): self
    {
        return self::createDebtRecord(
            customerId: $customerId,
            amount: $amount,
            type: self::TYPE_ADJUSTMENT,
            refCode: $refCode,
            note: $note ?? "Điều chỉnh công nợ"
        );
    }

    /**
     * Cấn bằng công nợ với NCC
     */
    public static function createOffset(int $customerId, float $amount, ?string $refCode = null, ?string $note = null): self
    {
        return self::createDebtRecord(
            customerId: $customerId,
            amount: -abs($amount),
            type: self::TYPE_OFFSET,
            refCode: $refCode,
            note: $note ?? "Cấn bằng công nợ"
        );
    }

    // ====== UTILITY ======

    /**
     * Tính lại Customer.total_debt từ bản ghi mới nhất
     */
    public function recalcCustomerTotalDebt(): void
    {
        if (!$this->customer_id) return;

        $latestRecord = static::where('customer_id', $this->customer_id)
            ->orderBy('recorded_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        Customer::where('id', $this->customer_id)
            ->update(['total_debt' => $latestRecord ? $latestRecord->debt_total : 0]);
    }

    /**
     * Get debt summary for a customer
     */
    public static function getCustomerDebtSummary(int $customerId): array
    {
        $debts = static::where('customer_id', $customerId)
            ->selectRaw('
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_debt,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_paid,
                COUNT(CASE WHEN amount > 0 THEN 1 END) as debt_count,
                COUNT(CASE WHEN amount < 0 THEN 1 END) as payment_count
            ')
            ->first();

        $latestRecord = static::where('customer_id', $customerId)
            ->orderBy('recorded_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return [
            'total_debt'           => $debts->total_debt ?? 0,
            'total_paid'           => $debts->total_paid ?? 0,
            'current_balance'      => $latestRecord ? $latestRecord->debt_total : 0,
            'debt_transactions'    => $debts->debt_count ?? 0,
            'payment_transactions' => $debts->payment_count ?? 0,
            'last_transaction_date' => $latestRecord?->recorded_at,
        ];
    }

    /**
     * Get top debtors
     */
    public static function getTopDebtors(int $limit = 10)
    {
        return Customer::where('total_debt', '>', 0)
            ->orderBy('total_debt', 'desc')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'email', 'phone', 'total_debt']);
    }

    // ====== SCOPES ======

    public function scopeByCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByDateRange(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('recorded_at', [$startDate, $endDate]);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('recorded_at', 'desc')->orderBy('id', 'desc');
    }

    public function scopeWithCustomer(Builder $query): Builder
    {
        return $query->with(['customer:id,code,name,email,phone,total_debt']);
    }

    public function scopeWithOrder(Builder $query): Builder
    {
        return $query->with(['order:id,code,total,status']);
    }

    public function scopeWithCreator(Builder $query): Builder
    {
        return $query->with(['creator:id,name,email']);
    }

    // ====== ACCESSORS ======

    public function getTypeTextAttribute(): string
    {
        return match($this->type) {
            self::TYPE_SALE       => 'Bán hàng',
            self::TYPE_PAYMENT    => 'Thanh toán',
            self::TYPE_RETURN     => 'Trả hàng',
            self::TYPE_ADJUSTMENT => 'Điều chỉnh',
            self::TYPE_OFFSET     => 'Cấn bằng',
            default               => 'Không xác định',
        };
    }

    public function getTypeIconAttribute(): string
    {
        return match($this->type) {
            self::TYPE_SALE       => '🛒',
            self::TYPE_PAYMENT    => '💵',
            self::TYPE_RETURN     => '🔄',
            self::TYPE_ADJUSTMENT => '⚙️',
            self::TYPE_OFFSET     => '⚖️',
            default               => '❓',
        };
    }

    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->amount >= 0 ? '+' : '';
        return $prefix . number_format($this->amount, 0, ',', '.') . ' VNĐ';
    }

    public function getFormattedDebtTotalAttribute(): string
    {
        return number_format($this->debt_total, 0, ',', '.') . ' VNĐ';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->amount > 0 ? 'text-red-600' : 'text-green-600';
    }

    public function getBadgeColorAttribute(): string
    {
        return match($this->type) {
            self::TYPE_SALE       => 'bg-red-100 text-red-800',
            self::TYPE_PAYMENT    => 'bg-green-100 text-green-800',
            self::TYPE_RETURN     => 'bg-blue-100 text-blue-800',
            self::TYPE_ADJUSTMENT => 'bg-yellow-100 text-yellow-800',
            self::TYPE_OFFSET     => 'bg-purple-100 text-purple-800',
            default               => 'bg-gray-100 text-gray-800',
        };
    }

    public function isDebt(): bool
    {
        return $this->amount > 0;
    }

    public function isPayment(): bool
    {
        return $this->amount < 0;
    }
}