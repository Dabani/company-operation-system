# COPS Phase 6c - Payroll exports (API, bundle 22)

Scope (Guide 5.6): bank-transfer file, PAYE / RSSB declaration files, accounting payroll journal. Requires bundles 20 and 21.
**Not in this bundle**: the web screens for payroll (next), scheduled reminders for statutory deadlines (the deadlines must first be confirmed against official sources, Guide 11).
**No bank, RRA or RSSB file layout is built in.** The guide says the bank format "must be agreed with the company bank" and the declaration formats and deadlines must be confirmed: so the layout is configuration.

## How it works
1. **Layout** (`POST payroll/export-profiles`, perm `hr.payroll.export.configure`): kind (bank_transfer | declaration | journal), delimited or fixed width, delimiter / quote / line ending / encoding / decimal separator / date format, optional header and trailer text with placeholders
   (`{run_code} {period_from} {period_to} {paid_on} {currency} {count} {total} {generated_on}`), file-name template, and the ordered **columns**. Each column takes one value from a closed catalogue (`GET payroll/export/fields`),
   formatted as text, amount, integer in minor units, integer or date. **`agreed_with` is mandatory**: who agreed the layout (the bank, RRA, RSSB, the accountant) and the reference.
2. **Check it**: `GET payroll/export-profiles/{id}/preview` renders the layout on **invented rows** (never real data) so the accountant can show it to the bank. A layout that cannot even render the sample (unknown placeholder, value too wide, unrepresentable character) is refused when it is defined.
3. **Verify** (`POST .../verify`, perm `hr.payroll.verify`): a **different person** than the one who prepared it. Only a verified, active layout can generate a file. A verified layout is final: switch it off and create a new code.
4. **Generate** (`POST payroll/runs/{id}/exports {profile_id}`, perm `hr.payroll.export`), always from the STORED payslips:
   - bank file: run **locked or paid**; never by the person who prepared or calculated the run (same rule as paying); only payslips with an amount to pay (> 0); a missing bank account **blocks the file** and names the employees;
   - declaration file: run **paid or declared**;
   - journal: run locked, paid or declared; needs **every account mapped** and must **balance to the cent**, otherwise it is refused.
   - **Adjustment runs support the bank file only** (it pays the difference). Declarations and the journal for corrections are handled with the accountant.
5. **Stored immutable**: the file is kept encrypted with its sha256 checksum, row count and total, and can be downloaded again identically (`GET payroll/exports/{id}/download`, perm `hr.payroll.export`, audited). Generating again creates a new record; older ones stay.

## Strictness (nothing is silently changed)
A value that does not fit a fixed-width column is an **error** (only text columns flagged `truncate` are cut, never numbers); a cell containing the delimiter without a quote character is an error; an unknown placeholder is an error; an encoding that would lose a character
(ISO-8859-1) is an error. Text beginning with `= + - @` is neutralised with a leading apostrophe so a spreadsheet cannot run it as a formula; amount columns are never touched.

## Journal
Debit: each earning component and each employer contribution (expense). Credit: each employee contribution, each deduction, each employer contribution (liability) and net pay.
Accounts are configured with `PUT payroll/journal-accounts` using the keys `component.{code}`, `scheme.{code}.employee_liability`, `scheme.{code}.employer_expense`, `scheme.{code}.employer_liability`, `net_pay`;
`GET payroll/runs/{id}/journal-accounts` lists exactly the keys a run needs and which are still unmapped. Nothing is guessed.

## Permissions (admin only by default)
`hr.payroll.export` (generate, download), `hr.payroll.export.configure` (layouts, accounts), `hr.payroll.verify` (the second person). Give them to different roles/people. Every generation and download is audited without the content.

## Deployment
1. `python scripts/apply-bundle-22.py <path to cops-api>` (backups in `.bundle-22-backup/`; idempotent; `--dry-run`, `--revert`; replaces no other bundle's file)
2. `php artisan migrate` then `php artisan db:seed --class=HrPayrollExportSeeder`
3. `php artisan test`

## Decisions and known issues (please confirm)
1. **The three layouts and the account plan** come from the bank, RRA / RSSB and the accountant. Enter them with the real reference and verify them before payday; use the preview to agree them with the bank.
2. **Declaration data the employee master does not hold**: tax identifier and RSSB number are not employee fields yet (national ID is read from the employee master **at export time**, not snapshotted). If a declaration needs them, tell me and they will be added with the same protections as the bank account.
3. **Deadlines**: payment and declaration deadlines are not built in; confirm them with official sources before any reminder is scheduled.
4. **Bank-file safety**: the file is generated only after lock, by a different person than the preparer, and the payment is then recorded in the run (`pay`). The bank's own cut-off and duplicate-payment controls still apply: a second export of the same run is allowed (a new record) and should be checked by finance.
5. Journal for **adjustment runs** and declarations for corrections are intentionally unsupported.
6. Files are stored in the database (encrypted); for very large payrolls move them to the file service.
7. The ISO-8859-1 option is a convenience for older bank systems; confirm the encoding with the bank.
8. rw and sw texts are first-pass: native review needed.
9. Feature tests (`tests/Feature/PayrollExportApiTest.php`) were written but **not executed**. Executed here: `PayrollExportTest` (17 tests: formatter, fixed width, quoting, encoding, journal, catalogue).
