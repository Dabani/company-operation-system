<?php

namespace App\Console\Commands;

use App\Services\Hr\RecruitmentService;
use Illuminate\Console\Command;

class HrRetentionReview extends Command
{
    protected $signature = 'cops:hr-retention-review';
    protected $description = 'Tell HR which applicant records passed their retention date (nothing is deleted)';

    public function handle(RecruitmentService $svc): int
    {
        $this->info($svc->retentionReview() . ' applicant record(s) flagged for review.');
        return self::SUCCESS;
    }
}
