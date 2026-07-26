<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImages\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

class ProductImageController extends Controller
{
    public function store(Request $request, Product $product, ProductImageService $images): JsonResponse
    {
        $maxCount = max(1, (int) config('integrations.pc_website.product_images.max_count', 12));
        $maxSize = max(1, (int) config('integrations.pc_website.product_images.max_size_kb', 5120));
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.$maxCount],
            'images.*' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp'])->max($maxSize)],
            'primary_index' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json([
            'images' => $images->uploadMany($product, $validated['images'], $validated['primary_index'] ?? null, $request->user()),
        ], 201);
    }

    public function reorder(Request $request, Product $product, ProductImageService $images): JsonResponse
    {
        $validated = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return response()->json(['images' => $images->reorder($product, $validated['image_ids'], $request->user())]);
    }

    public function primary(Request $request, Product $product, ProductImage $productImage, ProductImageService $images): JsonResponse
    {
        return response()->json(['image' => $images->setPrimary($product, $productImage, $request->user())]);
    }

    public function destroy(Request $request, Product $product, ProductImage $productImage, ProductImageService $images): JsonResponse
    {
        $images->delete($product, $productImage, $request->user());

        return response()->json(['message' => 'Đã xóa ảnh sản phẩm.']);
    }
}
