# Local staging and backend release verification

From the repository root in PowerShell:

```powershell
./ops/verify-backend.ps1
./ops/start-staging.ps1
./ops/check-staging.ps1 -ExerciseOutage
```

Verification uses a unique disposable Compose project and runs syntax, schema,
proxy security, authentication, HTTP, fee lifecycle, exam cycle, official import,
foundation and upload checks. Any check failure fails the command. Cleanup never
removes application/staging volumes. Docker Desktop and Node.js are required;
host PHP is not required. CI remains manually triggered.

Staging is persistent and loopback-only at http://127.0.0.1:8081/api/v1/ready.
`start-staging.ps1 -Port 8082` selects a different port on first initialization.
Generated independent database passwords are stored in ignored `.staging.env`.
Existing settings are preserved; protect that file and Docker access. Never
reuse its credentials for production. No fictional institute, student, official
approval or default login is created. Official record imports remain disabled.
The runtime database identity has SELECT/INSERT/UPDATE/DELETE only; use separate
operator credentials for schema maintenance. Grant initialization runs only on
an empty staging volume; it does not migrate existing volumes.

`check-staging.ps1` verifies HTTP access boundaries, runtime grants and schema
presence. `-ExerciseOutage` stops only the staging database, checks readiness 503
and liveness 200, then restarts it in a finally block and verifies recovery.
Do not run that option during someone else's UAT session. Runtime grants are
checked without printing raw grants or authentication hashes. The check fails
for administrator/DDL/grant privileges or privileges on other databases.
Database name underscores are escaped in grants to prevent wildcard scope;
see [MariaDB GRANT documentation](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant).

Read-only schema audit with the separate audit container:

```powershell
docker compose -p iti-erp-staging --env-file .staging.env -f compose.staging.yaml --profile audit run --rm schema-audit
```

Review the draft RBAC policy before importing `backend/database/seeders/rbac_seed.sql`
into staging using operator credentials. Create the first developer using JSON
on stdin to `php bin/create-developer.php` in the backend container; the existing
authentication README explains this workflow. Do not put passwords in command
arguments or tracked files. Provision test accounts through authorized APIs.

Stop while retaining data:

```powershell
docker compose -p iti-erp-staging --env-file .staging.env -f compose.staging.yaml down
```

Do not use `down -v` unless intentionally deleting staging data. A container
rebuild does not upgrade installed triggers. Existing databases require a
reviewed migration/recovery rehearsal. See PRODUCTION.md for public deployment,
encrypted off-machine backups, TLS, UAT and production acceptance gates.

## Staging acceptance

- Automated suite green for the exact source being released.
- Ready responds 200 with the restricted runtime identity.
- Unauthenticated business routes return 401.
- Schema audit finds no missing canonical tables, columns or integrity triggers.
- Restart retains staging data; outage makes ready return 503.
- API-level UAT uses reviewed real source data and independent officer approvals.

The automated suite uses fictional disposable fixtures; it does not establish
official record authenticity. Locked academic corrections, public recovery
delivery, document storage and official-format exports remain separately scoped
features. This staging setup is not a claim that every possible ERP module or
populated legacy upgrade is complete.
