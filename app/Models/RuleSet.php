<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RuleSet extends Model
{
    use \App\Support\HasGeneratedCode;
    protected static string $codeEntity = 'rule_set';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['is_legal_profile' => 'boolean', 'requires_full_coverage' => 'boolean'];

    public function domain() { return $this->belongsTo(LookupValue::class, 'domain_id'); }
    public function versions() { return $this->hasMany(RuleVersion::class)->orderByDesc('version'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }
}
