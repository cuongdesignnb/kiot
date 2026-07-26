<?php

namespace App\Services\ProductImages;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProductImageService
{
    /** @param list<UploadedFile> $files */
    public function uploadMany(Product $product, array $files, ?int $primaryIndex, ?User $actor): array
    {
        $prepared = array_map(fn (UploadedFile $file) => $this->prepare($file), $files);
        $newChecksums = collect($prepared)->pluck('checksum')->unique();
        if ($primaryIndex !== null && ! array_key_exists($primaryIndex, $files)) {
            throw ValidationException::withMessages(['primary_index' => 'Ảnh đại diện không thuộc danh sách tải lên.']);
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($product, $prepared, $newChecksums, $primaryIndex, $actor, &$storedPaths) {
                Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $existingChecksums = $product->images()
                    ->whereIn('checksum', $newChecksums)
                    ->pluck('checksum');
                $maxCount = max(1, (int) config('integrations.pc_website.product_images.max_count', 12));
                if ($product->images()->count() + $newChecksums->diff($existingChecksums)->count() > $maxCount) {
                    throw ValidationException::withMessages([
                        'images' => "Mỗi sản phẩm được tải tối đa {$maxCount} ảnh.",
                    ]);
                }

                $disk = (string) config('integrations.pc_website.product_images.disk', 'public');
                $nextOrder = (int) $product->images()->max('sort_order') + 1;
                $hasPrimary = $product->images()->where('is_primary', true)->exists();
                foreach ($prepared as $index => $image) {
                    $existing = $product->images()->where('checksum', $image['checksum'])->first();
                    $makePrimary = $primaryIndex === $index || (! $hasPrimary && $primaryIndex === null && $index === 0);

                    if ($existing) {
                        if ($makePrimary) {
                            $this->makePrimaryLocked($product, $existing, $actor);
                            $hasPrimary = true;
                        }

                        continue;
                    }

                    $path = 'products/'.$product->id.'/'.Str::uuid().'.webp';
                    if (! Storage::disk($disk)->put($path, $image['contents'], ['visibility' => 'public'])) {
                        throw new RuntimeException('Không thể lưu ảnh sản phẩm.');
                    }
                    $storedPaths[] = [$disk, $path];

                    if ($makePrimary) {
                        $this->clearPrimaryLocked($product);
                        $hasPrimary = true;
                    }

                    $product->images()->create([
                        'storage_disk' => $disk,
                        'storage_path' => $path,
                        'original_filename' => $image['original_filename'],
                        'mime_type' => 'image/webp',
                        'width' => $image['width'],
                        'height' => $image['height'],
                        'file_size' => strlen($image['contents']),
                        'checksum' => $image['checksum'],
                        'sort_order' => $nextOrder++,
                        'is_primary' => $makePrimary,
                        'primary_product_id' => $makePrimary ? $product->id : null,
                        'created_by' => $actor?->id,
                        'updated_by' => $actor?->id,
                    ]);
                }

                $this->syncLegacyImageAndTouch($product);

                return $product->images()->get()->map(fn (ProductImage $image) => $image->integrationPayload())->all();
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }
    }

    /** @param list<int> $imageIds */
    public function reorder(Product $product, array $imageIds, ?User $actor): array
    {
        return DB::transaction(function () use ($product, $imageIds, $actor) {
            Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $images = $product->images()->lockForUpdate()->get()->keyBy('id');
            if ($images->keys()->sort()->values()->all() !== collect($imageIds)->sort()->values()->all()) {
                throw ValidationException::withMessages(['image_ids' => 'Danh sách ảnh không khớp với sản phẩm.']);
            }

            foreach ($imageIds as $order => $imageId) {
                $images[$imageId]->update(['sort_order' => $order, 'updated_by' => $actor?->id]);
            }
            $this->syncLegacyImageAndTouch($product);

            return $product->images()->get()->map->integrationPayload()->all();
        });
    }

    public function setPrimary(Product $product, ProductImage $image, ?User $actor): array
    {
        $this->assertOwned($product, $image);

        return DB::transaction(function () use ($product, $image, $actor) {
            Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked = $product->images()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            $this->makePrimaryLocked($product, $locked, $actor);
            $this->syncLegacyImageAndTouch($product);

            return $locked->fresh()->integrationPayload();
        });
    }

    public function delete(Product $product, ProductImage $image, ?User $actor): void
    {
        $this->assertOwned($product, $image);
        $disk = $image->storage_disk;
        $path = $image->storage_path;
        $contents = Storage::disk($disk)->exists($path) ? Storage::disk($disk)->get($path) : null;

        if ($contents !== null && ! Storage::disk($disk)->delete($path)) {
            throw new RuntimeException('Không thể xóa file ảnh sản phẩm.');
        }

        try {
            DB::transaction(function () use ($product, $image, $actor) {
                Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $locked = $product->images()->whereKey($image->id)->lockForUpdate()->firstOrFail();
                $wasPrimary = $locked->is_primary;
                $locked->update([
                    'is_primary' => false,
                    'primary_product_id' => null,
                    'updated_by' => $actor?->id,
                ]);
                $locked->delete();

                if ($wasPrimary) {
                    $next = $product->images()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->first();
                    if ($next) {
                        $this->makePrimaryLocked($product, $next, $actor);
                    }
                }
                $this->syncLegacyImageAndTouch($product);
            });
        } catch (Throwable $exception) {
            if ($contents !== null) {
                Storage::disk($disk)->put($path, $contents, ['visibility' => 'public']);
            }
            throw $exception;
        }
    }

    private function prepare(UploadedFile $file): array
    {
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['images' => 'Chỉ chấp nhận JPG, JPEG, PNG hoặc WebP hợp lệ.']);
        }

