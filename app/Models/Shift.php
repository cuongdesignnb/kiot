<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'start_time',
        'end_time',
        'checkin_start_time',
        'checkin_end_time',
        'allow_late_minutes',
        'allow_early_minutes',
        'rounding_minutes',
        'is_overnight',
        'status',
        'notes',
    ];

    protected $casts = [
        'is_overnight' => 'boolean',
        'allow_late_minutes' => 'integer',
        'allow_early_minutes' => 'integer',
        'rounding_minutes' => 'integer',
    ];

    // Accessor tự tính: thời lượng ca (phút)
    protected $appends = ['duration_minutes', 'work_time_text'];

    public function getDurationMinutesAttribute(): int
    {
        $startMin = $this->timeToMinutes($this->start_time);
        $endMin = $this->timeToMinutes($this->end_time);
        if ($startMin === null || $endMin === null)
            return 0;
        $diff = $endMin - $startMin;
        return $diff <= 0 ? $diff + 1440 : $diff;  // 1440 = 24*60 (xử lý ca đêm)
    }

    public function getWorkTimeTextAttribute(): string
    {
        // avoid trim null
        return trim($this->start_time ?? '') . ' - ' . trim($this->end_time ?? '');
    }

    private function timeToMinutes($time)
    {
        if (!$time)
            return null;
        $parts = explode(':', $time);
        if (count($parts) >= 2) {
            return (int) $parts[0] * 60 + (int) $parts[1];
        }
        return null;
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
