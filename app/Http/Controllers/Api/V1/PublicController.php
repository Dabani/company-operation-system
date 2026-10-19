<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Organization;
use App\Services\FileService;
use Illuminate\Support\Facades\Storage;

class PublicController extends Controller
{
    /** Pre-login data: languages + branding of the default organization. */
    public function config()
    {
        $org = Organization::whereHas('status', fn ($q) => $q->where('code', 'active'))->orderBy('created_at')->first();
        return response()->json(['data' => [
            'app_name' => $org?->name ?? config('app.name'),
            'has_logo' => (bool) $org?->logo_path,
            'default_language' => config('ops.default_language'),
            'languages' => Language::where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'native_name']),
        ]]);
    }

    public function logo()
    {
        $org = Organization::whereNotNull('logo_path')->orderBy('created_at')->first();
        abort_unless($org && Storage::disk(FileService::disk())->exists($org->logo_path), 404);
        return Storage::disk(FileService::disk())->response($org->logo_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
