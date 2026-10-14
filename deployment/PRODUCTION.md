# Production deployment, recovery and acceptance

This is an **operator-run template**, not a production release certificate.
No domain, actual TLS certificate, secrets provider, external backup destination,
maintenance window or official regulatory data source is provisioned by this code.

## HTTPS perimeter (manually activated)

1. Provision your production domain, CA-signed full chain, private key, and
   renewal procedure. Put files at `deployment/certs/fullchain.pem` and
   `deployment/certs/privkey.pem` (never commit either file).
2. Set `ERP_DOMAIN` to the production DNS hostname in the root `.env` (no scheme or port). The TLS certificate must match it. Find the *specific* Docker bridge CIDR used by the reverse proxy via
   `docker network inspect`. Set `TRUSTED_PROXY_CIDRS` in the root `.env`
   to that network only; never trust arbitrary internet address ranges.
   The application ignores forwarded headers from untrusted peers. nginx
   overwrites incoming `X-Forwarded-For` to prevent client spoofing.
3. Check `docker compose -f compose.yaml -f compose.production.yaml config`
   before deploying. Only on your authorized production host, start with both
   files. nginx listens on 80 (redirect only) and 443; API remains
   host-loopback-only on the existing base Compose port.
4. Verify correct public DNS, TLS chain and hostname, redirect, no public HTTP
   API bypass, and `GET /api/v1/ready` returns HTTP 503 during DB outages.
   `/api/v1/health` is a DB-independent liveness endpoint. The container
   healthcheck now calls readiness rather than liveness.

nginx applies a client-IP login limit shared across PHP backend replicas behind
this **single ingress instance**. Independent proxy replicas still need a
shared limiter at the load balancer or provider edge. The per-PHP-instance
local file limiter is supplemental, not distributed protection.

Configure production secrets outside source control, restrict DB grants,
rotate credentials and protect Docker access. The root `.env` and TLS private
keys are sensitive. External HTTPS and readiness monitoring must page an
operator. Structured 500 logs contain only timestamp, exception class,
basename, line and request ID; not SQL, exception messages, tokens or user
data. Restrict log read access and configure alerts.

The production overlay now installs `production-runtime-grants.sql` on a fresh
empty canonical database: the `iti_erp` runtime identity receives only
SELECT/INSERT/UPDATE/DELETE on the exact application database. It cannot perform
DDL, manage grants or read other databases. Existing volumes do not rerun this
initialization. Review and apply a compatible grant change separately with an
operator account after testing on a restored copy. Migration, RBAC seed imports,
schema audits and backups use separate maintenance credentials.
Run `php bin/verify-runtime-grants.php` in the backend container to check the
runtime scope without exposing raw grants/password hashes. Unexpected roles,
DDL, grant authority, wildcard database scope and missing required data grants
fail closed. Health/normal API reads continue using only the runtime identity.

## Encrypted backup (manual, no scheduler enabled)

The complete paired workflow is `ops/backup-restore-local.ps1`. For an explicitly
authorized production maintenance window, use `-Environment production
-SourceProject YOUR_ACTUAL_COMPOSE_PROJECT` with configured production settings.
It pauses that backend, encrypts SQL plus its `production_private` volume in one
bundle, resumes it in finally, and independently verifies restoration. All other
writers must be quiesced. Demo/staging execution is verified; production execution
requires the actual hosting configuration and owner authorization.

