# Advanced fee foundation

The canonical DDL is the `ADVANCED FEE MANAGEMENT v1` section of `iti_erp_schema.sql`. It adds 13 tables. No fee amounts or government policies are seeded. Adapted from the committed `v-l-y/iti_erp` assessment/demand/allocation model, with composite student/institute foreign keys and institute-scoped financial actors.

## Workflow

1. Configure institute fee heads and versioned trade fee structures. Only one structure per trade/institute can be active. Activated structures and issued assessments must become immutable in the future service.
2. Create a student assessment; snapshot head codes, names and amounts in its items. Link enrollment when available; before enrollment, the service must allow only registration charges.
3. Issue one consolidated demand per assessment. Partial payments are supported by multiple payments and allocations; installment schedules are not implemented.
4. Record payments with immutable receipts, request fingerprints and institute-scoped idempotency keys. Allocate to demands of the same student and institute. Unallocated money remains student credit.
5. Approve waivers and refunds through a different user from the requester. Refunds reference an original allocation and therefore retain its payment, demand, student and institute identity. Reversals void an entire payment once; they do not contain a second arbitrary amount.
6. Record cash movements and a daily closing. Expected cash and differences are generated from the closing snapshot; the service must reconcile the snapshot with that day's source records.

## Required transactional service rules

Internal transactional fee services and database record guards are now added; see [FOUNDATION_UPGRADE.md](FOUNDATION_UPGRADE.md). Guarded financial HTTP routes now call this service; no automatic permission grants were added. Financial writes must use the service; direct SQL does not enforce aggregate sums.

The fee service serializes institute transactions before reading assessment/payment totals. It checks allocation limits, pending/approved/paid refund reservations, waiver limits and refund/reversal conflicts. Reversed payments cease contributing to paid totals and credit. Draft/cancelled assessment/demand allocations fail. Payment/refund retries require matching fingerprints. Further API integration, approval UI and legacy cutover must preserve these invariants.

Gross = assessment item sum. Net due = gross minus approved waivers. Effective paid = allocations from payments without reversal minus paid refunds. Outstanding = net due minus effective paid. Credit = non-reversed payment amount minus its original allocations; an allocated refund is paid out, not restored as credit. Query summaries rather than independently storing editable balance copies. Cash reversals/refunds/transfers must be reconciled on their occurrence dates. Keep audit history, approval authority checks and receipt identity snapshots in the service.

Legacy `fee_transactions`, `fee_payment_balances` and `exam_fee_clearances` remain intact. Existing rows are not silently converted or mirrored. Until a separately reviewed data migration and API cutover, they retain their historical meaning. Reports must explicitly choose a ledger and avoid counting both. New assessment items are snapshots, not references to mutable structure amounts. Exam clearance/payment bridging is future service work.

## Existing database upgrade (manual)

Back up the existing database first. Do not import the complete create-only schema into an existing database and do not remove the Docker volume. Fresh empty databases now receive 97 tables and the separate record-protection module. An already upgraded fee database needs the later foundation migration in [FOUNDATION_UPGRADE.md](FOUNDATION_UPGRADE.md).

For an existing original 81-table database, rebuild the backend image to include the new schema and exporter:

```powershell
docker compose up -d --build --wait
docker compose exec -T backend php bin/export-fee-upgrade.php
```

The second command only displays the 13-table additive SQL. Review it, then apply once:

```powershell
docker compose exec -T backend php bin/export-fee-upgrade.php | docker compose exec -T database sh -c 'exec mariadb --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

The exporter reads the canonical schema so there is no duplicated migration DDL. MariaDB DDL auto-commits: this is not an atomic migration. Do not retry blindly after a partial failure; inspect which tables were created and restore the backup or complete the remaining reviewed statements. An already upgraded database rejects this create-only upgrade. No application data is changed by these CREATE statements.

## Local verification

Tests were written but not executed by the agent, as requested. Run from the repository root:

```powershell
node --test backend/tests/schema-contracts.test.mjs
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from tests
docker compose -f compose.test.yaml down
```

The Docker suite verifies fresh SQL import, backend/auth/HTTP regressions, fee SQL constraints and the new transactional foundation services in disposable databases. Fee checks cover allocation/refund limits, retries, wrong student/institute links, independent approval and cash closing calculations. The opt-in `mysql-runtime.test.mjs` provides additional SQL checks with local mysql/mysqldump; see its header. New runtime test results must be reviewed before production acceptance of financial APIs.

To inspect the existing main database without changing data, first rebuild the application image so it contains the verifier, then run:

```powershell
docker compose up -d --build --wait
docker compose exec -T backend php bin/verify-fee-schema.php
```

This read-only verifier checks all 13 fee tables, expected column names and exact composite foreign-key column identities. Missing tables mean the additive upgrade has not been applied. It does not certify CHECK/UNIQUE definitions, amounts in existing records or service-level aggregate rules.
