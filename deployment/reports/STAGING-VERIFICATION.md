# Local staging verification — 13 October 2026

Target: working changes based on Git revision `6528d7e`; local Docker PHP 8.5
and MariaDB 10.11. These results cover this staging candidate, not a public
production installation or every future ERP feature.

## Fresh evidence

`ops/verify-backend.ps1` completed with exit code 0:

| Check | Passed |
| --- | ---: |
| Schema contracts | 28 |
| Backend contracts | 365 |
| Proxy security | 8 |
| Runtime grants integration | 4 |
| Authentication integration | 75 |
| HTTP integration | 507 |
| Fee SQL integration | 11 |
| Finance lifecycle | 34 |
| Exam cycle integration | 7 |
| Official local imports | 15 |
| Foundation integrity | 102 |
| Upload/decimal validation | 16 |
| Total | 1172 |

New balance tests verify paid and pending refunds, approved waivers,
unallocated credit, reversals, receipt identity snapshots and tenant/owner
isolation. Incomplete syllabus mapping returns a specific 409 and rolls back;
mapping a complete syllabus copies its learning outcomes. Certificate import
syntax now parses and its revision integration suite runs successfully.

`ops/check-staging.ps1 -ExerciseOutage` passed. Local readiness is HTTP 200,
unauthenticated student reads return 401, database outage produces readiness
503 while liveness remains 200, and readiness returns to 200 after recovery.
The final runtime-grant/schema checks passed again after the backend refresh.
The isolated schema has 97 canonical tables and 68 canonical integrity
triggers; no required tables, columns or trigger names are missing. Runtime
grants exclude DDL/grant authority and wildcard/sibling-database access.

Production Compose structure validates with placeholder hostname/proxy values.
No production host, certificate or public deployment was configured. Staging
data is separate and retained; no default account or official data was seeded.

## Remaining acceptance and feature boundaries

Actual populated legacy upgrades, encrypted backup/independent restore,
representative concurrent load, real TLS/monitoring and officer UAT remain
unverified. Presence checks are not an exact schema-definition audit.
No source authenticity or government acceptance is certified by fixture tests.

Locked academic corrections, full student lifecycle transitions, secure
document storage, public password-recovery delivery, PDF/official exports and
live authorized government integrations remain pending scope. Statement and
receipt-data APIs are available; official imports stay disabled by default.
These boundaries must remain visible when choosing which modules to release.
