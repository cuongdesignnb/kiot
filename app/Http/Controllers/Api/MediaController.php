<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\Media\MediaAssetService;
use App\Services\Media\MediaUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    public function index(Request $request, MediaUsageService $usages, MediaAssetService $assets)
    {
        $this->assertCanUseMedia($request);
        $query = Media::latest();

        if ($request->filled('collection')) {
            $query->where('collection', $request->collection);
        }

        if ($request->filled('search')) {
            $query->where('original_name', 'like', '%'.$request->search.'%');
        }

        $usage = (string) $request->input('usage', '');
        $usageRelations = [
            'product' => 'productImages',
            'customer' => 'customers',
            'employee' => 'employees',
            'variant' => 'productVariants',
        ];
        if ($usage === 'used') {
            $query->where(function ($builder): void {
                $builder->whereHas('productImages')
                    ->orWhereHas('customers')
                    ->orWhereHas('employees')
                    ->orWhereHas('productVariants');
            });
        } elseif ($usage === 'unused') {
            foreach (array_values($usageRelations) as $relation) {
                $query->whereDoesntHave($relation);
            }
        } elseif (isset($usageRelations[$usage])) {
            $query->whereHas($usageRelations[$usage]);
        }

        $page = $query->paginate(min(100, max(1, (int) ($request->per_page ?? 40))));
        $page->getCollection()->transform(function (Media $media) use ($usages, $assets): array {
            return $assets->payload($media, $usages->count($media));
        });

        return response()->json($page);
    }

    public function store(Request $request, MediaAssetService $assets)
    {
        $this->assertCanUseMedia($request, true);
        $request->validate([
            'file' => 'nullable|file|max:5120',
            'files' => 'nullable|array|max:20',
            'files.*' => 'file|max:5120',
            'collection' => 'nullable|string|max:50',
        ]);

        $files = $request->file('files', []);
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }
        if ($files === []) {
            throw ValidationException::withMessages(['file' => 'Vui lòng chọn ít nhất một ảnh.']);
        }

        $media = $assets->uploadMany($files, (string) $request->input('collection', 'default'), $request->user());
        $payload = collect($media)->map(fn (Media $item) => $assets->payload($item))->values()->all();

        return response()->json([
            'success' => true,
            'media' => $payload,
            'data' => count($payload) === 1 ? $payload[0] : $payload,
        ], 201);
    }

    public function show(Request $request, Media $media, MediaUsageService $usages, MediaAssetService $assets): \Illuminate\Http\JsonResponse
    {
        $this->assertCanUseMedia($request);

        return response()->json($assets->payload($media, $usages->count($media)));
    }

    public function usages(Request $request, Media $media, MediaUsageService $usages): \Illuminate\Http\JsonResponse
    {
        $this->assertCanUseMedia($request);

        return response()->json([
            'media_id' => $media->id,
            'usages' => $usages->usages($media),
        ]);
    }

    public function destroy(Request $request, Media $media, MediaUsageService $usages): \Illuminate\Http\JsonResponse
    {
        if (! $request->user()?->hasPermission('settings.manage')) {
            abort(403, 'Chỉ quản trị viên mới được xóa ảnh khỏi thư viện.');
        }

        $currentUsages = $usages->usages($media);
        if ($currentUsages !== []) {
            return response()->json([
                'code' => 'MEDIA_IN_USE',
                'message' => 'Ảnh đang được sử dụng và chưa thể xóa.',
                'usages' => $currentUsages,
            ], 409);
        }

        $paths = $media->variants()->get(['disk', 'path'])->push((object) ['disk' => $media->disk, 'path' => $media->path]);
        foreach ($paths as $path) {
            Storage::disk($path->disk)->delete($path->path);
        }

        DB::transaction(function () use ($media): void {
            $media->variants()->delete();
            $media->forceDelete();
        });

        return response()->json(['success' => true, 'message' => 'Đã xóa ảnh khỏi thư viện.']);
    }

    private function assertCanUseMedia(Request $request, bool $forUpload = false): void
    {
        $user = $request->user();
        $viewPermissions = [
            'products.view', 'products.create', 'products.edit',
            'customers.view', 'customers.create', 'customers.edit',
            'employees.view', 'employees.create', 'employees.edit',
            'settings.view', 'settings.manage',
        ];
        $uploadPermissions = [
            'products.create', 'products.edit',
            'customers.create', 'customers.edit',
            'employees.create', 'employees.edit',
            'settings.manage',
        ];

        if (! $user || ! $user->hasAnyPermission($forUpload ? $uploadPermissions : $viewPermissions)) {
            abort($user ? 403 : 401, $user ? 'Bạn không có quyền sử dụng thư viện ảnh.' : 'Chưa đăng nhập.');
        }
    }
}
