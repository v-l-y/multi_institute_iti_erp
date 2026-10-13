-- Bihar Multi-Institute ITI ERP — RBAC seed (roles, permissions, role grants)
--
-- Run AFTER database/schema/iti_erp_schema.sql, on the same database.
-- Safe to re-run: roles and permissions are upserted by unique `code`, role
-- grants use INSERT IGNORE on (role_id, permission_id). Existing ids are kept.
--
-- STATUS: DRAFT permission catalog and default grants. The README requires an
-- approved authorization policy before production use, so review every grant
-- below against it. Re-running this file only ADDS grants; it never removes
-- one. To tighten a role, delete the row from auth_role_permissions explicitly.
--
-- Permission naming: <module>.<resource>.<action>[.own|.assigned]
--   * no suffix  : institute-wide within the user's own institute
--   * .assigned  : only batches / sessions / students assigned to that staff
--   * .own       : only the user's own linked record (student)
-- IMPORTANT: a permission code does NOT enforce its scope. The application
-- must always filter by auth_users.institute_id and, for .assigned / .own,
-- by the staff/student link. The database foreign keys do not authorize requests.
--
-- Developer is a platform role: it has NO access to institute business data
-- (students, fees, attendance, results...) by default.
--
-- Not seeded: auth_users / credentials, auth_user_permissions (per-user overrides).

SET NAMES utf8mb4;

-- ---------- Roles ----------
INSERT INTO auth_roles (code, scope, name, description, is_system) VALUES
  ('developer',     'platform',  'Developer',     'ERP technical platform administration, not a government regulator', 1),
  ('principal',     'institute', 'Principal',     'Institute principal account', 1),
  ('administrator', 'institute', 'Administrator', 'Institute administration account', 1),
  ('instructor',    'institute', 'Instructor',    'Institute instructor assigned to staff identity', 1),
  ('student',       'institute', 'Student',       'Institute student linked to learner identity', 1)
ON DUPLICATE KEY UPDATE
  scope       = VALUES(scope),
  name        = VALUES(name),
  description = VALUES(description),
  is_system   = VALUES(is_system);

