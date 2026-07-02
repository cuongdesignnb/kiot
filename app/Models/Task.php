<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $table = 'tasks';

    protected $fillable = [
        'code',
        'type',
        'title',
        'category_id',
        'product_id',
        'serial_imei_id',
        'original_cost',
        'parts_cost',
        'total_cost',
        'issue_description',
        'priority',
        'progress',
        'status',
        'assigned_employee_id',
        'assigned_at',
        'completed_at',
        'cancelled_at',
        'branch_id',
        'notes',
        'deadline',
        'created_by',
        // External repair fields
        'external',
        'sub_status',
        'customer_id',
        'customer_name',
        'customer_phone',
        'warranty_id',
        'warranty_policy',
        'warranty_covered_amount',
        'customer_payable_amount',
        'invoice_id',
        'received_at',
        'returned_at',
        'labor_fee',
        'parts_total',
        'total_amount',
        'paid_amount',
        'debt_amount',
    ];

    protected $casts = [
        'original_cost' => 'decimal:0',
        'parts_cost'    => 'decimal:0',
        'total_cost'    => 'decimal:0',
        'assigned_at'   => 'datetime',
        'completed_at'  => 'datetime',
        'cancelled_at'  => 'datetime',
        'deadline'      => 'date',
        'progress'      => 'integer',
        // External repair casts
        'external'      => 'boolean',
        'received_at'   => 'datetime',
        'returned_at'   => 'datetime',
        'labor_fee'     => 'decimal:0',
        'parts_total'   => 'decimal:0',
        'total_amount'  => 'decimal:0',
        'paid_amount'   => 'decimal:0',
        'debt_amount'   => 'decimal:0',
        'warranty_covered_amount' => 'decimal:0',
        'customer_payable_amount' => 'decimal:0',
    ];

    // ── Warranty policy constants (Step 23.8D) ──
    const WARRANTY_POLICY_NONE      = 'none';
    const WARRANTY_POLICY_FREE_LABOR = 'free_labor';
    const WARRANTY_POLICY_FREE_PARTS = 'free_parts';
    const WARRANTY_POLICY_FULL_FREE  = 'full_free';

    const WARRANTY_POLICIES = [
        self::WARRANTY_POLICY_NONE,
        self::WARRANTY_POLICY_FREE_LABOR,
        self::WARRANTY_POLICY_FREE_PARTS,
        self::WARRANTY_POLICY_FULL_FREE,
    ];

    // ── Type constants ──
    const TYPE_GENERAL = 'general';
    const TYPE_REPAIR  = 'repair';

    // ── Status constants ──
    const STATUS_PENDING     = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';
    const STATUS_CANCELLED   = 'cancelled';

    const STATUS_MAP = [
        self::STATUS_PENDING     => 'Chờ xử lý',
        self::STATUS_IN_PROGRESS => 'Đang thực hiện',
        self::STATUS_COMPLETED   => 'Hoàn thành',
        self::STATUS_CANCELLED   => 'Đã hủy',
    ];

    // ── Priority constants ──
    const PRIORITY_LOW    = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH   = 'high';
    const PRIORITY_URGENT = 'urgent';

    const PRIORITY_MAP = [
        self::PRIORITY_LOW    => 'Thấp',
        self::PRIORITY_NORMAL => 'Bình thường',
        self::PRIORITY_HIGH   => 'Cao',
        self::PRIORITY_URGENT => 'Khẩn cấp',
    ];

    // ── Accessors ──

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_MAP[$this->status] ?? $this->status;
    }

    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITY_MAP[$this->priority] ?? $this->priority;
    }

    public function getIsRepairAttribute(): bool
    {
        return $this->type === self::TYPE_REPAIR;
    }

    // ── Scopes ──

    public function scopeRepairs($query)
    {
        return $query->where('type', self::TYPE_REPAIR);
    }

    public function scopeGeneral($query)
    {
        return $query->where('type', self::TYPE_GENERAL);
    }

    // ── Relationships ──

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function serialImei()
    {
        return $this->belongsTo(SerialImei::class, 'serial_imei_id');
    }

    public function assignedEmployee()
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    public function parts()
    {
        return $this->hasMany(TaskPart::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category()
    {
        return $this->belongsTo(TaskCategory::class, 'category_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function assignedEmployees()
    {
        return $this->belongsToMany(Employee::class, 'task_assignments')
            ->withPivot('status', 'assigned_at', 'responded_at', 'notes')
            ->withTimestamps();
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class);
    }

    // ── Helpers ──

    public function recalculateCosts(): void
    {
        $exportCost = $this->parts()->where('direction', 'export')->sum('total_cost');
        $importCost = $this->parts()->where('direction', 'import')->sum('total_cost');
        $this->parts_cost = $exportCost - $importCost;
        $this->total_cost = $this->original_cost + $this->parts_cost;
        $this->save();
    }

    public static function generateCode(string $type = 'general'): string
    {
        $prefix = $type === self::TYPE_REPAIR ? 'SC' : 'CV';

        $last = static::where('type', $type)->orderByDesc('id')->value('code');
        $num = 1;
        if ($last && preg_match("/{$prefix}-(\d+)/", $last, $m)) {
            $num = (int) $m[1] + 1;
        }
        return $prefix . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
