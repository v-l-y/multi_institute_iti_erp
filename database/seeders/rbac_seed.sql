-- ITI ERP canonical RBAC seed / repair
--
-- SINGLE SOURCE OF TRUTH:
-- * canonical system roles
-- * canonical permission catalog
-- * deterministic role grants
-- * Principal role + institute membership repair for existing databases
--
-- This file is intentionally idempotent. backend/scripts/migrate.php applies it
-- after the canonical schema baseline has been verified, so the same RBAC policy
-- is used for both fresh databases and existing current-schema databases.

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Canonical system roles
-- -----------------------------------------------------------------------------
INSERT INTO roles (code, name) VALUES
('principal', 'Principal'),
('administrator', 'Administrator'),
('student', 'Student'),
('instructor', 'Instructor')
ON DUPLICATE KEY UPDATE
  name = VALUES(name);

-- -----------------------------------------------------------------------------
-- Repair canonical Principal ownership on existing databases.
-- -----------------------------------------------------------------------------
UPDATE users u
INNER JOIN institutes i
    ON i.principal_user_id = u.id
   AND i.status = 'active'
INNER JOIN roles r
    ON r.code = 'principal'
SET u.role_id = r.id
WHERE u.status = 'active';

INSERT INTO institute_users (
  institute_id,
  user_id,
  relationship,
  status
)
SELECT
  i.id,
  i.principal_user_id,
  'principal',
  'active'
FROM institutes i
INNER JOIN users u
    ON u.id = i.principal_user_id
   AND u.status = 'active'
WHERE i.status = 'active'
  AND i.principal_user_id IS NOT NULL
ON DUPLICATE KEY UPDATE
  relationship = 'principal',
  status = 'active';

-- Ensure every current Principal is also addressable through canonical staff
-- workflows such as document ownership and Principal signature management.
INSERT INTO staff_profiles (
  institute_id,
  user_id,
  employee_code,
  full_name,
  designation,
  employment_type,
  status,
  joining_date,
  phone,
  email,
  address,
  remarks,
  created_by
)
SELECT
  i.id,
  u.id,
  CONCAT('PRINCIPAL-', u.id),
  u.name,
  'Principal',
  'regular',
  'active',
  NULL,
  NULL,
  u.email,
  NULL,
  'System-created canonical Principal staff profile.',
  u.id
FROM institutes i
INNER JOIN users u
    ON u.id = i.principal_user_id
   AND u.status = 'active'
WHERE i.status = 'active'
  AND i.principal_user_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM staff_profiles sp
    WHERE sp.institute_id = i.id
      AND sp.user_id = u.id
  );

-- Ensure every current Administrator and Instructor is addressable through the
-- same canonical staff workflows as the Principal. This includes suspended
-- login memberships because account access state is separate from personnel
-- identity; only explicitly removed memberships are excluded. Personal/employment
-- details can be completed later without leaving the account disconnected from staff.
INSERT INTO staff_profiles (
  institute_id,
  user_id,
  employee_code,
  full_name,
  designation,
  employment_type,
  status,
  joining_date,
  phone,
  email,
  address,
  remarks,
  created_by
)
SELECT
  iu.institute_id,
  u.id,
  CONCAT(UPPER(iu.relationship), '-', u.id),
  u.name,
  CASE iu.relationship
    WHEN 'administrator' THEN 'Administrator'
    ELSE 'Instructor'
  END,
  'regular',
  'active',
  NULL,
  NULL,
  u.email,
  NULL,
  'System-created canonical staff profile.',
  u.id
FROM institute_users iu
INNER JOIN users u
    ON u.id = iu.user_id
WHERE iu.relationship IN ('administrator','instructor')
  AND iu.status <> 'removed'
  AND NOT EXISTS (
    SELECT 1
    FROM staff_profiles sp
    WHERE sp.institute_id = iu.institute_id
      AND sp.user_id = u.id
  );

