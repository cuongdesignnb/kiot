<?php

namespace App\Models;

use App\Domain\DebtOffset\DecimalMoney;
use Illuminate\Database\Eloquent\Model;

class DebtOffset extends Model
{
    protected $fillable = [
        'code',
        'customer_id',
        'amount',
        'receivable_before',
        'payable_before',
        'receivable_after',
        'payable_after',
        'is_auto',
        'note',
        'user_id',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'workflow_status',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'applied_at',
        'idempotency_key',
        'approval_operation_id',
        'apply_operation_id',
        'reversal_operation_id',
        'customer_amount',
        'supplier_amount',
        'source_references',
        'reverses_debt_offset_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'receivable_before' => 'decimal:2',
        'payable_before' => 'decimal:2',
        'receivable_after' => 'decimal:2',
        'payable_after' => 'decimal:2',
        'is_auto' => 'boolean',
        'cancelled_at' => 'datetime',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'applied_at' => 'datetime',
        'customer_amount' => 'decimal:2',
        'supplier_amount' => 'decimal:2',
        'source_references' => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledByUser()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function approvalOperation()
    {
        return $this->belongsTo(PartnerDebtOperation::class, 'approval_operation_id');
    }

    public function applyOperation()
    {
        return $this->belongsTo(PartnerDebtOperation::class, 'apply_operation_id');
    }

    public function reversalOperation()
    {
        return $this->belongsTo(PartnerDebtOperation::class, 'reversal_operation_id');
    }

    public function originalOffset()
    {
        return $this->belongsTo(self::class, 'reverses_debt_offset_id');
    }

    public function reversalVoucher()
    {
        return $this->hasOne(self::class, 'reverses_debt_offset_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDraft(): bool
    {
        return $this->workflow_status === 'draft';
    }

    public function isPendingApproval(): bool
    {
        return $this->workflow_status === 'pending_approval';
    }

    public function isApproved(): bool
    {
        return $this->workflow_status === 'approved';
    }

    public function isApplied(): bool
    {
        return $this->workflow_status === 'applied';
    }

    public function isReversed(): bool
    {
        return $this->workflow_status === 'reversed';
    }

    public function isLegacy(): bool
    {
        return $this->workflow_status === null;
    }

    public function versionToken(): string
    {
        $payload = [
            'id' => (int) $this->id,
            'workflow_status' => $this->workflow_status,
            'status' => $this->status,
            'amount' => DecimalMoney::from((string) $this->amount)->toDecimal(),
            'note' => $this->note,
            'requested_by' => $this->requested_by === null ? null : (int) $this->requested_by,
            'approved_by' => $this->approved_by === null ? null : (int) $this->approved_by,
            'rejected_by' => $this->rejected_by === null ? null : (int) $this->rejected_by,
            'approval_operation_id' => $this->approval_operation_id === null ? null : (int) $this->approval_operation_id,
            'apply_operation_id' => $this->apply_operation_id === null ? null : (int) $this->apply_operation_id,
            'reversal_operation_id' => $this->reversal_operation_id === null ? null : (int) $this->reversal_operation_id,
            'reverses_debt_offset_id' => $this->reverses_debt_offset_id === null ? null : (int) $this->reverses_debt_offset_id,
            'updated_at' => $this->updated_at?->format('Y-m-d\TH:i:s.uP'),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
