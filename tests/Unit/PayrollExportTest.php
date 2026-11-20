<?php

namespace Tests\Unit;

use App\Services\Hr\Payroll\ExportFields as F;
use App\Services\Hr\Payroll\ExportFormatError;
use App\Services\Hr\Payroll\ExportFormatter as X;
use App\Services\Hr\Payroll\JournalBuilder as J;
use PHPUnit\Framework\TestCase;

/** Pure export logic. Every layout, amount and account is a TEST INPUT: no real bank, RRA or RSSB format is built in; the accountant defines them. */
class PayrollExportTest extends TestCase
{
    private function profile(array $o = []): array
    {
        return $o + ['layout' => 'delimited', 'delimiter' => ';', 'quote' => '"', 'line_ending' => 'LF', 'encoding' => 'UTF-8', 'decimal_separator' => ',', 'date_format' => 'd/m/Y', 'include_header' => true,
            'header_template' => null, 'trailer_template' => null, 'columns' => [
                ['header' => 'Number', 'source' => 'employee_number', 'format' => 'text'],
                ['header' => 'Name', 'source' => 'employee_name', 'format' => 'text'],
                ['header' => 'Net', 'source' => 'net', 'format' => 'amount', 'decimals' => 2],
                ['header' => 'Date', 'source' => 'paid_on', 'format' => 'date'],
            ]];
    }

    private function rows(): array
    {
        return [['employee_number' => 'E001', 'employee_name' => 'Alice A', 'net' => '475000', 'paid_on' => '2026-12-01'], ['employee_number' => 'E002', 'employee_name' => 'Bob B', 'net' => '1234.5', 'paid_on' => '2026-12-01']];
    }

    private function err(callable $f): string
    {
        try {
            $f();
        } catch (ExportFormatError $e) {
            return $e->reason;
        }
        $this->fail('expected an ExportFormatError');
    }

    // ---- delimited -----------------------------------------------------------------------------------------------------------------
    public function test_a_delimited_file_with_header_decimal_comma_and_date_format(): void
    {
        $this->assertSame("Number;Name;Net;Date\nE001;Alice A;475000,00;01/12/2026\nE002;Bob B;1234,50;01/12/2026\n", X::render($this->profile(), $this->rows()));
    }

    public function test_crlf_and_no_header(): void
    {
        $out = X::render($this->profile(['line_ending' => 'CRLF', 'include_header' => false]), [$this->rows()[0]]);
        $this->assertSame("E001;Alice A;475000,00;01/12/2026\r\n", $out);
    }

    public function test_values_containing_the_delimiter_or_quote_are_quoted_and_doubled(): void
    {
        $out = X::render($this->profile(['include_header' => false]), [['employee_number' => 'E1', 'employee_name' => 'Doe; "Jo"', 'net' => '1', 'paid_on' => '2026-12-01']]);
        $this->assertSame("E1;\"Doe; \"\"Jo\"\"\";1,00;01/12/2026\n", $out);
    }

    public function test_without_a_quote_character_a_value_with_the_delimiter_is_refused(): void
    {
        $this->assertSame('delimiter_in_value', $this->err(fn () => X::render($this->profile(['quote' => '', 'include_header' => false]), [['employee_number' => 'E1', 'employee_name' => 'A;B', 'net' => '1', 'paid_on' => '2026-12-01']])));
    }

    public function test_text_that_a_spreadsheet_would_run_as_a_formula_is_neutralised_but_amounts_are_not_touched(): void
    {
        $out = X::render($this->profile(['include_header' => false]), [['employee_number' => 'E1', 'employee_name' => '=SUM(A1:A9)', 'net' => '-5', 'paid_on' => '2026-12-01']]);
        $this->assertSame("E1;'=SUM(A1:A9);-5,00;01/12/2026\n", $out);
    }

    public function test_header_and_trailer_templates_with_count_and_known_placeholders_only(): void
    {
        $p = $this->profile(['header_template' => "HDR;{run_code};{count}", 'trailer_template' => 'TRL;{total}', 'include_header' => false]);
        $this->assertSame("HDR;PAY-0001;2\nE001;Alice A;475000,00;01/12/2026\nE002;Bob B;1234,50;01/12/2026\nTRL;476234.50\n", X::render($p, $this->rows(), ['run_code' => 'PAY-0001', 'total' => '476234.50']));
        $this->assertSame('unknown_placeholder', $this->err(fn () => X::render($p, $this->rows(), ['total' => '1'])));              // {run_code} is not provided: a typo can never slip through
    }

