<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskPart extends Model
{
    protected $table = 'task_parts';

    protected $fillable = [
        'task_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'exported_by',
        'notes',
        'direction',
    ];

    protected $casts = [
        'unit_cost'  => 'decimal:0',
        'total_cost' => 'decimal:0',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function exportedByUser()
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
}
