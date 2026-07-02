<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lưu trữ lịch sử sync từ AttendanceBridge agent (C# app)
 */
class AttendanceAgentSyncLog extends Model
{
    use HasFactory;

    protected $table = 'attendance_agent_sync_logs';

    protected $fillable = [
        'device_id',
        'app_version',
        'sync_type',
        'started_at',
        'finished_at',
        'result',
        'counts',
        'errors',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'counts' => 'array',
        'errors' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeForDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeOfType($query, string $syncType)
    {
        return $query->where('sync_type', $syncType);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('result', 'ok');
    }

    public function scopeFailed($query)
    {
        return $query->where('result', 'failed');
    }

    public function getTotalFetchedAttribute(): int
    {
        return $this->counts['fetched'] ?? 0;
    }

    public function getTotalCreatedAttribute(): int
    {
        return $this->counts['created'] ?? 0;
    }

    public function getTotalUpdatedAttribute(): int
    {
        return $this->counts['updated'] ?? 0;
    }

    public function getTotalSkippedAttribute(): int
    {
        return $this->counts['skipped'] ?? 0;
    }

    public function getTotalFailedAttribute(): int
    {
        return $this->counts['failed'] ?? 0;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getDurationSecondsAttribute(): ?int
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }
        return $this->finished_at->diffInSeconds($this->started_at);
    }
}
