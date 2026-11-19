# Phase 8 acceptance walkthrough (procure to pay)

Status: not yet executed against a live Laravel install. Run it once after applying bundles 24 to 26 on both sides, then tick the boxes. Unit rules covered by `ProcurementRulesTest`, `OrderRulesTest`, `InvoiceRulesTest`.

Setup: `composer dump-autoload && php artisan migrate && php artisan db:seed`. Three users: **A** (manager, buyer), **B** (admin), **C** (staff, requester). Set `procurement.po_tolerance_percent` = 0, `procurement.invoice_price_tolerance_percent` = 0, `procurement.tax_rate_percent` = 0.

| # | Step | Expected |
|---|---|---|
| 1 | A creates a supplier, adds a bank account, submits for onboarding | status under review; approval request to admin |
| 2 | A proposes bank details; A tries to confirm them | refused (proposer cannot verify); B confirms; account number masked for others |
| 3 | B approves the supplier | supplier approved; only approved suppliers appear in requisition and order dropdowns |
| 4 | C raises a requisition over the project budget without a reason | blocked; with a 10+ character reason it submits and the exception is audited |
| 5 | Department head approves the requisition | status approved; C notified |
| 6 | A creates a purchase order from it, edits prices above the requisition | refused beyond tolerance; at or below it is saved |
| 7 | A tries to cancel the requisition while the order is live | refused (`requisition_has_order`) |
| 8 | A submits the order; B approves; A issues it | issued; C told to expect delivery |
| 9 | A tries to record a receipt | refused (raiser cannot receive); C records a partial receipt | order partly received; accepted quantities updated |
| 10 | A records an invoice for more than accepted | on hold with `qty_over`; list shows issue |
| 11 | A tries to decide the exception | refused (recorder); B accepts with a note under 10 characters, then 10+ | second succeeds; invoice matched, exception flagged |
| 12 | A records a duplicate number for the same supplier | refused (`invoice_duplicate`) |
| 13 | A records a correct invoice for the accepted quantity | matched directly |
| 14 | A tries to approve it | refused (recorder / buyer of the order); B approves |
| 15 | B tries to mark it paid | refused (approver); another user holding `procurement.invoice.pay` marks paid with reference | status paid, reference and date stored |
| 16 | Review the audit log | every step above has an entry (supplier, requisition, order, receipt, invoice events) |

Known gaps: no actual bank transfer (handoff only), no RFQ/sourcing stage, no invoice attachments, no camera scanning, rw/sw strings need native review. Step 15 needs a second user with the pay permission (admin is the only role granted it by default; give it to a finance role as needed).
