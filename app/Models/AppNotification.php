<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'app_notifications';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime'];
}
