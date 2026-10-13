# Bihar Multi-Institute ITI ERP database

This schema manages Bihar CTS ITI institute records. Institute state is restricted to `BR`; applicable national rules are stored as shared source-backed references. The ERP records official affiliation, portal evidence, results and certificates; official decisions remain with the issuing authority.

## Canonical files

- [iti_erp_schema.sql](iti_erp_schema.sql): fresh, empty-database tables, foreign keys, UNIQUE and CHECK constraints.
- [integrity_guards.sql](integrity_guards.sql): database protection for frozen evidence, immutable financial records and academic row limits. Import after the base schema.
- [rbac_seed.sql](../seeders/rbac_seed.sql): sole source for role inserts, draft permission catalog and draft grants. Review policy before importing; no credentials/accounts are seeded.
- [FEE_MANAGEMENT.md](FEE_MANAGEMENT.md): advanced financial model and legacy-ledger cutover boundaries.
- [FOUNDATION_UPGRADE.md](FOUNDATION_UPGRADE.md): source-backed service rules, local verification and manual migration of the existing database.

The supported Docker engine is MariaDB 10.11. Fresh installations contain 97 tables plus the separately loaded record-protection triggers. Docker loads both SQL files only when its database volume is empty; rebuilding a container does not migrate an existing volume.

## Institute and authority boundaries

Shared masters store official document revisions, trade/curriculum references, training calendars, admission/reservation policies and examination rule versions. No government ages, dates, amounts, quota percentages, syllabus hours or eligibility thresholds are invented or seeded.

Operational records use institute-scoped composite foreign keys. Student/enrollment/qualification, attendance/session/batch, fees/payment/allocation and official-result/certificate links retain the relevant identities. Physical affiliated shifts are separate from calendar-specific portal shift codes.

One staff master serves teaching and non-teaching staff. Employment designation and Principal appointment are separate from login role. NIMS IPRN is stored once in instructor identities and linked to staff/mappings. Five authentication roles use one login model: developer at platform scope; principal, administrator, instructor and student at institute scope. Authorization is enforced in the application; a foreign key does not grant permission.

Local students may be drafted before enrollment. Missing official-export values remain unknown until verified. EWS `NULL` is distinct from verified No. Aadhaar is not fabricated from an identity reference. Upload preparation requires a reviewed calendar profile and applicable annexure; it does not submit to a state-only government endpoint.

## Enforced protection and service rules

Database guards protect published document/rule content, final Seat Matrix entries, issued assessment items, activated structure items, immutable receipts and financial allocations, results/certificates and locked academic records. They reject attendance exceeding the session duration, template scores exceeding maxima and edits to used template items. Published-content revisions and selected supersession transitions preserve history.

Internal admission, fee and exam services perform source applicability and multi-row validations in serialized institute transactions. They enforce matching authenticated actors/permissions and store activity audit records in the business transaction. Admission activation/cancellation reconciles batch/quota counters. Fee operations enforce allocations, reservations, refunds, reversals and waiver limits using exact decimal cents. Exam freezing calculates evidence-backed attendance/formative eligibility and snapshots the applied rule thresholds.

Selected guarded admission and exam service transitions are exposed as institute-scoped HTTP endpoints. Selected FeeService payment/allocation/refund methods are also exposed through scoped HTTP routes. Direct SQL does not automatically invoke financial sums, admission policies or counter reconciliation. All future write routes must call the guarded services; controlled database administration remains necessary.

DST MoUs have explicit industry identity, validity, source evidence and same-trade/unit/institute coverage. Local activation checks MoU expiry and third-shift base-unit limits; official portal status changes remain official authority workflows.

## Remaining application work

- Reviewed official-source loading and handling conflicting/later orders.
- Approval/correction/revision user flows with clear role policies.
- Admission-route applicability, subject evidence and quota category assessment backed by the applicable Bihar policy.
- Remaining business API/UI integration using the internal transaction services (including fees, approval/correction, attendance and official-result workflows).
- Official portal duplicate checks and acceptance evidence, including state-authorized upload boundaries.
- Legacy fee data migration/report cutover; never count both ledgers.
- Complete official result/certificate correction and import workflows.

Source links and stored evidence are traceability, not regulatory approval. Successful fictional fixture tests do not certify real government records.

## Fresh installation only

### Existing trade revision guard repair

The trade revision update guard permits closing an active revision's validity
when it becomes superseded. It still blocks content edits, invalid end dates,
validity extensions and subsequent edits to historical revisions.
Fresh installations load this corrected guard automatically. For an existing
database, rebuilding the backend does not replace installed triggers. Run
`php bin/export-trade-guard-upgrade.php` from `backend/` to display the narrow
replacement SQL. Back up, review the output, pause writes and apply it using
the MySQL/MariaDB client. The exporter does not connect to or change a database;
the trigger DDL is not transactional. Do not reimport the complete schema.

Import in order:

```sh
mysql -u USER -p multi_institute_iti_erp < database/schema/iti_erp_schema.sql
mysql -u USER -p multi_institute_iti_erp < database/schema/integrity_guards.sql
# After reviewing the authorization policy:
mysql -u USER -p multi_institute_iti_erp < database/seeders/rbac_seed.sql
```

Never import these create-only files into an existing populated database. Use the reviewed upgrade export in [FOUNDATION_UPGRADE.md](FOUNDATION_UPGRADE.md); existing eligibility snapshots must not be backfilled with guessed thresholds.

## Manual verification

Run from the project root:

```powershell
node --test backend/tests/schema-contracts.test.mjs
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from tests
docker compose -f compose.test.yaml down
```

The Docker suite tests fresh import, backend/auth/HTTP regressions, fee SQL, transactional foundation behavior and upload validation in disposable databases. The optional [mysql-runtime.test.mjs](../../tests/mysql-runtime.test.mjs) adds local CLI SQL/backup/concurrency checks; see its header for explicit local-only configuration.

The agent did not run the new tests. Previous passing results predate this hardening.

## Official references

- [DGT Affiliation Norms 2025](https://dgt.gov.in/en/node/3975)
- [DGT CTS-2026 calendar and upload annexures](https://dgt.gov.in/en/node/4394)
- [DGT CTS trade-specific syllabi](https://dgt.gov.in/en/cts-details)
- [DGT Exam Corner](https://dgt.gov.in/en/exam-corner)
- [Bihar BCECEB ITICAT notices](https://bceceboard.bihar.gov.in/ITICATIndex.php)
