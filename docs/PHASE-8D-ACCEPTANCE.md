# Phase 8d acceptance walkthrough (sourcing / RFQ)

Not yet executed against a live install. Insert between steps 5 and 6 of PHASE-8-ACCEPTANCE.md. Setup: `procurement.rfq_min_suppliers` = 3, three approved suppliers S1 to S3, an approved requisition with two lines, buyer A (`procurement.manage`).

| # | Step | Expected |
|---|---|---|
| R1 | Requester C opens /rfqs | no menu entry, 403 on the API (quotes are buyer-only) |
| R2 | A opens the approved requisition and chooses Request quotations with S1 only and a closing date | RFQ created as draft |
| R3 | A tries to send it | refused until a sole-source reason of 10+ characters is given; then opens |
| R4 | A tries to cancel the requisition | refused (`requisition_has_rfq`) |
| R5 | A records S1 priced on both lines, S2 on one line only, S3 declined | S2 shown incomplete and not selectable; S3 declined |
| R6 | A records S2 with an expired valid-until date | marked expired, not selectable |
| R7 | A closes the RFQ, then reopens it, then closes again | allowed; quotes cannot be recorded while closed |
| R8 | A awards a quote that is not the lowest without a reason, then with 10+ characters | first refused, second accepted; reason in history and audit |
| R9 | A awards a quote above the requisition total beyond tolerance | refused (`rfq_over_requisition`) |
| R10 | A clicks Create purchase order | draft PO with the winner and quoted prices, linked to the RFQ; a second click is refused |
| R11 | Continue with step 7 of the main walkthrough (submit and approve the order) | unchanged |
