<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrganizationResource;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrganizationController extends Controller
{
    public function show(OrganizationContext $ctx)
    {
        return new OrganizationResource($ctx->organization()->load(['timezone', 'currency', 'dateFormat']));
    }

    public function update(Request $request, OrganizationContext $ctx)
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'legal_name' => 'nullable|string|max:250',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:40',
            'website' => 'nullable|url|max:250',
            'address' => 'nullable|string|max:2000',
            'default_language_id' => 'required|exists:languages,id',
            'timezone_id' => 'required|exists:lookup_values,id',
            'currency_id' => 'required|exists:lookup_values,id',
            'date_format_id' => 'required|exists:lookup_values,id',
        ]);
        $org = $ctx->organization();
        $old = $org->only(array_keys($data));
        $org->update($data);
        AuditLogger::log('organization.updated', $org, $old, $data);
        return new OrganizationResource($org->fresh(['timezone', 'currency', 'dateFormat']));
    }

    public function logo(Request $request, OrganizationContext $ctx)
    {
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,webp,svg|max:2048']);
        $org = $ctx->organization();
        if ($org->logo_path) {
            Storage::disk('local')->delete($org->logo_path);
        }
        $org->update(['logo_path' => $request->file('logo')->store('logos', 'local')]);
        AuditLogger::log('organization.logo_changed', $org);
        return response()->json(['message' => __('api.saved')]);
    }
}
