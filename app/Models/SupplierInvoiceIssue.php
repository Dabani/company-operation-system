<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceIssue extends Model
{
    use HasUlids, BelongsToOrganization;

    public $timestamps = false;
    protected $guarded = [];
}
