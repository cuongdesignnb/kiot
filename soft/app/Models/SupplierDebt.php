<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierDebt extends Model
{
    protected $fillable = [
        'supplier_id',
        'purchase_order_id', 
        'purchase_receipt_id',
        'ref_code',
        'amount',
        'debt_total',
        'type',
        'note',
        'created_by',
        'recorded_at'
    ];

    protected $dates = [
        'recorded_at'
    ];

    // Relationships
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseReceipt()
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Accessors
    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->amount >= 0 ? '+' : '';
        return $prefix . number_format($this->amount, 0, ',', '.') . ' VNĐ';
    }

    public function getFormattedDebtTotalAttribute(): string
    {
        return number_format($this->debt_total, 0, ',', '.') . ' VNĐ';
    }

    public function getTypeTextAttribute(): string
    {
        return match($this->type) {
            'purchase' => 'Mua hàng',
            'payment' => 'Thanh toán',
            'adjustment' => 'Điều chỉnh',
            'return' => 'Trả hàng',
            default => 'Không xác định'
        };
    }

    /**
     * ✅ FIXED: Create debt record and update supplier total
     * This is the MAIN method all controllers should use
     */
    public static function createDebtRecord($supplierId, $amount, $type = 'purchase', $refCode = null, $purchaseOrderId = null, $purchaseReceiptId = null, $note = null)
    {
        DB::beginTransaction();
        try {
            // Lock supplier record to prevent race conditions
            $supplier = Supplier::lockForUpdate()->findOrFail($supplierId);
            
            // Calculate new debt total
            $oldDebtTotal = $supplier->total_debt;
            $newDebtTotal = $oldDebtTotal + $amount;

            // Create debt record with CORRECT debt_total
            $debt = self::create([
                'supplier_id' => $supplierId,
                'purchase_order_id' => $purchaseOrderId,
                'purchase_receipt_id' => $purchaseReceiptId,
                'ref_code' => $refCode,
                'amount' => $amount,
                'debt_total' => $newDebtTotal,  // ✅ FIXED: Calculate correctly
                'type' => $type,
                'note' => $note,
                'created_by' => auth()->id(),
                'recorded_at' => now(),
            ]);

            // ✅ FIXED: Update supplier total debt
            $supplier->update(['total_debt' => $newDebtTotal]);

            // Log the transaction
            Log::info("Supplier debt record created", [
                'supplier_id' => $supplierId,
                'amount' => $amount,
                'old_debt_total' => $oldDebtTotal,
                'new_debt_total' => $newDebtTotal,
                'type' => $type,
                'ref_code' => $refCode
            ]);

            DB::commit();

            // Auto-offset nếu đối tác có liên kết (chỉ khi KHÔNG phải giao dịch offset)
            if (!in_array($type, ['offset', 'adjustment'])) {
                \App\Services\DebtOffsetService::tryAutoOffset('supplier', $supplierId);
            }

            return $debt;

        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Failed to create supplier debt record", [
                'supplier_id' => $supplierId,
                'amount' => $amount,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * ✅ ENHANCED: Create purchase debt from Purchase Order
     */
    public static function createPurchaseDebt($purchaseOrder, $note = null, $customAmount = null)
{
    $amount = $customAmount ?? $purchaseOrder->need_pay;  // ✅ Use need_pay instead of total
    
    return self::createDebtRecord(
        supplierId: $purchaseOrder->supplier_id,
        amount: $amount,
        type: 'purchase',
        refCode: $purchaseOrder->code,
        purchaseOrderId: $purchaseOrder->id,
        note: $note ?? "Nợ từ đơn nhập hàng {$purchaseOrder->code}"
    );
}

    /**
     * ✅ ENHANCED: Create payment record (negative debt)
     */
    public static function createPayment($supplierId, $amount, $refCode, $purchaseOrderId = null, $note = null)
    {
        return self::createDebtRecord(
            supplierId: $supplierId,
            amount: -abs($amount), // Always negative for payment
            type: 'payment',
            refCode: $refCode,
            purchaseOrderId: $purchaseOrderId,
            note: $note ?? "Thanh toán cho nhà cung cấp"
        );
    }

    /**
     * ✅ ENHANCED: Create return credit (negative debt)
     */
    public static function createReturnCredit($supplierId, $amount, $refCode, $purchaseReceiptId = null, $purchaseOrderId = null, $note = null)
    {
        return self::createDebtRecord(
            supplierId: $supplierId,
            amount: -abs($amount), // Always negative for return
            type: 'return',
            refCode: $refCode,
            purchaseOrderId: $purchaseOrderId,
            purchaseReceiptId: $purchaseReceiptId,
            note: $note ?? "Trả hàng - giảm nợ"
        );
    }

    /**
     * ✅ ENHANCED: Create adjustment record
     */
    public static function createAdjustment($supplierId, $amount, $refCode, $note = null)
    {
        return self::createDebtRecord(
            supplierId: $supplierId,
            amount: $amount,
            type: 'adjustment',
            refCode: $refCode,
            note: $note ?? "Điều chỉnh công nợ"
        );
    }

    /**
     * Get debt summary for a supplier
     */
    public static function getSupplierDebtSummary($supplierId)
    {
        $debts = static::where('supplier_id', $supplierId)
            ->selectRaw('
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_debt,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_paid,
                COUNT(CASE WHEN amount > 0 THEN 1 END) as debt_count,
                COUNT(CASE WHEN amount < 0 THEN 1 END) as payment_count
            ')
            ->first();

        $latestRecord = static::where('supplier_id', $supplierId)
            ->orderBy('recorded_at', 'desc')
            ->first();

        return [
            'total_debt' => $debts->total_debt ?? 0,
            'total_paid' => $debts->total_paid ?? 0,
            'current_balance' => $latestRecord ? $latestRecord->debt_total : 0,
            'debt_transactions' => $debts->debt_count ?? 0,
            'payment_transactions' => $debts->payment_count ?? 0,
            'last_transaction_date' => $latestRecord ? $latestRecord->recorded_at : null
        ];
    }

    /**
     * Get top debtors
     */
    public static function getTopDebtors($limit = 10)
    {
        return static::select('supplier_id')
            ->selectRaw('MAX(debt_total) as current_debt')
            ->with(['supplier:id,code,name,email,phone'])
            ->groupBy('supplier_id')
            ->having('current_debt', '>', 0)
            ->orderBy('current_debt', 'desc')
            ->limit($limit)
            ->get();
    }
}