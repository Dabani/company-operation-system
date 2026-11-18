<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use App\Support\HasGeneratedCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasGeneratedCode;
    protected static string $codeEntity = 'supplier';
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['approved_at' => UtcDateTime::class];

    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function category() { return $this->belongsTo(LookupValue::class, 'category_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function bankAccounts() { return $this->hasMany(SupplierBankAccount::class)->orderByDesc('created_at'); }
    public function history() { return $this->hasMany(SupplierStatusHistory::class)->orderBy('created_at'); }
}
