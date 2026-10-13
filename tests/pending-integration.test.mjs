// Explicit incomplete coverage. TODO is never counted as PASS.
// This repository contains a create-only SQL schema, NOT an auth/API implementation,
// official regulatory evidence loader, migration runner or production app.
import { test } from 'node:test';

test.todo('migration runner: upgrade populated databases with history/checksum and rollback');
test.todo('seed runner: load verified official DGT/NCVET/Bihar source values without fake regulatory defaults');
test.todo('authentication HTTP: password hashing, login lockout, session rotation, reset, logout');
test.todo('RBAC HTTP: enforce developer/principal/administrator/instructor/student access across institutes');
test.todo('admission HTTP lifecycle: published seat matrix, age/qualification evidence, approvals and cancellations');
test.todo('attendance-to-exam calculation: authoritative working days, formative marks and real eligibility reconciliation');
test.todo('seat admission concurrency: simultaneous enrollment/cancellation with transactional counters and quota locks');
test.todo('official OJT, AITT, results and certificate evidence: governed end-to-end imports and immutable revisions');
test.todo('real backup disaster drill: restore and reconcile a populated app database in a separate isolated environment');
