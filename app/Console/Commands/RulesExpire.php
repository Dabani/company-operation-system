<?php

namespace App\Console\Commands;

use App\Services\Rules\RuleLifecycle;
use Illuminate\Console\Command;

class RulesExpire extends Command
{
    protected $signature = 'cops:rules-expire';
    protected $description = 'Mark active rule versions whose effective period has ended as expired';

    public function handle(RuleLifecycle $lifecycle): int
    {
        $this->info('Expired ' . $lifecycle->expireDue() . ' rule version(s).');
        return self::SUCCESS;
    }
}
