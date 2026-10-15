<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasUlids;

    protected $guarded = [];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function timezone() { return $this->belongsTo(LookupValue::class, 'timezone_id'); }
    public function currency() { return $this->belongsTo(LookupValue::class, 'currency_id'); }
    public function dateFormat() { return $this->belongsTo(LookupValue::class, 'date_format_id'); }
    public function defaultLanguage() { return $this->belongsTo(Language::class, 'default_language_id'); }
    public function memberships() { return $this->hasMany(Membership::class); }
}
