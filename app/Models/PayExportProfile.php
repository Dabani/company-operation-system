<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayExportProfile extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_export_profiles';
    protected $guarded = [];
    protected $casts = ['include_header' => 'boolean', 'is_active' => 'boolean', 'verified_at' => \App\Casts\UtcDateTime::class];

    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function layout() { return $this->belongsTo(LookupValue::class, 'layout_id'); }
    public function verification() { return $this->belongsTo(LookupValue::class, 'verification_status_id'); }
    public function columns() { return $this->hasMany(PayExportColumn::class, 'profile_id')->orderBy('position'); }
    public function preparer() { return $this->belongsTo(User::class, 'prepared_by'); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }
}
