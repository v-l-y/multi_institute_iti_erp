// Static schema contracts. Run: node --test tests/schema-contracts.test.mjs
// These structural checks do not replace a MySQL/MariaDB SQL import or runtime tests.
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
const sql = readFileSync(new URL('../database/schema/iti_erp_schema.sql', import.meta.url), 'utf8');
const rbacSeed = readFileSync(new URL('../database/seeders/rbac_seed.sql', import.meta.url), 'utf8');
const guards = readFileSync(new URL('../database/schema/integrity_guards.sql', import.meta.url), 'utf8');

test('Bihar foundation retains route evidence, DST coverage and unknown EWS', () => {
  contains('students', 'is_ews TINYINT NULL');
  contains('enrollments', 'FOREIGN KEY(admission_route_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id)');
  contains('dst_agreement_units', 'FOREIGN KEY(agreement_id,trade_id,institute_id)');
  contains('dst_agreement_units', 'FOREIGN KEY(trade_unit_shift_id,trade_id,institute_id)');
  contains('cts_training_calendars', 'upload_profile_json LONGTEXT NULL');
  contains('institute_activity_log', 'FOREIGN KEY(actor_user_id,institute_id) REFERENCES auth_users(id,institute_id)');
});

test('exam snapshots reject contradictory eligibility and excess relaxation', () => {
  contains('exam_eligibility', 'effective_attendance-raw_attendance<=relaxation_cap_as_applied');
  contains('exam_eligibility', 'relaxation_id IS NOT NULL OR effective_attendance=raw_attendance');
  contains('exam_eligibility', "eligibility_status<>'eligible' OR (formative_complete=1 AND effective_attendance>=minimum_attendance_as_applied AND formative_percent>=minimum_formative_as_applied)");
});

test('separate integrity module protects frozen evidence and immutable financial records', () => {
  assert.equal((guards.match(/^END\$\$\s*$/gm) || []).length, (guards.match(/CREATE TRIGGER /g) || []).length, 'each trigger must close with END$$');
  assert.ok(!/^END\$(?!\$)/m.test(guards), 'single-dollar trigger delimiter is invalid');
  for (const trigger of ['global_regulatory_documents_frozen_update','seat_matrix_entries_parent_update','student_fee_assessment_items_parent_update','fee_payments_immutable_update','fee_payment_allocations_immutable_delete','fee_refunds_workflow_update','exam_eligibility_immutable_update','attendance_records_session_update','formative_template_scores_max_insert']) {
    assert.ok(guards.includes('CREATE TRIGGER ' + trigger), 'missing integrity trigger ' + trigger);
  }
});

test('fee demands, allocations and refunds retain student and institute identity', () => {
  contains('student_fee_assessments', 'FOREIGN KEY(enrollment_id,student_id,institute_id) REFERENCES enrollments(id,student_id,institute_id)');
  contains('fee_demands', 'FOREIGN KEY(assessment_id,student_id,institute_id) REFERENCES student_fee_assessments(id,student_id,institute_id)');
  contains('fee_payment_allocations', 'FOREIGN KEY(payment_id,student_id,institute_id) REFERENCES fee_payments(id,student_id,institute_id)');
  contains('fee_payment_allocations', 'FOREIGN KEY(demand_id,student_id,institute_id) REFERENCES fee_demands(id,student_id,institute_id)');
  contains('fee_refunds', 'FOREIGN KEY(allocation_id,demand_id,student_id,institute_id) REFERENCES fee_payment_allocations(id,demand_id,student_id,institute_id)');
  contains('fee_waivers', 'FOREIGN KEY(assessment_id,student_id,institute_id)');
  contains('fee_payment_reversals', 'FOREIGN KEY(payment_id,student_id,institute_id)');
});

