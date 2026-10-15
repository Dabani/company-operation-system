<?php

namespace App\Models;

use App\Services\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LookupValue extends Model
{
    use HasUlids;

    protected $guarded = [];
    protected $casts = ['is_system' => 'boolean', 'is_active' => 'boolean'];

    public function type() { return $this->belongsTo(LookupType::class, 'lookup_type_id'); }
    public function translations() { return $this->hasMany(LookupValueTranslation::class); }

    /** Global values plus the active organization's own values. */
    public function scopeVisible(Builder $q): Builder
    {
        $org = app(OrganizationContext::class)->organizationId();
        return $q->where(fn ($w) => $w->whereNull('organization_id')->when($org, fn ($x) => $x->orWhere('organization_id', $org)));
    }

    public function label(?string $languageId = null): string
    {
        $t = $this->translations;
        return ($languageId ? $t->firstWhere('language_id', $languageId) : null)?->label
            ?? $t->first()?->label
            ?? $this->code;
    }
}
