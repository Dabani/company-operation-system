<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLogChange extends Model
{
    public $timestamps = false;
    protected $guarded = [];
}
