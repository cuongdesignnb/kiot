<?php

namespace App\Models;

/**
 * @deprecated Use App\Models\Task instead.
 * Kept for backward compatibility — extends Task with repair scope by default.
 */
class DeviceRepair extends Task
{
    protected static function booted(): void
    {
        static::addGlobalScope('repair', function ($query) {
            $query->where('type', Task::TYPE_REPAIR);
        });

        static::creating(function ($model) {
            $model->type = Task::TYPE_REPAIR;
        });
    }
}
