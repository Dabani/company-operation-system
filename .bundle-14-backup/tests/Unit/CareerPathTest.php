<?php

namespace Tests\Unit;

use App\Services\Hr\CareerPath;
use PHPUnit\Framework\TestCase;

class CareerPathTest extends TestCase
{
    public function test_months_are_whole_months_and_never_negative(): void
    {
        $this->assertSame(10, CareerPath::months('2026-01-06', '2026-11-09'));
        $this->assertSame(10, CareerPath::months('2026-01-06', '2026-12-05'));      // the 11th month is not complete yet
        $this->assertSame(11, CareerPath::months('2026-01-06', '2026-12-06'));
        $this->assertSame(12, CareerPath::months('2026-01-06', '2027-01-06'));
        $this->assertSame(0, CareerPath::months('2026-11-09', '2026-01-06'));
    }

    public function test_grade_since_walks_back_through_the_continuous_run_of_the_same_grade(): void
    {
        $rows = [
            ['from' => '2024-01-01', 'grade' => 'G1'],
            ['from' => '2025-03-01', 'grade' => 'G2'],
            ['from' => '2025-09-01', 'grade' => 'G2'],      // salary change inside the same grade: the grade run started in March
            ['from' => '2026-06-01', 'grade' => 'G3'],
        ];
        $this->assertSame('2025-03-01', CareerPath::gradeSince($rows, '2026-05-31'));
        $this->assertSame('2026-06-01', CareerPath::gradeSince($rows, '2026-11-09'));
        $this->assertNull(CareerPath::gradeSince($rows, '2023-12-31'));
        $this->assertNull(CareerPath::gradeSince([['from' => '2024-01-01', 'grade' => null]], '2026-01-01'));
    }

    public function test_criteria_report_exactly_what_is_missing(): void
    {
        $c = ['min_months_in_grade' => 12, 'min_rating_level' => 4];
        $this->assertSame(['eligible' => true, 'missing' => []], CareerPath::evaluate($c, 12, 4));
        $this->assertSame(['eligible' => false, 'missing' => ['months_in_grade']], CareerPath::evaluate($c, 11, 5));
        $this->assertSame(['eligible' => false, 'missing' => ['rating']], CareerPath::evaluate($c, 30, 3));
        $this->assertSame(['eligible' => false, 'missing' => ['months_in_grade', 'rating']], CareerPath::evaluate($c, 0, null));
        $this->assertSame(['eligible' => true, 'missing' => []], CareerPath::evaluate(['min_months_in_grade' => 6, 'min_rating_level' => null], 6, null));
    }
}
