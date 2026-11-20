<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/** COPS Phase 6b wiring: the code prefix of payroll runs (PAY-0001). */
class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config(['cops.code_prefixes' => array_merge(config('cops.code_prefixes', []), ['pay_run' => 'PAY'])]);
    }
}
