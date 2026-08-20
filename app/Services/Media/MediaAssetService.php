<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MediaAssetService
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const VARIANTS = [
        'thumb' => 160,
        'small' => 480,
        'medium' => 1280,
        'original' => null,
    ];

    /** @param list<UploadedFile> $files */
    public function uploadMany(array $files, string $collection = 'default', ?User $actor = null): array
    {
        $prepared = array_map(fn (UploadedFile $file) => $this->prepare($file), $files);

        return array_map(
            fn (array $image) => $this->persistPrepared($image, $this->normalizeCollection($collection), $actor),
            $prepared,
        );
    }

    /**
     * Register an existing internal storage file without moving or deleting
     * the legacy file. The generated WebP variants are new library files and
     * the caller remains responsible for linking the returned asset.
     */
    public function registerStoredFile(string $disk, string $path, string $originalName, string $collection = 'legacy', ?User $actor = null): ?Media
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return null;
        }

        $contents = $storage->get($path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return $this->persistPrepared(
            $this->prepareContents($contents, $originalName),
            $this->normalizeCollection($collection),
            $actor,
        );
    }

    public function payload(Media $media, int $usageCount = 0): array
    {
        $media->loadMissing('variants');
        $payload = $media->payload();
        $payload['usage_count'] = $usageCount;

        return $payload;
    }

    /**
     * Build a WebP-only representation without retaining the uploaded source.
     *
     * @return array{original_name:string,width:int,height:int,size:int,checksum:string,variants:array<string,array{contents:string,width:int,height:int,size:int,checksum:string}>}
     */
    private function prepare(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages(['file' => 'Chỉ chấp nhận JPG, JPEG, PNG hoặc WebP hợp lệ.']);
        }

        $source = file_get_contents($file->getRealPath());
        if ($source === false) {
            throw ValidationException::withMessages(['file' => 'Không thể đọc file ảnh đã tải lên.']);
        }

        return $this->prepareContents($source, basename($file->getClientOriginalName()), $mime);
    }

    private function prepareContents(string $source, string $originalName, ?string $declaredMime = null): array
    {
        $dimensions = @getimagesizefromstring($source);
        if ($source === false || $dimensions === false || ! in_array($dimensions['mime'] ?? '', self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages(['file' => 'Nội dung file không phải ảnh hợp lệ.']);
        }

        if ($declaredMime !== null && ! in_array($declaredMime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages(['file' => 'Chỉ chấp nhận JPG, JPEG, PNG hoặc WebP hợp lệ.']);
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $maxPixels = max(1, (int) config('integrations.pc_website.product_images.max_pixels', 40_000_000));
        if ($width * $height > $maxPixels) {
            throw ValidationException::withMessages(['file' => 'Kích thước điểm ảnh vượt quá giới hạn cấu hình.']);
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['file' => 'Máy chủ chưa hỗ trợ chuyển đổi ảnh WebP.']);
        }

        $resource = @imagecreatefromstring($source);
        if ($resource === false) {
            throw ValidationException::withMessages(['file' => 'Không thể giải mã ảnh đã tải lên.']);
        }

        $variants = [];
        try {
            foreach (self::VARIANTS as $name => $maxDimension) {
                $canvas = $this->resize($resource, $width, $height, $maxDimension);
                try {
                    $contents = $this->encodeWebp($canvas);
                } finally {
                    if ($canvas !== $resource) {
                        imagedestroy($canvas);
                    }
                }

                $variantWidth = $maxDimension === null ? $width : min($width, $maxDimension);
                $variantHeight = $maxDimension === null ? $height : min($height, max(1, (int) round($height * ($variantWidth / $width))));
                $variants[$name] = [
                    'contents' => $contents,
                    'width' => $variantWidth,
                    'height' => $variantHeight,
                    'size' => strlen($contents),
                    'checksum' => hash('sha256', $contents),
                ];
            }
        } finally {
            imagedestroy($resource);
        }

        $original = $variants['original'];

        return [
            'original_name' => mb_substr(basename($originalName), 0, 255),
            'width' => $width,
            'height' => $height,
            'size' => $original['size'],
            'checksum' => $original['checksum'],
            'variants' => $variants,
        ];
    }

    /** @param resource $source */
    private function resize($source, int $width, int $height, ?int $maxDimension)
    {
        if ($maxDimension === null || max($width, $height) <= $maxDimension) {
            return $source;
        }

        $scale = $maxDimension / max($width, $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    /** @param resource $resource */
    private function encodeWebp($resource): string
    {
        imagealphablending($resource, true);
        imagesavealpha($resource, true);
        ob_start();
        $encoded = imagewebp($resource, null, max(1, min(100, (int) config('integrations.pc_website.product_images.webp_quality', 82))));
        $contents = ob_get_clean();
        if (! $encoded || ! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['file' => 'Không thể tối ưu ảnh sang WebP.']);
        }

        return $contents;
    }

    private function persistPrepared(array $image, string $collection, ?User $actor): Media
    {
        $lock = Cache::lock('media:checksum:'.$image['checksum'], 30);

        return $lock->block(10, function () use ($image, $collection, $actor): Media {
            $existing = Media::query()->where('checksum', $image['checksum'])->first();
            if ($existing) {
                return $existing->load('variants');
            }

            $disk = (string) config('integrations.pc_website.product_images.disk', 'public');
            $directory = 'media/'.$collection.'/'.Str::uuid();
            $paths = [];

            try {
                foreach ($image['variants'] as $name => $variant) {
                    $path = $directory.'/'.$name.'.webp';
                    if (! Storage::disk($disk)->put($path, $variant['contents'], ['visibility' => 'public'])) {
                        throw new RuntimeException('Không thể lưu ảnh WebP.');
                    }
                    $paths[] = [$disk, $path];
                }

                return DB::transaction(function () use ($image, $collection, $actor, $disk, $directory) {
                    $original = $image['variants']['original'];
                    $media = Media::create([
                        'filename' => basename($directory).'.webp',
                        'original_name' => $image['original_name'],
                        'mime_type' => 'image/webp',
                        'size' => $original['size'],
                        'disk' => $disk,
                        'path' => $directory.'/original.webp',
                        'url' => Storage::disk($disk)->url($directory.'/original.webp'),
                        'collection' => $collection,
                        'checksum' => $original['checksum'],
                        'width' => $image['width'],
                        'height' => $image['height'],
                        'status' => 'active',
                        'uploaded_by' => $actor?->id,
                    ]);

                    foreach ($image['variants'] as $name => $variant) {
                        MediaVariant::create([
                            'media_id' => $media->id,
                            'variant' => $name,
                            'disk' => $disk,
                            'path' => $directory.'/'.$name.'.webp',
                            'mime_type' => 'image/webp',
                            'width' => $variant['width'],
                            'height' => $variant['height'],
                            'size' => $variant['size'],
                            'checksum' => $variant['checksum'],
                        ]);
                    }

                    return $media->load('variants');
                });
            } catch (Throwable $exception) {
                foreach ($paths as [$storedDisk, $path]) {
                    Storage::disk($storedDisk)->delete($path);
                }
                throw $exception;
            }
        });
    }

    private function normalizeCollection(string $collection): string
    {
        $collection = preg_replace('/[^a-z0-9_-]+/i', '-', trim($collection)) ?: 'default';

        return mb_substr($collection, 0, 50);
    }
}
