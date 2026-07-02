<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskAssignment extends Model
{
    protected $fillable = [
        'task_id',
        'employee_id',
        'assigned_by',
        'status',
        'assigned_at',
        'responded_at',
        'notes',
    ];

    protected $casts = [
        'assigned_at'  => 'datetime',
        'responded_at' => 'datetime',
    ];

    const STATUS_PENDING  = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';

    const STATUS_MAP = [
        self::STATUS_PENDING  => 'Chờ xác nhận',
        self::STATUS_ACCEPTED => 'Đã nhận',
        self::STATUS_REJECTED => 'Từ chối',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
