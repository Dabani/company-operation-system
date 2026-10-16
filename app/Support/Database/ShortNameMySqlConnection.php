<?php

namespace App\Support\Database;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\MySqlBuilder;

class ShortNameMySqlConnection extends MySqlConnection
{
    public function getSchemaBuilder()
    {
        if (is_null($this->getSchemaGrammar())) {
            $this->useDefaultSchemaGrammar();
        }

        $builder = new MySqlBuilder($this);

        $builder->blueprintResolver(
            function ($connection, $table, $callback = null) {
                return new ShortNameBlueprint(
                    $connection,
                    $table,
                    $callback
                );
            }
        );

        return $builder;
    }
}
