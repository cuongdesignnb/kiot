<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReturn extends Model
{
    protected $table = 'returns';

    protected $guarded = ['id'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'sales_attribution_updated_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function receivedByEmployee()
    {
        return $this->belongsTo(Employee::class, 'received_by_employee_id');
    }

    public function salesAttributionEmployee()
    {
        return $this->belongsTo(Employee::class, 'sales_attribution_employee_id');
    }

    public function salesAttributionUpdatedBy()
    {
        return $this->belongsTo(User::class, 'sales_attribution_updated_by');
    }

    public function items()
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }
}
