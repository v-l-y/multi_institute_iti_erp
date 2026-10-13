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
const fixtures = [
  "INSERT INTO institute (id,name,mis_code,address) VALUES (9101,'LOCAL TEST ITI A','LOCAL-TEST-A','Test'),(9102,'LOCAL TEST ITI B','LOCAL-TEST-B','Test');",
  "INSERT INTO global_regulatory_documents (id,authority,document_type,title,reference_no,issued_on,evidence_reference) VALUES (9101,'DGT','local_fixture','LOCAL TEST ONLY','NOT-AN-OFFICIAL-ORDER','2026-01-01','NOT-OFFICIAL');",
  "INSERT INTO regulatory_sources (id,institute_id,global_document_id) VALUES (9101,9101,9101),(9102,9102,9101);",
  "INSERT INTO trades (id,dgt_code) VALUES (9101,'LOCAL-TRADE-TEST');",
  "INSERT INTO global_shifts (id,shift_no,shift_code,display_name,revision_code,source_document_id,effective_from) VALUES (9101,1,'LOCAL-1','Local shift','LOCAL-REV',9101,'2026-01-01');",
  "INSERT INTO global_curriculum_versions (id,trade_id,source_document_id,version_code,duration_months,nsqf_level,unit_strength,effective_from) VALUES (9101,9101,9101,'LOCAL',12,4,20,'2026-01-01');",
  "INSERT INTO global_curriculum_years (id,global_curriculum_id,year_no,trade_practical_minutes,trade_theory_minutes,employability_minutes) VALUES (9101,9101,1,100,100,100);",
  "INSERT INTO curriculum_versions (id,institute_id,trade_id,global_curriculum_id) VALUES (9101,9101,9101,9101);",
  "INSERT INTO curriculum_years (id,institute_id,curriculum_id,global_curriculum_id,global_curriculum_year_id,year_no) VALUES (9101,9101,9101,9101,9101,1);",
  "INSERT INTO trade_units (id,institute_id,trade_id,unit_no) VALUES (9101,9101,9101,1);",
  "INSERT INTO trade_unit_shifts (id,institute_id,trade_unit_id,trade_id,global_shift_id,shift_no,affiliation_status,sanctioned_capacity,valid_from,source_id,approval_reference) VALUES (9101,9101,9101,9101,9101,1,'approved',20,'2026-01-01',9101,'LOCAL-TEST');",
  "INSERT INTO instructor_identities (id,iprn) VALUES (9101,'NOT-OFFICIAL-IPRN-TEST');",
  "INSERT INTO staff (id,institute_id,employee_code,full_name,staff_category,designation,joined_on,identity_id) VALUES (9101,9101,'T-A','Local Instructor','teaching','Instructor','2026-01-01',9101),(9102,9102,'T-B','Other Institute Staff','non_teaching','Clerk','2026-01-01',NULL);",
  "INSERT INTO instructor_mappings (id,institute_id,staff_id,mapping_type,identity_id,trade_unit_shift_id,valid_from,qualification_source_id,qualification_check_json,qualification_checked_by,nims_reference) VALUES (9101,9101,9101,'trade',9101,9101,'2026-01-01',9101,'{}','LOCAL','NOT-OFFICIAL');",
  "INSERT INTO cts_training_calendars (id,admission_year,session_code,revision_code,source_document_id,training_starts_on,training_ends_on) VALUES (9101,2026,'LOCAL','LOCAL',9101,'2026-08-01','2027-07-01');",
  "INSERT INTO cts_portal_shift_mappings (id,calendar_id,session_part,physical_shift_no,portal_shift_no,document_section_reference) VALUES (9101,9101,'primary',1,1,'LOCAL TEST ONLY');",
  "INSERT INTO admission_intakes (id,institute_id,admission_year,calendar_id,intake_code,session_part) VALUES (9101,9101,2026,9101,'LOCAL-PRIMARY','primary');",
  "INSERT INTO admission_intake_revisions (id,institute_id,intake_id,source_id,revision_code,opens_on,closes_on,approval_reference) VALUES (9101,9101,9101,9101,'LOCAL','2026-01-01','2026-12-31','LOCAL-TEST');",
  "INSERT INTO seat_matrix_versions (id,institute_id,admission_year,intake_id,version_code,source_id,portal_reference) VALUES (9101,9101,2026,9101,'LOCAL',9101,'NOT-OFFICIAL');",
  "INSERT INTO seat_matrix_entries (id,institute_id,version_id,intake_id,trade_unit_shift_id,calendar_id,session_part,physical_shift_no,portal_shift_no,seat_capacity,admission_allowed,instructor_mapping_id,instructor_mapping_satisfied,portal_itsu_reference) VALUES (9101,9101,9101,9101,9101,9101,'primary',1,1,20,1,9101,1,'LOCAL-TEST');",
  "INSERT INTO global_admission_rule_versions (id,trade_id,global_curriculum_id,source_document_id,admission_window_document_id,version_code,minimum_age_years,age_cutoff_date,qualification_routes_json,admission_opens_on,admission_closes_on) VALUES (9101,9101,9101,9101,9101,'LOCAL',14,'2026-08-01','{}','2026-01-01','2026-12-31');",
  "INSERT INTO admission_rules (id,institute_id,trade_id,curriculum_id,global_curriculum_id,global_admission_rule_id) VALUES (9101,9101,9101,9101,9101,9101);",
  "INSERT INTO batches (id,institute_id,batch_code,trade_unit_shift_id,trade_id,curriculum_id,seat_matrix_entry_id,admission_rule_id,admission_year,intake_id,calendar_id,starts_on,ends_on,capacity) VALUES (9101,9101,'LOCAL-BATCH',9101,9101,9101,9101,9101,2026,9101,9101,'2026-08-01','2027-07-01',20);",
  "INSERT INTO students (id,institute_id,local_student_code,full_name,date_of_birth,gender,address,identity_mode,identity_evidence_reference) VALUES (9101,9101,'S-A','Local Student A','2005-01-01','other','LOCAL','non_aadhaar','LOCAL-NOT-OFFICIAL'),(9102,9102,'S-B','Local Student B','2005-01-01','other','LOCAL','non_aadhaar','LOCAL-NOT-OFFICIAL');",
  "INSERT INTO student_qualifications (id,institute_id,student_id,level_code,board_name,passing_year,certificate_reference,subject_results_json,verified_by,verified_on) VALUES (9101,9101,9101,'10','LOCAL',2024,'LOCAL','{}','LOCAL','2026-07-01'),(9102,9102,9102,'10','LOCAL',2024,'LOCAL','{}','LOCAL','2026-07-01');",
  "INSERT INTO enrollments (id,institute_id,student_id,batch_id,intake_id,admission_intake_revision_id,admission_number,admitted_on,admission_rule_id,qualification_id,qualification_route_code,qualification_check_json,qualification_check_passed,checked_by,checked_on) VALUES (9101,9101,9101,9101,9101,9101,'LOCAL-ADM','2026-08-01',9101,9101,'LOCAL','{}',1,'LOCAL','2026-08-01');",
  "INSERT INTO batch_years (id,institute_id,batch_id,curriculum_id,curriculum_year_id,calendar_id,starts_on,ends_on,attendance_basis,attendance_basis_source_id) VALUES (9101,9101,9101,9101,9101,9101,'2026-08-01','2027-07-01','minutes',9101);",
  "INSERT INTO attendance_sessions (id,institute_id,batch_year_id,batch_id,session_date,component,scheduled_minutes,instructor_id) VALUES (9101,9101,9101,9101,'2026-09-01','trade_theory',60,9101);",
  "INSERT INTO attendance_records (institute_id,session_id,batch_id,enrollment_id,attended_minutes,status,recorded_by) VALUES (9101,9101,9101,9101,40,'present','LOCAL');",
  "INSERT INTO global_reservation_policies (id,admission_year,version_code,source_document_id,policy_json) VALUES (9101,2026,'LOCAL',9101,'{}');",
  "INSERT INTO reservation_policies (id,institute_id,admission_year,global_reservation_policy_id) VALUES (9101,9101,2026,9101);",
  "INSERT INTO batch_quotas (id,institute_id,batch_id,admission_year,policy_id,category_code,quota_type,seats,eligibility_condition_json) VALUES (9101,9101,9101,2026,9101,'LOCAL-V','vertical',10,'{}'),(9102,9101,9101,2026,9101,'LOCAL-H','horizontal',10,'{}');",
  "INSERT INTO fee_transactions (id,institute_id,student_id,enrollment_id,fee_head,transaction_type,amount,paid_on,receipt_no,payment_mode,recorded_by) VALUES (9101,9101,9101,9101,'tuition','payment',100,'2026-08-02','LOCAL-FEE-1','cash','LOCAL'),(9102,9101,9101,9101,'tuition','refund',20,'2026-08-03','LOCAL-FEE-2','cash','LOCAL');"
];
// Replace the refund fixture with a legal reference to the original payment:
fixtures[fixtures.length - 1] = "INSERT INTO fee_transactions (id,institute_id,student_id,enrollment_id,fee_head,transaction_type,amount,paid_on,receipt_no,payment_mode,reverses_transaction_id,recorded_by) VALUES (9101,9101,9101,9101,'tuition','payment',100,'2026-08-02','LOCAL-FEE-1','cash',NULL,'LOCAL'),(9102,9101,9101,9101,'tuition','refund',20,'2026-08-03','LOCAL-FEE-2','cash',9101,'LOCAL');";

