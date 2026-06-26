<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function index(Request $request)
    {
        $query = Media::with('folder:id,name,path')->latest();

        if ($request->filled('collection')) {
            $query->where('collection', (string) $request->input('collection'));
        }

        if ($request->has('folder_id')) {
            $folderId = $request->input('folder_id');
            $folderId === '' || $folderId === null
                ? $query->whereNull('folder_id')
                : $query->where('folder_id', $folderId);
        }

        if ($request->filled('type')) {
            $query->where('mime_type', 'like', (string) $request->input('type') . '/%');
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('original_name', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('alt_text', 'like', "%{$search}%");
            });
        }

        $items = $query->paginate(min((int) $request->input('per_page', 30), 100));

        $items->getCollection()->transform(fn (Media $media) => $this->payload($media));

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'collection' => ['nullable', 'string', 'max:50'],
            'folder_id' => ['nullable', 'integer', 'exists:media_folders,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $validated['file'];
        $collection = $validated['collection'] ?? 'default';
        $folder = $this->resolveFolder($collection, $validated['folder_id'] ?? null, $request);
        $folderPath = trim($folder?->path ?: $collection, '/');
        $uuid = (string) Str::uuid();
        $originalExtension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $originalPath = "media/original/{$folderPath}/{$uuid}.{$originalExtension}";
        $webpPath = "media/{$folderPath}/{$uuid}.webp";

        Storage::disk('public')->putFileAs("media/original/{$folderPath}", $file, "{$uuid}.{$originalExtension}");
        $webpBinary = $this->convertToWebp($file);
        Storage::disk('public')->put($webpPath, $webpBinary);

        [$width, $height] = getimagesizefromstring($webpBinary) ?: [null, null];
        $media = Media::create([
            'folder_id' => $folder?->id,
            'filename' => "{$uuid}.webp",
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'size' => strlen($webpBinary),
            'width' => $width,
            'height' => $height,
            'disk' => 'public',
            'path' => $webpPath,
            'url' => Storage::disk('public')->url($webpPath),
            'original_path' => $originalPath,
            'original_url' => Storage::disk('public')->url($originalPath),
            'webp_path' => $webpPath,
            'webp_url' => Storage::disk('public')->url($webpPath),
            'collection' => $collection,
            'title' => $validated['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'alt_text' => $validated['alt_text'] ?? null,
            'caption' => $validated['caption'] ?? null,
            'hash' => hash('sha256', $webpBinary),
            'uploaded_by' => $request->user()?->id,
        ]);

        ActivityLog::log('media_upload', "Tải ảnh {$media->original_name} lên thư viện media", $media, [
            'collection' => $collection,
            'folder_id' => $folder?->id,
            'url' => $media->webp_url,
        ]);

        return response()->json($this->payload($media->load('folder:id,name,path')), 201);
    }

    public function update(Request $request, Media $media)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'folder_id' => ['nullable', 'integer', 'exists:media_folders,id'],
        ]);

        $oldFolderId = $media->folder_id;
        if (
            array_key_exists('folder_id', $validated)
            && $oldFolderId !== ($validated['folder_id'] ?? null)
            && !$request->user()?->hasPermission('media.move')
        ) {
            return response()->json(['message' => 'Bạn không có quyền di chuyển media.'], 403);
        }

        $media->fill($validated);

        if (array_key_exists('folder_id', $validated) && $oldFolderId !== ($validated['folder_id'] ?? null)) {
            ActivityLog::log('media_move', "Chuyển ảnh {$media->original_name} sang thư mục khác", $media, [
                'old_folder_id' => $oldFolderId,
                'new_folder_id' => $validated['folder_id'] ?? null,
            ]);
        }

        $media->save();

        ActivityLog::log('media_update', "Cập nhật thông tin ảnh {$media->original_name}", $media);

        return response()->json($this->payload($media->load('folder:id,name,path')));
    }

    public function destroy(Media $media)
    {
        if ($this->isMediaInUse($media)) {
            return response()->json(['message' => 'Ảnh đang được sử dụng, không thể xóa.'], 422);
        }

        $media->delete();

        ActivityLog::log('media_delete', "Xóa ảnh {$media->original_name} khỏi thư viện media", $media);

        return response()->json(['message' => 'Đã xóa ảnh.']);
    }

    private function resolveFolder(string $collection, ?int $folderId, Request $request): ?MediaFolder
    {
        if ($folderId) {
            return MediaFolder::find($folderId);
        }

        $name = match ($collection) {
            'products' => 'Sản phẩm',
            'employees' => 'Nhân viên',
            default => 'Chung',
        };
        $slug = Str::slug($name) ?: $collection;

        return MediaFolder::firstOrCreate(
            ['parent_id' => null, 'slug' => $slug],
            [
                'name' => $name,
                'path' => $slug,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]
        );
    }

    private function convertToWebp(UploadedFile $file): string
    {
        $bytes = file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($bytes);

        if (!$image) {
            abort(422, 'Ảnh không hợp lệ');
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, 85);
        $webp = ob_get_clean();
        imagedestroy($image);

        if (!$webp) {
            abort(422, 'Không thể chuyển ảnh sang WebP');
        }

        return $webp;
    }

    private function isMediaInUse(Media $media): bool
    {
        $values = array_values(array_filter([
            $media->url,
            $media->path,
            $media->webp_url,
            $media->webp_path,
            $media->original_url,
            $media->original_path,
        ]));

        if (!$values) {
            return false;
        }

        return Product::whereIn('image', $values)->exists()
            || Employee::whereIn('avatar', $values)->exists()
            || ProductVariant::whereIn('image', $values)->exists();
    }

    private function payload(Media $media): array
    {
        return [
            'id' => $media->id,
            'folder_id' => $media->folder_id,
            'folder' => $media->folder,
            'filename' => $media->filename,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'extension' => $media->extension,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'disk' => $media->disk,
            'path' => $media->path,
            'url' => $media->publicUrl(),
            'original_path' => $media->original_path,
            'original_url' => $media->original_url,
            'webp_path' => $media->webp_path,
            'webp_url' => $media->webp_url,
            'collection' => $media->collection,
            'title' => $media->title,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'created_at' => $media->created_at,
        ];
    }
}
