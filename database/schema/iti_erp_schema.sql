-- GENERATED Bihar private CTS multi-ITI final fresh-install schema.
-- MySQL 9.7 LTS; select a NEW EMPTY database before import.
-- Includes access provisioning and checksum ledger; no seeded institutes/users.
-- Do not edit: regenerate with backend/database/mysql_bihar/build_final_schema.py.
-- No legacy-data migration, live portal integration or production certification.

CREATE TABLE schema_migrations(version VARCHAR(80) PRIMARY KEY,sha256 CHAR(64) NOT NULL,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;


-- Migration 001_core.sql; SHA-256 dafe989e701fa8d1d1f820c722e5d46b7a1a8c99cd16efb6278e16ca7a4a3a8b
-- ITI ERP: managed private institutes, shared database, multi-State/UT CTS
-- Research cut-off: 12 October 2026. Target: MySQL 9.7 LTS; actual verification build 9.7.2.
-- Import into a NEW EMPTY database. This is NOT an in-place migration.
-- Official rule data, trade syllabi and State/UT reservation rules must be configured.
SET NAMES utf8mb4;
SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET time_zone='+05:30';

CREATE TABLE `institute` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL, mis_code VARCHAR(40) NOT NULL UNIQUE,
 state_ut_code VARCHAR(20) NOT NULL, address TEXT NOT NULL,
 principal_name VARCHAR(190) NOT NULL,
 affiliation_status ENUM('affiliated','suspended','deactivated','deaffiliated') NOT NULL,
 affiliation_from DATE NOT NULL, affiliation_to DATE NULL,
 affiliation_order_reference VARCHAR(190) NOT NULL,
 CHECK(affiliation_to IS NULL OR affiliation_to>=affiliation_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `regulatory_sources` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 authority ENUM('DGT','NCVET','SIDH','NCVT_MIS','NIMS','STATE_UT','SSDEC') NOT NULL,
 state_ut_code VARCHAR(20) NULL,
 CHECK(authority NOT IN('STATE_UT','SSDEC') OR state_ut_code IS NOT NULL),
 document_type VARCHAR(60) NOT NULL, title VARCHAR(255) NOT NULL,
 reference_no VARCHAR(190) NOT NULL, issued_on DATE NOT NULL,
 source_url VARCHAR(900) NULL, local_evidence_reference VARCHAR(500) NULL,
 sha256 CHAR(64) NULL, supersedes_id BIGINT UNSIGNED NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 reviewed_by VARCHAR(190) NULL, reviewed_at DATETIME NULL,
 FOREIGN KEY(supersedes_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(status='draft' OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
 CHECK(source_url IS NOT NULL OR local_evidence_reference IS NOT NULL),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trades` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 dgt_code VARCHAR(80) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL,
 trade_type ENUM('engineering','non_engineering','divyang') NOT NULL,
 active TINYINT NOT NULL DEFAULT 1 CHECK(active IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `curriculum_versions` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
 version_code VARCHAR(80) NOT NULL, duration_months SMALLINT UNSIGNED NOT NULL,
 nsqf_level DECIMAL(3,1) NOT NULL, unit_strength SMALLINT UNSIGNED NOT NULL,
 effective_from DATE NOT NULL, effective_to DATE NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 UNIQUE(trade_id,version_code,institute_id), UNIQUE(id,trade_id,institute_id),
 FOREIGN KEY(trade_id) REFERENCES trades(id), FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(duration_months IN(6,12,24)), CHECK(nsqf_level BETWEEN 1 AND 8),
 CHECK(unit_strength>0), CHECK(effective_to IS NULL OR effective_to>=effective_from),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `curriculum_years` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curriculum_id BIGINT UNSIGNED NOT NULL, year_no TINYINT UNSIGNED NOT NULL,
 trade_practical_minutes INT UNSIGNED NOT NULL, trade_theory_minutes INT UNSIGNED NOT NULL,
 employability_minutes INT UNSIGNED NOT NULL, ojt_project_minutes INT UNSIGNED NOT NULL DEFAULT 0,
 credits DECIMAL(7,2) NULL,
 UNIQUE(curriculum_id,year_no,institute_id), UNIQUE(id,curriculum_id,institute_id),
 FOREIGN KEY(curriculum_id,institute_id) REFERENCES curriculum_versions(id,institute_id),
 CHECK(year_no IN(1,2)), CHECK(credits IS NULL OR credits>=0),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learning_outcomes` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curriculum_year_id BIGINT UNSIGNED NOT NULL,
 lo_code VARCHAR(80) NOT NULL, nos_code VARCHAR(80) NULL,
 outcome_text TEXT NOT NULL, assessment_criteria TEXT NOT NULL,
 formative_max_marks DECIMAL(7,2) NULL CHECK(formative_max_marks IS NULL OR formative_max_marks>0),
 UNIQUE(curriculum_year_id,lo_code,institute_id), UNIQUE(id,curriculum_year_id,institute_id),
 FOREIGN KEY(curriculum_year_id,institute_id) REFERENCES curriculum_years(id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `resource_requirements` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curriculum_id BIGINT UNSIGNED NOT NULL, resource_code VARCHAR(80) NOT NULL,
 resource_name VARCHAR(255) NOT NULL, specification TEXT NULL,
 resource_type ENUM('tool','machine','equipment','power_kw','workshop_area_m2','safety','staff') NOT NULL,
 base_quantity DECIMAL(10,3) NOT NULL, additional_unit_quantity DECIMAL(10,3) NOT NULL DEFAULT 0,
 measurement_unit VARCHAR(30) NOT NULL,
 UNIQUE(curriculum_id,resource_code,institute_id), FOREIGN KEY(curriculum_id,institute_id) REFERENCES curriculum_versions(id,institute_id),
 CHECK(base_quantity>=0 AND additional_unit_quantity>=0),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `instructor_identities` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 iprn VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `staff` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_code VARCHAR(50) NOT NULL, full_name VARCHAR(190) NOT NULL,
 role ENUM('principal','trade_instructor','es_instructor','wcs_ed_instructor','office','other') NOT NULL,
 joined_on DATE NOT NULL, left_on DATE NULL,
 iprn VARCHAR(120) NULL, identity_id BIGINT UNSIGNED NULL,
 FOREIGN KEY(identity_id) REFERENCES instructor_identities(id),
 nims_verification ENUM('pending','verified','inactive','rejected') NOT NULL DEFAULT 'pending',
 verification_reference VARCHAR(190) NULL, verified_on DATE NULL,
 identity_mode ENUM('aadhaar','non_aadhaar') NULL,
 active_principal_guard BIGINT UNSIGNED GENERATED ALWAYS AS
 (CASE WHEN role='principal' AND left_on IS NULL THEN institute_id ELSE NULL END) STORED UNIQUE,
 CHECK(left_on IS NULL OR left_on>=joined_on),
 CHECK(nims_verification<>'verified' OR (iprn IS NOT NULL AND verification_reference IS NOT NULL AND verified_on IS NOT NULL)),
 UNIQUE(employee_code,institute_id),
 UNIQUE(iprn,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `staff_qualifications` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_id BIGINT UNSIGNED NOT NULL, qualification_type VARCHAR(60) NOT NULL,
 specialization VARCHAR(190) NOT NULL, certificate_reference VARCHAR(190) NOT NULL,
 experience_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 verified_by VARCHAR(190) NULL, verified_on DATE NULL,
 FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trade_units` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL, unit_no SMALLINT UNSIGNED NOT NULL,
 UNIQUE(trade_id,unit_no,institute_id), UNIQUE(id,trade_id,institute_id),
 FOREIGN KEY(trade_id) REFERENCES trades(id), CHECK(unit_no>0),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trade_unit_shifts` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_unit_id BIGINT UNSIGNED NOT NULL, trade_id BIGINT UNSIGNED NOT NULL,
 shift_no TINYINT UNSIGNED NOT NULL,
 status ENUM('active','inactive','closed') NOT NULL DEFAULT 'active',
 training_mode ENUM('CTS','DST') NOT NULL DEFAULT 'CTS',
 affiliation_status ENUM('approved','suspended','surrendered','deaffiliated') NOT NULL,
 sanctioned_capacity SMALLINT UNSIGNED NOT NULL,
 valid_from DATE NOT NULL, valid_to DATE NULL,
 source_id BIGINT UNSIGNED NOT NULL, approval_reference VARCHAR(190) NOT NULL,
 UNIQUE(trade_unit_id,shift_no,institute_id), UNIQUE(id,trade_id,institute_id),
 FOREIGN KEY(trade_unit_id,trade_id,institute_id) REFERENCES trade_units(id,trade_id,institute_id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(shift_no BETWEEN 1 AND 3 AND sanctioned_capacity>0),
 CHECK(shift_no<>3 OR training_mode='DST'), CHECK(valid_to IS NULL OR valid_to>=valid_from),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `instructor_mappings` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_id BIGINT UNSIGNED NOT NULL,
 mapping_type ENUM('trade','subject') NOT NULL, identity_id BIGINT UNSIGNED NULL,
 active_identity_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='active' THEN identity_id ELSE NULL END) STORED UNIQUE,
 FOREIGN KEY(identity_id) REFERENCES instructor_identities(id),
 trade_unit_shift_id BIGINT UNSIGNED NULL, subject_code VARCHAR(30) NULL,
 valid_from DATE NOT NULL, valid_to DATE NULL,
 status ENUM('active','released','revoked') NOT NULL DEFAULT 'active',
 qualification_source_id BIGINT UNSIGNED NOT NULL,
 qualification_check_json LONGTEXT NOT NULL CHECK(JSON_VALID(qualification_check_json)),
 qualification_checked_by VARCHAR(190) NOT NULL,
 nims_reference VARCHAR(190) NOT NULL,
 active_staff_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='active' THEN staff_id ELSE NULL END) STORED UNIQUE,
 active_unit_guard BIGINT UNSIGNED GENERATED ALWAYS AS
 (CASE WHEN status='active' AND mapping_type='trade' THEN trade_unit_shift_id ELSE NULL END) STORED UNIQUE,
 FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id), FOREIGN KEY(trade_unit_shift_id,institute_id) REFERENCES trade_unit_shifts(id,institute_id),
 FOREIGN KEY(qualification_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK((mapping_type='trade' AND trade_unit_shift_id IS NOT NULL AND subject_code IS NULL)
 OR (mapping_type='subject' AND trade_unit_shift_id IS NULL AND subject_code IN('ES','WCS','ED','WCS_ED'))),
 CHECK(valid_to IS NULL OR valid_to>=valid_from),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `instructor_events` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_id BIGINT UNSIGNED NOT NULL, mapping_id BIGINT UNSIGNED NULL,
 event_type ENUM('transfer_in','transfer_out','terminated','vacancy','replacement') NOT NULL,
 occurred_on DATE NOT NULL, remap_due_on DATE NULL,
 resolved_on DATE NULL, replacement_mapping_id BIGINT UNSIGNED NULL,
 portal_reference VARCHAR(190) NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
 notes VARCHAR(1000) NULL,
 FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id), FOREIGN KEY(mapping_id,institute_id) REFERENCES instructor_mappings(id,institute_id),
 FOREIGN KEY(replacement_mapping_id,institute_id) REFERENCES instructor_mappings(id,institute_id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(remap_due_on IS NULL OR remap_due_on>=occurred_on),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admission_intakes` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admission_year SMALLINT UNSIGNED NOT NULL, intake_code VARCHAR(80) NOT NULL,
 opens_on DATE NOT NULL, closes_on DATE NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL, approval_reference VARCHAR(190) NOT NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 UNIQUE(admission_year,intake_code,institute_id), UNIQUE(id,admission_year,institute_id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id), CHECK(closes_on>=opens_on),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `seat_matrix_versions` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admission_year SMALLINT UNSIGNED NOT NULL, intake_id BIGINT UNSIGNED NOT NULL, version_code VARCHAR(80) NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL, portal_reference VARCHAR(190) NOT NULL,
 dgt_circular_no VARCHAR(80) NULL,
 published_on DATE NULL, status ENUM('draft','final','superseded') NOT NULL DEFAULT 'draft',
 supersedes_id BIGINT UNSIGNED NULL,
 final_intake_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='final' THEN intake_id ELSE NULL END) STORED UNIQUE,
 UNIQUE(intake_id,version_code,institute_id), FOREIGN KEY(intake_id,admission_year,institute_id) REFERENCES admission_intakes(id,admission_year,institute_id), FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(supersedes_id,institute_id) REFERENCES seat_matrix_versions(id,institute_id),
 CHECK(status='draft' OR published_on IS NOT NULL),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `seat_matrix_entries` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 version_id BIGINT UNSIGNED NOT NULL, trade_unit_shift_id BIGINT UNSIGNED NOT NULL,
 seat_capacity SMALLINT UNSIGNED NOT NULL, admission_allowed TINYINT NOT NULL,
 consumed_previous_session TINYINT NOT NULL DEFAULT 0,
 instructor_mapping_id BIGINT UNSIGNED NOT NULL,
 instructor_mapping_satisfied TINYINT NOT NULL DEFAULT 0, eligibility_reason VARCHAR(500) NULL, portal_itsu_reference VARCHAR(190) NOT NULL,
 UNIQUE(version_id,trade_unit_shift_id,institute_id), UNIQUE(id,trade_unit_shift_id,institute_id),
 FOREIGN KEY(version_id,institute_id) REFERENCES seat_matrix_versions(id,institute_id),
 FOREIGN KEY(trade_unit_shift_id,institute_id) REFERENCES trade_unit_shifts(id,institute_id),
 FOREIGN KEY(instructor_mapping_id,institute_id) REFERENCES instructor_mappings(id,institute_id),
 CHECK(admission_allowed IN(0,1) AND consumed_previous_session IN(0,1) AND instructor_mapping_satisfied IN(0,1)),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admission_rules` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL, curriculum_id BIGINT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL, state_source_id BIGINT UNSIGNED NULL,
 version_code VARCHAR(80) NOT NULL, state_ut_code VARCHAR(20) NOT NULL,
 minimum_age_years TINYINT UNSIGNED NOT NULL, age_cutoff_date DATE NOT NULL,
 qualification_routes_json LONGTEXT NOT NULL CHECK(JSON_VALID(qualification_routes_json)),
 admission_opens_on DATE NOT NULL, admission_closes_on DATE NOT NULL,
 admission_window_source_id BIGINT UNSIGNED NOT NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 UNIQUE(trade_id,version_code,state_ut_code,institute_id), UNIQUE(id,trade_id,curriculum_id,institute_id),
 FOREIGN KEY(curriculum_id,trade_id,institute_id) REFERENCES curriculum_versions(id,trade_id,institute_id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id), FOREIGN KEY(state_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(admission_window_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(admission_closes_on>=admission_opens_on),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reservation_policies` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 state_ut_code VARCHAR(20) NOT NULL, admission_year SMALLINT UNSIGNED NOT NULL,
 version_code VARCHAR(80) NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
 policy_json LONGTEXT NOT NULL CHECK(JSON_VALID(policy_json)),
 status ENUM('draft','published','superseded') NOT NULL DEFAULT 'draft',
 UNIQUE(state_ut_code,admission_year,version_code,institute_id), FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `batches` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_code VARCHAR(80) NOT NULL, trade_unit_shift_id BIGINT UNSIGNED NOT NULL,
 trade_id BIGINT UNSIGNED NOT NULL, curriculum_id BIGINT UNSIGNED NOT NULL,
 seat_matrix_entry_id BIGINT UNSIGNED NOT NULL, admission_rule_id BIGINT UNSIGNED NOT NULL,
 admission_year SMALLINT UNSIGNED NOT NULL, intake_id BIGINT UNSIGNED NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL,
 training_mode ENUM('CTS','DST') NOT NULL DEFAULT 'CTS',
 itsu_approval_snapshot_json LONGTEXT NOT NULL DEFAULT ('{}') CHECK(JSON_VALID(itsu_approval_snapshot_json)),
 capacity SMALLINT UNSIGNED NOT NULL,
 occupied_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Trigger-managed; application must not write this column',
 status ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'planned',
 unit_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status<>'cancelled' THEN trade_unit_shift_id ELSE NULL END) STORED,
 intake_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status<>'cancelled' THEN intake_id ELSE NULL END) STORED,
 UNIQUE(unit_guard,intake_guard,institute_id), UNIQUE(id,curriculum_id,institute_id), UNIQUE(id,trade_id,institute_id),
 FOREIGN KEY(intake_id,admission_year,institute_id) REFERENCES admission_intakes(id,admission_year,institute_id),
 FOREIGN KEY(trade_unit_shift_id,trade_id,institute_id) REFERENCES trade_unit_shifts(id,trade_id,institute_id),
 FOREIGN KEY(curriculum_id,trade_id,institute_id) REFERENCES curriculum_versions(id,trade_id,institute_id),
 FOREIGN KEY(seat_matrix_entry_id,trade_unit_shift_id,institute_id) REFERENCES seat_matrix_entries(id,trade_unit_shift_id,institute_id),
 FOREIGN KEY(admission_rule_id,trade_id,curriculum_id,institute_id) REFERENCES admission_rules(id,trade_id,curriculum_id,institute_id),
 CHECK(capacity>0 AND occupied_seats<=capacity AND ends_on>=starts_on),
 UNIQUE(batch_code,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `batch_years` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL, curriculum_id BIGINT UNSIGNED NOT NULL,
 curriculum_year_id BIGINT UNSIGNED NOT NULL,
 starts_on DATE NOT NULL, ends_on DATE NOT NULL,
 attendance_basis ENUM('minutes','working_days') NOT NULL,
 attendance_basis_source_id BIGINT UNSIGNED NOT NULL,
 calendar_status ENUM('draft','reviewed') NOT NULL DEFAULT 'draft',
 calendar_reviewed_through DATE NULL, calendar_reviewed_by VARCHAR(190) NULL, calendar_evidence_reference VARCHAR(500) NULL,
 formative_method ENUM('unconfigured','points_ratio') NOT NULL DEFAULT 'unconfigured',
 formative_scheme_source_id BIGINT UNSIGNED NULL, formative_scheme_reference VARCHAR(500) NULL,
 FOREIGN KEY(formative_scheme_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 UNIQUE(batch_id,curriculum_year_id,institute_id), UNIQUE(id,batch_id,institute_id), UNIQUE(id,curriculum_year_id,institute_id),
 FOREIGN KEY(batch_id,curriculum_id,institute_id) REFERENCES batches(id,curriculum_id,institute_id),
 FOREIGN KEY(curriculum_year_id,curriculum_id,institute_id) REFERENCES curriculum_years(id,curriculum_id,institute_id),
 FOREIGN KEY(attendance_basis_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id), CHECK(ends_on>=starts_on),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_calendar_entries` (
 institute_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 session_date DATE NOT NULL,
 component ENUM('trade_practical','trade_theory','employability','ojt_project') NOT NULL,
 scheduled_minutes SMALLINT UNSIGNED NOT NULL,
 PRIMARY KEY(batch_year_id,session_date,component,institute_id), FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id),
 CHECK(scheduled_minutes>0),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subject_batch_assignments` (
 institute_id BIGINT UNSIGNED NOT NULL,
 mapping_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL, assigned_minutes_per_week SMALLINT UNSIGNED NOT NULL,
 principal_reference VARCHAR(190) NOT NULL,
 PRIMARY KEY(mapping_id,batch_year_id,institute_id), FOREIGN KEY(mapping_id,institute_id) REFERENCES instructor_mappings(id,institute_id),
 FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `batch_quotas` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL, policy_id BIGINT UNSIGNED NOT NULL,
 category_code VARCHAR(40) NOT NULL,
 quota_type ENUM('vertical','horizontal') NOT NULL,
 seats SMALLINT UNSIGNED NOT NULL, occupied_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Trigger-managed',
 eligibility_condition_json LONGTEXT NOT NULL CHECK(JSON_VALID(eligibility_condition_json)),
 UNIQUE(batch_id,category_code,quota_type,institute_id), UNIQUE(id,batch_id,institute_id),
 FOREIGN KEY(batch_id,institute_id) REFERENCES batches(id,institute_id), FOREIGN KEY(policy_id,institute_id) REFERENCES reservation_policies(id,institute_id), CHECK(occupied_seats<=seats),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 local_student_code VARCHAR(60) NOT NULL,
 full_name VARCHAR(190) NOT NULL, date_of_birth DATE NOT NULL,
 father_name VARCHAR(190) NULL, mother_name VARCHAR(190) NULL,
 gender VARCHAR(30) NOT NULL, mobile VARCHAR(20) NULL, address TEXT NOT NULL,
 category_code VARCHAR(40) NOT NULL DEFAULT 'GENERAL', is_ews TINYINT NOT NULL DEFAULT 0,
 disability_percent DECIMAL(5,2) NULL, identity_mode ENUM('aadhaar','non_aadhaar') NOT NULL,
 identity_evidence_reference VARCHAR(190) NOT NULL,
 prn VARCHAR(120) NULL, prn_verified_on DATE NULL, prn_source_reference VARCHAR(190) NULL,
 apaar_id VARCHAR(30) NULL, apaar_consent ENUM('not_requested','granted','declined','withdrawn') NOT NULL DEFAULT 'not_requested',
 consent_reference VARCHAR(190) NULL, consent_on DATE NULL,
 verification_iti_mis_code VARCHAR(40) NULL,
 CHECK(is_ews IN(0,1)), CHECK(disability_percent IS NULL OR disability_percent BETWEEN 0 AND 100),
 CHECK(prn_verified_on IS NULL OR (prn IS NOT NULL AND prn_source_reference IS NOT NULL)),
 CHECK(apaar_id IS NULL OR (apaar_consent='granted' AND consent_reference IS NOT NULL AND consent_on IS NOT NULL)),
 UNIQUE(local_student_code,institute_id),
 UNIQUE(prn,institute_id),
 UNIQUE(apaar_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_qualifications` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id BIGINT UNSIGNED NOT NULL, level_code VARCHAR(40) NOT NULL,
 board_name VARCHAR(190) NOT NULL, passing_year SMALLINT UNSIGNED NOT NULL,
 certificate_reference VARCHAR(190) NOT NULL,
 subject_results_json LONGTEXT NOT NULL CHECK(JSON_VALID(subject_results_json)),
 verified_by VARCHAR(190) NOT NULL, verified_on DATE NOT NULL,
 FOREIGN KEY(student_id,institute_id) REFERENCES students(id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `enrollments` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NOT NULL,
 admission_number VARCHAR(80) NOT NULL, state_registration_number VARCHAR(100) NULL,
 admitted_on DATE NOT NULL, status ENUM('pending_verification','active','completed','transferred','dropout','cancelled') NOT NULL DEFAULT 'pending_verification',
 admission_rule_id BIGINT UNSIGNED NOT NULL,
 qualification_id BIGINT UNSIGNED NOT NULL,
 qualification_route_code VARCHAR(80) NOT NULL,
 qualification_check_json LONGTEXT NOT NULL CHECK(JSON_VALID(qualification_check_json)),
 qualification_check_passed TINYINT NOT NULL,
 checked_by VARCHAR(190) NOT NULL, checked_on DATE NOT NULL,
 vertical_quota_id BIGINT UNSIGNED NULL, horizontal_quota_id BIGINT UNSIGNED NULL,
 quota_evidence_reference VARCHAR(190) NULL,
 active_student_guard BIGINT UNSIGNED GENERATED ALWAYS AS
 (CASE WHEN status IN('pending_verification','active') THEN student_id ELSE NULL END) STORED UNIQUE,
 UNIQUE(batch_id,student_id,institute_id), UNIQUE(id,batch_id,institute_id),
 FOREIGN KEY(student_id,institute_id) REFERENCES students(id,institute_id), FOREIGN KEY(batch_id,institute_id) REFERENCES batches(id,institute_id),
 FOREIGN KEY(admission_rule_id,institute_id) REFERENCES admission_rules(id,institute_id), FOREIGN KEY(qualification_id,institute_id) REFERENCES student_qualifications(id,institute_id),
 FOREIGN KEY(vertical_quota_id,batch_id,institute_id) REFERENCES batch_quotas(id,batch_id,institute_id),
 FOREIGN KEY(horizontal_quota_id,batch_id,institute_id) REFERENCES batch_quotas(id,batch_id,institute_id), CHECK(qualification_check_passed IN(0,1)),
 UNIQUE(admission_number,institute_id),
 UNIQUE(state_registration_number,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_events` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, event_type ENUM('transfer','withdrawal','dropout','cancellation','readmission','correction') NOT NULL,
 effective_on DATE NOT NULL, reference_no VARCHAR(190) NOT NULL,
 destination_mis_code VARCHAR(40) NULL, reason TEXT NOT NULL,
 recorded_by VARCHAR(190) NOT NULL, recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_sessions` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_year_id BIGINT UNSIGNED NOT NULL, session_date DATE NOT NULL,
 component ENUM('trade_practical','trade_theory','employability','ojt_project') NOT NULL,
 scheduled_minutes SMALLINT UNSIGNED NOT NULL,
 instructor_id BIGINT UNSIGNED NOT NULL, locked_on DATETIME NULL,
 UNIQUE(batch_year_id,session_date,component,institute_id), FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id),
 FOREIGN KEY(instructor_id,institute_id) REFERENCES staff(id,institute_id), CHECK(scheduled_minutes>0),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_records` (
 institute_id BIGINT UNSIGNED NOT NULL,
 session_id BIGINT UNSIGNED NOT NULL, enrollment_id BIGINT UNSIGNED NOT NULL,
 attended_minutes SMALLINT UNSIGNED NOT NULL, status ENUM('present','absent','leave','medical_leave','emergency_leave','ojt') NOT NULL,
 leave_evidence_reference VARCHAR(190) NULL, recorded_by VARCHAR(190) NOT NULL,
 PRIMARY KEY(session_id,enrollment_id,institute_id), FOREIGN KEY(session_id,institute_id) REFERENCES attendance_sessions(id,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_corrections` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT UNSIGNED NOT NULL, enrollment_id BIGINT UNSIGNED NOT NULL,
 before_json LONGTEXT NOT NULL CHECK(JSON_VALID(before_json)), after_json LONGTEXT NOT NULL CHECK(JSON_VALID(after_json)),
 reason TEXT NOT NULL, authority_reference VARCHAR(190) NOT NULL,
 approved_by VARCHAR(190) NOT NULL, approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(session_id,enrollment_id,institute_id) REFERENCES attendance_records(session_id,enrollment_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `formative_assessments` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL, curriculum_year_id BIGINT UNSIGNED NOT NULL,
 learning_outcome_id BIGINT UNSIGNED NOT NULL, assessed_on DATE NOT NULL,
 max_marks DECIMAL(7,2) NOT NULL, obtained_marks DECIMAL(7,2) NOT NULL,
 evidence_reference VARCHAR(190) NOT NULL, assessor_id BIGINT UNSIGNED NOT NULL,
 locked_on DATETIME NULL,
 UNIQUE(enrollment_id,batch_year_id,learning_outcome_id,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,curriculum_year_id,institute_id) REFERENCES batch_years(id,curriculum_year_id,institute_id),
 FOREIGN KEY(learning_outcome_id,curriculum_year_id,institute_id) REFERENCES learning_outcomes(id,curriculum_year_id,institute_id),
 FOREIGN KEY(assessor_id,institute_id) REFERENCES staff(id,institute_id), CHECK(max_marks>0 AND obtained_marks BETWEEN 0 AND max_marks),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ojt_projects` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 mode ENUM('ojt','group_project') NOT NULL, title VARCHAR(255) NOT NULL,
 industry_name VARCHAR(255) NULL, industry_registration_reference VARCHAR(190) NULL, workplace_address TEXT NULL,
 industry_mentor VARCHAR(190) NULL, coordinator_id BIGINT UNSIGNED NOT NULL,
 starts_on DATE NOT NULL, ends_on DATE NOT NULL, required_minutes INT UNSIGNED NOT NULL,
 safety_reference VARCHAR(190) NOT NULL, female_safety_reference VARCHAR(190) NULL,
 completion_certificate_reference VARCHAR(190) NULL,
 status ENUM('planned','ongoing','completed') NOT NULL DEFAULT 'planned',
 UNIQUE(enrollment_id,batch_year_id,institute_id), FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(coordinator_id,institute_id) REFERENCES staff(id,institute_id), CHECK(ends_on>=starts_on AND required_minutes>0),
 CHECK(mode<>'ojt' OR (industry_name IS NOT NULL AND industry_mentor IS NOT NULL)),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ojt_logbook` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL, entry_date DATE NOT NULL,
 attended_minutes SMALLINT UNSIGNED NOT NULL, activity TEXT NOT NULL,
 mentor_confirmation VARCHAR(190) NOT NULL, monitoring_safety_reference VARCHAR(190) NULL,
 UNIQUE(project_id,entry_date,institute_id), FOREIGN KEY(project_id,institute_id) REFERENCES ojt_projects(id,institute_id), CHECK(attended_minutes<=1440),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ojt_evaluations` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL UNIQUE,
 primary_evaluator_role ENUM('industry_mentor','trade_instructor') NOT NULL,
 primary_evaluator_name VARCHAR(190) NOT NULL, primary_marks DECIMAL(6,2) NOT NULL,
 external_examiner_name VARCHAR(190) NOT NULL, external_marks DECIMAL(6,2) NOT NULL,
 primary_max_marks DECIMAL(6,2) NOT NULL, external_max_marks DECIMAL(6,2) NOT NULL,
 final_grade ENUM('A1','A','B','C') NOT NULL, evaluated_on DATE NOT NULL,
 evaluation_source_id BIGINT UNSIGNED NOT NULL,
 signed_evidence_reference VARCHAR(190) NOT NULL, sidh_submission_reference VARCHAR(190) NULL,
 FOREIGN KEY(project_id,institute_id) REFERENCES ojt_projects(id,institute_id), FOREIGN KEY(evaluation_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(primary_max_marks>0 AND external_max_marks>0),
 CHECK(primary_marks BETWEEN 0 AND primary_max_marks AND external_marks BETWEEN 0 AND external_max_marks),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_rule_versions` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL, code VARCHAR(80) NOT NULL,
 cycle_type ENUM('mains','supplementary','leftover') NOT NULL,
 minimum_attendance DECIMAL(5,2) NOT NULL, minimum_formative DECIMAL(5,2) NOT NULL,
 principal_relaxation_cap DECIMAL(5,2) NOT NULL DEFAULT 0,
 relaxation_source_id BIGINT UNSIGNED NULL,
 requires_prn TINYINT NOT NULL DEFAULT 1, requires_cbt_fee TINYINT NOT NULL DEFAULT 1,
 requires_practical_fee TINYINT NOT NULL DEFAULT 1,
 requires_ojt_for_certificate TINYINT NOT NULL DEFAULT 1,
 practical_upload_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48,
 effective_from DATE NOT NULL, effective_to DATE NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id), FOREIGN KEY(relaxation_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(minimum_attendance BETWEEN 0 AND 100 AND minimum_formative BETWEEN 0 AND 100),
 CHECK(principal_relaxation_cap BETWEEN 0 AND 100),
 CHECK(principal_relaxation_cap=0 OR relaxation_source_id IS NOT NULL),
 CHECK(requires_prn IN(0,1) AND requires_cbt_fee IN(0,1) AND requires_practical_fee IN(0,1) AND requires_ojt_for_certificate IN(0,1)),
 CHECK(effective_to IS NULL OR effective_to>=effective_from),
 UNIQUE(code,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_cycles` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(80) NOT NULL, cycle_type ENUM('mains','supplementary','leftover') NOT NULL,
 rule_id BIGINT UNSIGNED NOT NULL, schedule_source_id BIGINT UNSIGNED NOT NULL,
 eligibility_cutoff DATE NOT NULL, exam_starts_on DATE NOT NULL,
 activity_windows_json LONGTEXT NOT NULL CHECK(JSON_VALID(activity_windows_json)),
 status ENUM('draft','eligibility_open','eligibility_frozen','scheduled','closed') NOT NULL DEFAULT 'draft',
 frozen_by VARCHAR(190) NULL, frozen_on DATETIME NULL,
 FOREIGN KEY(rule_id,institute_id) REFERENCES exam_rule_versions(id,institute_id), FOREIGN KEY(schedule_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 UNIQUE(code,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fee_transactions` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, cycle_id BIGINT UNSIGNED NULL,
 fee_head ENUM('admission','tuition','cbt','practical','other') NOT NULL,
 transaction_type ENUM('payment','refund','reversal') NOT NULL,
 amount DECIMAL(10,2) NOT NULL, paid_on DATETIME NOT NULL,
 receipt_no VARCHAR(100) NOT NULL, portal_reference VARCHAR(190) NULL,
 payment_mode ENUM('cash','bank','upi','sidh_gateway') NOT NULL,
 reverses_transaction_id BIGINT UNSIGNED NULL, recorded_by VARCHAR(190) NOT NULL,
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id), FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(reverses_transaction_id,institute_id) REFERENCES fee_transactions(id,institute_id), CHECK(amount>0),
 CHECK(transaction_type='payment' OR reverses_transaction_id IS NOT NULL),
 UNIQUE(receipt_no,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_fee_clearances` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, cycle_id BIGINT UNSIGNED NOT NULL,
 cbt_cleared TINYINT NOT NULL, practical_cleared TINYINT NOT NULL,
 cbt_portal_reference VARCHAR(190) NULL, practical_reference VARCHAR(190) NULL,
 verified_by VARCHAR(190) NOT NULL, verified_on DATETIME NOT NULL,
 UNIQUE(enrollment_id,cycle_id,institute_id), FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id), FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 CHECK(cbt_cleared IN(0,1) AND practical_cleared IN(0,1)),
 CHECK(cbt_cleared=0 OR cbt_portal_reference IS NOT NULL), CHECK(practical_cleared=0 OR practical_reference IS NOT NULL),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_relaxations` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, cycle_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 points DECIMAL(5,2) NOT NULL, authority_source_id BIGINT UNSIGNED NOT NULL,
 authority_order_reference VARCHAR(190) NOT NULL, supporting_evidence_reference VARCHAR(190) NOT NULL,
 reason TEXT NOT NULL, approved_by VARCHAR(190) NOT NULL, approved_on DATE NOT NULL,
 UNIQUE(enrollment_id,cycle_id,batch_year_id,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id), FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id), FOREIGN KEY(authority_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(points>0 AND points<=100),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_eligibility` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL, cycle_id BIGINT UNSIGNED NOT NULL,
 scheduled_units DECIMAL(10,2) NOT NULL, attended_units DECIMAL(10,2) NOT NULL,
 attendance_basis ENUM('minutes','working_days') NOT NULL,
 raw_attendance DECIMAL(5,2) NOT NULL, effective_attendance DECIMAL(5,2) NOT NULL,
 relaxation_id BIGINT UNSIGNED NULL,
 formative_percent DECIMAL(5,2) NOT NULL, formative_complete TINYINT NOT NULL,
 eligibility_status ENUM('eligible','ineligible','withheld') NOT NULL, reason VARCHAR(1000) NULL,
 rule_snapshot_json LONGTEXT NOT NULL CHECK(JSON_VALID(rule_snapshot_json)),
 evidence_reference VARCHAR(190) NOT NULL, frozen_by VARCHAR(190) NOT NULL,
 frozen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(enrollment_id,cycle_id,batch_year_id,institute_id), UNIQUE(id,enrollment_id,cycle_id,batch_year_id,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id), FOREIGN KEY(relaxation_id,institute_id) REFERENCES attendance_relaxations(id,institute_id),
 CHECK(scheduled_units>0 AND attended_units BETWEEN 0 AND scheduled_units),
 CHECK(raw_attendance BETWEEN 0 AND 100 AND effective_attendance BETWEEN 0 AND 100 AND formative_percent BETWEEN 0 AND 100),
 CHECK(formative_complete IN(0,1)),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hall_tickets` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 eligibility_id BIGINT UNSIGNED NOT NULL, ticket_number VARCHAR(120) NOT NULL,
 revision_no SMALLINT UNSIGNED NOT NULL DEFAULT 1, source_system ENUM('SIDH','NCVT_MIS','DGT') NOT NULL,
 source_reference VARCHAR(190) NOT NULL, payload_sha256 CHAR(64) NOT NULL,
 exam_centre_reference VARCHAR(190) NOT NULL, issued_at DATETIME NOT NULL,
 UNIQUE(ticket_number,revision_no,institute_id), FOREIGN KEY(eligibility_id,institute_id) REFERENCES exam_eligibility(id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(payload_sha256 REGEXP '^[0-9a-fA-F]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `practical_sessions` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 cycle_id BIGINT UNSIGNED NOT NULL, batch_year_id BIGINT UNSIGNED NOT NULL,
 ended_at DATETIME NOT NULL,
 examiner_name VARCHAR(190) NOT NULL, examiner_reference VARCHAR(190) NOT NULL,
 question_paper_authority ENUM('DGT','STATE_UT') NOT NULL,
 question_paper_reference VARCHAR(190) NOT NULL, question_paper_source_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id), FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id),
 FOREIGN KEY(question_paper_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `practical_marks` (
 institute_id BIGINT UNSIGNED NOT NULL,
 practical_session_id BIGINT UNSIGNED NOT NULL,
 eligibility_id BIGINT UNSIGNED NOT NULL, attendance_status ENUM('present','absent') NOT NULL,
 max_marks DECIMAL(7,2) NOT NULL, obtained_marks DECIMAL(7,2) NULL,
 PRIMARY KEY(practical_session_id,eligibility_id,institute_id),
 FOREIGN KEY(practical_session_id,institute_id) REFERENCES practical_sessions(id,institute_id), FOREIGN KEY(eligibility_id,institute_id) REFERENCES exam_eligibility(id,institute_id),
 CHECK(max_marks>0), CHECK((attendance_status='present' AND obtained_marks IS NOT NULL AND obtained_marks BETWEEN 0 AND max_marks)
 OR (attendance_status='absent' AND obtained_marks IS NULL)),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `portal_submissions` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 submission_type ENUM('trainee_verification','attendance_formative','practical_marks','ojt_grade','abc_credit','nims_mapping') NOT NULL,
 cycle_id BIGINT UNSIGNED NULL, practical_session_id BIGINT UNSIGNED NULL,
 enrollment_id BIGINT UNSIGNED NULL, staff_id BIGINT UNSIGNED NULL,
 attempt_no SMALLINT UNSIGNED NOT NULL, submitted_at DATETIME NOT NULL,
 accepted_at DATETIME NULL, status ENUM('submitted','accepted','rejected') NOT NULL,
 expected_records INT UNSIGNED NOT NULL, submitted_records INT UNSIGNED NOT NULL,
 portal_reference VARCHAR(190) NOT NULL, payload_sha256 CHAR(64) NOT NULL,
 response_evidence_reference VARCHAR(190) NOT NULL,
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id), FOREIGN KEY(practical_session_id,institute_id) REFERENCES practical_sessions(id,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id), FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id),
 CHECK(status<>'accepted' OR (accepted_at IS NOT NULL AND submitted_records=expected_records)),
 CHECK(submission_type<>'practical_marks' OR practical_session_id IS NOT NULL),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(payload_sha256 REGEXP '^[0-9a-fA-F]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `official_results` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL, cycle_id BIGINT UNSIGNED NOT NULL,
 result_status ENUM('pass','fail','promoted_with_back','withheld','absent','not_declared') NOT NULL,
 component_marks_json LONGTEXT NOT NULL CHECK(JSON_VALID(component_marks_json)),
 ojt_grade ENUM('A1','A','B','C') NULL,
 revision_no SMALLINT UNSIGNED NOT NULL, supersedes_result_id BIGINT UNSIGNED NULL,
 source_system ENUM('SIDH','NCVT_MIS','DGT') NOT NULL,
 source_reference VARCHAR(190) NOT NULL, payload_sha256 CHAR(64) NOT NULL,
 declared_at DATETIME NOT NULL, imported_by VARCHAR(190) NOT NULL,
 UNIQUE(enrollment_id,batch_year_id,cycle_id,revision_no,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id), FOREIGN KEY(supersedes_result_id,institute_id) REFERENCES official_results(id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(payload_sha256 REGEXP '^[0-9a-fA-F]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `certificates` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL, official_result_id BIGINT UNSIGNED NOT NULL,
 document_type ENUM('NTC','marksheet') NOT NULL,
 certificate_number VARCHAR(120) NOT NULL, revision_no SMALLINT UNSIGNED NOT NULL,
 supersedes_certificate_id BIGINT UNSIGNED NULL,
 status ENUM('valid','revoked') NOT NULL,
 apaar_id_as_issued VARCHAR(30) NULL, credits_as_issued DECIMAL(8,2) NULL,
 ojt_grade_as_issued ENUM('A1','A','B','C') NULL,
 source_system ENUM('SIDH','NCVT_MIS','DGT') NOT NULL,
 source_reference VARCHAR(190) NOT NULL, payload_sha256 CHAR(64) NOT NULL,
 verification_reference VARCHAR(255) NOT NULL, issued_on DATE NOT NULL,
 imported_by VARCHAR(190) NOT NULL,
 UNIQUE(certificate_number,revision_no,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id), FOREIGN KEY(official_result_id,institute_id) REFERENCES official_results(id,institute_id),
 FOREIGN KEY(supersedes_certificate_id,institute_id) REFERENCES certificates(id,institute_id), CHECK(credits_as_issued IS NULL OR credits_as_issued>=0),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(payload_sha256 REGEXP '^[0-9a-fA-F]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credit_transfers` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 certificate_id BIGINT UNSIGNED NOT NULL, apaar_id VARCHAR(30) NOT NULL,
 credits DECIMAL(8,2) NOT NULL, abc_reference VARCHAR(190) NOT NULL,
 transferred_at DATETIME NOT NULL, submission_id BIGINT UNSIGNED NOT NULL,
 UNIQUE(certificate_id,abc_reference,institute_id), FOREIGN KEY(certificate_id,institute_id) REFERENCES certificates(id,institute_id),
 FOREIGN KEY(submission_id,institute_id) REFERENCES portal_submissions(id,institute_id), CHECK(credits>=0),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_assets` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 asset_code VARCHAR(80) NOT NULL, resource_requirement_id BIGINT UNSIGNED NULL,
 name VARCHAR(255) NOT NULL, specification TEXT NULL,
 quantity DECIMAL(10,3) NOT NULL, serviceable_quantity DECIMAL(10,3) NOT NULL,
 location VARCHAR(190) NOT NULL, checked_on DATE NOT NULL,
 FOREIGN KEY(resource_requirement_id,institute_id) REFERENCES resource_requirements(id,institute_id),
 CHECK(quantity>=0 AND serviceable_quantity BETWEEN 0 AND quantity),
 UNIQUE(asset_code,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `compliance_evidence` (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 evidence_type ENUM('affiliation','renewal','building','fire','power','safety','equipment','staff','DST_mou','inspection','grading','admission_window','late_nims_mapping') NOT NULL,
 trade_unit_shift_id BIGINT UNSIGNED NULL, source_id BIGINT UNSIGNED NOT NULL,
 reference_no VARCHAR(190) NOT NULL, issued_on DATE NOT NULL, expires_on DATE NULL,
 status ENUM('valid','expired','superseded','pending') NOT NULL,
 evidence_reference VARCHAR(500) NOT NULL,
 measurements_json LONGTEXT NULL CHECK(JSON_VALID(measurements_json)),
 FOREIGN KEY(trade_unit_shift_id,institute_id) REFERENCES trade_unit_shifts(id,institute_id), FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(expires_on IS NULL OR expires_on>=issued_on),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER bind_staff_identity_insert BEFORE INSERT ON staff FOR EACH ROW
BEGIN
 IF NEW.iprn IS NOT NULL THEN
 INSERT IGNORE INTO instructor_identities(iprn) VALUES(NEW.iprn);
 SET NEW.identity_id=(SELECT id FROM instructor_identities WHERE iprn=NEW.iprn);
 ELSE SET NEW.identity_id=NULL; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER bind_staff_identity_update BEFORE UPDATE ON staff FOR EACH ROW
BEGIN
 IF NEW.iprn IS NOT NULL THEN
 INSERT IGNORE INTO instructor_identities(iprn) VALUES(NEW.iprn);
 SET NEW.identity_id=(SELECT id FROM instructor_identities WHERE iprn=NEW.iprn);
 ELSE SET NEW.identity_id=NULL; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_iprn_identity BEFORE UPDATE ON instructor_identities FOR EACH ROW
BEGIN
 IF NEW.iprn<>OLD.iprn THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IPRN is a lifetime identifier'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_regulatory_sources BEFORE UPDATE ON regulatory_sources FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_curriculum_versions BEFORE UPDATE ON curriculum_versions FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_curriculum_years BEFORE UPDATE ON curriculum_years FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_learning_outcomes BEFORE UPDATE ON learning_outcomes FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_resource_requirements BEFORE UPDATE ON resource_requirements FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_staff BEFORE UPDATE ON staff FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_staff_qualifications BEFORE UPDATE ON staff_qualifications FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_trade_units BEFORE UPDATE ON trade_units FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_trade_unit_shifts BEFORE UPDATE ON trade_unit_shifts FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_instructor_mappings BEFORE UPDATE ON instructor_mappings FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_instructor_events BEFORE UPDATE ON instructor_events FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_admission_intakes BEFORE UPDATE ON admission_intakes FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_seat_matrix_versions BEFORE UPDATE ON seat_matrix_versions FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_seat_matrix_entries BEFORE UPDATE ON seat_matrix_entries FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_admission_rules BEFORE UPDATE ON admission_rules FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_reservation_policies BEFORE UPDATE ON reservation_policies FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_batches BEFORE UPDATE ON batches FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_batch_years BEFORE UPDATE ON batch_years FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_training_calendar_entries BEFORE UPDATE ON training_calendar_entries FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_subject_batch_assignments BEFORE UPDATE ON subject_batch_assignments FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_batch_quotas BEFORE UPDATE ON batch_quotas FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_students BEFORE UPDATE ON students FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_student_qualifications BEFORE UPDATE ON student_qualifications FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_enrollments BEFORE UPDATE ON enrollments FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_student_events BEFORE UPDATE ON student_events FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_attendance_sessions BEFORE UPDATE ON attendance_sessions FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_attendance_records BEFORE UPDATE ON attendance_records FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_attendance_corrections BEFORE UPDATE ON attendance_corrections FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_formative_assessments BEFORE UPDATE ON formative_assessments FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_ojt_projects BEFORE UPDATE ON ojt_projects FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_ojt_logbook BEFORE UPDATE ON ojt_logbook FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_ojt_evaluations BEFORE UPDATE ON ojt_evaluations FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_exam_rule_versions BEFORE UPDATE ON exam_rule_versions FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_exam_cycles BEFORE UPDATE ON exam_cycles FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_fee_transactions BEFORE UPDATE ON fee_transactions FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_exam_fee_clearances BEFORE UPDATE ON exam_fee_clearances FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_attendance_relaxations BEFORE UPDATE ON attendance_relaxations FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_exam_eligibility BEFORE UPDATE ON exam_eligibility FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_hall_tickets BEFORE UPDATE ON hall_tickets FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_practical_sessions BEFORE UPDATE ON practical_sessions FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_practical_marks BEFORE UPDATE ON practical_marks FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_portal_submissions BEFORE UPDATE ON portal_submissions FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_official_results BEFORE UPDATE ON official_results FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_certificates BEFORE UPDATE ON certificates FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_credit_transfers BEFORE UPDATE ON credit_transfers FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_inventory_assets BEFORE UPDATE ON inventory_assets FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER freeze_owner_compliance_evidence BEFORE UPDATE ON compliance_evidence FOR EACH ROW
BEGIN
 IF NEW.institute_id<>OLD.institute_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute ownership is immutable; record an authorized transfer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_source_state_insert BEFORE INSERT ON regulatory_sources FOR EACH ROW
BEGIN
 IF NEW.state_ut_code IS NOT NULL AND NOT EXISTS(SELECT 1 FROM institute WHERE id=NEW.institute_id AND state_ut_code=NEW.state_ut_code) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='State regulatory source must match its institute State/UT'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_source_state_update BEFORE UPDATE ON regulatory_sources FOR EACH ROW
BEGIN
 IF NEW.state_ut_code IS NOT NULL AND NOT EXISTS(SELECT 1 FROM institute WHERE id=NEW.institute_id AND state_ut_code=NEW.state_ut_code) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='State regulatory source must match its institute State/UT'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_state_profile_admission_rules_insert BEFORE INSERT ON admission_rules FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS(SELECT 1 FROM institute WHERE id=NEW.institute_id AND state_ut_code=NEW.state_ut_code) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published State profile must match the managed institute State/UT'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_state_profile_admission_rules_update BEFORE UPDATE ON admission_rules FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS(SELECT 1 FROM institute WHERE id=NEW.institute_id AND state_ut_code=NEW.state_ut_code) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published State profile must match the managed institute State/UT'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_state_profile_reservation_policies_insert BEFORE INSERT ON reservation_policies FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS(SELECT 1 FROM institute WHERE id=NEW.institute_id AND state_ut_code=NEW.state_ut_code) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published State profile must match the managed institute State/UT'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_state_profile_reservation_policies_update BEFORE UPDATE ON reservation_policies FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS(SELECT 1 FROM institute WHERE id=NEW.institute_id AND state_ut_code=NEW.state_ut_code) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published State profile must match the managed institute State/UT'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_admission_intakes_insert BEFORE INSERT ON admission_intakes FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_admission_intakes_update BEFORE UPDATE ON admission_intakes FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND NOT
 (OLD.status='published' AND NEW.status IN('superseded','withdrawn') AND (NEW.id<=>OLD.id) AND (NEW.admission_year<=>OLD.admission_year) AND (NEW.intake_code<=>OLD.intake_code) AND (NEW.opens_on<=>OLD.opens_on) AND (NEW.closes_on<=>OLD.closes_on) AND (NEW.source_id<=>OLD.source_id) AND (NEW.approval_reference<=>OLD.approval_reference) AND (NEW.institute_id<=>OLD.institute_id)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definitions are immutable; insert a new sourced version'; END IF;
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_admission_intakes_delete BEFORE DELETE ON admission_intakes FOR EACH ROW
BEGIN
IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_curriculum_versions_insert BEFORE INSERT ON curriculum_versions FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_curriculum_versions_update BEFORE UPDATE ON curriculum_versions FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND NOT
 (OLD.status='published' AND NEW.status IN('superseded','withdrawn') AND (NEW.id<=>OLD.id) AND (NEW.trade_id<=>OLD.trade_id) AND (NEW.source_id<=>OLD.source_id) AND (NEW.version_code<=>OLD.version_code) AND (NEW.duration_months<=>OLD.duration_months) AND (NEW.nsqf_level<=>OLD.nsqf_level) AND (NEW.unit_strength<=>OLD.unit_strength) AND (NEW.effective_from<=>OLD.effective_from) AND (NEW.effective_to<=>OLD.effective_to) AND (NEW.institute_id<=>OLD.institute_id)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definitions are immutable; insert a new sourced version'; END IF;
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_curriculum_versions_delete BEFORE DELETE ON curriculum_versions FOR EACH ROW
BEGIN
IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_admission_rules_insert BEFORE INSERT ON admission_rules FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF; IF NEW.status='published' AND (NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.admission_window_source_id AND status='published')
 OR (NEW.state_source_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.state_source_id AND status='published'))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Publication requires reviewed admission-window and State sources'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_admission_rules_update BEFORE UPDATE ON admission_rules FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND NOT
 (OLD.status='published' AND NEW.status IN('superseded','withdrawn') AND (NEW.id<=>OLD.id) AND (NEW.trade_id<=>OLD.trade_id) AND (NEW.curriculum_id<=>OLD.curriculum_id) AND (NEW.source_id<=>OLD.source_id) AND (NEW.state_source_id<=>OLD.state_source_id) AND (NEW.version_code<=>OLD.version_code) AND (NEW.state_ut_code<=>OLD.state_ut_code) AND (NEW.minimum_age_years<=>OLD.minimum_age_years) AND (NEW.age_cutoff_date<=>OLD.age_cutoff_date) AND (NEW.qualification_routes_json<=>OLD.qualification_routes_json) AND (NEW.admission_opens_on<=>OLD.admission_opens_on) AND (NEW.admission_closes_on<=>OLD.admission_closes_on) AND (NEW.admission_window_source_id<=>OLD.admission_window_source_id) AND (NEW.institute_id<=>OLD.institute_id)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definitions are immutable; insert a new sourced version'; END IF;
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF; IF NEW.status='published' AND (NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.admission_window_source_id AND status='published')
 OR (NEW.state_source_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.state_source_id AND status='published'))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Publication requires reviewed admission-window and State sources'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_admission_rules_delete BEFORE DELETE ON admission_rules FOR EACH ROW
BEGIN
IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_reservation_policies_insert BEFORE INSERT ON reservation_policies FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_reservation_policies_update BEFORE UPDATE ON reservation_policies FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND NOT
 (OLD.status='published' AND NEW.status IN('superseded','withdrawn') AND (NEW.id<=>OLD.id) AND (NEW.state_ut_code<=>OLD.state_ut_code) AND (NEW.admission_year<=>OLD.admission_year) AND (NEW.version_code<=>OLD.version_code) AND (NEW.source_id<=>OLD.source_id) AND (NEW.policy_json<=>OLD.policy_json) AND (NEW.institute_id<=>OLD.institute_id)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definitions are immutable; insert a new sourced version'; END IF;
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_reservation_policies_delete BEFORE DELETE ON reservation_policies FOR EACH ROW
BEGIN
IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_exam_rule_versions_insert BEFORE INSERT ON exam_rule_versions FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_exam_rule_versions_update BEFORE UPDATE ON exam_rule_versions FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND NOT
 (OLD.status='published' AND NEW.status IN('superseded','withdrawn') AND (NEW.id<=>OLD.id) AND (NEW.source_id<=>OLD.source_id) AND (NEW.code<=>OLD.code) AND (NEW.cycle_type<=>OLD.cycle_type) AND (NEW.minimum_attendance<=>OLD.minimum_attendance) AND (NEW.minimum_formative<=>OLD.minimum_formative) AND (NEW.principal_relaxation_cap<=>OLD.principal_relaxation_cap) AND (NEW.relaxation_source_id<=>OLD.relaxation_source_id) AND (NEW.requires_prn<=>OLD.requires_prn) AND (NEW.requires_cbt_fee<=>OLD.requires_cbt_fee) AND (NEW.requires_practical_fee<=>OLD.requires_practical_fee) AND (NEW.requires_ojt_for_certificate<=>OLD.requires_ojt_for_certificate) AND (NEW.practical_upload_hours<=>OLD.practical_upload_hours) AND (NEW.effective_from<=>OLD.effective_from) AND (NEW.effective_to<=>OLD.effective_to) AND (NEW.institute_id<=>OLD.institute_id)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definitions are immutable; insert a new sourced version'; END IF;
 IF NEW.status='published' AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_exam_rule_versions_delete BEFORE DELETE ON exam_rule_versions FOR EACH ROW
BEGIN
IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published definition cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_curriculum_years_insert BEFORE INSERT ON curriculum_years FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_versions WHERE id=NEW.curriculum_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_curriculum_years_update BEFORE UPDATE ON curriculum_years FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_versions WHERE id=OLD.curriculum_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
IF NOT EXISTS (SELECT 1 FROM curriculum_versions WHERE id=NEW.curriculum_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_curriculum_years_delete BEFORE DELETE ON curriculum_years FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_versions WHERE id=OLD.curriculum_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_resource_requirements_insert BEFORE INSERT ON resource_requirements FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_versions WHERE id=NEW.curriculum_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_resource_requirements_update BEFORE UPDATE ON resource_requirements FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_versions WHERE id=OLD.curriculum_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
IF NOT EXISTS (SELECT 1 FROM curriculum_versions WHERE id=NEW.curriculum_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_resource_requirements_delete BEFORE DELETE ON resource_requirements FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_versions WHERE id=OLD.curriculum_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_learning_outcomes_insert BEFORE INSERT ON learning_outcomes FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_years y JOIN curriculum_versions c ON c.id=y.curriculum_id WHERE y.id=NEW.curriculum_year_id AND c.status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_learning_outcomes_update BEFORE UPDATE ON learning_outcomes FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_years y JOIN curriculum_versions c ON c.id=y.curriculum_id WHERE y.id=OLD.curriculum_year_id AND c.status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
IF NOT EXISTS (SELECT 1 FROM curriculum_years y JOIN curriculum_versions c ON c.id=y.curriculum_id WHERE y.id=NEW.curriculum_year_id AND c.status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_learning_outcomes_delete BEFORE DELETE ON learning_outcomes FOR EACH ROW
BEGIN
IF NOT EXISTS (SELECT 1 FROM curriculum_years y JOIN curriculum_versions c ON c.id=y.curriculum_id WHERE y.id=OLD.curriculum_year_id AND c.status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published curriculum detail is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_itsu_insert BEFORE INSERT ON trade_unit_shifts FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status IN('published','superseded')) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITSU approval requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_itsu_update BEFORE UPDATE ON trade_unit_shifts FOR EACH ROW
BEGIN
 IF NEW.trade_unit_id<>OLD.trade_unit_id OR NEW.trade_id<>OLD.trade_id OR NEW.shift_no<>OLD.shift_no THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITSU identity is immutable'; END IF;
 IF EXISTS(SELECT 1 FROM batches WHERE trade_unit_shift_id=OLD.id AND status<>'cancelled' AND capacity>NEW.sanctioned_capacity) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITSU capacity cannot invalidate existing batch'; END IF;
 IF NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status IN('published','superseded')) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITSU approval requires a reviewed official source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_trade_unit_identity BEFORE UPDATE ON trade_units FOR EACH ROW
BEGIN
 IF NEW.trade_id<>OLD.trade_id OR NEW.unit_no<>OLD.unit_no THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trade/unit identity is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_mapping_insert BEFORE INSERT ON instructor_mappings FOR EACH ROW
BEGIN
 SET NEW.identity_id=(SELECT identity_id FROM staff WHERE id=NEW.staff_id AND institute_id=NEW.institute_id);
 IF NEW.status='active' AND NOT EXISTS
 (SELECT 1 FROM staff WHERE id=NEW.staff_id AND iprn IS NOT NULL AND nims_verification='verified'
 AND left_on IS NULL AND joined_on<=NEW.valid_from) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mapping requires an employed, verified instructor with IPRN'; END IF;
 IF NEW.mapping_type='trade' AND NEW.status='active' AND NOT EXISTS
 (SELECT 1 FROM trade_unit_shifts us JOIN trade_units ua ON ua.id=us.trade_unit_id WHERE us.id=NEW.trade_unit_shift_id AND us.status='active' AND us.affiliation_status='approved') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trade mapping requires an approved ITSU'; END IF;
 IF NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.qualification_source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Instructor qualification check requires official trade norms'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_mapping_update BEFORE UPDATE ON instructor_mappings FOR EACH ROW
BEGIN
 SET NEW.identity_id=(SELECT identity_id FROM staff WHERE id=NEW.staff_id AND institute_id=NEW.institute_id);
 IF NEW.status='active' AND NOT EXISTS
 (SELECT 1 FROM staff WHERE id=NEW.staff_id AND iprn IS NOT NULL AND nims_verification='verified'
 AND left_on IS NULL AND joined_on<=NEW.valid_from) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mapping requires an employed, verified instructor with IPRN'; END IF;
 IF NEW.mapping_type='trade' AND NEW.status='active' AND NOT EXISTS
 (SELECT 1 FROM trade_unit_shifts us JOIN trade_units ua ON ua.id=us.trade_unit_id WHERE us.id=NEW.trade_unit_shift_id AND us.status='active' AND us.affiliation_status='approved') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trade mapping requires an approved ITSU'; END IF;
 IF NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.qualification_source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Instructor qualification check requires official trade norms'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_staff_separation BEFORE UPDATE ON staff FOR EACH ROW
BEGIN
 IF (NEW.left_on IS NOT NULL OR NEW.nims_verification<>'verified') AND EXISTS
 (SELECT 1 FROM instructor_mappings WHERE staff_id=OLD.id AND status='active') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Release active NIMS mapping before staff separation/inactivation'; END IF;
 IF OLD.iprn IS NOT NULL AND NOT(NEW.iprn<=>OLD.iprn) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IPRN is a lifetime identifier'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_matrix_insert BEFORE INSERT ON seat_matrix_versions FOR EACH ROW
BEGIN
 IF NEW.supersedes_id IS NOT NULL AND (NEW.supersedes_id=NEW.id OR NOT EXISTS(SELECT 1 FROM seat_matrix_versions WHERE id=NEW.supersedes_id AND institute_id=NEW.institute_id AND intake_id=NEW.intake_id AND admission_year=NEW.admission_year AND status IN('final','superseded'))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Matrix predecessor must be reviewed and belong to the same intake'; END IF;
 IF NEW.status IN('final','superseded') AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final matrix requires reviewed official source'; END IF;
 IF NEW.status='final' AND NOT EXISTS(SELECT 1 FROM seat_matrix_entries WHERE version_id=NEW.id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Load official entries in draft before finalizing matrix'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_matrix_update BEFORE UPDATE ON seat_matrix_versions FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND NOT(OLD.status='final' AND NEW.status='superseded'
 AND NEW.admission_year=OLD.admission_year AND NEW.intake_id=OLD.intake_id AND NEW.version_code=OLD.version_code
 AND NEW.source_id=OLD.source_id AND NEW.portal_reference=OLD.portal_reference
 AND NEW.dgt_circular_no<=>OLD.dgt_circular_no AND NEW.published_on<=>OLD.published_on AND NEW.supersedes_id<=>OLD.supersedes_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final matrix identity is immutable'; END IF;
 IF NEW.supersedes_id IS NOT NULL AND (NEW.supersedes_id=NEW.id OR NOT EXISTS(SELECT 1 FROM seat_matrix_versions WHERE id=NEW.supersedes_id AND institute_id=NEW.institute_id AND intake_id=NEW.intake_id AND admission_year=NEW.admission_year AND status IN('final','superseded'))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Matrix predecessor must be reviewed and belong to the same intake'; END IF;
 IF NEW.status IN('final','superseded') AND NOT EXISTS
 (SELECT 1 FROM regulatory_sources WHERE id=NEW.source_id AND status='published') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final matrix requires reviewed official source'; END IF;
 IF NEW.status='final' AND NOT EXISTS(SELECT 1 FROM seat_matrix_entries WHERE version_id=NEW.id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Load official entries in draft before finalizing matrix'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_matrix_delete BEFORE DELETE ON seat_matrix_versions FOR EACH ROW
BEGIN
IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final matrix cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_matrix_entry_insert BEFORE INSERT ON seat_matrix_entries FOR EACH ROW
BEGIN
IF NOT EXISTS(SELECT 1 FROM seat_matrix_versions WHERE id=NEW.version_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final matrix entries are immutable'; END IF;
IF NOT EXISTS(SELECT 1 FROM instructor_mappings WHERE id=NEW.instructor_mapping_id
 AND mapping_type='trade' AND trade_unit_shift_id=NEW.trade_unit_shift_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Seat matrix instructor must belong to the same ITSU'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_matrix_entry_update BEFORE UPDATE ON seat_matrix_entries FOR EACH ROW
BEGIN
IF NOT EXISTS(SELECT 1 FROM seat_matrix_versions WHERE id=OLD.version_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final matrix entries are immutable'; END IF;
IF NOT EXISTS(SELECT 1 FROM seat_matrix_versions WHERE id=NEW.version_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final matrix entries are immutable'; END IF;
IF NOT EXISTS(SELECT 1 FROM instructor_mappings WHERE id=NEW.instructor_mapping_id
 AND mapping_type='trade' AND trade_unit_shift_id=NEW.trade_unit_shift_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Seat matrix instructor must belong to the same ITSU'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_matrix_entry_delete BEFORE DELETE ON seat_matrix_entries FOR EACH ROW
BEGIN
IF NOT EXISTS(SELECT 1 FROM seat_matrix_versions WHERE id=OLD.version_id AND status='draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final matrix entries are immutable'; END IF;

END$$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE assert_batch_intake(IN p_entry BIGINT UNSIGNED, IN p_unit BIGINT UNSIGNED,
 IN p_trade BIGINT UNSIGNED, IN p_curriculum BIGINT UNSIGNED, IN p_year SMALLINT,
 IN p_start DATE, IN p_capacity INT, IN p_rule BIGINT UNSIGNED, IN p_intake BIGINT UNSIGNED)
BEGIN
 DECLARE v_count INT DEFAULT 0;
 SELECT COUNT(*) INTO v_count
 FROM seat_matrix_entries e JOIN seat_matrix_versions m ON m.id=e.version_id
 JOIN trade_unit_shifts us ON us.id=e.trade_unit_shift_id JOIN trade_units u ON u.id=us.trade_unit_id JOIN curriculum_versions c ON c.id=p_curriculum
 JOIN admission_rules r ON r.id=p_rule JOIN admission_intakes ai ON ai.id=p_intake JOIN institute i ON i.id=us.institute_id
 JOIN instructor_mappings n ON n.id=e.instructor_mapping_id
 JOIN staff s ON s.id=n.staff_id
 WHERE e.id=p_entry AND e.institute_id=us.institute_id AND m.institute_id=us.institute_id AND r.institute_id=us.institute_id AND ai.institute_id=us.institute_id AND e.trade_unit_shift_id=p_unit AND u.trade_id=p_trade AND c.trade_id=p_trade
 AND m.admission_year=p_year AND m.intake_id=p_intake AND m.status='final'
 AND ai.admission_year=p_year AND ai.status='published'
 AND e.admission_allowed=1 AND e.consumed_previous_session=0 AND e.instructor_mapping_satisfied=1
 AND p_capacity BETWEEN 1 AND LEAST(e.seat_capacity,us.sanctioned_capacity,c.unit_strength)
 AND us.status='active' AND us.affiliation_status='approved' AND p_start>=us.valid_from AND (us.valid_to IS NULL OR (p_start<=us.valid_to AND CURRENT_DATE<=us.valid_to))
 AND EXISTS(SELECT 1 FROM regulatory_sources WHERE id=us.source_id AND status IN('published','superseded'))
 AND EXISTS(SELECT 1 FROM regulatory_sources WHERE id=r.admission_window_source_id AND status IN('published','superseded'))
 AND EXISTS(SELECT 1 FROM regulatory_sources WHERE id=r.source_id AND status IN('published','superseded'))
 AND (r.state_source_id IS NULL OR EXISTS(SELECT 1 FROM regulatory_sources WHERE id=r.state_source_id AND status IN('published','superseded')))
 AND EXISTS(SELECT 1 FROM regulatory_sources WHERE id=ai.source_id AND status IN('published','superseded'))
 AND i.affiliation_status='affiliated' AND p_start>=i.affiliation_from AND (i.affiliation_to IS NULL OR (p_start<=i.affiliation_to AND CURRENT_DATE<=i.affiliation_to))
 AND c.status IN('published','superseded') AND p_start>=c.effective_from AND (c.effective_to IS NULL OR p_start<=c.effective_to)
 AND NOT EXISTS(SELECT 1 FROM curriculum_versions newer WHERE newer.trade_id=c.trade_id AND newer.institute_id=c.institute_id AND newer.status='published'
 AND newer.effective_from>c.effective_from AND newer.effective_from<=p_start)
 AND r.status='published' AND r.trade_id=p_trade AND r.curriculum_id=p_curriculum AND r.state_ut_code=i.state_ut_code
 AND n.mapping_type='trade' AND n.trade_unit_shift_id=p_unit AND n.status='active'
 AND n.valid_from<=p_start AND (n.valid_to IS NULL OR n.valid_to>=p_start)
 AND s.nims_verification='verified' AND s.left_on IS NULL;
 IF v_count<>1 THEN SIGNAL SQLSTATE '45000'
 SET MESSAGE_TEXT='Intake requires current final ITSU, valid affiliation, matching published curriculum/rules and NIMS mapping'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_batch_insert BEFORE INSERT ON batches FOR EACH ROW
BEGIN
 DECLARE v_lock BIGINT UNSIGNED;
 SELECT id INTO v_lock FROM trade_unit_shifts WHERE id=NEW.trade_unit_shift_id FOR UPDATE;
 IF NEW.status<>'cancelled' AND EXISTS(SELECT 1 FROM batches WHERE trade_unit_shift_id=NEW.trade_unit_shift_id AND status<>'cancelled' AND starts_on<=NEW.ends_on AND ends_on>=NEW.starts_on) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITSU training periods overlap'; END IF;
 SET NEW.training_mode=(SELECT training_mode FROM trade_unit_shifts WHERE id=NEW.trade_unit_shift_id);
 SET NEW.itsu_approval_snapshot_json=(SELECT JSON_OBJECT('shift_id',id,'source_id',source_id,'approval_reference',approval_reference,'training_mode',training_mode,'affiliation_status',affiliation_status,'capacity',sanctioned_capacity,'valid_from',valid_from,'valid_to',valid_to) FROM trade_unit_shifts WHERE id=NEW.trade_unit_shift_id);
 CALL assert_batch_intake(NEW.seat_matrix_entry_id,NEW.trade_unit_shift_id,NEW.trade_id,
 NEW.curriculum_id,NEW.admission_year,NEW.starts_on,NEW.capacity,NEW.admission_rule_id,NEW.intake_id);
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_batch_update BEFORE UPDATE ON batches FOR EACH ROW
BEGIN
 DECLARE v_used INT DEFAULT 0; DECLARE v_lock BIGINT UNSIGNED;
 SELECT id INTO v_lock FROM trade_unit_shifts WHERE id=NEW.trade_unit_shift_id FOR UPDATE;
 IF NEW.status<>'cancelled' AND EXISTS(SELECT 1 FROM batches WHERE id<>OLD.id AND trade_unit_shift_id=NEW.trade_unit_shift_id AND status<>'cancelled' AND starts_on<=NEW.ends_on AND ends_on>=NEW.starts_on) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ITSU training periods overlap'; END IF;
 IF NEW.trade_unit_shift_id<>OLD.trade_unit_shift_id OR NEW.trade_id<>OLD.trade_id OR NEW.curriculum_id<>OLD.curriculum_id
 OR NEW.seat_matrix_entry_id<>OLD.seat_matrix_entry_id OR NEW.admission_year<>OLD.admission_year
 OR NEW.admission_rule_id<>OLD.admission_rule_id OR NEW.intake_id<>OLD.intake_id OR NEW.starts_on<>OLD.starts_on
 OR NEW.training_mode<>OLD.training_mode OR NOT(NEW.itsu_approval_snapshot_json<=>OLD.itsu_approval_snapshot_json) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cohort regulatory identity is frozen; create a replacement batch'; END IF;
 SET v_used=NEW.occupied_seats;
 IF NEW.capacity<v_used THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Capacity cannot fall below occupied seats'; END IF;
 IF NEW.capacity<>OLD.capacity OR (NEW.status='active' AND OLD.status<>'active') THEN
 CALL assert_batch_intake(NEW.seat_matrix_entry_id,NEW.trade_unit_shift_id,NEW.trade_id,
 NEW.curriculum_id,NEW.admission_year,NEW.starts_on,NEW.capacity,NEW.admission_rule_id,NEW.intake_id); END IF;
 IF NEW.status='cancelled' AND v_used>0 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Resolve occupied enrollments before cancelling batch'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE assert_enrollment(IN p_id BIGINT UNSIGNED,IN p_student BIGINT UNSIGNED,
 IN p_batch BIGINT UNSIGNED,IN p_rule BIGINT UNSIGNED,IN p_qualification BIGINT UNSIGNED,
 IN p_admitted DATE,IN p_status VARCHAR(30),IN p_passed INT,IN p_vertical BIGINT UNSIGNED,IN p_horizontal BIGINT UNSIGNED)
BEGIN
 DECLARE v_capacity INT DEFAULT NULL; DECLARE v_used INT DEFAULT 0;
 DECLARE v_count INT DEFAULT 0; DECLARE v_quota INT DEFAULT 0;
 SELECT capacity INTO v_capacity FROM batches WHERE id=p_batch FOR UPDATE;
 IF v_capacity IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unknown batch'; END IF;
 -- Occupancy is reserved atomically by enrollment triggers after validation.
 SELECT COUNT(*) INTO v_count FROM batches b JOIN admission_rules r ON r.id=b.admission_rule_id
 JOIN admission_intakes ai ON ai.id=b.intake_id JOIN students s ON s.id=p_student JOIN student_qualifications q ON q.id=p_qualification
 WHERE b.id=p_batch AND r.id=p_rule AND q.student_id=s.id
 AND TIMESTAMPDIFF(YEAR,s.date_of_birth,r.age_cutoff_date)>=r.minimum_age_years
 AND p_admitted BETWEEN r.admission_opens_on AND r.admission_closes_on
 AND p_admitted BETWEEN ai.opens_on AND ai.closes_on;
 IF v_count<>1 OR p_passed<>1 THEN SIGNAL SQLSTATE '45000'
 SET MESSAGE_TEXT='Admission requires matching verified qualification, age and sourced admission window'; END IF;
 IF p_status='active' AND NOT EXISTS(SELECT 1 FROM students WHERE id=p_student
 AND prn IS NOT NULL AND prn_verified_on IS NOT NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Activate trainee only after official PRN verification'; END IF;
 IF p_vertical IS NOT NULL THEN
 SELECT COUNT(*),COALESCE(MAX(seats),0) INTO v_count,v_quota FROM batch_quotas
 WHERE id=p_vertical AND batch_id=p_batch AND quota_type='vertical';
 IF v_count<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong vertical quota'; END IF;
 END IF;
 IF p_horizontal IS NOT NULL THEN
 SELECT COUNT(*),COALESCE(MAX(seats),0) INTO v_count,v_quota FROM batch_quotas
 WHERE id=p_horizontal AND batch_id=p_batch AND quota_type='horizontal';
 IF v_count<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong horizontal quota'; END IF;
 END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_enrollment_insert BEFORE INSERT ON enrollments FOR EACH ROW
BEGIN
 CALL assert_enrollment(COALESCE(NEW.id,0),NEW.student_id,NEW.batch_id,NEW.admission_rule_id,
 NEW.qualification_id,NEW.admitted_on,NEW.status,NEW.qualification_check_passed,NEW.vertical_quota_id,NEW.horizontal_quota_id);
 IF (NEW.vertical_quota_id IS NOT NULL OR NEW.horizontal_quota_id IS NOT NULL) AND NEW.quota_evidence_reference IS NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reservation claim requires verified evidence'; END IF;
 IF NOT EXISTS(SELECT 1 FROM batches b JOIN trade_unit_shifts us ON us.id=b.trade_unit_shift_id WHERE b.id=NEW.batch_id AND b.status IN('planned','active') AND b.training_mode=us.training_mode) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='New enrollment requires an open cohort'; END IF;
 CALL assert_batch_intake((SELECT seat_matrix_entry_id FROM batches WHERE id=NEW.batch_id),
 (SELECT trade_unit_shift_id FROM batches WHERE id=NEW.batch_id),(SELECT trade_id FROM batches WHERE id=NEW.batch_id),
 (SELECT curriculum_id FROM batches WHERE id=NEW.batch_id),(SELECT admission_year FROM batches WHERE id=NEW.batch_id),
 NEW.admitted_on,(SELECT capacity FROM batches WHERE id=NEW.batch_id),NEW.admission_rule_id,(SELECT intake_id FROM batches WHERE id=NEW.batch_id)); IF NEW.status IN('pending_verification','active','completed') THEN
 UPDATE batches SET occupied_seats=occupied_seats+1 WHERE id=NEW.batch_id AND occupied_seats<capacity;
 IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Sanctioned batch capacity is full'; END IF;
 IF NEW.vertical_quota_id IS NOT NULL THEN
 UPDATE batch_quotas SET occupied_seats=occupied_seats+1 WHERE id=NEW.vertical_quota_id AND occupied_seats<seats;
 IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vertical quota is full'; END IF; END IF;
 IF NEW.horizontal_quota_id IS NOT NULL THEN
 UPDATE batch_quotas SET occupied_seats=occupied_seats+1 WHERE id=NEW.horizontal_quota_id AND occupied_seats<seats;
 IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Horizontal quota is full'; END IF; END IF;
 END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_enrollment_update BEFORE UPDATE ON enrollments FOR EACH ROW
BEGIN
 CALL assert_enrollment(COALESCE(NEW.id,0),NEW.student_id,NEW.batch_id,NEW.admission_rule_id,
 NEW.qualification_id,NEW.admitted_on,NEW.status,NEW.qualification_check_passed,NEW.vertical_quota_id,NEW.horizontal_quota_id);
 IF (NEW.vertical_quota_id IS NOT NULL OR NEW.horizontal_quota_id IS NOT NULL) AND NEW.quota_evidence_reference IS NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reservation claim requires verified evidence'; END IF;
 IF NEW.student_id<>OLD.student_id OR NEW.batch_id<>OLD.batch_id OR NEW.admission_rule_id<>OLD.admission_rule_id
 OR NEW.admitted_on<>OLD.admitted_on OR NEW.qualification_id<>OLD.qualification_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admission identity is frozen; use a sourced transfer/correction'; END IF;
 IF OLD.status IN('transferred','dropout','cancelled','completed') AND NEW.status<>OLD.status THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Closed enrollment cannot be reopened; record a new admission'; END IF; IF OLD.status IN('pending_verification','active','completed') THEN
 UPDATE batches SET occupied_seats=occupied_seats-1 WHERE id=OLD.batch_id;
 IF OLD.vertical_quota_id IS NOT NULL THEN UPDATE batch_quotas SET occupied_seats=occupied_seats-1 WHERE id=OLD.vertical_quota_id; END IF;
 IF OLD.horizontal_quota_id IS NOT NULL THEN UPDATE batch_quotas SET occupied_seats=occupied_seats-1 WHERE id=OLD.horizontal_quota_id; END IF;
 END IF; IF NEW.status IN('pending_verification','active','completed') THEN
 UPDATE batches SET occupied_seats=occupied_seats+1 WHERE id=NEW.batch_id AND occupied_seats<capacity;
 IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Sanctioned batch capacity is full'; END IF;
 IF NEW.vertical_quota_id IS NOT NULL THEN
 UPDATE batch_quotas SET occupied_seats=occupied_seats+1 WHERE id=NEW.vertical_quota_id AND occupied_seats<seats;
 IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vertical quota is full'; END IF; END IF;
 IF NEW.horizontal_quota_id IS NOT NULL THEN
 UPDATE batch_quotas SET occupied_seats=occupied_seats+1 WHERE id=NEW.horizontal_quota_id AND occupied_seats<seats;
 IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Horizontal quota is full'; END IF; END IF;
 END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_enrollment_delete BEFORE DELETE ON enrollments FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Record cancellation/transfer instead of deleting an admission';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_attendance_insert BEFORE INSERT ON attendance_records FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT y.id INTO v_year_lock FROM attendance_sessions a JOIN batch_years y ON y.id=a.batch_year_id WHERE a.id=NEW.session_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM attendance_sessions a JOIN batch_years y ON y.id=a.batch_year_id
 JOIN enrollments e ON e.batch_id=y.batch_id WHERE a.id=NEW.session_id AND e.id=NEW.enrollment_id
 AND a.session_date BETWEEN y.starts_on AND y.ends_on AND NEW.attended_minutes<=a.scheduled_minutes
 AND a.locked_on IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance requires same cohort, valid minutes and unlocked session'; END IF;
 IF NEW.status IN('absent','leave','medical_leave','emergency_leave') AND NEW.attended_minutes<>0 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Leave/absence cannot count as attended minutes'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_attendance_update BEFORE UPDATE ON attendance_records FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT y.id INTO v_year_lock FROM attendance_sessions a JOIN batch_years y ON y.id=a.batch_year_id WHERE a.id=NEW.session_id FOR UPDATE;
 IF NEW.session_id<>OLD.session_id OR NEW.enrollment_id<>OLD.enrollment_id THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance record identity is immutable'; END IF;
 IF NOT EXISTS(SELECT 1 FROM attendance_sessions a JOIN batch_years y ON y.id=a.batch_year_id
 JOIN enrollments e ON e.batch_id=y.batch_id WHERE a.id=NEW.session_id AND e.id=NEW.enrollment_id
 AND a.session_date BETWEEN y.starts_on AND y.ends_on AND NEW.attended_minutes<=a.scheduled_minutes
 AND a.locked_on IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance requires same cohort, valid minutes and unlocked session'; END IF;
 IF NEW.status IN('absent','leave','medical_leave','emergency_leave') AND NEW.attended_minutes<>0 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Leave/absence cannot count as attended minutes'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_attendance_delete BEFORE DELETE ON attendance_records FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT y.id INTO v_year_lock FROM attendance_sessions a JOIN batch_years y ON y.id=a.batch_year_id WHERE a.id=OLD.session_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM attendance_sessions WHERE id=OLD.session_id AND locked_on IS NOT NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked attendance cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER lock_session_insert BEFORE INSERT ON attendance_sessions FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT id INTO v_year_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
 
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER lock_session_delete BEFORE DELETE ON attendance_sessions FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT id INTO v_year_lock FROM batch_years WHERE id=OLD.batch_year_id FOR UPDATE;
 IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked attendance session cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_attendance_session_lock BEFORE UPDATE ON attendance_sessions FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT id INTO v_year_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
 IF NEW.batch_year_id<>OLD.batch_year_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance session cohort is immutable'; END IF;
 IF OLD.locked_on IS NOT NULL AND (
 NOT(NEW.locked_on<=>OLD.locked_on) OR NEW.batch_year_id<>OLD.batch_year_id OR NEW.session_date<>OLD.session_date
 OR NEW.component<>OLD.component OR NEW.scheduled_minutes<>OLD.scheduled_minutes OR NEW.instructor_id<>OLD.instructor_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked session cannot be reopened/rewritten; append approved correction'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_attendance_correction BEFORE INSERT ON attendance_corrections FOR EACH ROW
BEGIN
 DECLARE v_minutes INT; DECLARE v_status VARCHAR(40); DECLARE v_scheduled INT; DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT y.id INTO v_year_lock FROM attendance_sessions a JOIN batch_years y ON y.id=a.batch_year_id WHERE a.id=NEW.session_id FOR UPDATE;
 IF JSON_CONTAINS_PATH(NEW.after_json,'all','$.attended_minutes','$.status')<>1 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction requires attended_minutes and status'; END IF;
 SET v_minutes=CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_json,'$.attended_minutes')) AS SIGNED);
 SET v_status=JSON_UNQUOTE(JSON_EXTRACT(NEW.after_json,'$.status'));
 SELECT scheduled_minutes INTO v_scheduled FROM attendance_sessions WHERE id=NEW.session_id;
 IF v_minutes<0 OR v_minutes>v_scheduled OR v_status NOT IN('present','absent','leave','medical_leave','emergency_leave','ojt')
 OR (v_status IN('absent','leave','medical_leave','emergency_leave') AND v_minutes<>0) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction minutes/status are invalid'; END IF;
 SET NEW.before_json=COALESCE((SELECT c.after_json FROM attendance_corrections c
 WHERE c.session_id=NEW.session_id AND c.enrollment_id=NEW.enrollment_id ORDER BY c.id DESC LIMIT 1),
 (SELECT JSON_OBJECT('attended_minutes',attended_minutes,'status',status) FROM attendance_records
 WHERE session_id=NEW.session_id AND enrollment_id=NEW.enrollment_id));
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_locked_formative_update BEFORE UPDATE ON formative_assessments FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked formative assessment cannot be changed'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_locked_formative_delete BEFORE DELETE ON formative_assessments FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked formative assessment cannot be changed'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_relaxation_insert BEFORE INSERT ON attendance_relaxations FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM exam_cycles c JOIN exam_rule_versions r ON r.id=c.rule_id
 JOIN enrollments e ON e.id=NEW.enrollment_id JOIN batch_years y ON y.id=NEW.batch_year_id AND y.batch_id=e.batch_id
 WHERE c.id=NEW.cycle_id AND c.status='eligibility_open' AND NEW.points<=r.principal_relaxation_cap
 AND NEW.authority_source_id=r.relaxation_source_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Relaxation exceeds authorized rule cap or has wrong cycle/cohort/source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_relaxation_update BEFORE UPDATE ON attendance_relaxations FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM exam_cycles c JOIN exam_rule_versions r ON r.id=c.rule_id
 JOIN enrollments e ON e.id=NEW.enrollment_id JOIN batch_years y ON y.id=NEW.batch_year_id AND y.batch_id=e.batch_id
 WHERE c.id=NEW.cycle_id AND c.status='eligibility_open' AND NEW.points<=r.principal_relaxation_cap
 AND NEW.authority_source_id=r.relaxation_source_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Relaxation exceeds authorized rule cap or has wrong cycle/cohort/source'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER lock_formative_insert BEFORE INSERT ON formative_assessments FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT id INTO v_year_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
 
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER lock_formative_update BEFORE UPDATE ON formative_assessments FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT id INTO v_year_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
 
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER lock_formative_delete BEFORE DELETE ON formative_assessments FOR EACH ROW
BEGIN
 DECLARE v_year_lock BIGINT UNSIGNED;
 SELECT id INTO v_year_lock FROM batch_years WHERE id=OLD.batch_year_id FOR UPDATE;
 
END$$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE assert_register_reconciled(IN p_enrollment BIGINT UNSIGNED,IN p_year BIGINT UNSIGNED,
 IN p_cycle BIGINT UNSIGNED,IN p_scheduled DECIMAL(10,2),IN p_attended DECIMAL(10,2),IN p_form DECIMAL(5,2),IN p_complete TINYINT)
BEGIN
 DECLARE v_end DATE; DECLARE v_start DATE; DECLARE v_cy BIGINT UNSIGNED;
 DECLARE v_scheduled DECIMAL(12,2); DECLARE v_attended DECIMAL(12,2);
 DECLARE v_required INT; DECLARE v_done INT; DECLARE v_marks DECIMAL(12,2); DECLARE v_max DECIMAL(12,2);
 DECLARE v_lock BIGINT UNSIGNED;
 SELECT id INTO v_lock FROM batch_years WHERE id=p_year FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM batch_years y JOIN exam_cycles c ON c.id=p_cycle
 JOIN regulatory_sources a ON a.id=y.attendance_basis_source_id
 JOIN regulatory_sources f ON f.id=y.formative_scheme_source_id
 WHERE y.id=p_year AND y.calendar_status='reviewed' AND y.calendar_reviewed_through>=LEAST(y.ends_on,c.eligibility_cutoff)
 AND a.status IN('published','superseded') AND f.status IN('published','superseded')
 AND y.formative_method='points_ratio' AND y.formative_scheme_reference IS NOT NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Register reconciliation requires reviewed calendar and sourced formative scheme'; END IF;
 IF (SELECT attendance_basis FROM batch_years WHERE id=p_year)<>'minutes' THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Working-day reconciliation blocked pending applicable State method'; END IF;
 SELECT y.starts_on,LEAST(y.ends_on,c.eligibility_cutoff),y.curriculum_year_id INTO v_start,v_end,v_cy
 FROM batch_years y JOIN exam_cycles c ON c.id=p_cycle WHERE y.id=p_year;
 IF EXISTS(SELECT 1 FROM training_calendar_entries t LEFT JOIN attendance_sessions a
 ON a.batch_year_id=t.batch_year_id AND a.session_date=t.session_date AND a.component=t.component
 LEFT JOIN v_effective_attendance r ON r.session_id=a.id AND r.enrollment_id=p_enrollment
 WHERE t.batch_year_id=p_year AND t.session_date BETWEEN v_start AND v_end
 AND (a.id IS NULL OR a.locked_on IS NULL OR a.scheduled_minutes<>t.scheduled_minutes OR r.session_id IS NULL)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar session or locked trainee attendance is missing'; END IF;
 IF EXISTS(SELECT 1 FROM attendance_sessions a LEFT JOIN training_calendar_entries t
 ON t.batch_year_id=a.batch_year_id AND t.session_date=a.session_date AND t.component=a.component
 WHERE a.batch_year_id=p_year AND a.session_date BETWEEN v_start AND v_end AND t.batch_year_id IS NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance contains an unsourced calendar session'; END IF;
 SELECT COALESCE(SUM(t.scheduled_minutes),0),COALESCE(SUM(r.attended_minutes),0) INTO v_scheduled,v_attended
 FROM training_calendar_entries t JOIN attendance_sessions a
 ON a.batch_year_id=t.batch_year_id AND a.session_date=t.session_date AND a.component=t.component
 JOIN v_effective_attendance r ON r.session_id=a.id AND r.enrollment_id=p_enrollment
 WHERE t.batch_year_id=p_year AND t.session_date BETWEEN v_start AND v_end;
 IF v_scheduled=0 OR p_scheduled<>v_scheduled OR p_attended<>v_attended THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Supplied attendance totals differ from approved calendar and corrected registers'; END IF;
 SELECT COUNT(*),COALESCE(SUM(formative_max_marks),0) INTO v_required,v_max FROM learning_outcomes WHERE curriculum_year_id=v_cy;
 IF v_required=0 OR EXISTS(SELECT 1 FROM learning_outcomes WHERE curriculum_year_id=v_cy AND formative_max_marks IS NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Formative points scheme lacks required outcome weights'; END IF;
 SELECT COUNT(*),COALESCE(SUM(f.obtained_marks),0) INTO v_done,v_marks
 FROM formative_assessments f JOIN learning_outcomes l ON l.id=f.learning_outcome_id
 WHERE f.enrollment_id=p_enrollment AND f.batch_year_id=p_year AND f.locked_on IS NOT NULL
 AND f.assessed_on BETWEEN v_start AND v_end AND f.max_marks=l.formative_max_marks;
 IF v_done<>v_required OR p_complete<>1 OR ABS(p_form-ROUND(100*v_marks/v_max,2))>0.01 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Formative totals or completion differ from locked required outcome assessments'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_calendar_insert BEFORE INSERT ON training_calendar_entries FOR EACH ROW
BEGIN
 DECLARE v_lock BIGINT UNSIGNED;
 SELECT id INTO v_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM batch_years WHERE id=NEW.batch_year_id AND (calendar_status='draft' OR NEW.session_date>calendar_reviewed_through)
 AND NEW.session_date BETWEEN starts_on AND ends_on) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed calendar is immutable; use an authorized replacement year/profile'; END IF;
 
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_calendar_update BEFORE UPDATE ON training_calendar_entries FOR EACH ROW
BEGIN
 DECLARE v_lock BIGINT UNSIGNED;
 SELECT id INTO v_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM batch_years WHERE id=NEW.batch_year_id AND (calendar_status='draft' OR NEW.session_date>calendar_reviewed_through)
 AND NEW.session_date BETWEEN starts_on AND ends_on) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed calendar is immutable; use an authorized replacement year/profile'; END IF;
 IF NEW.batch_year_id<>OLD.batch_year_id OR EXISTS(SELECT 1 FROM batch_years WHERE id=OLD.batch_year_id AND calendar_status='reviewed' AND OLD.session_date<=calendar_reviewed_through) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar identity is immutable'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_calendar_delete BEFORE DELETE ON training_calendar_entries FOR EACH ROW
BEGIN
 DECLARE v_lock BIGINT UNSIGNED;
 SELECT id INTO v_lock FROM batch_years WHERE id=OLD.batch_year_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM batch_years WHERE id=OLD.batch_year_id AND (calendar_status='draft' OR OLD.session_date>calendar_reviewed_through)
 AND OLD.session_date BETWEEN starts_on AND ends_on) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed calendar is immutable; use an authorized replacement year/profile'; END IF;
 
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_year_register_profile BEFORE UPDATE ON batch_years FOR EACH ROW
BEGIN
 IF OLD.calendar_status='reviewed' AND
 (NEW.calendar_status<>OLD.calendar_status OR NEW.starts_on<>OLD.starts_on OR NEW.ends_on<>OLD.ends_on
 OR NEW.batch_id<>OLD.batch_id OR NEW.curriculum_year_id<>OLD.curriculum_year_id
 OR NEW.attendance_basis<>OLD.attendance_basis OR NEW.attendance_basis_source_id<>OLD.attendance_basis_source_id
  OR NEW.formative_method<>OLD.formative_method OR NOT(NEW.formative_scheme_source_id<=>OLD.formative_scheme_source_id)
 OR NOT(NEW.formative_scheme_reference<=>OLD.formative_scheme_reference)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed register profile is immutable'; END IF;
 IF OLD.calendar_status='reviewed' AND (NEW.calendar_reviewed_through<OLD.calendar_reviewed_through OR (NEW.calendar_reviewed_through>OLD.calendar_reviewed_through AND NEW.calendar_evidence_reference<=>OLD.calendar_evidence_reference)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Extending calendar review needs new signed evidence; reviewed dates cannot shrink'; END IF;
 IF NEW.calendar_status='reviewed' AND (NEW.calendar_reviewed_through IS NULL OR NEW.calendar_reviewed_by IS NULL
 OR NEW.calendar_evidence_reference IS NULL OR NOT EXISTS(SELECT 1 FROM training_calendar_entries WHERE batch_year_id=NEW.id)
 OR NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.attendance_basis_source_id AND status IN('published','superseded'))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar review needs sourced schedule, evidence and responsible reviewer'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_year_register_insert BEFORE INSERT ON batch_years FOR EACH ROW
BEGIN
 IF NEW.calendar_status<>'draft' THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Load calendar in draft before review'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_eligibility_insert BEFORE INSERT ON exam_eligibility FOR EACH ROW
BEGIN
 DECLARE v_att DECIMAL(5,2); DECLARE v_form DECIMAL(5,2); DECLARE v_points DECIMAL(5,2) DEFAULT 0;
 DECLARE v_prn INT; DECLARE v_cbt INT; DECLARE v_practical INT; DECLARE v_rule BIGINT UNSIGNED;
 DECLARE v_found INT DEFAULT 0;
 SELECT COUNT(*),MAX(r.id),MAX(r.minimum_attendance),MAX(r.minimum_formative),MAX(r.requires_prn),
 MAX(r.requires_cbt_fee),MAX(r.requires_practical_fee)
 INTO v_found,v_rule,v_att,v_form,v_prn,v_cbt,v_practical
 FROM exam_cycles c JOIN exam_rule_versions r ON r.id=c.rule_id
 JOIN batch_years y ON y.id=NEW.batch_year_id
 WHERE c.id=NEW.cycle_id AND c.status='eligibility_open' AND r.status IN('published','superseded')
 AND c.exam_starts_on>=r.effective_from AND (r.effective_to IS NULL OR c.exam_starts_on<=r.effective_to)
 AND r.cycle_type=c.cycle_type AND NEW.attendance_basis=y.attendance_basis;
 IF v_found<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Eligibility requires open cycle and published matching rule/basis'; END IF;
 IF ABS(NEW.raw_attendance-ROUND(100*NEW.attended_units/NEW.scheduled_units,2))>0.01 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance percentage does not match numerator/denominator'; END IF;
 IF NEW.relaxation_id IS NOT NULL THEN
 SELECT COUNT(*),COALESCE(MAX(points),0) INTO v_found,v_points FROM attendance_relaxations
 WHERE id=NEW.relaxation_id AND enrollment_id=NEW.enrollment_id AND cycle_id=NEW.cycle_id AND batch_year_id=NEW.batch_year_id;
 IF v_found<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Relaxation belongs to another trainee/cycle/year'; END IF;
 END IF;
 IF ABS(NEW.effective_attendance-LEAST(100,NEW.raw_attendance+v_points))>0.01 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Effective attendance must use authorized relaxation'; END IF;
 IF NEW.eligibility_status='eligible' THEN
 IF NEW.effective_attendance<v_att OR NEW.formative_percent<v_form OR NEW.formative_complete<>1 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance/formative eligibility requirement failed'; END IF;
 IF v_prn=1 AND NOT EXISTS(SELECT 1 FROM enrollments e JOIN students s ON s.id=e.student_id
 WHERE e.id=NEW.enrollment_id AND s.prn IS NOT NULL AND s.prn_verified_on IS NOT NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Verified PRN is required'; END IF;
 IF (v_cbt=1 OR v_practical=1) AND NOT EXISTS(SELECT 1 FROM exam_fee_clearances f WHERE f.enrollment_id=NEW.enrollment_id AND f.cycle_id=NEW.cycle_id
 AND (v_cbt=0 OR f.cbt_cleared=1) AND (v_practical=0 OR f.practical_cleared=1)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Required examination fee clearance missing'; END IF;
 CALL assert_register_reconciled(NEW.enrollment_id,NEW.batch_year_id,NEW.cycle_id,NEW.scheduled_units,NEW.attended_units,NEW.formative_percent,NEW.formative_complete);
 END IF;
 SET NEW.rule_snapshot_json=(SELECT JSON_OBJECT('rule_id',id,'source_id',source_id,'minimum_attendance',minimum_attendance,
 'minimum_formative',minimum_formative,'principal_relaxation_cap',principal_relaxation_cap,
 'requires_prn',requires_prn,'requires_cbt_fee',requires_cbt_fee,'requires_practical_fee',requires_practical_fee,
 'attendance_basis_source_id',(SELECT attendance_basis_source_id FROM batch_years WHERE id=NEW.batch_year_id),
 'calendar_evidence',(SELECT calendar_evidence_reference FROM batch_years WHERE id=NEW.batch_year_id),
 'calendar_reviewed_through',(SELECT calendar_reviewed_through FROM batch_years WHERE id=NEW.batch_year_id),
 'formative_method',(SELECT formative_method FROM batch_years WHERE id=NEW.batch_year_id),
 'formative_scheme_source_id',(SELECT formative_scheme_source_id FROM batch_years WHERE id=NEW.batch_year_id),
 'formative_scheme_reference',(SELECT formative_scheme_reference FROM batch_years WHERE id=NEW.batch_year_id),
 'attendance_cutoff',(SELECT eligibility_cutoff FROM exam_cycles WHERE id=NEW.cycle_id),
 'correction_max_id',(SELECT MAX(c.id) FROM attendance_corrections c JOIN attendance_sessions a ON a.id=c.session_id WHERE c.enrollment_id=NEW.enrollment_id AND a.batch_year_id=NEW.batch_year_id))
 FROM exam_rule_versions WHERE id=v_rule);
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_hall_ticket_insert BEFORE INSERT ON hall_tickets FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM exam_eligibility WHERE id=NEW.eligibility_id AND eligibility_status='eligible') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official hall-ticket import must reference eligible snapshot'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_practical_marks_insert BEFORE INSERT ON practical_marks FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM practical_sessions p JOIN exam_eligibility e ON e.id=NEW.eligibility_id
 WHERE p.id=NEW.practical_session_id AND p.cycle_id=e.cycle_id AND p.batch_year_id=e.batch_year_id
 AND e.eligibility_status='eligible') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Practical candidate must be eligible in the same cycle/year'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_result_insert BEFORE INSERT ON official_results FOR EACH ROW
BEGIN
 IF NEW.result_status='promoted_with_back' AND NOT EXISTS
 (SELECT 1 FROM exam_cycles x JOIN batch_years y ON y.id=NEW.batch_year_id
 JOIN curriculum_years cy ON cy.id=y.curriculum_year_id JOIN batches b ON b.id=NEW.batch_id
 JOIN curriculum_versions c ON c.id=b.curriculum_id
 WHERE x.id=NEW.cycle_id AND x.cycle_type='mains' AND cy.year_no=1 AND c.duration_months=24 AND b.admission_year>=2025) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Promoted with back applies only to first-year two-year CTS mains, admission 2025 onwards'; END IF;
 IF NEW.revision_no=1 AND NEW.supersedes_result_id IS NOT NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Initial result cannot supersede another result'; END IF;
 IF NEW.revision_no>1 AND NOT EXISTS(SELECT 1 FROM official_results WHERE id=NEW.supersedes_result_id
 AND enrollment_id=NEW.enrollment_id AND batch_year_id=NEW.batch_year_id AND cycle_id=NEW.cycle_id AND revision_no=NEW.revision_no-1) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Result revision requires matching previous official record'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_certificate_insert BEFORE INSERT ON certificates FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM official_results WHERE id=NEW.official_result_id AND enrollment_id=NEW.enrollment_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Certificate result must belong to the same trainee'; END IF;
 IF NEW.document_type='NTC' AND NEW.status='valid' AND NOT EXISTS(SELECT 1 FROM official_results WHERE id=NEW.official_result_id AND result_status='pass') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Valid NTC requires official pass result'; END IF;
 IF NEW.document_type='NTC' AND NEW.status='valid' AND NOT EXISTS
 (SELECT 1 FROM official_results r JOIN batch_years y ON y.id=r.batch_year_id JOIN curriculum_years cy ON cy.id=y.curriculum_year_id
 JOIN curriculum_versions cv ON cv.id=y.curriculum_id WHERE r.id=NEW.official_result_id
 AND cy.year_no=CASE WHEN cv.duration_months=24 THEN 2 ELSE 1 END) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='NTC must reference the final training year'; END IF;
 IF NEW.status='valid' AND EXISTS(SELECT 1 FROM enrollments e JOIN batches b ON b.id=e.batch_id
 JOIN curriculum_versions c ON c.id=b.curriculum_id WHERE e.id=NEW.enrollment_id AND b.admission_year>=2025 AND c.duration_months IN(12,24)) THEN
 IF NEW.document_type='marksheet' AND NEW.ojt_grade_as_issued IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Revised marksheet requires recorded OJT/project grade'; END IF;
 IF NEW.document_type='NTC' AND NEW.credits_as_issued IS NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Revised NTC requires official earned credits'; END IF;
 END IF;
 IF NEW.revision_no>1 AND NOT EXISTS(SELECT 1 FROM certificates WHERE id=NEW.supersedes_certificate_id
 AND enrollment_id=NEW.enrollment_id AND certificate_number=NEW.certificate_number AND revision_no=NEW.revision_no-1) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Certificate revision requires matching previous document'; END IF;
 -- APAAR is intentionally nullable: declining consent is not grounds for denial.

END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_ojt_project_insert BEFORE INSERT ON ojt_projects FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM enrollments e JOIN batches b ON b.id=e.batch_id
 JOIN batch_years y ON y.id=NEW.batch_year_id JOIN curriculum_years cy ON cy.id=y.curriculum_year_id
 JOIN trade_unit_shifts us ON us.id=b.trade_unit_shift_id JOIN trade_units u ON u.id=us.trade_unit_id
 WHERE e.id=NEW.enrollment_id AND e.batch_id=NEW.batch_id AND y.batch_id=NEW.batch_id
 AND e.status IN('active','completed') AND NEW.starts_on>=DATE_ADD(b.starts_on,INTERVAL 3 MONTH)
 AND NEW.required_minutes=cy.ojt_project_minutes
 AND (b.training_mode<>'DST' OR NEW.mode='group_project')) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OJT/project requires verified cohort, curriculum hours, three-month training and DST project mode'; END IF;
 IF NEW.mode='ojt' AND EXISTS(SELECT 1 FROM enrollments e JOIN students s ON s.id=e.student_id
 WHERE e.id=NEW.enrollment_id AND LOWER(s.gender)='female') AND NEW.female_safety_reference IS NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Female OJT safety evidence is required'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_ojt_project_update BEFORE UPDATE ON ojt_projects FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM enrollments e JOIN batches b ON b.id=e.batch_id
 JOIN batch_years y ON y.id=NEW.batch_year_id JOIN curriculum_years cy ON cy.id=y.curriculum_year_id
 JOIN trade_unit_shifts us ON us.id=b.trade_unit_shift_id JOIN trade_units u ON u.id=us.trade_unit_id
 WHERE e.id=NEW.enrollment_id AND e.batch_id=NEW.batch_id AND y.batch_id=NEW.batch_id
 AND e.status IN('active','completed') AND NEW.starts_on>=DATE_ADD(b.starts_on,INTERVAL 3 MONTH)
 AND NEW.required_minutes=cy.ojt_project_minutes
 AND (b.training_mode<>'DST' OR NEW.mode='group_project')) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OJT/project requires verified cohort, curriculum hours, three-month training and DST project mode'; END IF;
 IF NEW.mode='ojt' AND EXISTS(SELECT 1 FROM enrollments e JOIN students s ON s.id=e.student_id
 WHERE e.id=NEW.enrollment_id AND LOWER(s.gender)='female') AND NEW.female_safety_reference IS NULL THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Female OJT safety evidence is required'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_ojt_evaluation BEFORE INSERT ON ojt_evaluations FOR EACH ROW
BEGIN
 DECLARE v_score DECIMAL(8,4);
 IF NOT EXISTS(SELECT 1 FROM ojt_projects p WHERE p.id=NEW.project_id
 AND (SELECT COALESCE(SUM(l.attended_minutes),0) FROM ojt_logbook l WHERE l.project_id=p.id)>=p.required_minutes
 AND ((p.mode='ojt' AND NEW.primary_evaluator_role='industry_mentor')
 OR (p.mode='group_project' AND NEW.primary_evaluator_role='trade_instructor'))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Evaluation requires completed logbook hours and correct primary evaluator'; END IF;
 SET v_score=50*NEW.primary_marks/NEW.primary_max_marks+50*NEW.external_marks/NEW.external_max_marks;
 IF NEW.final_grade<>CASE WHEN v_score>=90 THEN 'A1' WHEN v_score>=75 THEN 'A' WHEN v_score>=60 THEN 'B' ELSE 'C' END THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OJT grade does not match 50:50 evaluation'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_ojt_log BEFORE INSERT ON ojt_logbook FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM ojt_projects WHERE id=NEW.project_id AND NEW.entry_date BETWEEN starts_on AND ends_on) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OJT log date is outside project period'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_cycle_update BEFORE UPDATE ON exam_cycles FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND (NEW.rule_id<>OLD.rule_id OR NEW.cycle_type<>OLD.cycle_type
 OR NEW.eligibility_cutoff<>OLD.eligibility_cutoff OR NEW.schedule_source_id<>OLD.schedule_source_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Opened cycle rules are frozen; create a sourced replacement cycle'; END IF;
 IF OLD.status IN('eligibility_frozen','scheduled','closed') AND NEW.status IN('draft','eligibility_open') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen cycle cannot be silently reopened'; END IF;
 IF NEW.status='eligibility_frozen' AND (NEW.frozen_on IS NULL OR NEW.frozen_by IS NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cycle freeze requires responsible official and timestamp'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_exam_eligibility_update BEFORE UPDATE ON exam_eligibility FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_exam_eligibility_delete BEFORE DELETE ON exam_eligibility FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_hall_tickets_update BEFORE UPDATE ON hall_tickets FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_hall_tickets_delete BEFORE DELETE ON hall_tickets FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_official_results_update BEFORE UPDATE ON official_results FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_official_results_delete BEFORE DELETE ON official_results FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_certificates_update BEFORE UPDATE ON certificates FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_certificates_delete BEFORE DELETE ON certificates FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_credit_transfers_update BEFORE UPDATE ON credit_transfers FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_credit_transfers_delete BEFORE DELETE ON credit_transfers FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_portal_submissions_update BEFORE UPDATE ON portal_submissions FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_portal_submissions_delete BEFORE DELETE ON portal_submissions FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_student_events_update BEFORE UPDATE ON student_events FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_student_events_delete BEFORE DELETE ON student_events FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_attendance_corrections_update BEFORE UPDATE ON attendance_corrections FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_attendance_corrections_delete BEFORE DELETE ON attendance_corrections FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_fee_transactions_update BEFORE UPDATE ON fee_transactions FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_fee_transactions_delete BEFORE DELETE ON fee_transactions FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_ojt_evaluations_update BEFORE UPDATE ON ojt_evaluations FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER immutable_ojt_evaluations_delete BEFORE DELETE ON ojt_evaluations FOR EACH ROW
BEGIN
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit record is immutable; record a new sourced revision/attempt/event';
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_subject_assignment BEFORE INSERT ON subject_batch_assignments FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM instructor_mappings WHERE id=NEW.mapping_id AND mapping_type='subject' AND status='active') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Subject allocation requires an active subject mapping'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_quota_scope_insert BEFORE INSERT ON batch_quotas FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM batches b JOIN reservation_policies p ON p.id=NEW.policy_id
 JOIN institute i ON i.id=b.institute_id WHERE b.id=NEW.batch_id AND p.state_ut_code=i.state_ut_code
 AND p.admission_year=b.admission_year AND p.status='published' AND NEW.seats<=b.capacity) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quota requires same State/session published policy and sanctioned capacity'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_quota_scope_update BEFORE UPDATE ON batch_quotas FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM batches b JOIN reservation_policies p ON p.id=NEW.policy_id
 JOIN institute i ON i.id=b.institute_id WHERE b.id=NEW.batch_id AND p.state_ut_code=i.state_ut_code
 AND p.admission_year=b.admission_year AND p.status='published' AND NEW.seats<=b.capacity) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quota requires same State/session published policy and sanctioned capacity'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_source_chain_insert BEFORE INSERT ON regulatory_sources FOR EACH ROW
BEGIN
 IF NEW.supersedes_id IS NOT NULL AND (NEW.supersedes_id=NEW.id OR NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.supersedes_id AND institute_id=NEW.institute_id AND status IN('published','superseded','withdrawn'))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Source predecessor must be reviewed and cannot reference itself'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_source_chain_update BEFORE UPDATE ON regulatory_sources FOR EACH ROW
BEGIN
 IF NEW.supersedes_id IS NOT NULL AND (NEW.supersedes_id=NEW.id OR NOT EXISTS(SELECT 1 FROM regulatory_sources WHERE id=NEW.supersedes_id AND institute_id=NEW.institute_id AND status IN('published','superseded','withdrawn'))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Source predecessor must be reviewed and cannot reference itself'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_source_update BEFORE UPDATE ON regulatory_sources FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND (
 NEW.authority<>OLD.authority OR NOT(NEW.state_ut_code<=>OLD.state_ut_code) OR NEW.document_type<>OLD.document_type OR NEW.title<>OLD.title
 OR NEW.reference_no<>OLD.reference_no OR NEW.issued_on<>OLD.issued_on
 OR NOT(NEW.source_url<=>OLD.source_url) OR NOT(NEW.local_evidence_reference<=>OLD.local_evidence_reference)
 OR NOT(NEW.sha256<=>OLD.sha256) OR NOT(NEW.supersedes_id<=>OLD.supersedes_id)
 OR NOT(NEW.reviewed_by<=>OLD.reviewed_by) OR NOT(NEW.reviewed_at<=>OLD.reviewed_at)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed source identity is immutable; insert a sourced correction'; END IF;
 IF OLD.status<>'draft' AND NEW.status='draft' THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed source cannot revert to draft'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_source_delete BEFORE DELETE ON regulatory_sources FOR EACH ROW
BEGIN
IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed official source cannot be deleted'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_evaluated_log_update BEFORE UPDATE ON ojt_logbook FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM ojt_evaluations WHERE project_id=OLD.project_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Evaluated OJT logbook is frozen'; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER guard_evaluated_log_delete BEFORE DELETE ON ojt_logbook FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM ojt_evaluations WHERE project_id=OLD.project_id) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Evaluated OJT logbook is frozen'; END IF;
END$$
DELIMITER ;

CREATE SQL SECURITY INVOKER VIEW v_batch_seats AS
 SELECT b.institute_id,b.id,b.batch_code,b.capacity,
 SUM(CASE WHEN e.status IN('pending_verification','active','completed') THEN 1 ELSE 0 END) AS occupied_seats,
 b.capacity-SUM(CASE WHEN e.status IN('pending_verification','active','completed') THEN 1 ELSE 0 END) AS remaining_seats
 FROM batches b LEFT JOIN enrollments e ON e.batch_id=b.id GROUP BY b.institute_id,b.id,b.batch_code,b.capacity;
CREATE SQL SECURITY INVOKER VIEW v_nims_vacancies AS
 SELECT e.*,DATEDIFF(CURRENT_DATE,e.remap_due_on) AS overdue_days FROM instructor_events e
 WHERE e.event_type='vacancy' AND e.resolved_on IS NULL;
CREATE SQL SECURITY INVOKER VIEW v_practical_submission_timeliness AS
 SELECT s.institute_id,s.id,s.practical_session_id,s.portal_reference,s.status,
 TIMESTAMPDIFF(SECOND,p.ended_at,s.submitted_at) AS elapsed_seconds,
 CASE WHEN s.submitted_at BETWEEN p.ended_at AND DATE_ADD(p.ended_at,INTERVAL r.practical_upload_hours HOUR)
 THEN 1 ELSE 0 END AS submitted_within_rule_deadline
 FROM portal_submissions s JOIN practical_sessions p ON p.id=s.practical_session_id
 JOIN exam_cycles c ON c.id=p.cycle_id JOIN exam_rule_versions r ON r.id=c.rule_id
 WHERE s.submission_type='practical_marks';
CREATE SQL SECURITY INVOKER VIEW v_latest_official_results AS
 SELECT r.* FROM official_results r WHERE NOT EXISTS(SELECT 1 FROM official_results n WHERE n.supersedes_result_id=r.id);
CREATE SQL SECURITY INVOKER VIEW v_latest_certificates AS
 SELECT c.* FROM certificates c WHERE NOT EXISTS(SELECT 1 FROM certificates n WHERE n.supersedes_certificate_id=c.id);
CREATE SQL SECURITY INVOKER VIEW v_resource_availability AS
 SELECT r.institute_id,r.id,r.resource_name,r.measurement_unit,r.base_quantity,r.additional_unit_quantity,
 COALESCE(SUM(a.serviceable_quantity),0) AS serviceable_quantity
 FROM resource_requirements r LEFT JOIN inventory_assets a ON a.resource_requirement_id=r.id
 GROUP BY r.institute_id,r.id,r.resource_name,r.measurement_unit,r.base_quantity,r.additional_unit_quantity;
CREATE SQL SECURITY INVOKER VIEW v_effective_attendance AS
 SELECT a.session_id,a.enrollment_id,
 COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(c.after_json,'$.attended_minutes')) AS UNSIGNED),a.attended_minutes) AS attended_minutes,
 COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.after_json,'$.status')),a.status) AS status,
 c.id AS correction_id
 FROM attendance_records a LEFT JOIN attendance_corrections c ON c.session_id=a.session_id AND c.enrollment_id=a.enrollment_id
 AND NOT EXISTS(SELECT 1 FROM attendance_corrections n WHERE n.session_id=c.session_id AND n.enrollment_id=c.enrollment_id AND n.id>c.id);

INSERT INTO schema_migrations(version,sha256) VALUES('001_core.sql','dafe989e701fa8d1d1f820c722e5d46b7a1a8c99cd16efb6278e16ca7a4a3a8b');


-- Migration 002_access.sql; SHA-256 eff80c97d1fbf1a1ed1f374ccc9ba32cad61b62eaa0d53d013a298f9e5e9b037
-- Fresh shared-database installation. Run as the dedicated schema owner/DBA.
-- No unrestricted application role, passwords, or user logins are created.
-- Replace the database qualifier below if you chose another database name.
-- Existing broad privileges must be removed before using a tenant account; this is not an access migration.
-- Uses the database selected by the importing client.
DELIMITER $$
CREATE PROCEDURE provision_institute_access(IN p_institute BIGINT UNSIGNED)
SQL SECURITY DEFINER
BEGIN
 DECLARE v_db VARCHAR(128); DECLARE v_role VARCHAR(128); DECLARE v_prefix VARCHAR(50);
 IF NOT EXISTS(SELECT 1 FROM institute WHERE id=p_institute) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unknown managed institute'; END IF;
 SET v_db=REPLACE(DATABASE(),'`','``'); SET v_role=CONCAT('iti_',LEFT(SHA2(DATABASE(),256),8),'_i_',p_institute); SET v_prefix=CONCAT('i',p_institute,'_');
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'regulatory_sources` AS SELECT * FROM `',v_db,'`.`regulatory_sources` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'regulatory_sources` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`authority`,`state_ut_code`,`document_type`,`title`,`reference_no`,`issued_on`,`source_url`,`local_evidence_reference`,`sha256`,`supersedes_id`,`status`,`reviewed_by`,`reviewed_at`) ON `',v_db,'`.`',v_prefix,'regulatory_sources` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`authority`,`state_ut_code`,`document_type`,`title`,`reference_no`,`issued_on`,`source_url`,`local_evidence_reference`,`sha256`,`supersedes_id`,`status`,`reviewed_by`,`reviewed_at`) ON `',v_db,'`.`',v_prefix,'regulatory_sources` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'regulatory_sources` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'curriculum_versions` AS SELECT * FROM `',v_db,'`.`curriculum_versions` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'curriculum_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`trade_id`,`source_id`,`version_code`,`duration_months`,`nsqf_level`,`unit_strength`,`effective_from`,`effective_to`,`status`) ON `',v_db,'`.`',v_prefix,'curriculum_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`trade_id`,`source_id`,`version_code`,`duration_months`,`nsqf_level`,`unit_strength`,`effective_from`,`effective_to`,`status`) ON `',v_db,'`.`',v_prefix,'curriculum_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'curriculum_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'curriculum_years` AS SELECT * FROM `',v_db,'`.`curriculum_years` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'curriculum_years` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`curriculum_id`,`year_no`,`trade_practical_minutes`,`trade_theory_minutes`,`employability_minutes`,`ojt_project_minutes`,`credits`) ON `',v_db,'`.`',v_prefix,'curriculum_years` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`curriculum_id`,`year_no`,`trade_practical_minutes`,`trade_theory_minutes`,`employability_minutes`,`ojt_project_minutes`,`credits`) ON `',v_db,'`.`',v_prefix,'curriculum_years` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'curriculum_years` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'learning_outcomes` AS SELECT * FROM `',v_db,'`.`learning_outcomes` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'learning_outcomes` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`curriculum_year_id`,`lo_code`,`nos_code`,`outcome_text`,`assessment_criteria`,`formative_max_marks`) ON `',v_db,'`.`',v_prefix,'learning_outcomes` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`curriculum_year_id`,`lo_code`,`nos_code`,`outcome_text`,`assessment_criteria`,`formative_max_marks`) ON `',v_db,'`.`',v_prefix,'learning_outcomes` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'learning_outcomes` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'resource_requirements` AS SELECT * FROM `',v_db,'`.`resource_requirements` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'resource_requirements` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`curriculum_id`,`resource_code`,`resource_name`,`specification`,`resource_type`,`base_quantity`,`additional_unit_quantity`,`measurement_unit`) ON `',v_db,'`.`',v_prefix,'resource_requirements` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`curriculum_id`,`resource_code`,`resource_name`,`specification`,`resource_type`,`base_quantity`,`additional_unit_quantity`,`measurement_unit`) ON `',v_db,'`.`',v_prefix,'resource_requirements` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'resource_requirements` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'staff` AS SELECT * FROM `',v_db,'`.`staff` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'staff` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`employee_code`,`full_name`,`role`,`joined_on`,`left_on`,`iprn`,`nims_verification`,`verification_reference`,`verified_on`,`identity_mode`) ON `',v_db,'`.`',v_prefix,'staff` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`employee_code`,`full_name`,`role`,`joined_on`,`left_on`,`iprn`,`nims_verification`,`verification_reference`,`verified_on`,`identity_mode`) ON `',v_db,'`.`',v_prefix,'staff` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'staff` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'staff_qualifications` AS SELECT * FROM `',v_db,'`.`staff_qualifications` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'staff_qualifications` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`staff_id`,`qualification_type`,`specialization`,`certificate_reference`,`experience_months`,`verified_by`,`verified_on`) ON `',v_db,'`.`',v_prefix,'staff_qualifications` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`staff_id`,`qualification_type`,`specialization`,`certificate_reference`,`experience_months`,`verified_by`,`verified_on`) ON `',v_db,'`.`',v_prefix,'staff_qualifications` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'staff_qualifications` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'trade_units` AS SELECT * FROM `',v_db,'`.`trade_units` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'trade_units` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`trade_id`,`unit_no`) ON `',v_db,'`.`',v_prefix,'trade_units` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`trade_id`,`unit_no`) ON `',v_db,'`.`',v_prefix,'trade_units` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'trade_units` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'trade_unit_shifts` AS SELECT * FROM `',v_db,'`.`trade_unit_shifts` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'trade_unit_shifts` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`trade_unit_id`,`trade_id`,`shift_no`,`status`,`training_mode`,`affiliation_status`,`sanctioned_capacity`,`valid_from`,`valid_to`,`source_id`,`approval_reference`) ON `',v_db,'`.`',v_prefix,'trade_unit_shifts` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`trade_unit_id`,`trade_id`,`shift_no`,`status`,`training_mode`,`affiliation_status`,`sanctioned_capacity`,`valid_from`,`valid_to`,`source_id`,`approval_reference`) ON `',v_db,'`.`',v_prefix,'trade_unit_shifts` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'trade_unit_shifts` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'instructor_mappings` AS SELECT * FROM `',v_db,'`.`instructor_mappings` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'instructor_mappings` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`staff_id`,`mapping_type`,`trade_unit_shift_id`,`subject_code`,`valid_from`,`valid_to`,`status`,`qualification_source_id`,`qualification_check_json`,`qualification_checked_by`,`nims_reference`) ON `',v_db,'`.`',v_prefix,'instructor_mappings` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`staff_id`,`mapping_type`,`trade_unit_shift_id`,`subject_code`,`valid_from`,`valid_to`,`status`,`qualification_source_id`,`qualification_check_json`,`qualification_checked_by`,`nims_reference`) ON `',v_db,'`.`',v_prefix,'instructor_mappings` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'instructor_mappings` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'instructor_events` AS SELECT * FROM `',v_db,'`.`instructor_events` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'instructor_events` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`staff_id`,`mapping_id`,`event_type`,`occurred_on`,`remap_due_on`,`resolved_on`,`replacement_mapping_id`,`portal_reference`,`source_id`,`notes`) ON `',v_db,'`.`',v_prefix,'instructor_events` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`staff_id`,`mapping_id`,`event_type`,`occurred_on`,`remap_due_on`,`resolved_on`,`replacement_mapping_id`,`portal_reference`,`source_id`,`notes`) ON `',v_db,'`.`',v_prefix,'instructor_events` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'instructor_events` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'admission_intakes` AS SELECT * FROM `',v_db,'`.`admission_intakes` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'admission_intakes` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`admission_year`,`intake_code`,`opens_on`,`closes_on`,`source_id`,`approval_reference`,`status`) ON `',v_db,'`.`',v_prefix,'admission_intakes` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`admission_year`,`intake_code`,`opens_on`,`closes_on`,`source_id`,`approval_reference`,`status`) ON `',v_db,'`.`',v_prefix,'admission_intakes` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'admission_intakes` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'seat_matrix_versions` AS SELECT * FROM `',v_db,'`.`seat_matrix_versions` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'seat_matrix_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`admission_year`,`intake_id`,`version_code`,`source_id`,`portal_reference`,`dgt_circular_no`,`published_on`,`status`,`supersedes_id`) ON `',v_db,'`.`',v_prefix,'seat_matrix_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`admission_year`,`intake_id`,`version_code`,`source_id`,`portal_reference`,`dgt_circular_no`,`published_on`,`status`,`supersedes_id`) ON `',v_db,'`.`',v_prefix,'seat_matrix_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'seat_matrix_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'seat_matrix_entries` AS SELECT * FROM `',v_db,'`.`seat_matrix_entries` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'seat_matrix_entries` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`version_id`,`trade_unit_shift_id`,`seat_capacity`,`admission_allowed`,`consumed_previous_session`,`instructor_mapping_id`,`instructor_mapping_satisfied`,`eligibility_reason`,`portal_itsu_reference`) ON `',v_db,'`.`',v_prefix,'seat_matrix_entries` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`version_id`,`trade_unit_shift_id`,`seat_capacity`,`admission_allowed`,`consumed_previous_session`,`instructor_mapping_id`,`instructor_mapping_satisfied`,`eligibility_reason`,`portal_itsu_reference`) ON `',v_db,'`.`',v_prefix,'seat_matrix_entries` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'seat_matrix_entries` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'admission_rules` AS SELECT * FROM `',v_db,'`.`admission_rules` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'admission_rules` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`trade_id`,`curriculum_id`,`source_id`,`state_source_id`,`version_code`,`state_ut_code`,`minimum_age_years`,`age_cutoff_date`,`qualification_routes_json`,`admission_opens_on`,`admission_closes_on`,`admission_window_source_id`,`status`) ON `',v_db,'`.`',v_prefix,'admission_rules` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`trade_id`,`curriculum_id`,`source_id`,`state_source_id`,`version_code`,`state_ut_code`,`minimum_age_years`,`age_cutoff_date`,`qualification_routes_json`,`admission_opens_on`,`admission_closes_on`,`admission_window_source_id`,`status`) ON `',v_db,'`.`',v_prefix,'admission_rules` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'admission_rules` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'reservation_policies` AS SELECT * FROM `',v_db,'`.`reservation_policies` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'reservation_policies` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`state_ut_code`,`admission_year`,`version_code`,`source_id`,`policy_json`,`status`) ON `',v_db,'`.`',v_prefix,'reservation_policies` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`state_ut_code`,`admission_year`,`version_code`,`source_id`,`policy_json`,`status`) ON `',v_db,'`.`',v_prefix,'reservation_policies` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'reservation_policies` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'batches` AS SELECT * FROM `',v_db,'`.`batches` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'batches` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`batch_code`,`trade_unit_shift_id`,`trade_id`,`curriculum_id`,`seat_matrix_entry_id`,`admission_rule_id`,`admission_year`,`intake_id`,`starts_on`,`ends_on`,`capacity`,`status`) ON `',v_db,'`.`',v_prefix,'batches` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`batch_code`,`trade_unit_shift_id`,`trade_id`,`curriculum_id`,`seat_matrix_entry_id`,`admission_rule_id`,`admission_year`,`intake_id`,`starts_on`,`ends_on`,`capacity`,`status`) ON `',v_db,'`.`',v_prefix,'batches` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'batch_years` AS SELECT * FROM `',v_db,'`.`batch_years` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'batch_years` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`batch_id`,`curriculum_id`,`curriculum_year_id`,`starts_on`,`ends_on`,`attendance_basis`,`attendance_basis_source_id`,`calendar_status`,`calendar_reviewed_through`,`calendar_reviewed_by`,`calendar_evidence_reference`,`formative_method`,`formative_scheme_source_id`,`formative_scheme_reference`,`working_day_method`,`working_day_method_reference`) ON `',v_db,'`.`',v_prefix,'batch_years` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`batch_id`,`curriculum_id`,`curriculum_year_id`,`starts_on`,`ends_on`,`attendance_basis`,`attendance_basis_source_id`,`calendar_status`,`calendar_reviewed_through`,`calendar_reviewed_by`,`calendar_evidence_reference`,`formative_method`,`formative_scheme_source_id`,`formative_scheme_reference`,`working_day_method`,`working_day_method_reference`) ON `',v_db,'`.`',v_prefix,'batch_years` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'batch_years` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'training_calendar_entries` AS SELECT * FROM `',v_db,'`.`training_calendar_entries` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'training_calendar_entries` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`batch_year_id`,`session_date`,`component`,`scheduled_minutes`) ON `',v_db,'`.`',v_prefix,'training_calendar_entries` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`batch_year_id`,`session_date`,`component`,`scheduled_minutes`) ON `',v_db,'`.`',v_prefix,'training_calendar_entries` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'training_calendar_entries` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'subject_batch_assignments` AS SELECT * FROM `',v_db,'`.`subject_batch_assignments` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'subject_batch_assignments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`mapping_id`,`batch_year_id`,`assigned_minutes_per_week`,`principal_reference`) ON `',v_db,'`.`',v_prefix,'subject_batch_assignments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`mapping_id`,`batch_year_id`,`assigned_minutes_per_week`,`principal_reference`) ON `',v_db,'`.`',v_prefix,'subject_batch_assignments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'subject_batch_assignments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'batch_quotas` AS SELECT * FROM `',v_db,'`.`batch_quotas` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'batch_quotas` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`batch_id`,`policy_id`,`category_code`,`quota_type`,`seats`,`eligibility_condition_json`) ON `',v_db,'`.`',v_prefix,'batch_quotas` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`batch_id`,`policy_id`,`category_code`,`quota_type`,`seats`,`eligibility_condition_json`) ON `',v_db,'`.`',v_prefix,'batch_quotas` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'students` AS SELECT * FROM `',v_db,'`.`students` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'students` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`local_student_code`,`full_name`,`date_of_birth`,`father_name`,`mother_name`,`gender`,`mobile`,`address`,`category_code`,`is_ews`,`disability_percent`,`identity_mode`,`identity_evidence_reference`,`prn`,`prn_verified_on`,`prn_source_reference`,`apaar_id`,`apaar_consent`,`consent_reference`,`consent_on`,`verification_iti_mis_code`) ON `',v_db,'`.`',v_prefix,'students` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`local_student_code`,`full_name`,`date_of_birth`,`father_name`,`mother_name`,`gender`,`mobile`,`address`,`category_code`,`is_ews`,`disability_percent`,`identity_mode`,`identity_evidence_reference`,`prn`,`prn_verified_on`,`prn_source_reference`,`apaar_id`,`apaar_consent`,`consent_reference`,`consent_on`,`verification_iti_mis_code`) ON `',v_db,'`.`',v_prefix,'students` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'students` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'student_qualifications` AS SELECT * FROM `',v_db,'`.`student_qualifications` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'student_qualifications` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`student_id`,`level_code`,`board_name`,`passing_year`,`certificate_reference`,`subject_results_json`,`verified_by`,`verified_on`) ON `',v_db,'`.`',v_prefix,'student_qualifications` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`student_id`,`level_code`,`board_name`,`passing_year`,`certificate_reference`,`subject_results_json`,`verified_by`,`verified_on`) ON `',v_db,'`.`',v_prefix,'student_qualifications` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'student_qualifications` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'enrollments` AS SELECT * FROM `',v_db,'`.`enrollments` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'enrollments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`student_id`,`batch_id`,`admission_number`,`state_registration_number`,`admitted_on`,`status`,`admission_rule_id`,`qualification_id`,`qualification_route_code`,`qualification_check_json`,`qualification_check_passed`,`checked_by`,`checked_on`,`vertical_quota_id`,`horizontal_quota_id`,`quota_evidence_reference`) ON `',v_db,'`.`',v_prefix,'enrollments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`student_id`,`batch_id`,`admission_number`,`state_registration_number`,`admitted_on`,`status`,`admission_rule_id`,`qualification_id`,`qualification_route_code`,`qualification_check_json`,`qualification_check_passed`,`checked_by`,`checked_on`,`vertical_quota_id`,`horizontal_quota_id`,`quota_evidence_reference`) ON `',v_db,'`.`',v_prefix,'enrollments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'student_events` AS SELECT * FROM `',v_db,'`.`student_events` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'student_events` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`event_type`,`effective_on`,`reference_no`,`destination_mis_code`,`reason`,`recorded_by`) ON `',v_db,'`.`',v_prefix,'student_events` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'attendance_sessions` AS SELECT * FROM `',v_db,'`.`attendance_sessions` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'attendance_sessions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`batch_year_id`,`session_date`,`component`,`scheduled_minutes`,`instructor_id`,`locked_on`) ON `',v_db,'`.`',v_prefix,'attendance_sessions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`batch_year_id`,`session_date`,`component`,`scheduled_minutes`,`instructor_id`,`locked_on`) ON `',v_db,'`.`',v_prefix,'attendance_sessions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'attendance_sessions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'attendance_records` AS SELECT * FROM `',v_db,'`.`attendance_records` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'attendance_records` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`session_id`,`enrollment_id`,`attended_minutes`,`status`,`leave_evidence_reference`,`recorded_by`) ON `',v_db,'`.`',v_prefix,'attendance_records` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`session_id`,`enrollment_id`,`attended_minutes`,`status`,`leave_evidence_reference`,`recorded_by`) ON `',v_db,'`.`',v_prefix,'attendance_records` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'attendance_records` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'attendance_corrections` AS SELECT * FROM `',v_db,'`.`attendance_corrections` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'attendance_corrections` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`session_id`,`enrollment_id`,`before_json`,`after_json`,`reason`,`authority_reference`,`approved_by`) ON `',v_db,'`.`',v_prefix,'attendance_corrections` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'formative_assessments` AS SELECT * FROM `',v_db,'`.`formative_assessments` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'formative_assessments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`curriculum_year_id`,`learning_outcome_id`,`assessed_on`,`max_marks`,`obtained_marks`,`evidence_reference`,`assessor_id`,`locked_on`) ON `',v_db,'`.`',v_prefix,'formative_assessments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`curriculum_year_id`,`learning_outcome_id`,`assessed_on`,`max_marks`,`obtained_marks`,`evidence_reference`,`assessor_id`,`locked_on`) ON `',v_db,'`.`',v_prefix,'formative_assessments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'formative_assessments` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'ojt_projects` AS SELECT * FROM `',v_db,'`.`ojt_projects` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'ojt_projects` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`mode`,`title`,`industry_name`,`industry_registration_reference`,`workplace_address`,`industry_mentor`,`coordinator_id`,`starts_on`,`ends_on`,`required_minutes`,`safety_reference`,`female_safety_reference`,`completion_certificate_reference`,`status`) ON `',v_db,'`.`',v_prefix,'ojt_projects` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`mode`,`title`,`industry_name`,`industry_registration_reference`,`workplace_address`,`industry_mentor`,`coordinator_id`,`starts_on`,`ends_on`,`required_minutes`,`safety_reference`,`female_safety_reference`,`completion_certificate_reference`,`status`) ON `',v_db,'`.`',v_prefix,'ojt_projects` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'ojt_projects` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'ojt_logbook` AS SELECT * FROM `',v_db,'`.`ojt_logbook` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'ojt_logbook` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`project_id`,`entry_date`,`attended_minutes`,`activity`,`mentor_confirmation`,`monitoring_safety_reference`) ON `',v_db,'`.`',v_prefix,'ojt_logbook` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`project_id`,`entry_date`,`attended_minutes`,`activity`,`mentor_confirmation`,`monitoring_safety_reference`) ON `',v_db,'`.`',v_prefix,'ojt_logbook` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'ojt_logbook` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'ojt_evaluations` AS SELECT * FROM `',v_db,'`.`ojt_evaluations` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'ojt_evaluations` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`project_id`,`primary_evaluator_role`,`primary_evaluator_name`,`primary_marks`,`external_examiner_name`,`external_marks`,`primary_max_marks`,`external_max_marks`,`final_grade`,`evaluated_on`,`evaluation_source_id`,`signed_evidence_reference`,`sidh_submission_reference`) ON `',v_db,'`.`',v_prefix,'ojt_evaluations` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'exam_rule_versions` AS SELECT * FROM `',v_db,'`.`exam_rule_versions` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'exam_rule_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`source_id`,`code`,`cycle_type`,`minimum_attendance`,`minimum_formative`,`principal_relaxation_cap`,`relaxation_source_id`,`requires_prn`,`requires_cbt_fee`,`requires_practical_fee`,`requires_ojt_for_certificate`,`practical_upload_hours`,`effective_from`,`effective_to`,`status`) ON `',v_db,'`.`',v_prefix,'exam_rule_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`source_id`,`code`,`cycle_type`,`minimum_attendance`,`minimum_formative`,`principal_relaxation_cap`,`relaxation_source_id`,`requires_prn`,`requires_cbt_fee`,`requires_practical_fee`,`requires_ojt_for_certificate`,`practical_upload_hours`,`effective_from`,`effective_to`,`status`) ON `',v_db,'`.`',v_prefix,'exam_rule_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'exam_rule_versions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'exam_cycles` AS SELECT * FROM `',v_db,'`.`exam_cycles` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'exam_cycles` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`code`,`cycle_type`,`rule_id`,`schedule_source_id`,`eligibility_cutoff`,`exam_starts_on`,`activity_windows_json`,`status`,`frozen_by`,`frozen_on`) ON `',v_db,'`.`',v_prefix,'exam_cycles` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`code`,`cycle_type`,`rule_id`,`schedule_source_id`,`eligibility_cutoff`,`exam_starts_on`,`activity_windows_json`,`status`,`frozen_by`,`frozen_on`) ON `',v_db,'`.`',v_prefix,'exam_cycles` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'exam_cycles` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'fee_transactions` AS SELECT * FROM `',v_db,'`.`fee_transactions` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'fee_transactions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`cycle_id`,`fee_head`,`transaction_type`,`amount`,`paid_on`,`receipt_no`,`portal_reference`,`payment_mode`,`reverses_transaction_id`,`recorded_by`) ON `',v_db,'`.`',v_prefix,'fee_transactions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'exam_fee_clearances` AS SELECT * FROM `',v_db,'`.`exam_fee_clearances` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'exam_fee_clearances` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`cycle_id`,`cbt_cleared`,`practical_cleared`,`cbt_portal_reference`,`practical_reference`,`verified_by`,`verified_on`) ON `',v_db,'`.`',v_prefix,'exam_fee_clearances` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`enrollment_id`,`cycle_id`,`cbt_cleared`,`practical_cleared`,`cbt_portal_reference`,`practical_reference`,`verified_by`,`verified_on`) ON `',v_db,'`.`',v_prefix,'exam_fee_clearances` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'exam_fee_clearances` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'attendance_relaxations` AS SELECT * FROM `',v_db,'`.`attendance_relaxations` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'attendance_relaxations` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`cycle_id`,`batch_year_id`,`points`,`authority_source_id`,`authority_order_reference`,`supporting_evidence_reference`,`reason`,`approved_by`,`approved_on`) ON `',v_db,'`.`',v_prefix,'attendance_relaxations` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`enrollment_id`,`cycle_id`,`batch_year_id`,`points`,`authority_source_id`,`authority_order_reference`,`supporting_evidence_reference`,`reason`,`approved_by`,`approved_on`) ON `',v_db,'`.`',v_prefix,'attendance_relaxations` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'attendance_relaxations` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'exam_eligibility` AS SELECT * FROM `',v_db,'`.`exam_eligibility` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'exam_eligibility` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`cycle_id`,`scheduled_units`,`attended_units`,`attendance_basis`,`raw_attendance`,`effective_attendance`,`relaxation_id`,`formative_percent`,`formative_complete`,`eligibility_status`,`reason`,`rule_snapshot_json`,`evidence_reference`,`frozen_by`) ON `',v_db,'`.`',v_prefix,'exam_eligibility` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'hall_tickets` AS SELECT * FROM `',v_db,'`.`hall_tickets` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'hall_tickets` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`eligibility_id`,`ticket_number`,`revision_no`,`source_system`,`source_reference`,`payload_sha256`,`exam_centre_reference`,`issued_at`) ON `',v_db,'`.`',v_prefix,'hall_tickets` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'practical_sessions` AS SELECT * FROM `',v_db,'`.`practical_sessions` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'practical_sessions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`cycle_id`,`batch_year_id`,`ended_at`,`examiner_name`,`examiner_reference`,`question_paper_authority`,`question_paper_reference`,`question_paper_source_id`) ON `',v_db,'`.`',v_prefix,'practical_sessions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`cycle_id`,`batch_year_id`,`ended_at`,`examiner_name`,`examiner_reference`,`question_paper_authority`,`question_paper_reference`,`question_paper_source_id`) ON `',v_db,'`.`',v_prefix,'practical_sessions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'practical_sessions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'practical_marks` AS SELECT * FROM `',v_db,'`.`practical_marks` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'practical_marks` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`practical_session_id`,`eligibility_id`,`attendance_status`,`max_marks`,`obtained_marks`) ON `',v_db,'`.`',v_prefix,'practical_marks` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`practical_session_id`,`eligibility_id`,`attendance_status`,`max_marks`,`obtained_marks`) ON `',v_db,'`.`',v_prefix,'practical_marks` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'practical_marks` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'portal_submissions` AS SELECT * FROM `',v_db,'`.`portal_submissions` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'portal_submissions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`submission_type`,`cycle_id`,`practical_session_id`,`enrollment_id`,`staff_id`,`attempt_no`,`submitted_at`,`accepted_at`,`status`,`expected_records`,`submitted_records`,`portal_reference`,`payload_sha256`,`response_evidence_reference`) ON `',v_db,'`.`',v_prefix,'portal_submissions` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'official_results` AS SELECT * FROM `',v_db,'`.`official_results` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'official_results` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`cycle_id`,`result_status`,`component_marks_json`,`ojt_grade`,`revision_no`,`supersedes_result_id`,`source_system`,`source_reference`,`payload_sha256`,`declared_at`,`imported_by`) ON `',v_db,'`.`',v_prefix,'official_results` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'certificates` AS SELECT * FROM `',v_db,'`.`certificates` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'certificates` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`official_result_id`,`document_type`,`certificate_number`,`revision_no`,`supersedes_certificate_id`,`status`,`apaar_id_as_issued`,`credits_as_issued`,`ojt_grade_as_issued`,`source_system`,`source_reference`,`payload_sha256`,`verification_reference`,`issued_on`,`imported_by`) ON `',v_db,'`.`',v_prefix,'certificates` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'credit_transfers` AS SELECT * FROM `',v_db,'`.`credit_transfers` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'credit_transfers` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`certificate_id`,`apaar_id`,`credits`,`abc_reference`,`transferred_at`,`submission_id`) ON `',v_db,'`.`',v_prefix,'credit_transfers` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'inventory_assets` AS SELECT * FROM `',v_db,'`.`inventory_assets` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'inventory_assets` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`asset_code`,`resource_requirement_id`,`name`,`specification`,`quantity`,`serviceable_quantity`,`location`,`checked_on`) ON `',v_db,'`.`',v_prefix,'inventory_assets` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`asset_code`,`resource_requirement_id`,`name`,`specification`,`quantity`,`serviceable_quantity`,`location`,`checked_on`) ON `',v_db,'`.`',v_prefix,'inventory_assets` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'inventory_assets` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'compliance_evidence` AS SELECT * FROM `',v_db,'`.`compliance_evidence` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'compliance_evidence` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`evidence_type`,`trade_unit_shift_id`,`source_id`,`reference_no`,`issued_on`,`expires_on`,`status`,`evidence_reference`,`measurements_json`) ON `',v_db,'`.`',v_prefix,'compliance_evidence` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`evidence_type`,`trade_unit_shift_id`,`source_id`,`reference_no`,`issued_on`,`expires_on`,`status`,`evidence_reference`,`measurements_json`) ON `',v_db,'`.`',v_prefix,'compliance_evidence` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'compliance_evidence` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'formative_template_items` AS SELECT * FROM `',v_db,'`.`formative_template_items` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'formative_template_items` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`batch_year_id`,`item_code`,`description`,`max_marks`,`weight_percent`) ON `',v_db,'`.`',v_prefix,'formative_template_items` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`batch_year_id`,`item_code`,`description`,`max_marks`,`weight_percent`) ON `',v_db,'`.`',v_prefix,'formative_template_items` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'formative_template_items` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'formative_template_scores` AS SELECT * FROM `',v_db,'`.`formative_template_scores` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'formative_template_scores` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`item_code`,`obtained_marks`,`assessed_on`,`assessor_id`,`evidence_reference`,`locked_on`) ON `',v_db,'`.`',v_prefix,'formative_template_scores` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`item_code`,`obtained_marks`,`assessed_on`,`assessor_id`,`evidence_reference`,`locked_on`) ON `',v_db,'`.`',v_prefix,'formative_template_scores` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'formative_template_scores` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'working_day_attendance` AS SELECT * FROM `',v_db,'`.`working_day_attendance` WHERE institute_id=',p_institute,' WITH CASCADED CHECK OPTION'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'working_day_attendance` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT INSERT (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`session_date`,`counted_present`,`evidence_reference`,`certified_by`,`locked_on`) ON `',v_db,'`.`',v_prefix,'working_day_attendance` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT UPDATE (`institute_id`,`enrollment_id`,`batch_id`,`batch_year_id`,`session_date`,`counted_present`,`evidence_reference`,`certified_by`,`locked_on`) ON `',v_db,'`.`',v_prefix,'working_day_attendance` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT DELETE ON `',v_db,'`.`',v_prefix,'working_day_attendance` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'trades` AS SELECT * FROM `',v_db,'`.`trades`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'trades` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'institute` AS SELECT * FROM `',v_db,'`.`institute` WHERE id=',p_institute); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'institute` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'instructor_identities` AS SELECT n.* FROM `',v_db,'`.`instructor_identities` n WHERE EXISTS(SELECT 1 FROM `',v_db,'`.`staff` s WHERE s.identity_id=n.id AND s.institute_id=',p_institute,')'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'instructor_identities` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'v_batch_seats` AS SELECT * FROM `',v_db,'`.`v_batch_seats` WHERE institute_id=',p_institute); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'v_batch_seats` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'v_nims_vacancies` AS SELECT * FROM `',v_db,'`.`v_nims_vacancies` WHERE institute_id=',p_institute); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'v_nims_vacancies` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'v_practical_submission_timeliness` AS SELECT * FROM `',v_db,'`.`v_practical_submission_timeliness` WHERE institute_id=',p_institute); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'v_practical_submission_timeliness` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'v_latest_official_results` AS SELECT * FROM `',v_db,'`.`v_latest_official_results` WHERE institute_id=',p_institute); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'v_latest_official_results` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'v_latest_certificates` AS SELECT * FROM `',v_db,'`.`v_latest_certificates` WHERE institute_id=',p_institute); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'v_latest_certificates` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'v_resource_availability` AS SELECT * FROM `',v_db,'`.`v_resource_availability` WHERE institute_id=',p_institute); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'v_resource_availability` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `',v_db,'`.`',v_prefix,'v_effective_attendance` AS SELECT v.* FROM `',v_db,'`.`v_effective_attendance` v JOIN `',v_db,'`.`attendance_sessions` a ON a.id=v.session_id WHERE a.institute_id=',p_institute); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SET @iti_access_sql=CONCAT('GRANT SELECT ON `',v_db,'`.`',v_prefix,'v_effective_attendance` TO `',v_role,'`'); PREPARE iti_access_stmt FROM @iti_access_sql; EXECUTE iti_access_stmt; DEALLOCATE PREPARE iti_access_stmt;
 SELECT v_role AS institute_role,v_prefix AS view_prefix;
