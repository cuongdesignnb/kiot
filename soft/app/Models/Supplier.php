<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'group_id',
        'email',
        'phone',
        'address',
        'tax_code',
        'website',
        'person_in_charge',
        'bank_account',
        'bank_name',
        'status',
        'total_debt',
        'credit_limit',
        'payment_terms',
        'tags',
        'note',
        'linked_customer_id',
    ];

    protected $casts = [
        'total_debt' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'payment_terms' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the supplier group
     */
    public function group()
    {
        return $this->belongsTo(SupplierGroup::class, 'group_id');
    }

    /**
     * Get supplier contacts
     */
    public function contacts()
    {
        return $this->hasMany(SupplierContact::class);
    }

    /**
     * Get primary contact
     */
    public function primaryContact()
    {
        return $this->hasOne(SupplierContact::class)->where('is_primary', true);
    }

    /**
     * Get supplier debts
     */
    public function debts()
    {
        return $this->hasMany(SupplierDebt::class);
    }

    /**
     * Get products from this supplier
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get purchase orders from this supplier
     */
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Get purchase return orders to this supplier
     */
    public function purchaseReturnOrders()
    {
        return $this->hasMany(PurchaseReturnOrder::class);
    }

    /**
     * Get invoices from this supplier
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Scope to search suppliers
     */
    public function scopeSearch(Builder $query, string $keyword = null): Builder
    {
        if (empty($keyword)) {
            return $query;
        }

        return $query->where(function ($q) use ($keyword) {
            $q->where('code', 'like', "%{$keyword}%")
              ->orWhere('name', 'like', "%{$keyword}%")
              ->orWhere('email', 'like', "%{$keyword}%")
              ->orWhere('phone', 'like', "%{$keyword}%")
              ->orWhere('tax_code', 'like', "%{$keyword}%")
              ->orWhere('person_in_charge', 'like', "%{$keyword}%");
        });
    }

    /**
     * Scope to filter by status
     */
    public function scopeStatus(Builder $query, string $status = null): Builder
    {
        if (empty($status)) {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * Scope to filter by group
     */
    public function scopeGroup(Builder $query, $groupId = null): Builder
    {
        if (empty($groupId)) {
            return $query;
        }

        return $query->where('group_id', $groupId);
    }

    /**
     * Generate next supplier code
     */
    public static function generateCode(): string
    {
        $lastSupplier = self::orderBy('id', 'desc')->first();
        $nextNumber = $lastSupplier ? ($lastSupplier->id + 1) : 1;
        
        return 'SUPN' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Update total debt from debt records
     */
    public function updateTotalDebt()
    {
        $this->total_debt = $this->debts()->sum('amount');
        $this->save();
    }

    /**
     * Add debt record
     */
    public function addDebt($amount, $type = 'purchase', $refCode = null, $purchaseOrderId = null, $note = null)
    {
        return SupplierDebt::createDebtRecord(
            $this->id, 
            $amount, 
            $type, 
            $refCode, 
            $purchaseOrderId, 
            $note
        );
    }

    /**
     * Get formatted attributes
     */
    public function getFormattedPhoneAttribute(): ?string
    {
        if (empty($this->phone)) {
            return null;
        }

        $phone = preg_replace('/\D/', '', $this->phone);
        
        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            return substr($phone, 0, 4) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7);
        }
        
        return $this->phone;
    }

    public function getShortAddressAttribute(): ?string
    {
        if (empty($this->address)) {
            return null;
        }

        return strlen($this->address) > 50 
            ? substr($this->address, 0, 47) . '...' 
            : $this->address;
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'active' => 'success',
            'inactive' => 'secondary', 
            'suspended' => 'danger',
            default => 'secondary'
        };
    }

    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            'active' => 'Đang giao dịch',
            'inactive' => 'Tạm ngưng',
            'suspended' => 'Đình chỉ',
            default => 'Không xác định'
        };
    }

    /**
     * Check if supplier has related data
     */
    public function hasRelatedData(): bool
    {
        return $this->products()->exists() 
            || $this->purchaseOrders()->exists() 
            || $this->purchaseReturnOrders()->exists() 
            || $this->invoices()->exists()
            || $this->debts()->exists();
    }

    /**
     * Get supplier's full info
     */
    public function getFullInfoAttribute(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'group' => $this->group ? [
                'id' => $this->group->id,
                'name' => $this->group->name,
                'code' => $this->group->code,
                'type' => $this->group->type,
                'discount_percent' => $this->group->discount_percent,
                'payment_terms' => $this->group->payment_terms,
            ] : null,
            'email' => $this->email,
            'phone' => $this->phone,
            'formatted_phone' => $this->formatted_phone,
            'address' => $this->address,
            'short_address' => $this->short_address,
            'tax_code' => $this->tax_code,
            'website' => $this->website,
            'person_in_charge' => $this->person_in_charge,
            'bank_account' => $this->bank_account,
            'bank_name' => $this->bank_name,
            'status' => $this->status,
            'status_text' => $this->status_text,
            'status_color' => $this->status_color,
            'total_debt' => $this->total_debt,
            'credit_limit' => $this->credit_limit,
            'payment_terms' => $this->payment_terms,
            'tags' => $this->tags,
            'note' => $this->note,
            'primary_contact' => $this->primaryContact ? [
                'id' => $this->primaryContact->id,
                'name' => $this->primaryContact->name,
                'phone' => $this->primaryContact->phone,
                'email' => $this->primaryContact->email,
                'position' => $this->primaryContact->position,
                'department' => $this->primaryContact->department,
            ] : null,
            'contacts_count' => $this->contacts()->count(),
            'purchase_orders_count' => $this->purchaseOrders()->count(),
            'total_purchase_amount' => $this->purchaseOrders()->sum('total'),
            'debt_balance' => $this->total_debt,
            'credit_remaining' => max(0, $this->credit_limit - $this->total_debt),
            'is_over_credit_limit' => $this->total_debt > $this->credit_limit,
            'has_related_data' => $this->hasRelatedData(),
            'linked_customer_id' => $this->linked_customer_id,
            'partner_debt_summary' => $this->getPartnerDebtSummary(),
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at?->format('d/m/Y H:i'),
        ];
    }
    // Thêm vào class Supplier