// Authentication fixtures validate database identities, not HTTP login or password verification.
fixtures.push(
  "INSERT INTO auth_users (id,institute_id,username,password_hash) VALUES (9201,NULL,'local-dev','NOT-A-REAL-PASSWORD-HASH'),(9202,9101,'local-instructor','NOT-A-REAL-PASSWORD-HASH'),(9203,9101,'local-student','NOT-A-REAL-PASSWORD-HASH'),(9204,9102,'local-staff-b','NOT-A-REAL-PASSWORD-HASH');",
  "INSERT INTO auth_staff_links (institute_id,user_id,staff_id) VALUES (9101,9202,9101),(9102,9204,9102);",
  "INSERT INTO auth_student_links (institute_id,user_id,student_id) VALUES (9101,9203,9101);",
  "INSERT INTO auth_user_roles (institute_id,user_id,role_id,role_code,scope) VALUES (NULL,9201,(SELECT id FROM auth_roles WHERE code='developer'),'developer','platform');",
  "INSERT INTO auth_user_roles (institute_id,user_id,role_id,role_code,scope,staff_link_user_id) VALUES (9101,9202,(SELECT id FROM auth_roles WHERE code='instructor'),'instructor','institute',9202);",
  "INSERT INTO auth_user_roles (institute_id,user_id,role_id,role_code,scope,student_link_user_id) VALUES (9101,9203,(SELECT id FROM auth_roles WHERE code='student'),'student','institute',9203);"
);

