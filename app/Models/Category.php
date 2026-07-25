<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'slug', 'is_active', 'show_on_pc_website', 'parent_id', 'description'];

    protected $attributes = [
        'is_active' => true,
        'show_on_pc_website' => false,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_on_pc_website' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            $category->code = $category->code ?: static::uniqueCode($category->name);
            $category->slug = $category->slug ?: Str::slug($category->name);
        });

        static::saving(function (Category $category) {
            $category->assertAcyclicParent();

            if ($category->isDirty('name') && $category->exists) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::saved(function (Category $category) {
            if ($category->wasChanged(['name', 'code', 'slug', 'is_active', 'show_on_pc_website', 'parent_id'])) {
                Product::withTrashed()->where('category_id', $category->id)->update(['updated_at' => now()]);
            }
        });

        static::deleted(function (Category $category) {
            Product::withTrashed()->where('category_id', $category->id)->update(['updated_at' => now()]);
        });
    }

    private static function uniqueCode(string $name): string
    {
        $base = Str::upper(mb_substr(Str::slug($name), 0, 90)) ?: 'CATEGORY';
        $candidate = $base;
        $suffix = 2;

        while (static::withTrashed()->where('code', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    private function assertAcyclicParent(): void
    {
        if (! $this->isDirty('parent_id') || $this->parent_id === null || ! $this->exists) {
            return;
        }

        $ancestorId = (int) $this->parent_id;
        $visited = [];
        while ($ancestorId > 0) {
            if ($ancestorId === (int) $this->id || isset($visited[$ancestorId])) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Nhóm hàng cha không được tạo vòng lặp danh mục.',
                ]);
            }

            $visited[$ancestorId] = true;
            $ancestorId = (int) (static::withTrashed()->whereKey($ancestorId)->value('parent_id') ?? 0);
        }
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
