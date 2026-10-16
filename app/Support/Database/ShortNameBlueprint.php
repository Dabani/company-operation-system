<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

class ShortNameBlueprint extends Blueprint
{
    /**
     * Generate a MySQL-safe index or constraint name.
     *
     * MySQL index names are limited to 64 characters.
     */
    protected function createIndexName($type, array $columns)
    {
        $name = parent::createIndexName($type, $columns);

        if (strlen($name) <= 64) {
            return $name;
        }

        // A deterministic suffix helps distinguish long names.
        $suffix = '_' . substr(md5($name), 0, 8);

        return substr($name, 0, 64 - strlen($suffix))
            . $suffix;
    }
}
