<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class MediaLibraryController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Settings/MediaLibrary', [
            'canDelete' => (bool) $request->user()?->hasPermission('settings.manage'),
        ]);
    }
}
