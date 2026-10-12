// Static schema contracts. Run: node --test tests/schema-contracts.test.mjs
// These tests do not replace executing the SQL on MySQL 9.7.
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
const sql = readFileSync(new URL('../database/schema/iti_erp_schema.sql', import.meta.url), 'utf8');
function table(name) {
  const re = new RegExp('CREATE TABLE ' + name + ' \\(([\\s\\S]*?)\\n\\) ENGINE=InnoDB');
  const m = sql.match(re);
  assert.ok(m, 'missing table ' + name);
  return m[1];
}
function contains(name, fragment) {
  assert.ok(table(name).includes(fragment), name + ' missing: ' + fragment);
}
test('expected table/trigger count', () => {
  assert.equal((sql.match(/CREATE TABLE /g) || []).length, 81);
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
  contains('certificates', 'FOREIGN KEY(supersedes_certificate_id,enrollment_id,institute_id)');
  contains('official_results', 'FOREIGN KEY(supersedes_result_id,enrollment_id,institute_id)');
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
  contains('seat_matrix_entries', 'FOREIGN KEY(trade_unit_shift_id,institute_id)');
  contains('admission_intake_revisions', 'FOREIGN KEY(intake_id,institute_id)');
  contains('enrollments', 'FOREIGN KEY(admission_intake_revision_id,intake_id,institute_id)');
  assert.ok(!sql.includes('CREATE TRIGGER '), 'DATA-INTEGRITY GUARDS are deferred');
});

test('five canonical authentication roles have correct scope and identity contracts', () => {
  const roles = table('auth_roles');
  for (const code of ['developer', 'principal', 'administrator', 'instructor', 'student']) {
    assert.ok(roles.includes("'" + code + "'"), 'auth_roles missing ' + code);
    assert.ok(sql.includes("'" + code + "'"), 'role seed missing ' + code);
  }
  assert.ok(!roles.includes("'administration'"), 'legacy administration role should be renamed');
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
  contains('auth_user_roles', 'staff_link_user_id IS NOT NULL AND staff_link_user_id=user_id');
  contains('auth_sessions', 'UNIQUE(session_token_hash)');
  contains('auth_password_resets', 'UNIQUE(token_hash)');
  contains('auth_audit_log', 'FOREIGN KEY(user_id,tenant_key) REFERENCES auth_users(id,tenant_key)');
  contains('auth_user_permissions', 'institute_id BIGINT UNSIGNED NOT NULL');
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
