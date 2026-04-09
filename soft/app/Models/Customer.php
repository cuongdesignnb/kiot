<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name', 
        'group_id',
        'email',
        'phone',
        'birthday',
        'gender',
        'tax_code',
        'website',
        'status',
        'total_spend',
        'total_debt',
        'total_orders',
        'customer_type',
        'person_in_charge',
        'tags',
        'note',
        'linked_supplier_id'
    ];

    protected $casts = [
        'birthday' => 'date',
        'total_spend' => 'decimal:2',
        'total_orders' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $attributes = [
        'status' => 'active',
        'total_spend' => 0.00,
        'total_orders' => 0,
        'customer_type' => 'Bán lẻ',
        'person_in_charge' => 'Cao Đức Bình'
    ];

    // Relationships
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'group_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(CustomerDebt::class);
    }

    // ====== PARTNER LINKING ======

    /**
     * NCC liên kết (đối tác vừa là KH vừa là NCC)
     */
    public function linkedSupplier()
    {
        return $this->belongsTo(Supplier::class, 'linked_supplier_id');
    }

    /**
     * Kiểm tra có phải đối tác 2 vai trò không
     */
    public function isLinkedPartner(): bool
    {
        return !is_null($this->linked_supplier_id);
    }

    /**
     * Liên kết với NCC (đồng bộ 2 chiều)
     */
    public function linkToSupplier(int $supplierId): void
    {
        $this->update(['linked_supplier_id' => $supplierId]);
        Supplier::where('id', $supplierId)->update(['linked_customer_id' => $this->id]);
    }

    /**
     * Hủy liên kết (đồng bộ 2 chiều)
     */
    public function unlinkSupplier(): void
    {
        if ($this->linked_supplier_id) {
            Supplier::where('id', $this->linked_supplier_id)->update(['linked_customer_id' => null]);
        }
        $this->update(['linked_supplier_id' => null]);
    }

    /**
     * Lấy tổng hợp công nợ 2 chiều
     */
    public function getPartnerDebtSummary(): ?array
    {
        if (!$this->isLinkedPartner()) return null;

        $supplier = $this->linkedSupplier;
        if (!$supplier) return null;

        $receivable = abs($this->total_debt ?? 0);  // KH nợ mình (luôn dương)
        $payable = abs($supplier->total_debt ?? 0);  // Mình nợ NCC (luôn dương)
        $netDebt = $receivable - $payable;

        return [
            'customer_id' => $this->id,
            'supplier_id' => $supplier->id,
            'customer_name' => $this->name,
            'supplier_name' => $supplier->name,
            'receivable' => $receivable,   // KH nợ mình (phải thu)
            'payable' => $payable,         // Mình nợ NCC (phải trả)
            'net_debt' => $netDebt,        // >0: họ nợ mình, <0: mình nợ họ
            'net_label' => $netDebt > 0 ? 'Họ nợ mình' : ($netDebt < 0 ? 'Mình nợ họ' : 'Cân bằng'),
        ];
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function scopeByGroup($query, $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('code', 'like', "%{$search}%")
              ->orWhere('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('phone', 'like', "%{$search}%")
              ->orWhere('tax_code', 'like', "%{$search}%");
        });
    }

    // Accessors & Mutators
    public function getFormattedTotalSpendAttribute(): string
    {
        return number_format($this->total_spend, 0, ',', '.');
    }

    public function getStatusTextAttribute(): string
    {
        return $this->status === 'active' ? 'Đang giao dịch' : 'Ngừng giao dịch';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status === 'active' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800';
    }

    public function getCustomerTypeColorAttribute(): string
    {
        return match($this->customer_type) {
            'Bán lẻ' => 'bg-blue-100 text-blue-800',
            'Bán buôn' => 'bg-green-100 text-green-800',
            'VIP' => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-800'
        };
    }

    // Methods
    public static function generateCode(): string
    {
        $lastCustomer = self::orderBy('id', 'desc')->first();
        $lastNumber = $lastCustomer ? (int)substr($lastCustomer->code, 4) : 1796;
        $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);
        
        return 'CUZN' . $newNumber;
    }

    public function updateTotals(): void
    {
        $this->total_orders = $this->orders()->count();
        $this->total_spend = $this->orders()->sum('total');
        $this->save();
    }

    public function getCurrentDebt(): float
    {
        return $this->total_debt ?? 0;
    }

    public function getLastOrderDate(): ?string
    {
        $lastOrder = $this->orders()->latest('created_at')->first();
        return $lastOrder ? $lastOrder->created_at->format('d/m/Y') : null;
    }

    public function cashReceipts()
{
    return $this->hasMany(CashReceipt::class, 'recipient_id')->where('recipient_type', 'customer');
}

public function cashPayments()
{
    return $this->hasMany(CashPayment::class, 'recipient_id')->where('recipient_type', 'customer');
}

    // Boot method for auto-generating code
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($customer) {
            if (empty($customer->code)) {
                $customer->code = self::generateCode();
            }
        });
    }
}