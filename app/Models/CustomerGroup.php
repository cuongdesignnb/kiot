<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Step 24.4A — KiotViet-style Customer Group master data.
 *
 * In 24.4A: only stores config. Does NOT auto-assign customers.
 * In 24.4B: condition engine will use `conditions`, `update_mode`, `auto_update`.
 */
class CustomerGroup extends Model
{
    protected $fillable = [
        'code',
        'name',
        'discount_type',
        'discount_value',
        'note',
        'description',
        'conditions',
        'update_mode',
        'auto_update',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'conditions'     => 'array',
        'auto_update'    => 'boolean',
        'is_active'      => 'boolean',
        'sort_order'     => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * Customers currently assigned to this group (by string match).
     * After 24.4B migration to FK, this becomes a standard hasMany.
     */
    public function customers()
    {
        return $this->hasMany(Customer::class, 'customer_group', 'name');
    }
}
