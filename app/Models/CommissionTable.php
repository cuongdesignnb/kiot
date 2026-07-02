<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommissionTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function tiers()
    {
        return $this->hasMany(CommissionTableTier::class)->orderBy('sort_order');
    }
}
