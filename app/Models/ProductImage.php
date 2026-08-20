<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'media_id',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'width',
        'height',
        'file_size',
        'checksum',
        'sort_order',
        'is_primary',
        'primary_product_id',
        'created_by',
        'updated_by',
    ];

    protected $hidden = ['storage_disk', 'storage_path', 'primary_product_id'];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'sort_order' => 'integer',
        'is_primary' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function publicUrl(): string
    {
        if ($this->media) {
            return $this->media->publicUrl();
        }

        $url = Storage::disk($this->storage_disk)->url($this->storage_path);
        $absolute = str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);

        return preg_replace('/^http:\/\//i', 'https://', $absolute) ?? $absolute;
    }

    public function integrationPayload(): array
    {
        return [
            'id' => $this->id,
            'media_id' => $this->media_id,
            'url' => $this->publicUrl(),
            'checksum' => $this->checksum,
            'width' => (int) $this->width,
            'height' => (int) $this->height,
            'sort_order' => (int) $this->sort_order,
            'is_primary' => (bool) $this->is_primary,
            'variants' => $this->media?->payload()['variants'] ?? [],
        ];
    }
}
