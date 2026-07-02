<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairPerformanceTier extends Model
{
    protected $fillable = [
        'min_percent',
        'max_percent',
        'salary_percent',
        'label',
        'sort_order',
    ];

    protected $casts = [
        'min_percent'    => 'integer',
        'max_percent'    => 'integer',
        'salary_percent' => 'integer',
        'sort_order'     => 'integer',
    ];

    /**
     * Tìm mốc đánh giá phù hợp cho tỷ lệ hoàn thành.
     */
    public static function getTierForPercent(float $percent): ?self
    {
        return static::where('min_percent', '<=', $percent)
            ->where('max_percent', '>=', $percent)
            ->orderBy('sort_order')
            ->first();
    }
}
