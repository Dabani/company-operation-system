<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class Heartbeat extends Command
{
    protected $signature = 'ops:heartbeat';
    protected $description = 'Record that the scheduler is running (read by GET /api/v1/health/details).';

    public function handle(): int
    {
        Cache::forever('ops:scheduler:heartbeat', now()->timestamp);
        return self::SUCCESS;
    }
}
