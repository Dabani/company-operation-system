# Payroll golden files (Guide section 10, point 9)

Every `*.json` file here is a worked example run through the real calculation engine by `tests/Unit/PayrollGoldenTest.php`, and must match **exactly** (to the last digit).
Add the accountant's signed worked examples here - no code change is needed.

```json
{
  "name": "Short description",
  "source": "who prepared it",
  "signed_off": true,
  "signed_off_by": "Accountant name, date",
  "input":    { "earnings": [...], "deductions": [...], "schemes": [...] },
  "expected": { "totals": { "gross": "...", "net": "..." }, "lines": [ { "kind": "scheme_employee", "code": "PAYE", "amount": "..." } ] }
}
```
Input shape: see the docblock of `App\Services\Hr\Payroll\PayrollEngine`. Rates are percentages. Amounts are strings.

The two files starting with `synthetic-` use invented round numbers to prove the mechanism. They are **not** statutory examples and are marked `"signed_off": false`.
Production activation needs real fixtures signed off by the accountant (the Phase 6 exit criterion is a two-month parallel run that matches to the franc).
