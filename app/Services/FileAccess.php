<?php

namespace App\Services;

use App\Models\FileAsset;
use App\Models\FileClassificationRule;
use App\Models\SupportCase;
use App\Support\Lookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place that decides who may do what with a file. Controllers never re-implement these rules.
 *
 * View  = file.view AND (owner OR role level >= classification minimum OR participant of a linked case, unless "restricted").
 * Other operations add their own permission and ownership rules (see abilities()).
 */
class FileAccess
{
    private static function ctx(): OrganizationContext { return app(OrganizationContext::class); }

    public static function scopeVisible(Builder $q): Builder
    {
        $ctx = self::ctx();
        if (!$ctx->can('file.view')) {
            return $q->whereRaw('1 = 0');
        }
        $me = request()->user()->id;
        $allowed = FileClassificationRule::where('min_level', '<=', $ctx->level())->pluck('classification_id');
        $restricted = Lookup::id('file_classification', 'restricted');
        $caseType = Lookup::id('file_link_type', 'case');

        return $q->where(function (Builder $w) use ($me, $allowed, $restricted, $caseType, $ctx) {
            $w->where('file_assets.owner_id', $me)
              ->orWhereIn('file_assets.classification_id', $allowed)
              ->orWhere(function (Builder $c) use ($me, $restricted, $caseType, $ctx) {
                  $c->where('file_assets.classification_id', '!=', $restricted)
                    ->whereExists(function ($sub) use ($me, $caseType, $ctx) {
                        $sub->select(DB::raw(1))->from('file_links')
                            ->join('cases', 'cases.id', '=', 'file_links.link_id')
                            ->whereColumn('file_links.file_id', 'file_assets.id')
                            ->where('file_links.link_type_id', $caseType)
                            ->where('cases.organization_id', $ctx->organizationId())
                            ->when(!$ctx->can('case.view_all'), fn ($x) => $x->where(fn ($y) => $y->where('cases.requester_id', $me)->orWhere('cases.assignee_id', $me)));
                    });
              });
        });
    }

    public static function canView(FileAsset $f): bool
    {
        return self::scopeVisible(FileAsset::query())->whereKey($f->id)->exists();
    }

    public static function owns(FileAsset $f): bool { return $f->owner_id === request()->user()->id; }

    /** Owner or an administrator (file.delete_approve) may manage the file itself. */
    public static function canManage(FileAsset $f): bool { return self::owns($f) || self::ctx()->can('file.delete_approve'); }

    public static function abilities(FileAsset $f): array
    {
        $c = self::ctx();
        $active = $f->status?->code === 'active';
        $archived = $f->status?->code === 'archived';
        $owns = self::owns($f);
        return [
            'download' => $c->can('file.download'),
            'replace' => $active && $c->can('file.replace') && self::canManage($f),
            'edit' => self::canManage($f),
            'archive' => $active && $c->can('file.archive') && ($owns || $c->can('file.restore')),
            'restore' => $archived && ($c->can('file.restore') || ($owns && $c->can('file.archive'))),
            'request_deletion' => $c->can('file.delete_request'),
            'decide_deletion' => self::canManage($f),
            'legal_hold' => $c->can('file.legal_hold'),
        ];
    }
}
