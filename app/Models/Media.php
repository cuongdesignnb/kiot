<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'filename',
        'original_name',
        'mime_type',
        'size',
        'disk',
        'path',
        'url',
        'collection',
        'checksum',
        'width',
        'height',
        'status',
        'uploaded_by',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'avatar_media_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'avatar_media_id');
    }

    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'image_media_id');
    }

    public function publicUrl(): string
    {
        $url = Storage::disk($this->disk)->url($this->path);
        $absolute = str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);

        return preg_replace('/^http:\/\//i', 'https://', $absolute) ?? $absolute;
    }

    public function payload(bool $includeVariants = true): array
    {
        $variants = [];
        if ($includeVariants) {
            foreach ($this->variants as $variant) {
                $variants[$variant->variant] = [
                    'url' => $variant->publicUrl(),
                    'width' => (int) $variant->width,
                    'height' => (int) $variant->height,
                    'size' => (int) $variant->size,
                ];
            }
        }

        return [
            'id' => $this->id,
            'url' => $this->publicUrl(),
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'width' => (int) $this->width,
            'height' => (int) $this->height,
            'collection' => $this->collection,
            'checksum' => $this->checksum,
            'usage_count' => 0,
            'variants' => $variants,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
