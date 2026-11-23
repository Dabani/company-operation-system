# COPS Phase 5b - Business trips and expenses (API, bundle 18)

Scope (Guide 5.5): trip request with approval and mission authorization, per diem / accommodation / transport rate tables, advances, booking details,
expenses with receipts, return report, finance review, settlement against the advance, flagging of unretired advances, payroll-deduction consent.
Not in this bundle: web screens (bundle 19), the payroll deduction itself (phase 6), a printable PDF of the mission authorization (the API returns it as data).

## Flow
1. **Request** (`POST trips`): purpose, destination, zone, dates, project, linked meeting, itinerary, budget estimate (explicit currency), travellers (default: me; others only inside my scope), optional advance per traveller (sum <= budget). A traveller already on a pending/approved trip in the period blocks it. Submitted to the approval engine (subject `trip`, amount = budget estimate, attribute `trip_zone`), so approval thresholds are ordinary approval rules. The requester can never approve.
2. **Approval issues the mission authorization** (`MA-...` number, `GET trips/{id}/authorization` returns it as data for printing). Not before.
3. **Bookings** (flight, bus, hotel, vehicle, other) recorded by the requester or finance.
4. **Advance**: finance marks it paid (`paid_on` not in the future). The retirement date = later of trip end and payment date + `trip_advance_retire_days` (HR setting, default 14). `GET trips/unretired-advances` lists late ones; `cops:trips-remind` (daily 08:00) notifies the traveller and finance at most once a week per advance.
5. **Expenses** (approved trip, before the report): date within the trip +-1 day, amount > 0. **Per diem lines are never typed**: `POST trips/{id}/per-diem` creates/refreshes one line per traveller = rate x calendar days from the rate table (grade rate beats general rate; latest valid rate; same currency, no silent conversion). **No rate = no line** and the traveller is listed as `missing_rate`; nothing is guessed.
6. **Receipts**: Controlled Files linked as `trip_expense` (confidential, hidden from the global Files library). Every non-per-diem expense needs one before the report can be submitted.
7. **Report** (`POST trips/{id}/report`): report text + all receipts present -> expenses become `submitted`, trip `report_submitted`.
8. **Finance review** per expense (accept, or reject with a reason). **Settlement** per traveller (`POST trips/{id}/settle`) only when every expense of that traveller is reviewed: balance = accepted - advance paid. Allowed resolutions follow the direction: organization owes -> `paid_to_employee`; even -> `closed_even`; traveller owes -> `refunded_by_employee`, `waived` (reason required) or `payroll_deduction`.
9. **Payroll deduction needs a recorded consent** (date + lawful basis) saved in `hr_trip_deduction_consents`; without it the settlement is refused. Phase 6 must read this table and never deduct without a row. Whether and how much may lawfully be deducted is for HR/legal: this bundle only refuses the undocumented case.
10. When every traveller is settled the trip is `settled`. A trip with a paid advance cannot be cancelled: it is settled.

## Permissions
`hr.trip.request` (staff), `hr.trip.view` (team leader, manager), `hr.trip.approve` (manager, HR), `hr.trip.configure` (HR), `hr.trip.finance` (admin only by default).
**Create a finance role in the Roles screen and give it `hr.trip.finance`**: it is deliberately not given to hr_manager (separation of duties: the person who configures rates does not pay or settle). Finance sees every trip; everyone else sees trips they requested or whose traveller is in their scope (404 otherwise).

## Deployment
1. `python scripts/apply-bundle-18.py <path to cops-api>` (backups in `.bundle-18-backup/`; idempotent; `--dry-run`, `--revert`)
2. `php artisan migrate` then `php artisan db:seed --class=HrTripSeeder` (run the seeder right away: the Files library now hides the `trip_expense` link type it creates)
3. `php artisan test`

## Go-live checklist
1. HR/finance enter the rate tables (nothing is pre-filled) with the decision they come from; to change a rate, end it and enter a new one.
2. Create the finance role; assign it.
3. Decide approval thresholds with ordinary approval rules (subject `trip`, amount, `trip_zone`).
4. Confirm `trip_advance_retire_days` with finance.
5. Check the zones (domestic / regional / international) match the organization's own policy; add or rename through lookups.

## Known issues / decisions
- Per diem is rate x calendar days of travel (both ends included). If the policy counts nights, or pays a reduced first/last day, say so and the calculation changes in one place (`TripMath::perDiem`).
- One currency per trip; rates in another currency are ignored (no conversion).
- Accommodation and transport rates can be stored but only per diem is calculated automatically; they are reference rates for budgets.
- Business trips do not yet mark attendance as "on mission".
- The mission authorization is data (`/authorization`); the printable PDF belongs with the web bundle.
- Feature tests (`tests/Feature/TripTest.php`) were written but **not executed** (no Laravel runtime here); the pure tests (`tests/Unit/TripMathTest.php`, 8 tests) were executed.
- rw and sw texts are first-pass: native review needed.
