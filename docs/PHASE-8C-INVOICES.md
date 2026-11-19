# Phase 8c: supplier invoices (Guide 7.4, stages 10 and 11)

Assumptions (the Guide gives no field-level values; change in settings or code as needed):
- An invoice is recorded against an issued, part-received, received or closed order, in the order currency, by a user with `procurement.invoice`.
- Three-way match per line: quantity may not exceed accepted quantity minus quantity already on live invoices; unit price may exceed the order price only by `procurement.invoice_price_tolerance_percent` (default 0); currency must equal the order; tax is checked only when `procurement.tax_rate_percent` > 0 (1 cent slack per line).
- Any discrepancy puts the invoice on hold with listed issues. A held invoice can be re-matched (e.g. after a late receipt), released by an approved exception (note of 10+ characters), or rejected.
- Duplicate check: same supplier and same normalised invoice number among live (not rejected/cancelled) invoices.
- Segregation of duties: the recorder cannot decide an exception or approve; the buyer who raised the order cannot approve; the approver cannot mark it paid.
- Payment is a handoff only: status, date and reference. No bank transfer is made here.
- Permissions: `procurement.invoice` (manager, admin), `.approve` and `.pay` (admin). Migration 2026_11_22_000001; seeder ProcurementInvoicesSeeder.
