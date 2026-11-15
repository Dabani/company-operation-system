<?php

namespace App\Services\Hr\Attendance\Import;

/** A problem with the uploaded file as a whole (unreadable, wrong columns...). The importer turns it into a translated validation error. */
class ImportFileException extends \RuntimeException
{
    public function __construct(public string $key, public array $params = [])
    {
        parent::__construct($key);
    }
}