public function cashReceipts()
{
    return $this->hasMany(CashReceipt::class, 'recipient_id')->where('recipient_type', 'supplier');
}

public function cashPayments()
{
    return $this->hasMany(CashPayment::class, 'recipient_id')->where('recipient_type', 'supplier');
}
public function purchaseReceipts()
{
    return $this->hasManyThrough(PurchaseReceipt::class, PurchaseOrder::class);
}

    // ====== PARTNER LINKING ======

    /**
     * KH liên kết (đối tác vừa là NCC vừa là KH)
     */
    public function linkedCustomer()
    {
        return $this->belongsTo(Customer::class, 'linked_customer_id');
    }

    /**
     * Kiểm tra có phải đối tác 2 vai trò không
     */
    public function isLinkedPartner(): bool
    {
        return !is_null($this->linked_customer_id);
    }

    /**
     * Liên kết với KH (đồng bộ 2 chiều)
     */
    public function linkToCustomer(int $customerId): void
    {
        $this->update(['linked_customer_id' => $customerId]);
        Customer::where('id', $customerId)->update(['linked_supplier_id' => $this->id]);
    }

    /**
     * Hủy liên kết (đồng bộ 2 chiều)
     */
    public function unlinkCustomer(): void
    {
        if ($this->linked_customer_id) {
            Customer::where('id', $this->linked_customer_id)->update(['linked_supplier_id' => null]);
        }
        $this->update(['linked_customer_id' => null]);
    }

    /**
     * Lấy tổng hợp công nợ 2 chiều
     */
    public function getPartnerDebtSummary(): ?array
    {
        if (!$this->isLinkedPartner()) return null;

        $customer = $this->linkedCustomer;
        if (!$customer) return null;

        $payable = abs($this->total_debt ?? 0);      // Mình nợ NCC (luôn dương)
        $receivable = abs($customer->total_debt ?? 0); // KH nợ mình (luôn dương)
        $netDebt = $receivable - $payable;

        return [
            'supplier_id' => $this->id,
            'customer_id' => $customer->id,
            'supplier_name' => $this->name,
            'customer_name' => $customer->name,
            'payable' => $payable,         // Mình nợ NCC
            'receivable' => $receivable,   // KH nợ mình
            'net_debt' => $netDebt,        // >0: họ nợ mình, <0: mình nợ họ
            'net_label' => $netDebt > 0 ? 'Họ nợ mình' : ($netDebt < 0 ? 'Mình nợ họ' : 'Cân bằng'),
        ];
    }

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($supplier) {
            if (empty($supplier->code)) {
                $supplier->code = self::generateCode();
            }
        });
    }
}