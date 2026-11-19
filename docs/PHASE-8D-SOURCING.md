# Phase 8d: sourcing / requests for quotation

Assumptions (the Guide gives no field-level values):
- A buyer (`procurement.manage`) creates an RFQ from an approved requisition that has no live order or RFQ, and invites approved suppliers only. Requesters never see quotes.
- Fewer invited suppliers than `procurement.rfq_min_suppliers` (default 3) needs a sole-source reason of 10+ characters when the RFQ is opened.
- Statuses: draft, open, closed (can reopen), awarded, cancelled. Quotes are recorded by the buyer on the supplier's behalf while the RFQ is open; a supplier can be marked as declined.
- Eligible to win: received, not declined, priced on every requisition line, still valid today. Awarding other than the lowest eligible quote needs a reason of 10+ characters. The award cannot exceed the approved requisition beyond `procurement.po_tolerance_percent`.
- The awarded quote becomes a draft purchase order (supplier and prices from the quote, `purchase_orders.rfq_id` set) that follows the normal order approval.
- Not included: a supplier portal, quote attachments, scoring criteria beyond price.
Migration 2026_11_23_000001; seeder ProcurementRfqSeeder; tests RfqRulesTest.
