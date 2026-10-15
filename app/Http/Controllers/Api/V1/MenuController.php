<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Services\OrganizationContext;

class MenuController extends Controller
{
    /** Permission-filtered accordion tree (max 3 levels). Parents with no visible children and no route are pruned. */
    public function index(OrganizationContext $ctx)
    {
        $items = MenuItem::with('permission')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $ctx->organizationId()))
            ->orderBy('sort_order')->get();

        $build = function (?string $parent, int $depth) use (&$build, $items, $ctx) {
            if ($depth > 3) return [];
            $out = [];
            foreach ($items->where('parent_id', $parent) as $i) {
                if ($i->permission && !$ctx->can($i->permission->code)) continue;
                $children = $build($i->id, $depth + 1);
                if (!$i->route && !$children) continue;
                $out[] = ['id' => $i->id, 'code' => $i->code, 'label_key' => $i->label_key, 'label' => $i->label,
                          'icon' => $i->icon, 'route' => $i->route, 'children' => $children];
            }
            return $out;
        };

        return response()->json(['data' => $build(null, 1)]);
    }
}
