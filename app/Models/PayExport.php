<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Support\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayExport extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    protected $table = 'pay_exports';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['content'];
    protected $casts = ['content' => 'encrypted', 'total_amount' => 'decimal:2', 'row_count' => 'integer', 'created_at' => \App\Casts\UtcDateTime::class];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at ??= now());
    }

    public function run() { return $this->belongsTo(PayRun::class, 'run_id'); }
    public function generator() { return $this->belongsTo(User::class, 'generated_by'); }
}
