# Phase 7e: Event expenses and budget

- The organizer (or manage-all) records expenses of an event: category, description, vendor, date, amount in the event's currency (set the currency on the event; it is required once a budget exists).
- Draft expenses can be edited or deleted. Submitting sends the expense through the approval engine (subject `event_expense`, flow: the event department's head, then the top authority when none is found; the requester can never approve).
- Budget: approved plus pending expenses count as committed. An expense that would exceed the budget is not blocked: it is flagged "over budget" and the approver sees it in the request title. The summary shows budget, approved, pending, remaining and approved spend per category.
- Receipts are Controlled Files (link type `event_expense`, restricted class refused). With `event.expense_receipt_over` > 0 a receipt is required from that amount upwards (amount in whole currency units, default 0 = off).
- Rejected or returned expenses go back to draft with the approver's comment, and the organizer is notified.
- Export (csv/xlsx) needs `data.export` and is audited. Every create, change, submission and decision is audited.

After applying: `composer dump-autoload && php artisan migrate && php artisan db:seed --class=EventsExpensesSeeder && php artisan test --filter=ExpenseRules`
