<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PayJournalAccount extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'pay_journal_accounts';
    protected $guarded = [];
}