-- -----------------------------------------------------------------------------
-- Canonical permission catalog
-- -----------------------------------------------------------------------------
INSERT INTO permissions (code, module, action, name) VALUES
('account.manage','account','manage','Manage account'),
('users.view','users','view','View users'),
('users.create','users','create','Create users'),
('users.update','users','update','Update users'),
('users.disable','users','disable','Disable users'),
('institute.manage','institute','manage','Manage ITI master'),
('compliance.view','compliance','view','View compliance'),
('compliance.run','compliance','run','Run compliance checks'),
('academic.view','academic','view','View academic records'),
('academic.manage','academic','manage','Manage academic records'),
('batch.create','batch','create','Create batches'),
('batch.update','batch','update','Update planned batches'),
('batch.activate','batch','activate','Activate planned batches'),
('batch.complete','batch','complete','Complete active batches'),
('batch.cancel','batch','cancel','Cancel batches'),
('students.view','students','view','View students'),
('students.manage','students','manage','Manage students'),
('admission.create','admission','create','Create admissions for review'),
('admission.update','admission','update','Update submitted or under-review admissions'),
('admission.submit','admission','submit','Submit admissions for review'),
('admission.cancel','admission','cancel','Cancel submitted admissions'),
('admission.review','admission','review','Start admission review'),
('admission.approve','admission','approve','Approve admissions'),
('admission.direct_approve','admission','direct_approve','Direct-approve Administrator-created direct admissions'),
('admission.reject','admission','reject','Reject admissions'),
('attendance.view','attendance','view','View attendance'),
('attendance.manage','attendance','manage','Manage attendance'),
('examination.view','examination','view','View examinations'),
('examination.manage','examination','manage','Manage examinations'),
('cts_result.view','cts_result','view','View CTS / AITT official result records'),
('cts_result.manage','cts_result','manage','Manage CTS / AITT official result records'),
('inventory.view','inventory','view','View inventory'),
('inventory.manage','inventory','manage','Manage inventory'),
('finance.view','finance','view','View finance'),
('finance.manage','finance','manage','Manage finance'),
('staff.view','staff','view','View staff'),
('staff.manage','staff','manage','Manage staff'),
('documents.view','documents','view','View documents'),
('documents.upload','documents','upload','Upload own documents'),
('documents.manage','documents','manage','Manage documents'),
('notifications.view','notifications','view','View notifications'),
('notifications.manage','notifications','manage','Manage notifications'),
('administration.manage','administration','manage','Manage administration'),
('grading.view','grading','view','View DGT grading'),
('grading.manage','grading','manage','Manage DGT grading'),
('reports.view','reports','view','View reports and dashboards'),
('audit.view','audit','view','View audit history'),
('security.manage','security','manage','Manage security'),
('data_import.manage','data_import','manage','Manage data imports'),
('workshop.view','workshop','view','View workshop and practical'),
('workshop.manage','workshop','manage','Manage workshop and practical'),
('dst.view','dst','view','View DST / dual system'),
('dst.manage','dst','manage','Manage DST / dual system')
ON DUPLICATE KEY UPDATE
  module = VALUES(module),
  action = VALUES(action),
  name = VALUES(name);

-- -----------------------------------------------------------------------------
-- Reset canonical system-role grants and rebuild the matrix.
-- -----------------------------------------------------------------------------
DELETE rp
FROM role_permissions rp
INNER JOIN roles r
    ON r.id = rp.role_id
WHERE r.code IN ('principal','administrator','student','instructor');

-- Principal owns the complete permission catalog.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'principal';

-- Administrator operational scope. ITI Master broad writes, batch cancellation,
-- and normal final admission approve/reject remain Principal-only. Direct approval
-- is separately gated to Administrator-created admissions whose admission_type is direct.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
    ON p.code IN (
      'users.view','users.create','users.update','users.disable',
      'academic.view','academic.manage','students.view','students.manage',
      'batch.create','batch.update','batch.activate','batch.complete',
      'admission.create','admission.update','admission.submit','admission.cancel','admission.review','admission.direct_approve',
      'attendance.view','attendance.manage','examination.view','examination.manage','cts_result.view','cts_result.manage',
      'inventory.view','inventory.manage',
      'finance.view','finance.manage','staff.view','staff.manage',
      'documents.view','documents.manage','notifications.view','notifications.manage',
      'administration.manage','grading.view','grading.manage','reports.view',
      'audit.view','data_import.manage','workshop.view','workshop.manage',
      'dst.view','dst.manage','compliance.view','compliance.run'
    )
WHERE r.code = 'administrator';

-- Student least-privilege self-service surface.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
    ON p.code IN (
      'attendance.view',
      'examination.view',
      'cts_result.view',
      'documents.view',
      'documents.upload',
      'notifications.view'
    )
WHERE r.code = 'student';

-- Instructor academic delivery / attendance / workshop surface.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
    ON p.code IN (
      'academic.view','academic.manage','students.view',
      'attendance.view','attendance.manage','examination.view','cts_result.view',
      'notifications.view','workshop.view','workshop.manage','dst.view'
    )
WHERE r.code = 'instructor';

COMMIT;
