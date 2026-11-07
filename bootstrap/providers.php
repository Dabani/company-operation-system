<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabaseServiceProvider;

return [
    App\Providers\PortalServiceProvider::class,
    App\Providers\BillingServiceProvider::class,
    App\Providers\TimeServiceProvider::class,
    App\Providers\ProjectsServiceProvider::class,
    App\Providers\CopsFoundationServiceProvider::class,
    AppServiceProvider::class,
    DatabaseServiceProvider::class,
];