Archive keys stay in ignored `.demo-private/backup-production.key`; protect and
store them separately from the encrypted `backups/` archives. The helper image
provides GnuPG and MariaDB clients; host GPG installation is not required. See
[paired recovery instructions](INSTITUTE-WORKFLOWS.md#paired-recovery).

The older script below is **database-only** and does not provide complete
document recovery. Use it only as a supplemental SQL backup.

Requires Linux/WSL Bash, Docker Compose, GnuPG and sha256sum.
Run from project root after configuring a valid GPG encryption recipient:

```bash
bash ops/backup-database.sh YOUR_GPG_RECIPIENT ./backups
sha256sum -c backups/iti-erp-YYYYMMDDTHHMMSSZ.sql.gpg.sha256
```

The script pipes a consistent MariaDB dump directly into GPG without keeping
a plaintext dump on the host. Transfer encrypted copies and checksums to
independent, access-controlled **off-machine** storage and verify backup
age, integrity and alerting. Schedule and retention policy remain for the
operator to decide, along with maximum acceptable data loss (RPO) and
recovery time (RTO). Store decryption keys separately. No off-machine
transfer, retention job, or automatic scheduler has been installed.

## Isolated restore rehearsal (manual)

```bash
export RESTORE_DRILL_ROOT_PASSWORD='unique-temporary-test-password'
bash ops/restore-drill.sh backups/iti-erp-YYYYMMDDTHHMMSSZ.sql.gpg
```

This creates a unique disposable Docker Compose project without host ports,
streams decrypted SQL into a temporary database, prints table and institute
counts, then removes *only that temporary project's* volume. It does not
modify production data. Document backup age, elapsed restore time, expected
institute/student/fee counts, failed-object checks, and evidence that a
restored application can read real representative records. A successful SQL
import by itself is not a successful production recovery certification.

## Existing populated database

On an isolated restored copy with matching runtime config, run the read-only
`php backend/bin/schema-preflight.php`. It reports missing canonical tables
and integrity triggers and always states that unreviewed upgrades are unsafe.
The results are not a complete schema diff or permission audit.

Review `backend/database/migrations/README.md`,
`backend/database/schema/FEE_MANAGEMENT.md` and
`backend/database/schema/FOUNDATION_UPGRADE.md`.
**Current database baseline is 000.** Former 001–014 changes are consolidated
into the canonical table and integrity-guard files for an empty database.
There are no forward migrations yet. After fresh installation, use the explicit
DDL-verified `--baseline-current` workflow to register `000_canonical_schema`.
Previously installed demo/staging databases and their old ledgers are preserved;
they need a separately reviewed transition. See the migration guide.
Never re-import the create-only canonical schema into populated data, retry
partially committed DDL blindly, or delete live Docker volumes. Review each
transition and rehearse against a restored populated copy, including rollback
by an independently verified backup.

## Manual release gates

Require written evidence for HTTPS validation, secrets and DB grants,
off-machine backup retention, independent restore, versioned DB upgrade,
selected module approval/correction E2E paths, representative concurrent
admissions/fees loads, deadlock handling, outage alerting and restart
recovery. Fee setup, cash-closing review, exam-cycle and local official-record import APIs have been added, but have not passed fresh end-to-end certification. Official imports remain disabled by default; these modules must stay out of a production-enabled release until independent evidence review and UAT verification.

GitHub Actions tests remain **manual workflow_dispatch only**. No automatic
CI, database reset, server deployment, or backup schedule is introduced.

## Opt-in proxy security regression

The new `backend/tests/proxy-security.php` checks untrusted client spoofing, trusted hop selection, malformed headers and IPv4/IPv6 ranges. Run it manually only if testing is requested: `php backend/tests/proxy-security.php`. Production release additionally requires real proxy traffic checks, TLS validation and incident alerting.

## Outstanding release evidence (updated)

The new fee setup/waiver/demand/cash and exam/official local-import paths are code changes, not release certificates. Before enabling real money or candidate records, manually execute fresh full Docker integration tests, audit DB trigger compatibility, check schema upgrade on a **populated restored copy**, run a real encrypted backup plus restore, verify officer approval/correction workflows, monitor requests across proxy replicas, and measure concurrency/recovery behavior. New official imports remain disabled by default. This repository does not have official exports, a configured TLS hostname/certificate, off-machine storage or a trusted production deployment. The new read-only `schema-preflight.php` also lists missing columns and counts live FKs/check constraints; it cannot automatically prove or perform schema upgrades. No numbered forward migrations have been certified.

For cash closing, a separate Principal with `fee.refund` must review each closing via `/api/v1/fees/cash/close/review`; audit acceptance does not remove a recorded cash discrepancy. Manual release verification must include a difference case and independent correction evidence. `TRUSTED_PROXY_CIDRS=0.0.0.0/0` and `::/0` are rejected by the API proxy parser.

## Readiness versus full schema integrity

`GET /api/v1/health` is liveness only. `GET /api/v1/ready` now checks database connectivity and existence of six essential application tables (`institute`, `auth_users`, `fee_payments`, `fee_cash_closings`, `exam_cycles`, `official_results`). It returns 503 if they are missing. It intentionally **does not** read `information_schema.TRIGGERS`: app credentials should not need DDL/trigger metadata privileges just to pass normal readiness probes. Readiness is not a complete regulatory or release proof.

Before release, an authorized operator must run read-only `php backend/bin/schema-preflight.php` with suitable **separate audit credentials** against an isolated restored database. Confirm that all required integrity triggers, columns, foreign keys and checks are present, not merely that application tables exist. The manually invoked HTTP integration suite additionally checks that removing a critical table makes readiness return 503; it does not touch a live database.
