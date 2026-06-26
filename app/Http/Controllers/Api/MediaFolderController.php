<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MediaFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaFolderController extends Controller
{
    public function index(Request $request)
    {
        $query = MediaFolder::withCount(['media', 'children'])->orderBy('path');

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->input('parent_id'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:media_folders,id'],
        ]);

        $folder = $this->makeFolder($validated['name'], $validated['parent_id'] ?? null, $request);

        ActivityLog::log('media_folder_create', "Tạo thư mục media {$folder->name}", $folder);

        return response()->json($folder->loadCount(['media', 'children']), 201);
    }

    public function update(Request $request, MediaFolder $mediaFolder)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:media_folders,id'],
        ]);

        if (($validated['parent_id'] ?? null) === $mediaFolder->id) {
            throw ValidationException::withMessages(['parent_id' => 'Không thể chọn chính thư mục này làm thư mục cha.']);
        }

        $slug = Str::slug($validated['name']) ?: Str::random(8);
        $parentId = $validated['parent_id'] ?? null;
        $exists = MediaFolder::query()
            ->where('parent_id', $parentId)
            ->where('slug', $slug)
            ->whereKeyNot($mediaFolder->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Tên thư mục đã tồn tại trong thư mục cha.']);
        }

        $parent = $parentId ? MediaFolder::find($parentId) : null;
        $mediaFolder->update([
            'parent_id' => $parentId,
            'name' => $validated['name'],
            'slug' => $slug,
            'path' => trim(($parent?->path ? $parent->path . '/' : '') . $slug, '/'),
            'updated_by' => $request->user()?->id,
        ]);

        ActivityLog::log('media_folder_update', "Cập nhật thư mục media {$mediaFolder->name}", $mediaFolder);

        return response()->json($mediaFolder->loadCount(['media', 'children']));
    }

    public function destroy(MediaFolder $mediaFolder)
    {
        if ($mediaFolder->media()->exists() || $mediaFolder->children()->exists()) {
            return response()->json(['message' => 'Chỉ được xóa thư mục rỗng.'], 422);
        }

        $name = $mediaFolder->name;
        $mediaFolder->delete();

        ActivityLog::log('media_folder_delete', "Xóa thư mục media {$name}", $mediaFolder);

        return response()->json(['message' => 'Đã xóa thư mục.']);
    }

    private function makeFolder(string $name, ?int $parentId, Request $request): MediaFolder
    {
        $slug = Str::slug($name) ?: Str::random(8);
        $parent = $parentId ? MediaFolder::find($parentId) : null;
        $exists = MediaFolder::query()
            ->where('parent_id', $parentId)
            ->where('slug', $slug)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Tên thư mục đã tồn tại trong thư mục cha.']);
        }

        return MediaFolder::create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'path' => trim(($parent?->path ? $parent->path . '/' : '') . $slug, '/'),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);
    }
}
