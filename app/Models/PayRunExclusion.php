<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Link row (composite key run_id + employee_id): no id column, no timestamps. */
class PayRunExclusion extends Model
{
    use BelongsToOrganization;

    protected $table = 'pay_run_exclusions';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(fn ($m) => PayRunLock::assertOpen($m->run_id));
        }
    }
}
