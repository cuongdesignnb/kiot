<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MediaVariant extends Model
{
    protected $fillable = [
        'media_id',
        'variant',
        'disk',
        'path',
        'mime_type',
        'width',
        'height',
        'size',
        'checksum',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function publicUrl(): string
    {
        $url = Storage::disk($this->disk)->url($this->path);
        $absolute = str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);

        return preg_replace('/^http:\/\//i', 'https://', $absolute) ?? $absolute;
    }
}
