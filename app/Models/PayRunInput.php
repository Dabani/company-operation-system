<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayRunInput extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_run_inputs';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2'];

    public function component() { return $this->belongsTo(PayComponent::class, 'component_id'); }
    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(fn ($m) => PayRunLock::assertOpen($m->run_id));
        }
    }
}