test('fee mutations support retry identity and independent approval', () => {
  for (const name of ['fee_payments', 'fee_refunds', 'fee_cash_transfers']) {
    contains(name, 'UNIQUE(idempotency_key,institute_id)');
    contains(name, "CHECK(request_fingerprint REGEXP '^[0-9a-f]{64}$')");
    contains(name, 'CHECK(amount>0)');
  }
  contains('fee_waivers', "CHECK(status<>'approved' OR decided_by<>requested_by)");
  contains('fee_refunds', "CHECK(status NOT IN ('approved','paid') OR decided_by<>requested_by)");
  contains('fee_payment_reversals', 'UNIQUE(payment_id,institute_id)');
  contains('fee_payment_reversals', 'CHECK(requested_by<>approved_by)');
  contains('fee_payments', 'FOREIGN KEY(received_by,institute_id) REFERENCES auth_users(id,institute_id)');
});

test('assessment snapshots and cash reconciliation avoid mutable balance copies', () => {
  contains('student_fee_assessment_items', 'head_code VARCHAR(50) NOT NULL');
  contains('student_fee_assessment_items', 'head_name VARCHAR(120) NOT NULL');
  contains('fee_structures', 'UNIQUE(active_trade_guard,institute_id)');
  contains('fee_cash_closings', 'opening_amount+collected_amount-refunded_amount+added_amount-deposited_amount-withdrawn_amount');
  contains('fee_cash_closings', 'actual_amount-expected_amount');
  assert.ok(!table('fee_demands').includes('balance_amount'));
});

