<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Quản lý phiên bản AttendanceBridge app cho auto-update
 */
class AttendanceBridgeVersion extends Model
{
    use HasFactory;

    protected $table = 'attendance_bridge_versions';

    protected $fillable = [
        'version',
        'channel',
        'mandatory',
        'min_supported',
        'released_at',
        'notes',
        'download_url',
        'sha256',
        'size_bytes',
        'is_active',
    ];

    protected $casts = [
        'mandatory' => 'boolean',
        'released_at' => 'datetime',
        'size_bytes' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeForChannel($query, string $channel = 'stable')
    {
        return $query->where('channel', $channel);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function getLatest(string $channel = 'stable'): ?self
    {
        return static::query()
            ->forChannel($channel)
            ->active()
            ->orderByDesc('released_at')
            ->first();
    }

    public static function compareVersions(string $v1, string $v2): int
    {
        return version_compare($v1, $v2);
    }

    public function needsUpdate(string $currentVersion): bool
    {
        return static::compareVersions($this->version, $currentVersion) > 0;
    }

    public function isSupported(string $currentVersion): bool
    {
        if (empty($this->min_supported)) {
            return true;
        }
        return static::compareVersions($currentVersion, $this->min_supported) >= 0;
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' bytes';
    }
}
