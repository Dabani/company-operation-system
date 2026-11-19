# Bundle 30: requisition cancel with a live RFQ

A purchase requisition can no longer be cancelled while a quotation request for it is draft, open or closed (key `requisition_has_rfq`). Cancel the RFQ first. An awarded RFQ already implies an order flow, which the existing order check covers. Needs bundle 28.
