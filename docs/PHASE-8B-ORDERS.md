# Phase 8b: purchase orders and goods receipts

Guide 7.4 steps 8 and 9. Sourcing (RFQ, bids, evaluation), invoice matching and payment handoff come later. The Guide gives no field-level rules here, so the design is an assumption.

## Purchase order
- Raised by a buyer (`procurement.manage`) from an **approved** requisition and an **approved** supplier. One live order per requisition; a cancelled order frees it again. A requisition with an order cannot be cancelled until the order is.
- Lines are copied from the requisition. In draft the buyer can set the negotiated unit price per line; quantities and descriptions stay as requisitioned.
- **Tolerance:** the order total may not exceed the requisition total by more than `procurement.po_tolerance_percent` (default 0). More than that is a variation and needs a new requisition.
- Approval: flow `purchase_order` (managing director by default). Delegation-of-authority rules (amount, department) in the approval rules decide the real route, as for every subject. Rejected or returned goes back to draft with the note.
- Issue: approved -> issued, checks the supplier is still approved, tells the requester.
- Cancel is possible in draft, pending, approved and issued while nothing has been accepted. A closed or fully received order cannot be cancelled.

## Goods receipt
- Recorded by someone with `procurement.receive` who is the buyer's colleague: **the person who raised the order cannot record its receipt** (independent receipt).
- Each receipt line has received and accepted quantities (accepted <= received <= still to come). The rest is rejected and stays open.
- The order moves to partly received or received on its own. Close: from received, or from partly received with a written reason (short delivery accepted).
- Requesters see issued orders for their own requisitions only; drafts and approvals stay with buyers.

## API
`GET/POST /purchase-orders`, `GET/PATCH /purchase-orders/{id}`, `POST /purchase-orders/{id}/submit|issue|status|receipts`.

## Not yet
Delivery notes as files, invoices and the three-way match, supplier performance reviews.
