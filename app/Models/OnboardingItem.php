<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OnboardingItem extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_onboarding_items';
    protected $guarded = [];
    protected $casts = ['due_on' => 'date:Y-m-d', 'done_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}
