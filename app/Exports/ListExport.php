<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ListExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(private array $rows, private array $headings) {}

    public function array(): array { return $this->rows; }

    public function headings(): array { return $this->headings; }
}