        $source = file_get_contents($file->getRealPath());
        $dimensions = $source !== false ? @getimagesizefromstring($source) : false;
        if ($source === false || $dimensions === false || ! in_array($dimensions['mime'] ?? '', $allowed, true)) {
            throw ValidationException::withMessages(['images' => 'Nội dung file không phải ảnh hợp lệ.']);
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $maxPixels = max(1, (int) config('integrations.pc_website.product_images.max_pixels', 40000000));
        if ($width * $height > $maxPixels) {
            throw ValidationException::withMessages(['images' => 'Kích thước điểm ảnh vượt quá giới hạn cấu hình.']);
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['images' => 'Máy chủ chưa hỗ trợ chuyển đổi ảnh WebP.']);
        }

        $resource = @imagecreatefromstring($source);
        if ($resource === false) {
            throw ValidationException::withMessages(['images' => 'Không thể giải mã ảnh đã tải lên.']);
        }
        ob_start();
        $encoded = imagewebp($resource, null, max(1, min(100, (int) config('integrations.pc_website.product_images.webp_quality', 82))));
        $contents = ob_get_clean();
        imagedestroy($resource);
        if (! $encoded || ! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['images' => 'Không thể tối ưu ảnh sang WebP.']);
        }

        return [
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'width' => $width,
            'height' => $height,
            'contents' => $contents,
            'checksum' => hash('sha256', $contents),
        ];
    }

    private function makePrimaryLocked(Product $product, ProductImage $image, ?User $actor): void
    {
        $this->clearPrimaryLocked($product);
        $image->update([
            'is_primary' => true,
            'primary_product_id' => $product->id,
            'updated_by' => $actor?->id,
        ]);
    }

    private function clearPrimaryLocked(Product $product): void
    {
        $product->images()->where('is_primary', true)->update([
            'is_primary' => false,
            'primary_product_id' => null,
        ]);
    }

    private function syncLegacyImageAndTouch(Product $product): void
    {
        $primary = $product->images()->where('is_primary', true)->first();
        Product::withTrashed()->whereKey($product->id)->update([
            'image' => $primary?->publicUrl(),
            'updated_at' => now(),
        ]);
    }

    private function assertOwned(Product $product, ProductImage $image): void
    {
        if ((int) $image->product_id !== (int) $product->id) {
            abort(404);
        }
    }
}
