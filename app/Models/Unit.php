<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['name', 'description'];

    // Count how many products use this unit as base_unit_name
    public function getProductsCountAttribute()
    {
        return \App\Models\ProductUnit::where('unit_name', $this->name)->count();
    }
}
