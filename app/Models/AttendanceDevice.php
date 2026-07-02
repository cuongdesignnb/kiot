<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'device_id',
        'model',
        'serial_number',
        'ip_address',
        'tcp_port',
        'comm_key',
        'status',
        'notes',
        'last_sync_at',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    public function logs()
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
