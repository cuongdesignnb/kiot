<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskCategory extends Model
{
    protected $fillable = [
        'name',
        'type',
        'description',
        'color',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tasks()
    {
        return $this->hasMany(Task::class, 'category_id');
    }
}
