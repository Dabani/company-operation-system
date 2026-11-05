<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabaseServiceProvider;

return [
    App\Providers\CopsFoundationServiceProvider::class,
    AppServiceProvider::class,
    DatabaseServiceProvider::class,
];
