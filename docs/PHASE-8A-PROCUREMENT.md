# Phase 8a: suppliers and purchase requisitions

First slice of Module D (Guide section 7). Purchase orders, goods receipts, sourcing events and three-way matching come in later bundles. The Guide gives no field-level rules for this slice, so the design below is an assumption you can change.

## Suppliers (Guide 7.6)
- Status: draft, under review, approved, suspended, blocked, archived. Only an **approved** supplier can be named on a requisition.
- Onboarding: a buyer completes legal name, tax number, country and a contact, then submits. The approval flow `supplier_onboarding` (managing director, role `admin`) decides. Rejected or returned goes back to draft with the reviewer's note.
- Suspend and block need a reason; reinstating a suspended supplier is a manual move; a blocked supplier needs a new review. Every change is in an immutable status history and the audit log.
- **Bank details are restricted.** A buyer proposes an account; someone else with `procurement.supplier.bank` confirms it (the proposer cannot). A confirmed account replaces the previous one. Account numbers are encrypted, masked in the API unless the viewer holds the bank permission, and showing one in full is audited.
- People without `procurement.manage` see approved suppliers by code, name, country and category only.

## Purchase requisitions (Guide 7.4 steps 2 and 3)
- Requester states title, purpose, type (goods, works, consultancy, services), cost centre (department), optional project, needed-by date, delivery location, currency and lines (quantity x unit price, totals computed from cents).
- Submit is refused when mandatory data is missing (acceptance criterion in 7.10).
- **Budget check:** when a project with a budget in the same currency is linked, approved plus pending requisitions are added to this one. Over budget is not silently blocked: a written exception reason (10+ characters) is required, the requisition is flagged and the approval title carries "(over budget)".
- **Split purchases:** same requester, cost centre, type and currency within `procurement.split_window_days` (default 30) that together reach `procurement.split_threshold` (default 0 = off) flag the new one as a possible split. Only buyers see the flag; the approval title carries it.
- A preferred supplier needs a recorded reason (restricted sourcing is justified).
- Approval: flow `purchase_requisition` (department head). Delegation-of-authority thresholds work through the existing approval rules, which receive amount and department. The requester can never approve their own request.
- Cancel from draft, pending (withdraws the approval) or approved; a rejected requisition can be reopened as a draft.

## Permissions
`procurement.view`, `procurement.requisition.create`, `procurement.manage` (buyer), `procurement.supplier.bank`. Staff and team leaders get view and create; managers also manage; admin has all four.

## API
`GET/POST /suppliers`, `GET/PATCH /suppliers/{id}`, `POST /suppliers/{id}/submit|status|bank-accounts`, `POST /supplier-bank-accounts/{id}/verify|reveal`;
`GET/POST /purchase-requisitions`, `GET/PATCH/DELETE /purchase-requisitions/{id}`, `POST /purchase-requisitions/{id}/submit|status`.

## Not in this bundle
Requisition attachments, sourcing events and quotes, purchase orders, receipts, matching, supplier performance reviews.