    public function test_amount_formats_round_exactly_and_integer_minor_has_no_separator(): void
    {
        $f = fn (string $format, string $v, int $dec) => X::cell(['source' => 'net', 'format' => $format, 'decimals' => $dec], ['net' => $v], 1, ['decimal_separator' => '.']);
        $this->assertSame('3', $f('amount', '2.5', 0));
        $this->assertSame('1.01', $f('amount', '1.005', 2));
        $this->assertSame('123450', $f('integer_minor', '1234.50', 2));
        $this->assertSame('101', $f('integer_minor', '1.005', 2));
        $this->assertSame('30000', $f('integer_minor', '30000', 0));
        $this->assertSame('12', $f('integer', '12.9', 0));
        $this->assertSame('', $f('amount', '', 2));
        $this->assertSame('not_numeric', $this->err(fn () => $f('amount', 'abc', 2)));
    }

    public function test_iso_8859_1_is_produced_and_a_lossy_encoding_is_refused(): void
    {
        $p = $this->profile(['encoding' => 'ISO-8859-1', 'include_header' => false]);
        $out = X::render($p, [['employee_number' => 'E1', 'employee_name' => 'Éric', 'net' => '1', 'paid_on' => '2026-12-01']]);
        $this->assertSame("E1;\xC9ric;1,00;01/12/2026\n", $out);
        $this->assertSame('encoding', $this->err(fn () => X::render($p, [['employee_number' => 'E1', 'employee_name' => '日本', 'net' => '1', 'paid_on' => '2026-12-01']])));
    }

    // ---- fixed width -----------------------------------------------------------------------------------------------------------------
    private function fixed(array $o = []): array
    {
        return $o + ['layout' => 'fixed_width', 'line_ending' => 'LF', 'encoding' => 'UTF-8', 'decimal_separator' => '.', 'date_format' => 'Ymd', 'columns' => [
            ['header' => 'Type', 'source' => 'constant', 'constant' => 'D', 'format' => 'text', 'width' => 1],
            ['header' => 'Seq', 'source' => 'row_number', 'format' => 'integer', 'width' => 4, 'align' => 'right', 'pad_char' => '0'],
            ['header' => 'Name', 'source' => 'employee_name', 'format' => 'text', 'width' => 8, 'truncate' => true],
            ['header' => 'Amount', 'source' => 'net', 'format' => 'integer_minor', 'decimals' => 2, 'width' => 10, 'align' => 'right', 'pad_char' => '0'],
            ['header' => 'Date', 'source' => 'paid_on', 'format' => 'date', 'width' => 8],
        ]];
    }

    public function test_a_fixed_width_record_is_padded_exactly(): void
    {
        $out = X::render($this->fixed(), $this->rows());
        $this->assertSame("D0001Alice A 004750000020261201\nD0002Bob B   000012345020261201\n", $out);      // 475000.00 = 47500000 minor units, in 10 zero-padded places
        foreach (explode("\n", trim($out)) as $line) {
            $this->assertSame(31, strlen($line));
        }
    }

    public function test_a_value_that_does_not_fit_is_refused_and_only_flagged_text_is_truncated(): void
    {
        $long = [['employee_name' => 'Alexandria Longname', 'net' => '1', 'paid_on' => '2026-12-01']];
        $this->assertStringContainsString('Alexandri', 'Alexandri' . X::render($this->fixed(), $long));                                // text with truncate=true is cut to 8
        $this->assertSame('D0001Alexandr0000000100' . "20261201\n", X::render($this->fixed(), $long));
        $big = [['employee_name' => 'A', 'net' => '1234567890.00', 'paid_on' => '2026-12-01']];                                          // 15 digits in 10: never cut a number
        $this->assertSame('overflow', $this->err(fn () => X::render($this->fixed(), $big)));
        $noTruncate = $this->fixed();
        $noTruncate['columns'][2]['truncate'] = false;
        $this->assertSame('overflow', $this->err(fn () => X::render($noTruncate, $long)));
        $noWidth = $this->fixed();
        unset($noWidth['columns'][1]['width']);
        $this->assertSame('width_required', $this->err(fn () => X::render($noWidth, $long)));
    }

    public function test_no_columns_is_refused(): void
    {
        $this->assertSame('no_columns', $this->err(fn () => X::render(['layout' => 'delimited', 'columns' => []], [])));
    }

