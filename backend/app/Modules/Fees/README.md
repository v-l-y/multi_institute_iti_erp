# Fees module

`Http/FeeController.php` handles API validation and authorization; `Application/FeeService.php` retains audited transaction logic; `Domain/Money.php` parses exact monetary values; `Infrastructure/FeePaymentRepository.php` implements institute- and own-student-scoped payment reads. No fee rules or default amounts were introduced.

Payment reversal requires two authenticated steps. The original payment recorder posts `payment_id`, `reversal_no` and `reason` to `/api/v1/fees/payments/reversal-request`. A different user then posts the same values plus the recorder's `requested_by` ID to `/api/v1/fees/payments/reverse`. Both require `fee.refund`; approval must match the latest immutable request stored in `institute_activity_log`. No schema migration is required. Existing approval clients must perform the request step first.

Duplicate payment or receipt numbers with a new idempotency key return `PAYMENT_IDENTIFIER_EXISTS` (HTTP 409); an equivalent retry with the original key still returns the original payment.

## Assessment-backed demand issuance

`POST /api/v1/fees/demands` requires the authenticated institute account with `fee.record`, `assessment_id` (positive integer), `demand_no` (unique institute reference) and a real ISO `due_on` date. It issues at most one demand per previously issued assessment, records a same-institute transaction audit and does not accept a client-provided amount. The amount stays derived from immutable assessment items. Due date must be on or after the server's UTC issue date; duplicate issue/number gives an explicit conflict. This endpoint does not replace the still-missing fee-head, fee-structure, assessment-creation and cash-closing workflows.

## Institute fee setup and cash closure (new local APIs)

The fee setup flow now uses:
`POST /api/v1/fees/heads` (explicit `requires_enrollment` boolean),
`POST /api/v1/fees/structures` (global `trade_id`, version, dates),
`POST /api/v1/fees/structures/items` (head and decimal-string amount),
`POST /api/v1/fees/structures/activate`,
`POST /api/v1/fees/assessments` (student, optional same-institute enrollment, active version, assessment date),
`POST /api/v1/fees/assessments/issue`,
`POST /api/v1/fees/demands`,
`POST /api/v1/fees/payments`, and `POST /api/v1/fees/allocations`.
All mutations use the authenticated institute and existing `fee.record` permission, serialise on the institute row, and write audit evidence in the same transaction. Fee heads and amounts are institute-provided, not assumed to be government fee rules. An activated structure is immutable except that a later version may move it to inactive status, retaining history. Draft assessments snapshot the then-configured heads and monetary values; they cannot be issued if the version ceases to be active.

`POST /api/v1/fees/waivers/request` accepts issued `assessment_id`, unique `waiver_no`, positive decimal-string `amount` and `reason`. `POST /api/v1/fees/waivers/approve` remains a separate `fee.refund` action by someone **other than the requester**; status and pending/approved reservations are checked. Cash adjustments `POST /api/v1/fees/cash/transfers` require `transfer_type` (`bank_deposit`, `cash_withdrawal`, `counter_addition`), exact amount, reference and retry key. `POST /api/v1/fees/cash/close` requires a fully elapsed UTC `closing_date`, explicit opening/actual balance and `opening_reference`. It reconciles that UTC day's cash payments, refunds, payment reversals and cash transfers; stores the database-computed expected balance and the actual-vs-expected difference. A nonzero difference is **recorded**, not deemed approved. It refuses out-of-order and duplicate closings. Before using closing for live finance, independently review opening evidence, bank reconciliation, daily sequence and exception approval.

`backend/tests/fee-lifecycle-integration.php` is an opt-in disposable integration test. It is included only in the **manually launched** Docker test command; no CI push/PR trigger is enabled. No real institute or payment data is needed for fixtures.
