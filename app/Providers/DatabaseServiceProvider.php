<?php

namespace App\Providers;

use App\Support\Database\ShortNameMySqlConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor(
            'mysql',
            function ($connection, $database, $prefix, $config) {
                return new ShortNameMySqlConnection(
                    $connection,
                    $database,
                    $prefix,
                    $config
                );
            }
        );
    }
}