-- ---------- Permissions ----------
INSERT INTO auth_permissions (code, module, description) VALUES
  ('platform.institute.create', 'platform', 'Create an institute (tenant)'),
  ('platform.institute.update', 'platform', 'Update institute directory details'),
  ('platform.institute.view', 'platform', 'View institute list and details'),
  ('platform.regulatory.manage', 'platform', 'Load and revise shared official masters (documents, trades, curriculum, rules, calendars)'),
  ('platform.user.provision_principal', 'platform', 'Create the first Principal account for an institute'),
  ('platform.user.manage_platform', 'platform', 'Manage platform (developer) accounts'),
  ('platform.audit.view', 'platform', 'View platform-level audit log'),
  ('institute.profile.view', 'institute', 'View own institute profile'),
  ('institute.profile.update', 'institute', 'Update own institute directory details'),
  ('institute.affiliation.manage', 'institute', 'Record institute affiliation history and regulatory source references'),
  ('regulatory.reference.view', 'regulatory', 'View shared official masters (read-only)'),
  ('staff.view', 'staff', 'View staff master'),
  ('staff.manage', 'staff', 'Create and update staff, qualifications and instructor identity links'),
  ('staff.principal_assignment.manage', 'staff', 'Set Principal / Acting Principal assignment on staff'),
  ('instructor.mapping.manage', 'staff', 'Manage instructor mappings and instructor events'),
  ('curriculum.view', 'curriculum', 'View local curriculum references'),
  ('curriculum.manage', 'curriculum', 'Manage local curriculum references'),
  ('tradeunit.view', 'curriculum', 'View trade units, shifts and assets mapping'),
  ('tradeunit.manage', 'curriculum', 'Manage trade units (ITSU) and shifts'),
  ('admission.intake.manage', 'admission', 'Create and revise admission intakes'),
  ('admission.rule.manage', 'admission', 'Select and manage applicable admission rules and reservation policies'),
  ('seatmatrix.view', 'admission', 'View seat matrix versions and entries'),
  ('seatmatrix.draft', 'admission', 'Draft seat matrix versions and entries'),
  ('seatmatrix.publish', 'admission', 'Publish / finalise a seat matrix version'),
  ('batch.view', 'admission', 'View batches, batch years and quotas'),
  ('batch.manage', 'admission', 'Create and manage batches, batch years and quotas'),
  ('batch.assign_instructor', 'admission', 'Assign instructors/subjects to batches'),
  ('calendar.view', 'admission', 'View training calendar entries'),
  ('calendar.manage', 'admission', 'Manage institute training calendar entries'),
  ('student.view', 'student', 'View student records of the institute'),
  ('student.view.assigned', 'student', 'View students of own assigned batches'),
  ('student.view.own', 'student', 'View own student profile'),
  ('student.manage', 'student', 'Create and update student records and qualifications'),
  ('enrollment.view', 'student', 'View enrollments'),
  ('enrollment.manage', 'student', 'Create and change enrollment status'),
  ('attendance.view', 'attendance', 'View attendance for the institute'),
  ('attendance.view.assigned', 'attendance', 'View attendance for own assigned batches'),
  ('attendance.view.own', 'attendance', 'View own attendance'),
  ('attendance.mark.assigned', 'attendance', 'Mark attendance for own assigned sessions'),
  ('attendance.correct', 'attendance', 'Approve attendance corrections and relaxations'),
  ('attendance.correction.request.assigned', 'attendance', 'Request attendance correction for own assigned sessions'),
  ('formative.view', 'assessment', 'View formative assessments'),
  ('formative.view.own', 'assessment', 'View own formative assessment scores'),
  ('formative.record.assigned', 'assessment', 'Record formative scores for own assigned batches'),
  ('formative.template.manage', 'assessment', 'Manage formative templates'),
  ('ojt.view', 'assessment', 'View OJT / project records'),
  ('ojt.view.own', 'assessment', 'View own OJT / project records'),
  ('ojt.log.own', 'assessment', 'Enter own OJT logbook'),
  ('ojt.evaluate.assigned', 'assessment', 'Evaluate OJT / project for own assigned students'),
  ('exam.cycle.manage', 'exam', 'Manage exam cycles and institute exam rule selection'),
  ('exam.eligibility.manage', 'exam', 'Compute and record exam eligibility'),
  ('exam.hallticket.view', 'exam', 'View hall tickets'),
  ('exam.hallticket.view.own', 'exam', 'View own hall ticket'),
  ('exam.practical.record.assigned', 'exam', 'Record practical marks for own assigned sessions'),
  ('exam.practical.manage', 'exam', 'Manage practical sessions and review practical marks'),
  ('exam.portal.submit', 'exam', 'Prepare and record official portal submissions'),
  ('result.view', 'exam', 'View official results'),
  ('result.view.own', 'exam', 'View own official results'),
  ('result.import', 'exam', 'Import official results with portal evidence'),
  ('certificate.view', 'certificate', 'View certificates'),
  ('certificate.view.own', 'certificate', 'View own certificates'),
  ('certificate.record', 'certificate', 'Record certificates issued by the official authority'),
  ('fee.view', 'fee', 'View fee ledger and balances'),
  ('fee.view.own', 'fee', 'View own fee ledger and balance'),
  ('fee.record', 'fee', 'Record fee payments'),
  ('fee.refund', 'fee', 'Record refunds and reversals'),
  ('fee.clearance.manage', 'fee', 'Manage exam fee clearances'),
  ('asset.view', 'asset', 'View inventory assets'),
  ('asset.manage', 'asset', 'Manage inventory assets'),
  ('compliance.view', 'compliance', 'View compliance evidence'),
  ('compliance.manage', 'compliance', 'Upload and manage compliance evidence'),
  ('auth.user.view', 'auth', 'View institute user accounts'),
  ('auth.user.manage_staff_accounts', 'auth', 'Create/disable accounts linked to staff (instructor)'),
  ('auth.user.manage_student_accounts', 'auth', 'Create/disable accounts linked to students'),
  ('auth.user.grant_administrator', 'auth', 'Grant or revoke the Administrator role'),
  ('auth.user.permission_override', 'auth', 'Add per-user permission grants or denies'),
  ('auth.password.reset_user', 'auth', 'Initiate password reset for another user in the institute'),
  ('audit.view', 'auth', 'View institute audit log')
ON DUPLICATE KEY UPDATE
  module      = VALUES(module),
  description = VALUES(description);

-- ---------- Role grants (explicit lists; no wildcards) ----------

-- developer: 7 permissions
INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.code IN (
  'platform.institute.create',
  'platform.institute.update',
  'platform.institute.view',
  'platform.regulatory.manage',
  'platform.user.provision_principal',
  'platform.user.manage_platform',
  'platform.audit.view'
) WHERE r.code = 'developer';

