<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OnboardingTemplateItem extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_onboarding_template_items';
    protected $guarded = [];
    protected $casts = ['offset_days' => 'integer', 'is_active' => 'boolean'];

}