function table(name) {
  const re = new RegExp('CREATE TABLE ' + name + '\\s*\\(([\\s\\S]*?)\\n\\) ENGINE=InnoDB');
  const m = sql.match(re);
  assert.ok(m, 'missing table ' + name);
  return m[1];
}
function contains(name, fragment) {
  assert.ok(table(name).includes(fragment), name + ' missing: ' + fragment);
}
test('expected table/trigger count', () => {
  assert.ok(!/,\s*,/.test(sql), 'schema contains duplicate comma separators');
  assert.equal((sql.match(/CREATE TABLE /g) || []).length, 97);
  assert.equal((sql.match(/CREATE TRIGGER /g) || []).length, 0);
});
test('enrollment qualification cannot refer to another student', () => {
  contains('student_qualifications', 'UNIQUE(id,student_id,institute_id)');
  contains('enrollments', 'FOREIGN KEY(qualification_id,student_id,institute_id)');
});
test('attendance record must match session batch and enrollment batch', () => {
  contains('attendance_sessions', 'FOREIGN KEY(batch_year_id,batch_id,institute_id)');
  contains('attendance_records', 'FOREIGN KEY(session_id,batch_id,institute_id)');
  contains('attendance_records', 'FOREIGN KEY(enrollment_id,batch_id,institute_id)');
});
test('certificates and superseded results share enrollment identity', () => {
  contains('certificates', 'FOREIGN KEY(official_result_id,enrollment_id,institute_id)');
  contains('certificates', 'FOREIGN KEY(supersedes_certificate_id,enrollment_id,document_type,institute_id)');
  contains('official_results', 'FOREIGN KEY(supersedes_result_id,enrollment_id,batch_year_id,cycle_id,institute_id)');
});
test('seat matrix pointer is for the same instructor ITSU and intake', () => {
  contains('seat_matrix_entries', 'FOREIGN KEY(instructor_mapping_id,trade_unit_shift_id,institute_id)');
  contains('seat_matrix_entries', 'FOREIGN KEY(version_id,intake_id,institute_id)');
  contains('batches', 'FOREIGN KEY(seat_matrix_entry_id,trade_unit_shift_id,intake_id,institute_id)');
  contains('seat_matrix_versions', 'FOREIGN KEY(supersedes_id,intake_id,institute_id)');
});
test('practical marks are from the same exam cycle and batch year', () => {
  contains('practical_marks', 'FOREIGN KEY(practical_session_id,cycle_id,batch_year_id,institute_id)');
  contains('practical_marks', 'FOREIGN KEY(eligibility_id,cycle_id,batch_year_id,institute_id)');
});
test('historical ITSU and role revisions have one current identity', () => {
  contains('trade_unit_shifts', 'UNIQUE(trade_unit_id,shift_no,valid_from,institute_id)');
  contains('trade_unit_shifts', 'UNIQUE(current_unit_guard,current_shift_guard,institute_id)');
  contains('auth_user_roles', 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY');
  contains('auth_user_roles', 'UNIQUE(current_user_guard,current_role_guard,tenant_key)');
});
test('calendar sources and certified day register versions are traceable', () => {
  contains('training_calendar_entries', 'calendar_id BIGINT UNSIGNED NOT NULL');
  contains('training_calendar_entries', 'FOREIGN KEY(batch_year_id,calendar_id,institute_id) REFERENCES batch_years(id,calendar_id,institute_id)');
  contains('working_day_attendance', 'UNIQUE(current_enrollment_guard,current_batch_year_guard,current_date_guard,institute_id)');
});
test('seat matrix and intake revisions retain relational constraints without deferred guards', () => {
  contains('seat_matrix_entries', 'FOREIGN KEY(version_id,intake_id,institute_id)');
  contains('seat_matrix_entries', 'FOREIGN KEY(trade_unit_shift_id,physical_shift_no,institute_id) REFERENCES trade_unit_shifts(id,shift_no,institute_id)');
  contains('admission_intake_revisions', 'FOREIGN KEY(intake_id,institute_id)');
  contains('enrollments', 'FOREIGN KEY(admission_intake_revision_id,intake_id,institute_id)');
  assert.ok(!sql.includes('CREATE TRIGGER '), 'DATA-INTEGRITY GUARDS are deferred');
});

test('five canonical authentication roles have correct scope and identity contracts', () => {
  const roles = table('auth_roles');
  for (const code of ['developer', 'principal', 'administrator', 'instructor', 'student']) {
    assert.ok(roles.includes("'" + code + "'"), 'auth_roles missing ' + code);
    assert.ok(rbacSeed.includes("'" + code + "'"), 'role seeder missing ' + code);
  }
  assert.ok(!roles.includes("'administration'"), 'legacy administration role should be renamed');
  assert.ok(!/INSERT\s+INTO\s+auth_roles\b/i.test(sql), 'role inserts belong in the seeder');
  contains('auth_roles', "scope ENUM('platform','institute') NOT NULL");
  contains('auth_roles', "code='developer' AND scope='platform'");
  contains('auth_users', 'institute_id BIGINT UNSIGNED NULL');
  contains('auth_users', 'UNIQUE(username,tenant_key)');
  contains('auth_users', 'UNIQUE(id,tenant_key)');
  contains('auth_user_roles', 'FOREIGN KEY(user_id,tenant_key) REFERENCES auth_users(id,tenant_key)');
  contains('auth_user_roles', 'FOREIGN KEY(role_id,role_code,scope) REFERENCES auth_roles(id,code,scope)');
  contains('auth_user_roles', 'FOREIGN KEY(staff_link_user_id,institute_id) REFERENCES auth_staff_links(user_id,institute_id)');
  contains('auth_user_roles', 'FOREIGN KEY(student_link_user_id,institute_id) REFERENCES auth_student_links(user_id,institute_id)');
  contains('auth_user_roles', 'student_link_user_id IS NOT NULL AND student_link_user_id=user_id');
  contains('staff', "staff_category ENUM('teaching','non_teaching') NOT NULL");
  contains('staff', 'designation VARCHAR(120) NOT NULL');
  contains('staff', "principal_assignment ENUM('none','principal','acting_principal') NOT NULL DEFAULT 'none'");
  contains('staff', "principal_assignment IN ('principal','acting_principal')");
  contains('staff', 'CHECK(CHAR_LENGTH(TRIM(designation))>0)');
  assert.ok(!table('staff').includes('role ENUM('), 'Staff designation must be separate from login role');
  contains('auth_user_roles', "role_code IN('principal','administrator','instructor')");
  contains('auth_user_roles', 'staff_link_user_id IS NOT NULL AND staff_link_user_id=user_id');
  contains('auth_sessions', 'UNIQUE(session_token_hash)');
  contains('auth_password_resets', 'UNIQUE(token_hash)');
  contains('auth_audit_log', 'FOREIGN KEY(user_id,tenant_key) REFERENCES auth_users(id,tenant_key)');
  contains('auth_user_permissions', 'institute_id BIGINT UNSIGNED NOT NULL');
});
test('NIMS IPRN is canonical and staff mapping uses the same institute identity', () => {
  contains('instructor_identities', 'iprn VARCHAR(120) NOT NULL UNIQUE');
  contains('staff', 'identity_id BIGINT UNSIGNED NULL');
  assert.ok(!table('staff').includes('iprn VARCHAR('), 'staff must not duplicate instructor IPRN');
  contains('staff', 'UNIQUE(id,identity_id,institute_id)');
  contains('staff', 'identity_id IS NOT NULL AND verification_reference IS NOT NULL');
  contains('instructor_mappings', 'FOREIGN KEY(staff_id,identity_id,institute_id) REFERENCES staff(id,identity_id,institute_id)');
});

test('government exam policy flags require explicit source-backed values', () => {
  for (const field of ['requires_prn', 'requires_cbt_fee', 'requires_practical_fee', 'requires_ojt_for_certificate']) {
    contains('global_exam_rule_versions', field + ' TINYINT NOT NULL');
    assert.ok(!table('global_exam_rule_versions').includes(field + ' TINYINT NOT NULL DEFAULT'), field + ' has an assumed default');
  }
  contains('global_exam_rule_versions', 'principal_relaxation_cap DECIMAL(5,2) NOT NULL');
});

test('pre-admission registration fees stay linked to student and within institute', () => {
  contains('fee_transactions', 'student_id BIGINT UNSIGNED NOT NULL');
  contains('fee_transactions', 'enrollment_id BIGINT UNSIGNED NULL');
  contains('fee_transactions', "CHECK(enrollment_id IS NOT NULL OR fee_head='registration')");
  contains('enrollments', 'UNIQUE(id,student_id,institute_id)');
  contains('fee_transactions', 'FOREIGN KEY(enrollment_id,student_id,institute_id) REFERENCES enrollments(id,student_id,institute_id)');
  contains('fee_transactions', 'FOREIGN KEY(reverses_transaction_id,student_id,institute_id) REFERENCES fee_transactions(id,student_id,institute_id)');
});

test('institute contact and issuer-vs-portal identity are not invented mandatory rules', () => {
  contains('institute', 'district_name VARCHAR(120) NULL');
  contains('institute', 'management_type VARCHAR(80) NULL');
  contains('institute', 'contact_phone VARCHAR(20) NULL');
  contains('institute', 'contact_email VARCHAR(190) NULL');
  contains('global_regulatory_documents', "authority ENUM('DGT','NCVET','STATE_UT','SSDEC') NOT NULL");
  contains('global_regulatory_documents', "source_system ENUM('SIDH','NCVT_MIS','NIMS') NULL");
});

test('active instructor mapping cannot bypass the staff IPRN FK with NULL', () => {
  contains('instructor_mappings', "CHECK(status<>'active' OR identity_id IS NOT NULL)");
  contains('instructor_mappings', 'FOREIGN KEY(staff_id,identity_id,institute_id) REFERENCES staff(id,identity_id,institute_id)');
});

test('seat matrix portal shift is bound to official calendar intake and physical shift', () => {
  contains('cts_portal_shift_mappings', 'UNIQUE(calendar_id,session_part,physical_shift_no,portal_shift_no)');
  contains('admission_intakes', 'UNIQUE(id,calendar_id,session_part,institute_id)');
  contains('trade_unit_shifts', 'UNIQUE(id,shift_no,institute_id)');
  contains('seat_matrix_entries', 'calendar_id BIGINT UNSIGNED NOT NULL');
  contains('seat_matrix_entries', "session_part ENUM('primary','six_month_second') NOT NULL");
  contains('seat_matrix_entries', 'physical_shift_no TINYINT UNSIGNED NOT NULL');
  contains('seat_matrix_entries', 'FOREIGN KEY(intake_id,calendar_id,session_part,institute_id) REFERENCES admission_intakes(id,calendar_id,session_part,institute_id)');
  contains('seat_matrix_entries', 'FOREIGN KEY(trade_unit_shift_id,physical_shift_no,institute_id) REFERENCES trade_unit_shifts(id,shift_no,institute_id)');
  contains('seat_matrix_entries', 'FOREIGN KEY(calendar_id,session_part,physical_shift_no,portal_shift_no) REFERENCES cts_portal_shift_mappings(calendar_id,session_part,physical_shift_no,portal_shift_no)');
});

test('enrollment quota identities cannot swap vertical and horizontal references', () => {
  contains('batch_quotas', 'UNIQUE(id,batch_id,quota_type,institute_id)');
  contains('enrollments', "vertical_quota_type ENUM('vertical','horizontal') NOT NULL DEFAULT 'vertical'");
  contains('enrollments', "horizontal_quota_type ENUM('vertical','horizontal') NOT NULL DEFAULT 'horizontal'");
  contains('enrollments', "CHECK(vertical_quota_type='vertical' AND horizontal_quota_type='horizontal')");
  contains('enrollments', 'FOREIGN KEY(vertical_quota_id,batch_id,vertical_quota_type,institute_id) REFERENCES batch_quotas(id,batch_id,quota_type,institute_id)');
  contains('enrollments', 'FOREIGN KEY(horizontal_quota_id,batch_id,horizontal_quota_type,institute_id) REFERENCES batch_quotas(id,batch_id,quota_type,institute_id)');
});

test('fee payment balances cannot reference refund or reversal transactions', () => {
  contains('fee_transactions', 'UNIQUE(id,transaction_type,institute_id)');
  contains('fee_payment_balances', "payment_transaction_type ENUM('payment','refund','reversal') NOT NULL DEFAULT 'payment'");
  contains('fee_payment_balances', "CHECK(payment_transaction_type='payment')");
  contains('fee_payment_balances', 'FOREIGN KEY(payment_id,payment_transaction_type,institute_id) REFERENCES fee_transactions(id,transaction_type,institute_id)');
});

test('all referenced foreign key tables exist and column counts match', () => {
  const names = new Set([...sql.matchAll(/CREATE TABLE (\w+)/g)].map(x => x[1]));
  for (const [name] of [...names].map(n => [n])) {
    const ddl = table(name);
    for (const fk of ddl.matchAll(/FOREIGN KEY\(([^)]*)\) REFERENCES (\w+)\(([^)]*)\)/g)) {
      assert.ok(names.has(fk[2]), name + ' references unknown ' + fk[2]);
      assert.equal(fk[1].split(',').length, fk[3].split(',').length, name + ' invalid FK arity');
    }
  }
});

// Regression checks for the re-review fixes; runtime coverage is still required.
test('role identity CHECK uses boolean branches without comma separators', () => {
  assert.ok(!/\),\s*OR\b/.test(table('auth_user_roles')));
});
test('official trainee payload has fields without guessed regulatory defaults', () => {
  contains('students', 'email VARCHAR(254) NULL');
  contains('students', 'pwd_category VARCHAR(80) NULL');
  contains('enrollments', 'trainee_type VARCHAR(40) NULL');
});
test('absent attendance cannot record positive attended minutes', () => {
  contains('attendance_records', "CHECK(status<>'absent' OR attended_minutes=0)");
});
