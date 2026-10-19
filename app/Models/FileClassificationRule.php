<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileClassificationRule extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'classification_id';
    protected $keyType = 'string';
    protected $guarded = [];
}