before(() => {
  if (!enabled) return;
  // Safety: refuse to run potentially destructive SQL against any remote DB or user-named DB.
  assert.ok(['127.0.0.1', 'localhost', '::1'].includes(host), 'Runtime runner accepts local host only');
  assert.match(port, /^\d{2,5}$/, 'Invalid local MySQL port');
  createDatabase(database);
  sql(schema);
  insertFixture(fixtures);
});
after(() => {
  if (!enabled) return;
  for (const name of [...created].reverse()) dropDatabase(name);
});

test('fresh SQL imports into a disposable database with 81 tables and zero triggers', { skip }, () => {
  assert.equal(Number(scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE';")), 81);
  assert.equal(Number(scalar("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE();")), 0);
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

test('backup/restore: dumped schema and fixtures survive a separate temporary restore', { skip }, () => {
  createDatabase(restored);
  const dump = cli(dumpBin, ['--single-transaction','--skip-lock-tables','--no-tablespaces',database], undefined);
  assert.ok(dump.includes('CREATE TABLE'), 'mysqldump did not include schema definitions');
  sql(dump, restored);
  for (const table of ['institute','staff','students','enrollments','fee_transactions']) {
    assert.equal(scalar('SELECT COUNT(*) FROM ' + table + ';', restored), scalar('SELECT COUNT(*) FROM ' + table + ';'), table + ' row count mismatch after restore');
  }
});
