<?php

namespace Tests\Unit;

use App\Services\MoneyService;
use App\Services\Rules\RuleMatcher;
use App\Support\DepartmentScope;
use PHPUnit\Framework\TestCase;

/** Golden scenarios for the framework-free parts: boundary amounts, overlapping dates, missing rules, grandfathering, rounding. */
class CopsPureLogicTest extends TestCase
{
    private function c(string $a, string $o, ?string $d = null, ?string $to = null, ?string $t = null): array
    {
        return ['attribute' => $a, 'operator' => $o, 'decimal' => $d, 'decimal_to' => $to, 'text' => $t];
    }

    private function cand(string $id, int $v, int $p, string $from, ?string $to, array $conds): array
    {
        return ['id' => $id, 'version' => $v, 'priority' => $p, 'effective_from' => $from, 'effective_to' => $to, 'conditions' => $conds];
    }

    private function pick(array $cs, array $attrs, string $on = '2026-11-03'): ?string { return RuleMatcher::pick($cs, $attrs, $on)['id'] ?? null; }

    public function test_boundary_amounts_and_missing_attribute(): void
    {
        $low = $this->cand('low', 1, 100, '2026-01-01', null, [$this->c('amount', 'lt', '1000000.00')]);
        $high = $this->cand('high', 1, 100, '2026-01-01', null, [$this->c('amount', 'gte', '1000000.00')]);
        $this->assertSame('low', $this->pick([$low, $high], ['amount' => '999999.99']));
        $this->assertSame('high', $this->pick([$low, $high], ['amount' => '1000000.00']));
        $this->assertNull($this->pick([$low, $high], []));
    }

    public function test_specificity_priority_then_latest_version(): void
    {
        $high = $this->cand('high', 1, 100, '2026-01-01', null, [$this->c('amount', 'gte', '1000000.00')]);
        $spec = $this->cand('spec', 1, 100, '2026-01-01', null, [$this->c('amount', 'gte', '1000000.00'), $this->c('department', 'eq', null, null, 'D1')]);
        $hp = $this->cand('hp', 1, 200, '2026-01-01', null, [$this->c('amount', 'gte', '1000000.00')]);
        $v2 = $this->cand('v2', 2, 100, '2026-06-01', null, [$this->c('amount', 'gte', '1000000.00')]);
        $this->assertSame('spec', $this->pick([$high, $spec], ['amount' => '2000000', 'department' => 'D1']));
        $this->assertSame('high', $this->pick([$high, $spec], ['amount' => '2000000', 'department' => 'D2']));
        $this->assertSame('hp', $this->pick([$high, $hp], ['amount' => '5000000']));
        $this->assertSame('v2', $this->pick([$high, $v2], ['amount' => '5000000']));
    }

    public function test_grandfathering_by_effective_dates(): void
    {
        $old = $this->cand('old', 1, 100, '2026-01-01', '2026-05-31', [$this->c('amount', 'gte', '1000000.00')]);
        $v2 = $this->cand('v2', 2, 100, '2026-06-01', null, [$this->c('amount', 'gte', '1000000.00')]);
        $this->assertSame('old', $this->pick([$old, $v2], ['amount' => '5000000'], '2026-05-15'));
        $this->assertSame('v2', $this->pick([$old, $v2], ['amount' => '5000000'], '2026-06-01'));
        $this->assertNull($this->pick([$old], ['amount' => '5000000'], '2026-07-01'));
    }

    public function test_amount_coverage_gaps(): void
    {
        $iv = fn (array $c) => RuleMatcher::amountInterval($c);
        $this->assertSame([], RuleMatcher::coverageGaps([$iv([$this->c('amount', 'lt', '1000000.00')]), $iv([$this->c('amount', 'gte', '1000000.00')])]));
        $this->assertCount(1, RuleMatcher::coverageGaps([$iv([$this->c('amount', 'lt', '1000000.00')]), $iv([$this->c('amount', 'gte', '2000000.00')])]));
    }

    public function test_money_rounding_is_decimal_exact(): void
    {
        $this->assertSame('1.01', MoneyService::round('1.005'));
        $this->assertSame('-1.01', MoneyService::round('-1.005'));
        $this->assertSame('222.22', MoneyService::round(MoneyService::percent('1234.56', '18')));
        $this->assertSame('0.30', MoneyService::add('0.10', '0.20'));
    }

    public function test_department_descendants(): void
    {
        $parents = ['a' => null, 'b' => 'a', 'c' => 'b', 'd' => 'a', 'x' => null, 'y' => 'x'];
        $this->assertEqualsCanonicalizing(['b', 'c', 'd'], DepartmentScope::descendants(['a'], $parents));
        $this->assertSame([], DepartmentScope::descendants(['c'], $parents));
    }
}
