# Phase 8e: documents on supplier quotes and invoices

Uses the existing Controlled Files (classification, virus scan, versions, audit). Link types `rfq_quote` and `supplier_invoice`.
- Quote documents: buyers (`procurement.manage`), while the RFQ is open or closed.
- Invoice scans: holders of `procurement.invoice`, while the invoice is received, on hold, matched or approved. Viewing needs any invoice permission.
- Files classified "restricted" are refused; everything else follows normal file visibility (file.view and classification level).
Needs bundles 26 and 28. Seeder: ProcurementFilesSeeder.
