<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    use HasUlids;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function type() { return $this->belongsTo(LookupValue::class, 'widget_type_id'); }
    public function permission() { return $this->belongsTo(Permission::class); }
}
