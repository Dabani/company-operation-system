<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];   // controllers only pass validated data; organization_id is set by the tenant trait
    protected $casts = ['is_system' => 'boolean', 'is_active' => 'boolean', 'level' => 'integer'];

    public function permissions() { return $this->belongsToMany(Permission::class, 'role_permissions'); }
    public function widgets() { return $this->belongsToMany(DashboardWidget::class, 'role_dashboard_widgets', 'role_id', 'widget_id')->withPivot(['sort_order', 'width']); }
    public function memberships() { return $this->belongsToMany(Membership::class, 'membership_roles', 'role_id', 'membership_id'); }
}
