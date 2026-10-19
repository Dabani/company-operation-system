<?php

namespace App\Services;

class NullFileScanner implements FileScanner
{
    public function isClean(string $absolutePath): bool { return true; }
}
