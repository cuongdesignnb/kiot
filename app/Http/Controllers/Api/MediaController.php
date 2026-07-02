<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function index(Request $request)
    {
        $query = Media::latest();

        if ($request->filled('collection')) {
            $query->where('collection', $request->collection);
        }

        if ($request->filled('search')) {
            $query->where('original_name', 'like', '%' . $request->search . '%');
        }

        return response()->json($query->paginate($request->per_page ?? 30));
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|image|max:5120', // 5MB max
            'collection' => 'nullable|string|max:50',
        ]);

        $file = $request->file('file');
        $collection = $request->input('collection', 'default');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs("media/{$collection}", $filename, 'public');

        $media = Media::create([
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'collection' => $collection,
            'uploaded_by' => $request->user()?->id,
        ]);

        return response()->json($media, 201);
    }

    public function destroy(Media $media)
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return response()->json(['message' => 'Đã xóa']);
    }
}
