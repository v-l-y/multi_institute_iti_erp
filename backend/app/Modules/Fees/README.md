# Fees module

`Http/FeeController.php` handles API validation and authorization; `Application/FeeService.php` retains audited transaction logic; `Domain/Money.php` parses exact monetary values; `Infrastructure/FeePaymentRepository.php` implements institute- and own-student-scoped payment reads. No fee rules or default amounts were introduced.

Payment reversal requires two authenticated steps. The original payment recorder posts `payment_id`, `reversal_no` and `reason` to `/api/v1/fees/payments/reversal-request`. A different user then posts the same values plus the recorder's `requested_by` ID to `/api/v1/fees/payments/reverse`. Both require `fee.refund`; approval must match the latest immutable request stored in `institute_activity_log`. No schema migration is required. Existing approval clients must perform the request step first.

Duplicate payment or receipt numbers with a new idempotency key return `PAYMENT_IDENTIFIER_EXISTS` (HTTP 409); an equivalent retry with the original key still returns the original payment.
