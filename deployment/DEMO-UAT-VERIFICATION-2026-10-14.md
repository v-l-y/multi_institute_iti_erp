# Persistent backend demo verification — 14 October 2026

Result: persistent demo and automated staff-role simulation PASS. Human staff acceptance PENDING.

Endpoint: http://127.0.0.1:8082/api/v1/health. API only; no frontend.
Docker project: iti-erp-demo. It reuses the staging compose definition with independent project-scoped database/private-file volumes and a separate ignored environment file. Existing iti-erp-staging was not modified.

## Evidence

- ops/start-demo.ps1 created the separate environment and fictional two-institute fixture with six random-password accounts; repeat startup retained existing data and credentials.
- ops/check-demo.ps1 -ExerciseRestart passed 37 HTTP/persistence assertions after correcting two test-script endpoint/field assumptions. No application defect was established by those script failures.
- Principal, administrator, instructor, student, other-institute administrator and platform developer authenticated. Student and developer broad business-data reads were denied; cross-institute fee/document reads returned 404.
- Staff/student/assigned attendance/formative/fee read routes and Principal audit route returned expected responses.
- Administrator profile correction, internal CSV and private synthetic PDF upload/download were exercised against Apache with its runtime database user.
- Database and backend were restarted. The corrected student's name and identical downloaded document content remained present. A fresh post-restart login succeeded.
- Runtime privilege checker passed: application data access without DDL or grant authority.
- Schema preflight: 99/99 application tables, 74/74 triggers, 283 foreign keys, 165 checks; no missing columns, tables or triggers. Fresh database has no migration ledger; future populated upgrades need reviewed baseline preparation.
- Both demo CLI files passed PHP syntax checks. A repeated seed with explicit demo authorization exited 1 and refused the populated database without resetting it.
- Credentials/settings were moved to .demo-private, excluded from Git; a tracked Apache .htaccess denies HTTP access. XAMPP was not reachable, so its directory-denial behavior was not exercised. The Docker API serves only backend/public; these root files are outside its public directory.
- Initial Docker credential stdout capture failed after successful seed. Exact-demo guarded credential recovery issued new random demo passwords, revoked existing demo sessions and saved credentials privately. No credentials were printed.

## Operator handoff

Credentials: .demo-private/accounts.json. Settings: .demo-private/settings.env. Do not publish or commit these files. Manual role scenarios and acceptance table: deployment/DEMO-UAT.md. Demo commands mutate fictional data only; each check adds a retained synthetic document.

Human testers must record their actual results, identities and acceptance. These automated checks do not establish staff sign-off, all 12 frozen modules, real regulatory applicability, backup/restore acceptance, load capacity or production readiness. The environment is left running for local API-based staff testing.
