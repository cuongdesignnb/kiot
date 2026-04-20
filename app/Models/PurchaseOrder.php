<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_DRAFT = 'draft';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_PARTIAL = 'partial';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_FINISHED = 'finished';

    protected $fillable = [
        'code',
        'branch_id',
        'supplier_id',
        'status',
        'total_amount',
        'discount',
        'import_fee',
        'other_import_fee',
        'total_payment',
        'supplier_deposit',
        'expected_date',
        'note',
        'created_by_name',
        'ordered_by_name'
    ];

    protected $casts = [
        'expected_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Customer::class, 'supplier_id');
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }
}
