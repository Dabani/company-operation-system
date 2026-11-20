# Regression checklist - COPS Phase 6c payroll exports (append to docs/REGRESSION-CHECKLIST.md)

- [ ] After seeding there is no layout and no journal account; export endpoints are 403 for staff and for roles without the export permissions.
- [ ] A layout cannot be created without `agreed_with`, with a field not allowed for its kind, with a scheme/component field missing its scheme/component, with a fixed-width column without width, or with an unknown placeholder; a refused layout leaves nothing behind.
- [ ] The preview shows invented rows only (never real employees).
- [ ] The preparer cannot verify their own layout; an unverified or switched-off layout generates nothing; a verified layout cannot be edited or deleted.
- [ ] Bank file: refused before lock; refused for the preparer/calculator; refused when an employee lacks a bank account (names listed); only amounts > 0; an adjustment run pays only the difference.
- [ ] Declaration file: refused before payment; refused for adjustment runs. Journal: refused when any needed account is unmapped or when it does not balance; refused for adjustment runs.
- [ ] A value that does not fit a fixed-width column, an unrepresentable character, a cell with the delimiter and no quote character: error, never a silent cut. Formula-looking text is neutralised; amounts untouched.
- [ ] A generated file is stored encrypted, immutable, with matching sha256 (header `X-Content-SHA256`); downloading returns the identical bytes; generation and download are audited without content.
- [ ] Audit log has payroll.export_profile_created/verified/active/deleted, journal_account_saved, export_generated, export_downloaded.