END$$
DELIMITER ;
-- DBA: CALL provision_institute_access(actual_institute_id);
-- Assign only the returned role to that institute application login.
-- Application uses i<id>_<table> views, never the base tables.
-- Do not grant EXECUTE on this administrative provisioning procedure to tenant accounts.

INSERT INTO schema_migrations(version,sha256) VALUES('002_access.sql','eff80c97d1fbf1a1ed1f374ccc9ba32cad61b62eaa0d53d013a298f9e5e9b037');


-- Migration 003_safe_seed.sql; SHA-256 fb977423c39fb1c7eef86350eec112a209d27377622d02d4cc729d2547b08490
-- Deliberately empty operational seed.
-- No fictitious ITI, principal, trainee, IPRN, approval, State formula or LO weight.
-- Actual institute adoption is a separate, sourced onboarding operation.
SELECT 'No operational or unverified regulatory rows seeded' AS seed_result;

INSERT INTO schema_migrations(version,sha256) VALUES('003_safe_seed.sql','fb977423c39fb1c7eef86350eec112a209d27377622d02d4cc729d2547b08490');


-- Migration 004_cycle_fee_integrity.sql; SHA-256 8265099ad779e92493d68b7a2bccb87f6204b63433233960511b87ca3f346778
-- Software-integrity repair. No statutory refund entitlement or regulatory formula added.
DELIMITER $$
DROP TRIGGER guard_cycle_update$$
CREATE TRIGGER guard_cycle_update BEFORE UPDATE ON exam_cycles FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND (NEW.rule_id<>OLD.rule_id OR NEW.cycle_type<>OLD.cycle_type
 OR NEW.eligibility_cutoff<>OLD.eligibility_cutoff OR NEW.schedule_source_id<>OLD.schedule_source_id
 OR NEW.exam_starts_on<>OLD.exam_starts_on OR NEW.activity_windows_json<>OLD.activity_windows_json OR NEW.code<>OLD.code) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Opened cycle context is frozen; create a sourced replacement cycle'; END IF;
 IF FIELD(NEW.status,'draft','eligibility_open','eligibility_frozen','scheduled','closed')
 <FIELD(OLD.status,'draft','eligibility_open','eligibility_frozen','scheduled','closed') THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Opened/frozen cycle cannot move backwards or reopen'; END IF;
 IF NEW.status='eligibility_frozen' AND (NEW.frozen_on IS NULL OR NEW.frozen_by IS NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cycle freeze requires responsible official and timestamp'; END IF;
END$$
CREATE TABLE fee_payment_balances (
 institute_id BIGINT UNSIGNED NOT NULL,
 payment_id BIGINT UNSIGNED NOT NULL,
 original_amount DECIMAL(10,2) NOT NULL,
 remaining_amount DECIMAL(10,2) NOT NULL,
 PRIMARY KEY(payment_id,institute_id),
 FOREIGN KEY(payment_id,institute_id) REFERENCES fee_transactions(id,institute_id),
 CHECK(original_amount>0 AND remaining_amount BETWEEN 0 AND original_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$$
-- Private trigger-managed balance; no tenant write grants.
INSERT INTO fee_payment_balances(institute_id,payment_id,original_amount,remaining_amount)
 SELECT p.institute_id,p.id,p.amount,p.amount-COALESCE(SUM(a.amount),0)
 FROM fee_transactions p LEFT JOIN fee_transactions a ON a.reverses_transaction_id=p.id AND a.institute_id=p.institute_id AND a.transaction_type IN('refund','reversal')
 WHERE p.transaction_type='payment' GROUP BY p.institute_id,p.id,p.amount$$
CREATE TRIGGER create_fee_payment_balance AFTER INSERT ON fee_transactions FOR EACH ROW
BEGIN
 IF NEW.transaction_type='payment' THEN
 INSERT INTO fee_payment_balances VALUES(NEW.institute_id,NEW.id,NEW.amount,NEW.amount);
 END IF;
END$$
CREATE TRIGGER guard_fee_transaction_insert BEFORE INSERT ON fee_transactions FOR EACH ROW
BEGIN
 DECLARE v_enrollment BIGINT UNSIGNED; DECLARE v_cycle BIGINT UNSIGNED;
 DECLARE v_head VARCHAR(30); DECLARE v_kind VARCHAR(30);
 DECLARE v_original DECIMAL(10,2); DECLARE v_remaining DECIMAL(10,2); DECLARE v_found TINYINT DEFAULT 1;
 DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_found=0;
 IF NEW.transaction_type='payment' THEN
  IF NEW.reverses_transaction_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment cannot reference an adjustment original'; END IF;
 ELSE
  SELECT enrollment_id,cycle_id,fee_head,transaction_type,amount
  INTO v_enrollment,v_cycle,v_head,v_kind,v_original
  FROM fee_transactions WHERE id=NEW.reverses_transaction_id AND institute_id=NEW.institute_id;
  IF v_found=0 OR v_kind<>'payment' OR v_enrollment<>NEW.enrollment_id OR v_head<>NEW.fee_head OR NOT(v_cycle<=>NEW.cycle_id) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Adjustment must reference the same trainee, fee head and cycle original payment'; END IF;
  SELECT remaining_amount INTO v_remaining FROM fee_payment_balances
  WHERE payment_id=NEW.reverses_transaction_id AND institute_id=NEW.institute_id FOR UPDATE;
  IF v_found=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Original payment balance is unavailable'; END IF;
  IF NEW.amount>v_remaining THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cumulative adjustments exceed original payment balance'; END IF;
  IF NEW.transaction_type='reversal' AND NEW.amount<>v_remaining THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reversal must clear the remaining original payment balance'; END IF;
  UPDATE fee_payment_balances SET remaining_amount=remaining_amount-NEW.amount
  WHERE payment_id=NEW.reverses_transaction_id AND institute_id=NEW.institute_id AND remaining_amount>=NEW.amount;
  IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Original payment balance changed concurrently'; END IF;
 END IF;
END$$
DELIMITER ;

INSERT INTO schema_migrations(version,sha256) VALUES('004_cycle_fee_integrity.sql','8265099ad779e92493d68b7a2bccb87f6204b63433233960511b87ca3f346778');


-- Migration 005_sourced_methods.sql; SHA-256 231775b857ee2cc7bc27d73cafef18de6e72bf6a763433888437d20f128b1f3a
-- Explicit source-configured engines, not nationwide default State/trade rules.
ALTER TABLE batch_years ADD working_day_method ENUM('unconfigured','certified_day_register') NOT NULL DEFAULT 'unconfigured',
 ADD working_day_method_reference VARCHAR(500) NULL,
 MODIFY formative_method ENUM('unconfigured','points_ratio','template_weighted') NOT NULL DEFAULT 'unconfigured';
CREATE TABLE formative_template_items (
 institute_id BIGINT UNSIGNED NOT NULL, batch_year_id BIGINT UNSIGNED NOT NULL,
 item_code VARCHAR(80) NOT NULL, description VARCHAR(500) NOT NULL,
 max_marks DECIMAL(9,3) NOT NULL, weight_percent DECIMAL(7,4) NOT NULL,
 PRIMARY KEY(batch_year_id,item_code,institute_id),
 FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id),
 CHECK(max_marks>0 AND weight_percent>0 AND weight_percent<=100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE formative_template_scores (
 institute_id BIGINT UNSIGNED NOT NULL, enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL, batch_year_id BIGINT UNSIGNED NOT NULL,
 item_code VARCHAR(80) NOT NULL, obtained_marks DECIMAL(9,3) NOT NULL,
 assessed_on DATE NOT NULL, assessor_id BIGINT UNSIGNED NOT NULL,
 evidence_reference VARCHAR(500) NOT NULL, locked_on DATETIME NULL,
 PRIMARY KEY(enrollment_id,batch_year_id,item_code,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,item_code,institute_id) REFERENCES formative_template_items(batch_year_id,item_code,institute_id),
 FOREIGN KEY(assessor_id,institute_id) REFERENCES staff(id,institute_id),
 CHECK(obtained_marks>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE working_day_attendance (
 institute_id BIGINT UNSIGNED NOT NULL, enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL, batch_year_id BIGINT UNSIGNED NOT NULL, session_date DATE NOT NULL,
 counted_present TINYINT UNSIGNED NOT NULL, evidence_reference VARCHAR(500) NOT NULL,
 certified_by BIGINT UNSIGNED NOT NULL, locked_on DATETIME NULL,
 revision_no BIGINT UNSIGNED NOT NULL DEFAULT 1 INVISIBLE, register_correction_id BIGINT UNSIGNED NOT NULL DEFAULT 0 INVISIBLE,
 PRIMARY KEY(enrollment_id,batch_year_id,session_date,institute_id,revision_no),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(certified_by,institute_id) REFERENCES staff(id,institute_id), CHECK(counted_present IN(0,1)),
 CHECK(CHAR_LENGTH(TRIM(evidence_reference))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
DROP PROCEDURE assert_register_reconciled$$

CREATE PROCEDURE assert_register_reconciled(IN p_enrollment BIGINT UNSIGNED,IN p_year BIGINT UNSIGNED,
 IN p_cycle BIGINT UNSIGNED,IN p_scheduled DECIMAL(10,2),IN p_attended DECIMAL(10,2),IN p_form DECIMAL(5,2),IN p_complete TINYINT)
BEGIN
 DECLARE v_end DATE; DECLARE v_start DATE; DECLARE v_cy BIGINT UNSIGNED;
 DECLARE v_scheduled DECIMAL(12,2); DECLARE v_attended DECIMAL(12,2);
 DECLARE v_required INT; DECLARE v_done INT; DECLARE v_marks DECIMAL(12,2); DECLARE v_max DECIMAL(12,2);
 DECLARE v_lock BIGINT UNSIGNED; DECLARE v_fraction DECIMAL(7,6); DECLARE v_method VARCHAR(40);
 SELECT id INTO v_lock FROM batch_years WHERE id=p_year FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM batch_years y JOIN exam_cycles c ON c.id=p_cycle AND c.institute_id=y.institute_id
 JOIN regulatory_sources a ON a.id=y.attendance_basis_source_id AND a.institute_id=y.institute_id
 JOIN regulatory_sources f ON f.id=y.formative_scheme_source_id AND f.institute_id=y.institute_id
 WHERE y.id=p_year AND EXISTS(SELECT 1 FROM enrollments e WHERE e.id=p_enrollment AND e.batch_id=y.batch_id AND e.institute_id=y.institute_id) AND y.calendar_status='reviewed' AND y.calendar_reviewed_through>=LEAST(y.ends_on,c.eligibility_cutoff)
 AND a.status IN('published','superseded') AND f.status IN('published','superseded')
 AND y.formative_method IN('points_ratio','template_weighted') AND y.formative_scheme_reference IS NOT NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Register reconciliation requires reviewed calendar and sourced formative scheme'; END IF;
 IF (SELECT attendance_basis FROM batch_years WHERE id=p_year)='working_days' THEN
 IF NOT EXISTS(SELECT 1 FROM batch_years y JOIN regulatory_sources s ON s.id=y.attendance_basis_source_id AND s.institute_id=y.institute_id
 WHERE y.id=p_year AND y.working_day_method='certified_day_register'
 AND CHAR_LENGTH(TRIM(y.working_day_method_reference))>0 AND s.authority IN('STATE_UT','SSDEC') AND s.status IN('published','superseded')) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Working-day State method requires reviewed State source and explicit parameters'; END IF;

 END IF;
 SELECT y.starts_on,LEAST(y.ends_on,c.eligibility_cutoff),y.curriculum_year_id INTO v_start,v_end,v_cy
 FROM batch_years y JOIN exam_cycles c ON c.id=p_cycle AND c.institute_id=y.institute_id WHERE y.id=p_year;
 IF EXISTS(SELECT 1 FROM training_calendar_entries t LEFT JOIN attendance_sessions a
 ON a.batch_year_id=t.batch_year_id AND a.session_date=t.session_date AND a.component=t.component
 LEFT JOIN v_effective_attendance r ON r.session_id=a.id AND r.enrollment_id=p_enrollment
 WHERE t.batch_year_id=p_year AND t.session_date BETWEEN v_start AND v_end
 AND (a.id IS NULL OR a.locked_on IS NULL OR a.scheduled_minutes<>t.scheduled_minutes OR r.session_id IS NULL)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar session or locked trainee attendance is missing'; END IF;
 IF EXISTS(SELECT 1 FROM attendance_sessions a LEFT JOIN training_calendar_entries t
 ON t.batch_year_id=a.batch_year_id AND t.session_date=a.session_date AND t.component=a.component
 WHERE a.batch_year_id=p_year AND a.session_date BETWEEN v_start AND v_end AND t.batch_year_id IS NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance contains an unsourced calendar session'; END IF;
 SELECT COALESCE(SUM(t.scheduled_minutes),0),COALESCE(SUM(r.attended_minutes),0) INTO v_scheduled,v_attended
 FROM training_calendar_entries t JOIN attendance_sessions a
 ON a.batch_year_id=t.batch_year_id AND a.session_date=t.session_date AND a.component=t.component
 JOIN v_effective_attendance r ON r.session_id=a.id AND r.enrollment_id=p_enrollment
 WHERE t.batch_year_id=p_year AND t.session_date BETWEEN v_start AND v_end;
 IF (SELECT attendance_basis FROM batch_years WHERE id=p_year)='working_days' THEN
 IF EXISTS(SELECT 1 FROM training_calendar_entries t LEFT JOIN working_day_attendance d
 ON d.batch_year_id=t.batch_year_id AND d.session_date=t.session_date AND d.enrollment_id=p_enrollment AND d.revision_no=(SELECT MAX(x.revision_no) FROM working_day_attendance x WHERE x.enrollment_id=d.enrollment_id AND x.batch_year_id=d.batch_year_id AND x.session_date=d.session_date AND x.institute_id=d.institute_id)
 WHERE t.batch_year_id=p_year AND t.session_date BETWEEN v_start AND v_end AND (d.session_date IS NULL OR d.locked_on IS NULL)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='State method requires locked certified attendance for every working day'; END IF;
 IF EXISTS(SELECT 1 FROM working_day_attendance d WHERE d.enrollment_id=p_enrollment AND d.batch_year_id=p_year AND d.session_date BETWEEN v_start AND v_end
 AND d.revision_no=(SELECT MAX(x.revision_no) FROM working_day_attendance x WHERE x.enrollment_id=d.enrollment_id AND x.batch_year_id=d.batch_year_id AND x.session_date=d.session_date AND x.institute_id=d.institute_id)
 AND d.register_correction_id<>COALESCE((SELECT MAX(c.id) FROM attendance_corrections c JOIN attendance_sessions a ON a.id=c.session_id WHERE c.enrollment_id=d.enrollment_id AND a.batch_year_id=d.batch_year_id AND a.session_date=d.session_date),0)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='State day certification is stale after attendance correction; append a new sourced certification'; END IF;
 SELECT COUNT(DISTINCT t.session_date),COALESCE(SUM(d.counted_present),0) INTO v_scheduled,v_attended
 FROM (SELECT DISTINCT session_date FROM training_calendar_entries WHERE batch_year_id=p_year AND session_date BETWEEN v_start AND v_end) t
 JOIN working_day_attendance d ON d.batch_year_id=p_year AND d.session_date=t.session_date AND d.enrollment_id=p_enrollment AND d.revision_no=(SELECT MAX(x.revision_no) FROM working_day_attendance x WHERE x.enrollment_id=d.enrollment_id AND x.batch_year_id=d.batch_year_id AND x.session_date=d.session_date AND x.institute_id=d.institute_id);
 END IF;
 IF v_scheduled=0 OR p_scheduled<>v_scheduled OR p_attended<>v_attended THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Supplied attendance totals differ from approved calendar and corrected registers'; END IF;
 SELECT formative_method INTO v_method FROM batch_years WHERE id=p_year;
 IF v_method='points_ratio' THEN
 SELECT COUNT(*),COALESCE(SUM(formative_max_marks),0) INTO v_required,v_max FROM learning_outcomes WHERE curriculum_year_id=v_cy;
 IF v_required=0 OR EXISTS(SELECT 1 FROM learning_outcomes WHERE curriculum_year_id=v_cy AND formative_max_marks IS NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Formative points scheme lacks required outcome weights'; END IF;
 SELECT COUNT(*),COALESCE(SUM(f.obtained_marks),0) INTO v_done,v_marks
 FROM formative_assessments f JOIN learning_outcomes l ON l.id=f.learning_outcome_id
 WHERE f.enrollment_id=p_enrollment AND f.batch_year_id=p_year AND f.locked_on IS NOT NULL
 AND f.assessed_on BETWEEN v_start AND v_end AND f.max_marks=l.formative_max_marks;
 IF v_done<>v_required OR p_complete<>1 OR ABS(p_form-ROUND(100*v_marks/v_max,2))>0.01 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Formative totals or completion differ from locked required outcome assessments'; END IF;
 ELSE
 SELECT COUNT(*) INTO v_required FROM learning_outcomes WHERE curriculum_year_id=v_cy;
 SELECT COUNT(*) INTO v_done FROM formative_assessments WHERE enrollment_id=p_enrollment AND batch_year_id=p_year
 AND locked_on IS NOT NULL AND assessed_on BETWEEN v_start AND v_end;
 IF v_required=0 OR v_done<>v_required THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template method requires every prescribed outcome assessment'; END IF;
 SELECT COUNT(*),COALESCE(SUM(weight_percent),0) INTO v_required,v_max FROM formative_template_items WHERE batch_year_id=p_year;
 IF v_required=0 OR v_max<>100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template weights must explicitly total 100 percent'; END IF;
 SELECT COUNT(*),COALESCE(SUM(t.weight_percent*a.obtained_marks/t.max_marks),0) INTO v_done,v_marks
 FROM formative_template_items t JOIN formative_template_scores a ON a.batch_year_id=t.batch_year_id AND a.item_code=t.item_code AND a.institute_id=t.institute_id
 WHERE t.batch_year_id=p_year AND a.enrollment_id=p_enrollment AND a.locked_on IS NOT NULL AND a.assessed_on BETWEEN v_start AND v_end;
 IF v_done<>v_required OR p_complete<>1 OR p_form<>ROUND(v_marks,2) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template totals or completion differ from locked template evidence'; END IF;
 END IF;
END$$
CREATE TRIGGER guard_method_profile BEFORE UPDATE ON batch_years FOR EACH ROW
BEGIN
 IF OLD.calendar_status='reviewed' AND (NEW.working_day_method<>OLD.working_day_method
 OR NOT(NEW.working_day_method_reference<=>OLD.working_day_method_reference)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed working-day method is immutable'; END IF;
END$$
CREATE TRIGGER guard_formative_template_items_insert BEFORE INSERT ON formative_template_items FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
IF (SELECT calendar_status FROM batch_years WHERE id=NEW.batch_year_id)<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed template is immutable'; END IF;
END$$
CREATE TRIGGER guard_formative_template_items_update BEFORE UPDATE ON formative_template_items FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
IF NEW.institute_id<>OLD.institute_id OR NEW.batch_year_id<>OLD.batch_year_id OR NEW.item_code<>OLD.item_code THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template ownership and identity are immutable'; END IF;
IF (SELECT calendar_status FROM batch_years WHERE id=NEW.batch_year_id)<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed template is immutable'; END IF;
END$$
CREATE TRIGGER guard_formative_template_items_delete BEFORE DELETE ON formative_template_items FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=OLD.batch_year_id FOR UPDATE;
IF (SELECT calendar_status FROM batch_years WHERE id=OLD.batch_year_id)<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed template is immutable'; END IF;
END$$
CREATE TRIGGER guard_formative_template_scores_insert BEFORE INSERT ON formative_template_scores FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
IF NOT EXISTS(SELECT 1 FROM formative_template_items t JOIN batch_years y ON y.id=t.batch_year_id WHERE t.batch_year_id=NEW.batch_year_id AND t.item_code=NEW.item_code AND t.institute_id=NEW.institute_id AND NEW.obtained_marks<=t.max_marks AND NEW.assessed_on BETWEEN y.starts_on AND y.ends_on) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template score exceeds maximum or year dates'; END IF;
END$$
CREATE TRIGGER guard_formative_template_scores_update BEFORE UPDATE ON formative_template_scores FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
IF NEW.institute_id<>OLD.institute_id OR NEW.batch_year_id<>OLD.batch_year_id OR NEW.item_code<>OLD.item_code THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template ownership and identity are immutable'; END IF;
IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked template score is immutable'; END IF;
IF NOT EXISTS(SELECT 1 FROM formative_template_items t JOIN batch_years y ON y.id=t.batch_year_id WHERE t.batch_year_id=NEW.batch_year_id AND t.item_code=NEW.item_code AND t.institute_id=NEW.institute_id AND NEW.obtained_marks<=t.max_marks AND NEW.assessed_on BETWEEN y.starts_on AND y.ends_on) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template score exceeds maximum or year dates'; END IF;
IF NEW.enrollment_id<>OLD.enrollment_id OR NEW.batch_id<>OLD.batch_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template score trainee is immutable'; END IF;
END$$
CREATE TRIGGER guard_formative_template_scores_delete BEFORE DELETE ON formative_template_scores FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=OLD.batch_year_id FOR UPDATE;
IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked template score is immutable'; END IF;
END$$
CREATE TRIGGER guard_working_day_insert BEFORE INSERT ON working_day_attendance FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
IF NOT EXISTS(SELECT 1 FROM training_calendar_entries WHERE batch_year_id=NEW.batch_year_id AND institute_id=NEW.institute_id AND session_date=NEW.session_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Working-day determination requires sourced calendar date'; END IF;
SET NEW.register_correction_id=COALESCE((SELECT MAX(c.id) FROM attendance_corrections c JOIN attendance_sessions a ON a.id=c.session_id WHERE c.enrollment_id=NEW.enrollment_id AND a.batch_year_id=NEW.batch_year_id AND a.session_date=NEW.session_date),0);
SET NEW.revision_no=COALESCE((SELECT MAX(d.revision_no) FROM working_day_attendance d WHERE d.enrollment_id=NEW.enrollment_id AND d.batch_year_id=NEW.batch_year_id AND d.session_date=NEW.session_date AND d.institute_id=NEW.institute_id),0)+1;
END$$
CREATE TRIGGER guard_working_day_update BEFORE UPDATE ON working_day_attendance FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=NEW.batch_year_id FOR UPDATE;
IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked State working-day determination is immutable'; END IF;
IF NEW.enrollment_id<>OLD.enrollment_id OR NEW.batch_year_id<>OLD.batch_year_id OR NEW.session_date<>OLD.session_date OR NEW.institute_id<>OLD.institute_id OR NEW.batch_id<>OLD.batch_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Working-day identity is immutable'; END IF;
IF NOT EXISTS(SELECT 1 FROM training_calendar_entries WHERE batch_year_id=NEW.batch_year_id AND institute_id=NEW.institute_id AND session_date=NEW.session_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Working-day determination requires sourced calendar date'; END IF;
SET NEW.register_correction_id=COALESCE((SELECT MAX(c.id) FROM attendance_corrections c JOIN attendance_sessions a ON a.id=c.session_id WHERE c.enrollment_id=NEW.enrollment_id AND a.batch_year_id=NEW.batch_year_id AND a.session_date=NEW.session_date),0);
IF NEW.revision_no<>OLD.revision_no THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Working-day revision identity is immutable'; END IF;
END$$
CREATE TRIGGER guard_working_day_delete BEFORE DELETE ON working_day_attendance FOR EACH ROW
BEGIN
DECLARE v_lock BIGINT UNSIGNED; SELECT id INTO v_lock FROM batch_years WHERE id=OLD.batch_year_id FOR UPDATE;
IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Locked State working-day determination is immutable'; END IF;
END$$
DROP TRIGGER guard_eligibility_insert$$
CREATE TRIGGER guard_eligibility_insert BEFORE INSERT ON exam_eligibility FOR EACH ROW
BEGIN
 DECLARE v_att DECIMAL(5,2); DECLARE v_form DECIMAL(5,2); DECLARE v_points DECIMAL(5,2) DEFAULT 0;
 DECLARE v_prn INT; DECLARE v_cbt INT; DECLARE v_practical INT; DECLARE v_rule BIGINT UNSIGNED;
 DECLARE v_found INT DEFAULT 0;
 SELECT COUNT(*),MAX(r.id),MAX(r.minimum_attendance),MAX(r.minimum_formative),MAX(r.requires_prn),
 MAX(r.requires_cbt_fee),MAX(r.requires_practical_fee)
 INTO v_found,v_rule,v_att,v_form,v_prn,v_cbt,v_practical
 FROM exam_cycles c JOIN exam_rule_versions r ON r.id=c.rule_id
 JOIN batch_years y ON y.id=NEW.batch_year_id
 WHERE c.id=NEW.cycle_id AND c.status='eligibility_open' AND r.status IN('published','superseded')
 AND c.exam_starts_on>=r.effective_from AND (r.effective_to IS NULL OR c.exam_starts_on<=r.effective_to)
 AND r.cycle_type=c.cycle_type AND NEW.attendance_basis=y.attendance_basis;
 IF v_found<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Eligibility requires open cycle and published matching rule/basis'; END IF;
 IF ABS(NEW.raw_attendance-ROUND(100*NEW.attended_units/NEW.scheduled_units,2))>0.01 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance percentage does not match numerator/denominator'; END IF;
 IF NEW.relaxation_id IS NOT NULL THEN
 SELECT COUNT(*),COALESCE(MAX(points),0) INTO v_found,v_points FROM attendance_relaxations
 WHERE id=NEW.relaxation_id AND enrollment_id=NEW.enrollment_id AND cycle_id=NEW.cycle_id AND batch_year_id=NEW.batch_year_id;
 IF v_found<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Relaxation belongs to another trainee/cycle/year'; END IF;
 END IF;
 IF ABS(NEW.effective_attendance-LEAST(100,NEW.raw_attendance+v_points))>0.01 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Effective attendance must use authorized relaxation'; END IF;
 IF NEW.eligibility_status='eligible' THEN
 IF NEW.effective_attendance<v_att OR NEW.formative_percent<v_form OR NEW.formative_complete<>1 THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance/formative eligibility requirement failed'; END IF;
 IF v_prn=1 AND NOT EXISTS(SELECT 1 FROM enrollments e JOIN students s ON s.id=e.student_id
 WHERE e.id=NEW.enrollment_id AND s.prn IS NOT NULL AND s.prn_verified_on IS NOT NULL) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Verified PRN is required'; END IF;
 IF (v_cbt=1 OR v_practical=1) AND NOT EXISTS(SELECT 1 FROM exam_fee_clearances f WHERE f.enrollment_id=NEW.enrollment_id AND f.cycle_id=NEW.cycle_id
 AND (v_cbt=0 OR f.cbt_cleared=1) AND (v_practical=0 OR f.practical_cleared=1)) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Required examination fee clearance missing'; END IF;
 CALL assert_register_reconciled(NEW.enrollment_id,NEW.batch_year_id,NEW.cycle_id,NEW.scheduled_units,NEW.attended_units,NEW.formative_percent,NEW.formative_complete);
 END IF;
 SET NEW.rule_snapshot_json=(SELECT JSON_OBJECT('rule_id',id,'source_id',source_id,'minimum_attendance',minimum_attendance,
 'minimum_formative',minimum_formative,'principal_relaxation_cap',principal_relaxation_cap,
 'requires_prn',requires_prn,'requires_cbt_fee',requires_cbt_fee,'requires_practical_fee',requires_practical_fee,
 'attendance_basis_source_id',(SELECT attendance_basis_source_id FROM batch_years WHERE id=NEW.batch_year_id),
 'calendar_evidence',(SELECT calendar_evidence_reference FROM batch_years WHERE id=NEW.batch_year_id),
 'calendar_reviewed_through',(SELECT calendar_reviewed_through FROM batch_years WHERE id=NEW.batch_year_id),
 'working_day_certifications',(SELECT JSON_ARRAYAGG(JSON_OBJECT('date',session_date,'revision',revision_no,'register_correction_id',register_correction_id,'evidence',evidence_reference)) FROM working_day_attendance WHERE enrollment_id=NEW.enrollment_id AND batch_year_id=NEW.batch_year_id AND locked_on IS NOT NULL),
 'working_day_method',(SELECT working_day_method FROM batch_years WHERE id=NEW.batch_year_id),
 'working_day_method_reference',(SELECT working_day_method_reference FROM batch_years WHERE id=NEW.batch_year_id),
 'formative_template',(SELECT JSON_ARRAYAGG(JSON_OBJECT('item',item_code,'max_marks',max_marks,'weight_percent',weight_percent)) FROM formative_template_items WHERE batch_year_id=NEW.batch_year_id),
 'formative_method',(SELECT formative_method FROM batch_years WHERE id=NEW.batch_year_id),
 'formative_scheme_source_id',(SELECT formative_scheme_source_id FROM batch_years WHERE id=NEW.batch_year_id),
 'formative_scheme_reference',(SELECT formative_scheme_reference FROM batch_years WHERE id=NEW.batch_year_id),
 'attendance_cutoff',(SELECT eligibility_cutoff FROM exam_cycles WHERE id=NEW.cycle_id),
 'correction_max_id',(SELECT MAX(c.id) FROM attendance_corrections c JOIN attendance_sessions a ON a.id=c.session_id WHERE c.enrollment_id=NEW.enrollment_id AND a.batch_year_id=NEW.batch_year_id))
 FROM exam_rule_versions WHERE id=v_rule);
END$$
DELIMITER ;

INSERT INTO schema_migrations(version,sha256) VALUES('005_sourced_methods.sql','231775b857ee2cc7bc27d73cafef18de6e72bf6a763433888437d20f128b1f3a');


-- Migration 006_bihar_scope.sql; SHA-256 afaf2d80dcdb1991358a5806cb84c83e2d4e8c2672141a42b7287cc1ff571661
-- Bihar-only application scope, requested by the user; not a new regulatory rule.
-- Canonical institute/policy state code is BR. National sources may have NULL state.
ALTER TABLE institute ALTER COLUMN state_ut_code SET DEFAULT 'BR',
 ADD CONSTRAINT chk_bihar_institute CHECK(CAST(state_ut_code AS BINARY)=CAST('BR' AS BINARY));
ALTER TABLE regulatory_sources ADD CONSTRAINT chk_bihar_source
 CHECK(state_ut_code IS NULL OR CAST(state_ut_code AS BINARY)=CAST('BR' AS BINARY));
ALTER TABLE admission_rules ADD CONSTRAINT chk_bihar_admission
 CHECK(CAST(state_ut_code AS BINARY)=CAST('BR' AS BINARY));
ALTER TABLE reservation_policies ADD CONSTRAINT chk_bihar_reservation
 CHECK(CAST(state_ut_code AS BINARY)=CAST('BR' AS BINARY));

INSERT INTO schema_migrations(version,sha256) VALUES('006_bihar_scope.sql','afaf2d80dcdb1991358a5806cb84c83e2d4e8c2672141a42b7287cc1ff571661');
