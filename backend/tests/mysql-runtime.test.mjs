// Opt-in destructive-on-OWN-TEMP-DB MySQL runtime checks for the create-only schema.
// Requires mysql and mysqldump CLI binaries. Never uses the configured application database.
// Run: ITI_ERP_RUN_MYSQL=1 node --test tests/mysql-runtime.test.mjs
import { readFileSync } from 'node:fs';
import { execFile, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { promisify } from 'node:util';
import { before, after, test } from 'node:test';
import assert from 'node:assert/strict';

const enabled = process.env.ITI_ERP_RUN_MYSQL === '1';
const skip = enabled ? false : 'Set ITI_ERP_RUN_MYSQL=1 and configure local mysql/mysqldump to execute disposable-DB tests';
const schema = readFileSync(new URL('../database/schema/iti_erp_schema.sql', import.meta.url), 'utf8');
const rbacSeed = readFileSync(new URL('../database/seeders/rbac_seed.sql', import.meta.url), 'utf8');
const guards = readFileSync(new URL('../database/schema/integrity_guards.sql', import.meta.url), 'utf8');
const host = process.env.ITI_ERP_TEST_HOST || '127.0.0.1';
const port = process.env.ITI_ERP_TEST_PORT || '3306';
const user = process.env.ITI_ERP_TEST_USER || 'root';
const mysqlBin = process.env.ITI_ERP_MYSQL_BIN || 'mysql';
const dumpBin = process.env.ITI_ERP_MYSQLDUMP_BIN || 'mysqldump';
const database = 'iti_erp_gate_' + process.pid + '_' + randomBytes(5).toString('hex');
const restored = database + '_restore';
const created = new Set();
const execFileAsync = promisify(execFile);

function cli(bin, args, input) {
  const env = { ...process.env };
  if (process.env.ITI_ERP_TEST_PASSWORD !== undefined) env.MYSQL_PWD = process.env.ITI_ERP_TEST_PASSWORD;
  try {
    return execFileSync(bin, [
      '--protocol=TCP', '--host=' + host, '--port=' + port, '--user=' + user,
      ...args,
    ], { input, env, encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 });
  } catch (e) {
    // MySQL CLI reports server error numbers on stderr; preserve them for assertions.
    throw new Error(String(e.stderr || e.stdout || e.message));
  }
}
async function queryConcurrent(statement) {
  const env = { ...process.env };
  if (process.env.ITI_ERP_TEST_PASSWORD !== undefined) env.MYSQL_PWD = process.env.ITI_ERP_TEST_PASSWORD;
  const args = ['--protocol=TCP', '--host=' + host, '--port=' + port, '--user=' + user,
    '--database=' + database, '--batch', '--raw', '--skip-column-names', '--execute=' + statement];
  const output = await execFileAsync(mysqlBin, args, { env, timeout: 120000, maxBuffer: 1024 * 1024 });
  return output.stdout.trim();
}
function sql(statement, db = database) {
  return cli(mysqlBin, [...(db ? ['--database=' + db] : []), '--batch', '--raw', '--skip-column-names'], statement);
}
function scalar(statement, db = database) {
  return sql(statement, db).trim();
}
function insertFixture(statements) {
  // A single client session; all values are explicitly marked as local-only fictitious fixtures.
  sql(statements.join('\n'));
}
function rejectsSql(statement, errorCode) {
  assert.throws(() => sql(statement), e => {
    assert.match(e.message, new RegExp('ERROR ' + errorCode + '\\b'), 'Unexpected SQL failure: ' + e.message);
    return true;
  });
}
function createDatabase(name) {
  assert.match(name, /^iti_erp_gate_[0-9]+_[a-f0-9]{10}(?:_restore)?$/);
  sql('CREATE DATABASE ' + name + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', null);
  created.add(name);
}
function dropDatabase(name) {
  assert.ok(created.has(name), 'Refusing to drop a database not created by this run');
  assert.match(name, /^iti_erp_gate_[0-9]+_[a-f0-9]{10}(?:_restore)?$/);
  sql('DROP DATABASE ' + name + ';', null);
  created.delete(name);
}

// No official regulatory facts, published evidence or government approvals are seeded.
// IDs are fixed only inside this isolated throwaway database for unambiguous negative cases.
const fixtures = readFileSync(new URL('./fixtures/foundation.sql', import.meta.url), 'utf8').split(/;\s*(?:\r?\n|$)/).map(s => s.trim()).filter(Boolean).map(s => s + ';');
before(() => {
  if (!enabled) return;
  // Safety: refuse to run potentially destructive SQL against any remote DB or user-named DB.
  assert.ok(['127.0.0.1', 'localhost', '::1'].includes(host), 'Runtime runner accepts local host only');
  assert.match(port, /^\d{2,5}$/, 'Invalid local MySQL port');
  createDatabase(database);
  sql(schema);
  sql(guards);
  sql(rbacSeed);
  insertFixture(fixtures);
});
after(() => {
  if (!enabled) return;
  for (const name of [...created].reverse()) dropDatabase(name);
});

test('fresh SQL imports into a disposable database with 106 tables and integrity triggers', { skip }, () => {
  assert.equal(Number(scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE';")), 106);
  assert.equal(Number(scalar("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE();")), (guards.match(/CREATE TRIGGER /g) || []).length);
});

test('tenant isolation: staff from institute B cannot teach institute A attendance', { skip }, () => {
  rejectsSql('UPDATE attendance_sessions SET instructor_id=9102 WHERE id=9101;', 1452);
  assert.equal(scalar('SELECT instructor_id FROM attendance_sessions WHERE id=9101;'), '9101');
});

test('tenant isolation: qualification from institute B cannot replace enrollment qualification', { skip }, () => {
  rejectsSql('UPDATE enrollments SET qualification_id=9102 WHERE id=9101;', 1452);
});

test('NIMS mapping: active instructor cannot drop the staff-linked IPRN identity', { skip }, () => {
  rejectsSql('UPDATE instructor_mappings SET identity_id=NULL WHERE id=9101;', '(?:3819|4025)');
});

test('RBAC database identities distinguish platform developer from institute staff and students', { skip }, () => {
  assert.equal(scalar("SELECT COUNT(*) FROM auth_user_roles WHERE scope='platform' AND institute_id IS NULL;"), '1');
  assert.equal(scalar("SELECT COUNT(*) FROM auth_user_roles WHERE scope='institute' AND staff_link_user_id IS NOT NULL;"), '1');
  assert.equal(scalar("SELECT COUNT(*) FROM auth_user_roles WHERE scope='institute' AND student_link_user_id IS NOT NULL;"), '1');
});

test('RBAC: assigning an instructor identity across institute boundaries is rejected', { skip }, () => {
  rejectsSql("INSERT INTO auth_user_roles (institute_id,user_id,role_id,role_code,scope,staff_link_user_id) VALUES (9102,9202,(SELECT id FROM auth_roles WHERE code='instructor'),'instructor','institute',9202);", 1452);
});

test('RBAC: student login role requires actual linked student identity', { skip }, () => {
  rejectsSql("INSERT INTO auth_user_roles (institute_id,user_id,role_id,role_code,scope) VALUES (9101,9202,(SELECT id FROM auth_roles WHERE code='student'),'student','institute');", '(?:3819|4025)');
});

test('seat matrix: incorrect official calendar portal shift is rejected', { skip }, () => {
  rejectsSql('UPDATE seat_matrix_entries SET portal_shift_no=2 WHERE id=9101;', 1452);
  assert.equal(scalar('SELECT portal_shift_no FROM seat_matrix_entries WHERE id=9101;'), '1');
});

test('admission: horizontal quota may not be placed into vertical quota pointer', { skip }, () => {
  rejectsSql('UPDATE enrollments SET vertical_quota_id=9102 WHERE id=9101;', 1452);
  sql('UPDATE enrollments SET vertical_quota_id=9101,horizontal_quota_id=9102 WHERE id=9101;');
  assert.equal(scalar('SELECT CONCAT(vertical_quota_id,\',\',horizontal_quota_id) FROM enrollments WHERE id=9101;'), '9101,9102');
});

test('fees: refunds cannot be referenced as original payment balances', { skip }, () => {
  rejectsSql('INSERT INTO fee_payment_balances (institute_id,payment_id,original_amount,remaining_amount) VALUES (9101,9102,20,20);', 1452);
  sql('INSERT INTO fee_payment_balances (institute_id,payment_id,original_amount,remaining_amount) VALUES (9101,9101,100,80);');
  assert.equal(scalar('SELECT remaining_amount FROM fee_payment_balances WHERE payment_id=9101;'), '80.00');
});

test('attendance: absent record cannot report positive attended minutes', { skip }, () => {
  rejectsSql("UPDATE attendance_records SET status='absent',attended_minutes=5 WHERE session_id=9101 AND enrollment_id=9101;", '(?:3819|4025)');
});

test('attendance: actual recorded minutes can be reconciled to scheduled minutes', { skip }, () => {
  const attendance = scalar('SELECT CONCAT(SUM(r.attended_minutes),\',\',SUM(s.scheduled_minutes)) FROM attendance_records r JOIN attendance_sessions s ON s.id=r.session_id AND s.institute_id=r.institute_id WHERE r.enrollment_id=9101;');
  assert.equal(attendance, '40,60'); // Only the raw evidence, NOT official eligibility logic.
});

test('concurrency: two separate MySQL sessions cannot oversubscribe a capacity-limited batch', { skip }, async () => {
  sql('UPDATE batches SET occupied_seats=capacity-1 WHERE id=9101;');
  const claim = "UPDATE batches SET occupied_seats=occupied_seats+1 WHERE id=9101 AND occupied_seats<capacity; SELECT ROW_COUNT();";
  const results = (await Promise.all([queryConcurrent(claim), queryConcurrent(claim)])).map(Number);
  assert.deepEqual(results.sort(), [0, 1]);
  assert.equal(scalar('SELECT CONCAT(occupied_seats,\',\',capacity) FROM batches WHERE id=9101;'), '20,20');
});


test('advanced fee workflow rejects cross-student allocations, foreign actors and unsafe refunds', { skip }, () => {
  sql("INSERT INTO students (id,institute_id,local_student_code,full_name,date_of_birth,gender,address,identity_mode,identity_evidence_reference) VALUES (9301,9101,'FEE-OTHER','Other same-institute learner','2005-01-01','other','LOCAL','non_aadhaar','LOCAL-NOT-OFFICIAL');");
  sql("INSERT INTO fee_heads (id,institute_id,code,name) VALUES (9301,9101,'LOCAL-TUITION','Local tuition');");
  sql("INSERT INTO fee_structures (id,institute_id,trade_id,version_no,effective_from,created_by) VALUES (9301,9101,9101,1,'2026-01-01',9202);");
  sql("INSERT INTO fee_structure_items (institute_id,structure_id,head_id,amount) VALUES (9101,9301,9301,1000);");
  sql("INSERT INTO student_fee_assessments (id,institute_id,student_id,structure_id,assessment_no,assessed_on,created_by) VALUES (9301,9101,9101,9301,'LOCAL-A','2026-01-01',9202),(9302,9101,9301,9301,'LOCAL-B','2026-01-01',9202);");
  sql("INSERT INTO student_fee_assessment_items (institute_id,assessment_id,head_id,head_code,head_name,amount) VALUES (9101,9301,9301,'LOCAL-TUITION','Local tuition',1000);");
  sql("INSERT INTO fee_demands (id,institute_id,student_id,assessment_id,demand_no,issued_on,due_on) VALUES (9301,9101,9101,9301,'LOCAL-A','2026-01-01','2026-02-01'),(9302,9101,9301,9302,'LOCAL-B','2026-01-01','2026-02-01');");
  const payment = "INSERT INTO fee_payments (id,institute_id,student_id,payment_no,receipt_no,paid_on,amount,payment_mode,idempotency_key,request_fingerprint,received_by) VALUES ";
  sql(payment + "(9301,9101,9101,'LOCAL-P','LOCAL-R','2026-01-01',500,'cash','local-payment',REPEAT('a',64),9202);");
  rejectsSql(payment + "(9302,9101,9101,'OTHER-P','OTHER-R','2026-01-01',100,'cash','other-payment',REPEAT('b',64),9204);", '1452');
  rejectsSql(payment + "(9302,9101,9101,'OTHER-P','OTHER-R','2026-01-01',100,'cash','local-payment',REPEAT('b',64),9202);", '1062');
  rejectsSql("INSERT INTO fee_payment_allocations (institute_id,student_id,payment_id,demand_id,amount) VALUES (9101,9101,9301,9302,500);", '1452');
  sql("INSERT INTO fee_payment_allocations (id,institute_id,student_id,payment_id,demand_id,amount) VALUES (9301,9101,9101,9301,9301,500);");
  const refund = "INSERT INTO fee_refunds (institute_id,student_id,demand_id,allocation_id,refund_no,amount,reason,status,refund_mode,idempotency_key,request_fingerprint,requested_by,decided_by,decided_at,paid_by,paid_at) VALUES ";
  rejectsSql(refund + "(9101,9301,9302,9301,'LOCAL-RF',100,'Local test','pending','cash','local-refund',REPEAT('a',64),9202,NULL,NULL,NULL,NULL);", '1452');
  rejectsSql(refund + "(9101,9101,9301,9301,'LOCAL-RF',100,'Local test','approved','cash','local-refund',REPEAT('a',64),9202,9202,NOW(),NULL,NULL);", '(?:3819|4025)');
  rejectsSql(refund + "(9101,9101,9301,9301,'LOCAL-RF',100,'Local test','paid','cash','local-refund',REPEAT('a',64),9202,9203,NOW(),NULL,NULL);", '(?:3819|4025)');
  sql(refund + "(9101,9101,9301,9301,'LOCAL-RF',100,'Local test','pending','cash','local-refund',REPEAT('a',64),9202,NULL,NULL,NULL,NULL);");
  assert.equal(scalar('SELECT SUM(amount) FROM fee_payment_allocations WHERE payment_id=9301;'), '500.00');
});


test('backup/restore: dumped schema and fixtures survive a separate temporary restore', { skip }, () => {
  createDatabase(restored);
  const dump = cli(dumpBin, ['--single-transaction','--skip-lock-tables','--no-tablespaces',database], undefined);
  assert.ok(dump.includes('CREATE TABLE'), 'mysqldump did not include schema definitions');
  sql(dump, restored);
  for (const table of ['institute','staff','students','enrollments','fee_transactions']) {
    assert.equal(scalar('SELECT COUNT(*) FROM ' + table + ';', restored), scalar('SELECT COUNT(*) FROM ' + table + ';'), table + ' row count mismatch after restore');
  }
});
