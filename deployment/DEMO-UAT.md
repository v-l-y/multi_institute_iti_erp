# Persistent backend demo and staff UAT

The isolated persistent demo uses http://127.0.0.1:8082 (API only). It is separate from existing local staging. Run ops/start-demo.ps1 to start/reuse it and ops/check-demo.ps1 -ExerciseRestart to exercise roles, data access, document upload and restart persistence. The check deliberately changes the fictional student's name and adds a marked demo document on each run; it does not delete records.

Operator-only credentials are in the ignored .demo-private/accounts.json file. Six accounts are provisioned: demo-principal, demo-administrator, demo-instructor, demo-student, demo-other-admin and demo-developer. Credentials are randomly generated, hashed with Argon2id and never printed. Do not distribute developer or cross-institute accounts to ordinary staff, expose the demo outside localhost or use real personal data. This demo uses a draft permission policy, not approved production grants.

The seed refuses a populated database. If the ignored credential file is lost, do not reset the database. The operator-only issue-demo-accounts.php command supports credential recovery exclusively for the exact six-account/two-institute fictional fixture, with UAT_DEMO_SEED=1; capture its JSON privately. Recovery changes demo passwords and revokes demo sessions. Do not invoke demo commands against production.

Data volumes: iti-erp-demo_staging_data (database) and iti-erp-demo_staging_private (private files/outbox). Restart and normal compose down retain them. Do not use down -v. Startup does not apply migrations or baseline a fresh migration ledger; future upgrades require the documented reviewed migration process.

## Staff acceptance checklist

Use an API client such as Postman with the local credentials. POST /api/v1/auth/login with username, password and institute_id from the credential file. Keep the returned access_token private and send Authorization: Bearer <token> for the following requests. No frontend has been implemented.

| Scenario | Staff action and acceptance | Human result |
|---|---|---|
| Administrator records | GET /api/v1/students and /api/v1/staff; confirm only institute A records | PENDING |
| Principal review | GET /api/v1/academic/corrections and /api/v1/audit/events; review scope and audit context | PENDING |
| Student access | GET /api/v1/students/me and /api/v1/attendance/records/me; confirm only linked trainee | PENDING |
| Instructor access | GET /api/v1/attendance/records/assigned; confirm assigned batch scope | PENDING |
| Fee view | GET /api/v1/fees/statements/me as student; reconcile sample data with expected demo records | PENDING |
| Profile correction | Administrator POST /api/v1/students/profile/correct with student_id 9101, changes object and reason; confirm change and audit history | PENDING |
| Document | Administrator upload synthetic PDF; student own download; other institute receives 404 | PENDING |
| Internal export | Administrator GET /api/v1/students/export; check register content | PENDING |
| Restart persistence | Run check-demo with ExerciseRestart; confirm corrected student name and document remain | PENDING |
| Complete V1 journeys | Admission-to-completion, fee refunds/reversals and academic review using approved demo scenarios | PENDING |

Record tester name/role, date, actual outcome and issue reference for each scenario. Automated staff-role simulation does not equal a real institute user's acceptance. Test-only source documents are clearly fictional and cannot certify regulatory alignment.