    // ---- journal ---------------------------------------------------------------------------------------------------------------------
    private function agg(array $o = []): array
    {
        return $o + ['earnings' => ['basic' => '500000.00'], 'deductions' => ['loan' => '10000.00'], 'schemes' => ['pen' => ['employee' => '25000.00', 'employer' => '25000.00']], 'net' => '465000.00'];
    }

    private function accounts(): array
    {
        return ['component.basic' => '6100', 'component.loan' => '4210', 'scheme.pen.employer_expense' => '6410', 'scheme.pen.employee_liability' => '4310', 'scheme.pen.employer_liability' => '4311', 'net_pay' => '4200'];
    }

    public function test_the_journal_balances_and_posts_to_the_configured_accounts(): void
    {
        $r = J::build($this->agg(), $this->accounts());
        $this->assertTrue($r['balanced']);
        $this->assertSame('525000.00', $r['debit']);
        $this->assertSame('525000.00', $r['credit']);
        $this->assertSame([], $r['missing']);
        $byKey = array_column($r['lines'], null, 'key');
        $this->assertSame('500000.00', $byKey['component.basic']['debit']);
        $this->assertSame('6100', $byKey['component.basic']['account']);
        $this->assertSame('465000.00', $byKey['net_pay']['credit']);
        $this->assertSame('10000.00', $byKey['component.loan']['credit']);
    }

    public function test_a_needed_account_that_is_not_mapped_is_reported_not_guessed(): void
    {
        $acc = $this->accounts();
        unset($acc['net_pay'], $acc['scheme.pen.employee_liability']);
        $r = J::build($this->agg(), $acc);
        $this->assertSame(['scheme.pen.employee_liability', 'net_pay'], $r['missing']);
        $this->assertSame('', array_column($r['lines'], null, 'key')['net_pay']['account']);
    }

    public function test_zero_amounts_make_no_line_and_a_wrong_net_makes_the_journal_unbalanced(): void
    {
        $r = J::build($this->agg(['deductions' => [], 'schemes' => []]), ['component.basic' => '6100', 'net_pay' => '4200']);
        $this->assertCount(2, $r['lines']);                                                         // no zero lines
        $this->assertFalse(J::build($this->agg(['net' => '400000.00']), $this->accounts())['balanced']);
    }

    public function test_cents_are_exact(): void
    {
        $r = J::build(['earnings' => ['basic' => '100.10', 'bonus' => '0.20'], 'deductions' => [], 'schemes' => [], 'net' => '100.30'], ['component.basic' => '1', 'component.bonus' => '2', 'net_pay' => '3']);
        $this->assertTrue($r['balanced']);
        $this->assertSame('100.30', $r['debit']);
    }
    // ---- field catalogue ---------------------------------------------------------------------------------------------------------------
    public function test_a_layout_can_only_name_fields_that_exist_for_its_kind(): void
    {
        $bank = F::allowed('bank_transfer');
        $this->assertContains('bank_account', $bank);
        $this->assertContains('pay_amount', $bank);
        $this->assertNotContains('scheme_employee_amount', $bank);        // a bank file has no scheme fields
        $this->assertNotContains('journal_debit', $bank);
        $decl = F::allowed('declaration');
        $this->assertContains('scheme_employee_amount', $decl);
        $this->assertContains('employee_national_id', $decl);
        $this->assertContains('component_amount', $decl);
        $journal = F::allowed('journal');
        $this->assertContains('journal_account', $journal);
        $this->assertNotContains('bank_account', $journal);                // a journal never carries bank accounts
        $this->assertNotContains('employee_name', $journal);
        $this->assertSame([], F::allowed('unknown'));
        $this->assertTrue(F::needsScheme('scheme_base'));
        $this->assertFalse(F::needsScheme('net'));
        $this->assertTrue(F::needsComponent('component_amount'));
    }

    public function test_money_files_are_only_possible_after_the_run_is_locked_and_declarations_after_payment(): void
    {
        $this->assertSame(['locked', 'paid'], F::runStatuses('bank_transfer'));
        $this->assertSame(['paid', 'declared'], F::runStatuses('declaration'));
        $this->assertSame(['locked', 'paid', 'declared'], F::runStatuses('journal'));
        foreach (['bank_transfer', 'declaration', 'journal'] as $k) {
            $this->assertNotContains('draft', F::runStatuses($k));
            $this->assertNotContains('calculated', F::runStatuses($k));
            $this->assertNotContains('reviewed', F::runStatuses($k));
            $this->assertNotContains('approved', F::runStatuses($k));
        }
    }
}
