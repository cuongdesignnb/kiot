<?php

namespace App\Services\ProductImages;

use App\Models\Media;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Media\MediaAssetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductImageService
{
    public function __construct(private readonly MediaAssetService $mediaAssets) {}

    /** @param list<UploadedFile> $files */
    public function uploadMany(Product $product, array $files, ?int $primaryIndex, ?User $actor): array
    {
        $media = $this->mediaAssets->uploadMany($files, 'products', $actor);

        return $this->attachMedia($product, collect($media)->pluck('id')->all(), $primaryIndex, $actor);
    }

    /** @param list<int> $mediaIds */
    public function attachMedia(Product $product, array $mediaIds, ?int $primaryIndex, ?User $actor): array
    {
        if ($primaryIndex !== null && ! array_key_exists($primaryIndex, $mediaIds)) {
            throw ValidationException::withMessages(['primary_index' => 'Ảnh đại diện không thuộc danh sách đã chọn.']);
        }

        return DB::transaction(function () use ($product, $mediaIds, $primaryIndex, $actor): array {
            Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $ids = array_values(array_unique(array_map('intval', $mediaIds)));
            $media = Media::query()->with('variants')->whereIn('id', $ids)->where('status', 'active')->get()->keyBy('id');
            if ($media->count() !== count($ids)) {
                throw ValidationException::withMessages(['media_ids' => 'Một hoặc nhiều ảnh không còn tồn tại hoặc đã bị khóa.']);
            }

            $maxCount = max(1, (int) config('integrations.pc_website.product_images.max_count', 12));
            $attached = $product->images()->with('media')->get();
            $newIds = collect($ids)->reject(fn (int $id) => $attached->contains('media_id', $id));
            if ($attached->count() + $newIds->count() > $maxCount) {
                throw ValidationException::withMessages(['media_ids' => "Mỗi sản phẩm được dùng tối đa {$maxCount} ảnh."]);
            }

            $nextOrder = (int) $attached->max('sort_order') + 1;
            $hasPrimary = $attached->contains('is_primary', true);
            foreach ($ids as $index => $mediaId) {
                $asset = $media->get($mediaId);
                $existing = $attached->firstWhere('media_id', $mediaId);
                $makePrimary = $primaryIndex === $index || (! $hasPrimary && $primaryIndex === null && $index === 0);

                if ($existing) {
                    if ($makePrimary) {
                        $this->makePrimaryLocked($product, $existing, $actor);
                        $hasPrimary = true;
                    }

                    continue;
                }

                if ($makePrimary) {
                    $this->clearPrimaryLocked($product);
                    $hasPrimary = true;
                }

                $product->images()->create([
                    'media_id' => $asset->id,
                    'storage_disk' => $asset->disk,
                    'storage_path' => $asset->path,
                    'original_filename' => $asset->original_name,
                    'mime_type' => 'image/webp',
                    'width' => (int) $asset->width,
                    'height' => (int) $asset->height,
                    'file_size' => (int) $asset->size,
                    'checksum' => $asset->checksum,
                    'sort_order' => $nextOrder++,
                    'is_primary' => $makePrimary,
                    'primary_product_id' => $makePrimary ? $product->id : null,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);
            }

            $this->syncLegacyImageAndTouch($product);

            return $product->images()->with('media.variants')->get()->map->integrationPayload()->all();
        });
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

            return $product->images()->with('media.variants')->get()->map->integrationPayload()->all();
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

            return $locked->fresh(['media.variants'])->integrationPayload();
        });
    }

    public function delete(Product $product, ProductImage $image, ?User $actor): void
    {
        $this->assertOwned($product, $image);

        DB::transaction(function () use ($product, $image, $actor) {
            Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked = $product->images()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            $wasPrimary = $locked->is_primary;
            $legacyDisk = $locked->storage_disk;
            $legacyPath = $locked->storage_path;
            $isLegacy = ! $locked->media_id;

            $locked->update([
                'is_primary' => false,
                'primary_product_id' => null,
                'updated_by' => $actor?->id,
            ]);
            if ($locked->media_id) {
                // Removing a gallery item is also an unlink from the shared
                // library. Keep the soft-deleted gallery row for history,
                // but release the FK so the asset can be deleted when no
                // other object uses it.
                $locked->update(['media_id' => null]);
            }
            $locked->delete();

            if ($wasPrimary) {
                $next = $product->images()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->first();
                if ($next) {
                    $this->makePrimaryLocked($product, $next, $actor);
                }
            }
            $this->syncLegacyImageAndTouch($product);

            // A legacy row owns its old file. A media-linked row only removes
            // the product relation because the asset may be shared elsewhere.
            if ($isLegacy) {
                \Illuminate\Support\Facades\Storage::disk($legacyDisk)->delete($legacyPath);
            }
        });
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
        $primary = $product->images()->with('media')->where('is_primary', true)->first();
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