-- principal: 55 permissions
INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.code IN (
  'institute.profile.view',
  'institute.profile.update',
  'institute.affiliation.manage',
  'regulatory.reference.view',
  'staff.view',
  'staff.manage',
  'staff.principal_assignment.manage',
  'instructor.mapping.manage',
  'curriculum.view',
  'curriculum.manage',
  'tradeunit.view',
  'tradeunit.manage',
  'admission.intake.manage',
  'admission.rule.manage',
  'seatmatrix.view',
  'seatmatrix.draft',
  'seatmatrix.publish',
  'batch.view',
  'batch.manage',
  'batch.assign_instructor',
  'calendar.view',
  'calendar.manage',
  'student.view',
  'student.manage',
  'enrollment.view',
  'enrollment.manage',
  'attendance.view',
  'attendance.correct',
  'formative.view',
  'formative.template.manage',
  'ojt.view',
  'exam.cycle.manage',
  'exam.eligibility.manage',
  'exam.hallticket.view',
  'exam.practical.manage',
  'exam.portal.submit',
  'result.view',
  'result.import',
  'certificate.view',
  'certificate.record',
  'fee.view',
  'fee.record',
  'fee.refund',
  'fee.clearance.manage',
  'asset.view',
  'asset.manage',
  'compliance.view',
  'compliance.manage',
  'auth.user.view',
  'auth.user.manage_staff_accounts',
  'auth.user.manage_student_accounts',
  'auth.user.grant_administrator',
  'auth.user.permission_override',
  'auth.password.reset_user',
  'audit.view'
) WHERE r.code = 'principal';

-- administrator: 45 permissions
INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.code IN (
  'institute.profile.view',
  'institute.profile.update',
  'regulatory.reference.view',
  'staff.view',
  'staff.manage',
  'instructor.mapping.manage',
  'curriculum.view',
  'curriculum.manage',
  'tradeunit.view',
  'tradeunit.manage',
  'admission.intake.manage',
  'admission.rule.manage',
  'seatmatrix.view',
  'seatmatrix.draft',
  'batch.view',
  'batch.manage',
  'batch.assign_instructor',
  'calendar.view',
  'calendar.manage',
  'student.view',
  'student.manage',
  'enrollment.view',
  'enrollment.manage',
  'attendance.view',
  'formative.view',
  'formative.template.manage',
  'ojt.view',
  'exam.cycle.manage',
  'exam.eligibility.manage',
  'exam.hallticket.view',
  'exam.practical.manage',
  'result.view',
  'certificate.view',
  'certificate.record',
  'fee.view',
  'fee.record',
  'fee.clearance.manage',
  'asset.view',
  'asset.manage',
  'compliance.view',
  'compliance.manage',
  'auth.user.view',
  'auth.user.manage_staff_accounts',
  'auth.user.manage_student_accounts',
  'auth.password.reset_user'
) WHERE r.code = 'administrator';

-- instructor: 14 permissions
INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.code IN (
  'institute.profile.view',
  'regulatory.reference.view',
  'curriculum.view',
  'tradeunit.view',
  'batch.view',
  'calendar.view',
  'student.view.assigned',
  'attendance.view.assigned',
  'attendance.mark.assigned',
  'attendance.correction.request.assigned',
  'formative.record.assigned',
  'ojt.evaluate.assigned',
  'exam.practical.record.assigned',
  'ojt.view'
) WHERE r.code = 'instructor';

-- student: 9 permissions
INSERT IGNORE INTO auth_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM auth_roles r JOIN auth_permissions p ON p.code IN (
  'student.view.own',
  'attendance.view.own',
  'formative.view.own',
  'ojt.view.own',
  'ojt.log.own',
  'exam.hallticket.view.own',
  'result.view.own',
  'certificate.view.own',
  'fee.view.own'
) WHERE r.code = 'student';

-- Private student documents. Review these added grants before production import.
INSERT IGNORE INTO auth_permissions(code,description,module) VALUES
 ('document.manage','Upload and archive institute student documents','documents'),
 ('document.view','Read institute student documents','documents'),
 ('document.view.own','Read own linked student documents','documents');
INSERT IGNORE INTO auth_role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM auth_roles r JOIN auth_permissions p ON p.code IN ('document.manage','document.view') WHERE r.code IN ('principal','administrator');
INSERT IGNORE INTO auth_role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM auth_roles r JOIN auth_permissions p ON p.code='document.view.own' WHERE r.code='student';
