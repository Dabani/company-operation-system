<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabaseServiceProvider;

return [
    App\Providers\TimeServiceProvider::class,
    App\Providers\ProjectsServiceProvider::class,
    App\Providers\CopsFoundationServiceProvider::class,
    AppServiceProvider::class,
    DatabaseServiceProvider::class,
];
