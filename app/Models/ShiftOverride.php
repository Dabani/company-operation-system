<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ShiftOverride extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_shift_overrides';
    protected $guarded = [];
    protected $casts = ['work_date' => 'date:Y-m-d'];

    public function shift() { return $this->belongsTo(Shift::class, 'shift_id'); }
}
