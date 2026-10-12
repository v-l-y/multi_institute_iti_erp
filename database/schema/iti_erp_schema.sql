-- ITI ERP — SCHEMA AND INTEGRITY GUARDS
-- MySQL 9.7 LTS, Bihar private CTS multi-ITI

SET NAMES utf8mb4;
SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET time_zone='+05:30';

CREATE TABLE schema_migrations(
 version VARCHAR(80) PRIMARY KEY,
 sha256 CHAR(64) NOT NULL,
 applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- CORE ----------

CREATE TABLE institute (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 mis_code VARCHAR(40) NOT NULL UNIQUE,
 state_ut_code VARCHAR(20) NOT NULL DEFAULT 'BR',
 address TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CHECK(state_ut_code='BR')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE global_regulatory_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 authority ENUM('DGT','NCVET','SIDH','NCVT_MIS','NIMS','STATE_UT','SSDEC') NOT NULL,
 state_ut_code VARCHAR(20) NULL,
 document_type VARCHAR(60) NOT NULL,
 title VARCHAR(255) NOT NULL,
 reference_no VARCHAR(190) NOT NULL,
 issued_on DATE NOT NULL,
 source_url VARCHAR(900) NULL,
 evidence_reference VARCHAR(500) NULL,
 sha256 CHAR(64) NULL,
 supersedes_id BIGINT UNSIGNED NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 reviewed_by VARCHAR(190) NULL,
 reviewed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,authority),
 UNIQUE(authority,reference_no,issued_on),
 FOREIGN KEY(supersedes_id,authority) REFERENCES global_regulatory_documents(id,authority),
 CHECK(authority NOT IN('STATE_UT','SSDEC') OR (state_ut_code IS NOT NULL AND state_ut_code='BR')),
 CHECK(status='draft' OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
 CHECK(source_url IS NOT NULL OR evidence_reference IS NOT NULL),
 CHECK(sha256 IS NULL OR sha256 REGEXP '^[0-9a-fA-F]{64}$'),
 CHECK(supersedes_id IS NULL OR supersedes_id<>id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE regulatory_sources (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 global_document_id BIGINT UNSIGNED NOT NULL,
 local_evidence_reference VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(global_document_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(global_document_id) REFERENCES global_regulatory_documents(id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Institute approval, renewal and relocation history is operational, not a second regulatory master.
CREATE TABLE institute_affiliation_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 institute_id BIGINT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 change_type VARCHAR(60) NOT NULL,
 approved_name VARCHAR(190) NOT NULL,
 approved_address TEXT NOT NULL,
 affiliation_status ENUM('affiliated','suspended','deactivated','deaffiliated') NOT NULL,
 affiliation_order_reference VARCHAR(190) NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 current_institute_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN effective_to IS NULL THEN institute_id ELSE NULL END) STORED,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(institute_id,effective_from),
 UNIQUE(current_institute_guard),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 CHECK(effective_to IS NULL OR effective_to>=effective_from),
 CHECK(CHAR_LENGTH(TRIM(change_type))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trades (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 dgt_code VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DGT global Trade Master versions; trade ID/code remain stable for institute history.
CREATE TABLE trade_master_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL,
 source_document_id BIGINT UNSIGNED NOT NULL,
 version_code VARCHAR(80) NOT NULL,
 official_name VARCHAR(190) NOT NULL,
 trade_type ENUM('engineering','non_engineering','divyang') NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 status ENUM('draft','active','superseded','retired') NOT NULL DEFAULT 'draft',
 active_trade_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='active' THEN trade_id ELSE NULL END) STORED,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(trade_id,version_code),
 UNIQUE(active_trade_guard),
 FOREIGN KEY(trade_id) REFERENCES trades(id),
 FOREIGN KEY(source_document_id) REFERENCES global_regulatory_documents(id),
 CHECK(effective_to IS NULL OR effective_to>=effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE global_shifts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shift_no TINYINT UNSIGNED NOT NULL,
 shift_code VARCHAR(40) NOT NULL,
 display_name VARCHAR(120) NOT NULL,
 revision_code VARCHAR(80) NOT NULL,
 source_document_id BIGINT UNSIGNED NOT NULL,
 status ENUM('active','superseded','retired') NOT NULL DEFAULT 'active',
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 current_shift_guard TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='active' THEN shift_no ELSE NULL END) STORED,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(shift_no,revision_code),
 UNIQUE(id,shift_no),
 UNIQUE(current_shift_guard),
 FOREIGN KEY(source_document_id) REFERENCES global_regulatory_documents(id),
 CHECK(shift_no BETWEEN 1 AND 3),
 CHECK(effective_to IS NULL OR effective_to>=effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared official CTS syllabus, versioned independently of institute data.
CREATE TABLE global_curriculum_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL,
 source_document_id BIGINT UNSIGNED NOT NULL,
 version_code VARCHAR(80) NOT NULL,
 duration_months SMALLINT UNSIGNED NOT NULL,
 nsqf_level DECIMAL(3,1) NOT NULL,
 unit_strength SMALLINT UNSIGNED NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(trade_id,version_code),
 UNIQUE(id,trade_id),
 FOREIGN KEY(trade_id) REFERENCES trades(id),
 FOREIGN KEY(source_document_id) REFERENCES global_regulatory_documents(id),
 CHECK(duration_months IN(6,12,24)),
 CHECK(nsqf_level BETWEEN 1 AND 8),
 CHECK(unit_strength>0),
 CHECK(effective_to IS NULL OR effective_to>=effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE global_curriculum_years (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 global_curriculum_id BIGINT UNSIGNED NOT NULL,
 year_no TINYINT UNSIGNED NOT NULL,
 trade_practical_minutes INT UNSIGNED NOT NULL,
 trade_theory_minutes INT UNSIGNED NOT NULL,
 employability_minutes INT UNSIGNED NOT NULL,
 ojt_project_minutes INT UNSIGNED NOT NULL DEFAULT 0,
 credits DECIMAL(7,2) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(global_curriculum_id,year_no),
 UNIQUE(id,global_curriculum_id,year_no),
 FOREIGN KEY(global_curriculum_id) REFERENCES global_curriculum_versions(id),
 CHECK(year_no IN(1,2)),
 CHECK(credits IS NULL OR credits>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE global_learning_outcomes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 global_curriculum_year_id BIGINT UNSIGNED NOT NULL,
 lo_code VARCHAR(80) NOT NULL,
 nos_code VARCHAR(80) NULL,
 outcome_text TEXT NOT NULL,
 assessment_criteria TEXT NOT NULL,
 formative_max_marks DECIMAL(7,2) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(global_curriculum_year_id,lo_code),
 UNIQUE(id,global_curriculum_year_id,lo_code),
 FOREIGN KEY(global_curriculum_year_id) REFERENCES global_curriculum_years(id),
 CHECK(formative_max_marks IS NULL OR formative_max_marks>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE global_resource_requirements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 global_curriculum_id BIGINT UNSIGNED NOT NULL,
 resource_code VARCHAR(80) NOT NULL,
 resource_name VARCHAR(255) NOT NULL,
 specification TEXT NULL,
 resource_type ENUM('tool','machine','equipment','power_kw','workshop_area_m2','safety','staff') NOT NULL,
 base_quantity DECIMAL(10,3) NOT NULL,
 additional_unit_quantity DECIMAL(10,3) NOT NULL DEFAULT 0,
 measurement_unit VARCHAR(30) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(global_curriculum_id,resource_code),
 UNIQUE(id,global_curriculum_id,resource_code),
 FOREIGN KEY(global_curriculum_id) REFERENCES global_curriculum_versions(id),
 CHECK(base_quantity>=0 AND additional_unit_quantity>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_versions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL,
 global_curriculum_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(global_curriculum_id,institute_id),
 UNIQUE(id,trade_id,institute_id),
 UNIQUE(id,global_curriculum_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(global_curriculum_id,trade_id) REFERENCES global_curriculum_versions(id,trade_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_years (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curriculum_id BIGINT UNSIGNED NOT NULL,
 global_curriculum_id BIGINT UNSIGNED NOT NULL,
 global_curriculum_year_id BIGINT UNSIGNED NOT NULL,
 year_no TINYINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(curriculum_id,year_no,institute_id),
 UNIQUE(id,curriculum_id,institute_id),
 UNIQUE(id,global_curriculum_year_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(curriculum_id,global_curriculum_id,institute_id) REFERENCES curriculum_versions(id,global_curriculum_id,institute_id),
 FOREIGN KEY(global_curriculum_year_id,global_curriculum_id,year_no) REFERENCES global_curriculum_years(id,global_curriculum_id,year_no),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_outcomes (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curriculum_year_id BIGINT UNSIGNED NOT NULL,
 global_curriculum_year_id BIGINT UNSIGNED NOT NULL,
 global_learning_outcome_id BIGINT UNSIGNED NOT NULL,
 lo_code VARCHAR(80) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(curriculum_year_id,lo_code,institute_id),
 UNIQUE(id,curriculum_year_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(curriculum_year_id,global_curriculum_year_id,institute_id) REFERENCES curriculum_years(id,global_curriculum_year_id,institute_id),
 FOREIGN KEY(global_learning_outcome_id,global_curriculum_year_id,lo_code) REFERENCES global_learning_outcomes(id,global_curriculum_year_id,lo_code),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resource_requirements (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 curriculum_id BIGINT UNSIGNED NOT NULL,
 global_curriculum_id BIGINT UNSIGNED NOT NULL,
 global_resource_requirement_id BIGINT UNSIGNED NOT NULL,
 resource_code VARCHAR(80) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(curriculum_id,resource_code,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(curriculum_id,global_curriculum_id,institute_id) REFERENCES curriculum_versions(id,global_curriculum_id,institute_id),
 FOREIGN KEY(global_resource_requirement_id,global_curriculum_id,resource_code) REFERENCES global_resource_requirements(id,global_curriculum_id,resource_code),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE instructor_identities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 iprn VARCHAR(120) NOT NULL UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_code VARCHAR(50) NOT NULL,
 full_name VARCHAR(190) NOT NULL,
 role ENUM('principal','trade_instructor','es_instructor','wcs_ed_instructor','office','other') NOT NULL,
 joined_on DATE NOT NULL,
 left_on DATE NULL,
 iprn VARCHAR(120) NULL,
 identity_id BIGINT UNSIGNED NULL,
 nims_verification ENUM('pending','verified','inactive','rejected') NOT NULL DEFAULT 'pending',
 verification_reference VARCHAR(190) NULL,
 verified_on DATE NULL,
 identity_mode ENUM('aadhaar','non_aadhaar') NULL,
 active_principal_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN role='principal' AND left_on IS NULL THEN institute_id ELSE NULL END) STORED UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(identity_id) REFERENCES instructor_identities(id),
 CHECK(left_on IS NULL OR left_on>=joined_on),
 CHECK(nims_verification<>'verified' OR (iprn IS NOT NULL AND verification_reference IS NOT NULL AND verified_on IS NOT NULL)),
 UNIQUE(employee_code,institute_id),
 UNIQUE(iprn,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_qualifications (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_id BIGINT UNSIGNED NOT NULL,
 qualification_type VARCHAR(60) NOT NULL,
 specialization VARCHAR(190) NOT NULL,
 certificate_reference VARCHAR(190) NOT NULL,
 experience_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 verified_by VARCHAR(190) NULL,
 verified_on DATE NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,institute_id),
 FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trade_units (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL,
 unit_no SMALLINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(trade_id,unit_no,institute_id),
 UNIQUE(id,trade_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(trade_id) REFERENCES trades(id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(unit_no>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trade_unit_shifts (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_unit_id BIGINT UNSIGNED NOT NULL,
 trade_id BIGINT UNSIGNED NOT NULL,
 global_shift_id BIGINT UNSIGNED NOT NULL,
 shift_no TINYINT UNSIGNED NOT NULL,
 status ENUM('active','inactive','closed') NOT NULL DEFAULT 'active',
 training_mode ENUM('CTS','DST') NOT NULL DEFAULT 'CTS',
 affiliation_status ENUM('approved','suspended','surrendered','deaffiliated') NOT NULL,
 sanctioned_capacity SMALLINT UNSIGNED NOT NULL,
 valid_from DATE NOT NULL,
 valid_to DATE NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 approval_reference VARCHAR(190) NOT NULL,
 current_unit_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN valid_to IS NULL THEN trade_unit_id ELSE NULL END) STORED,
 current_shift_guard TINYINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN valid_to IS NULL THEN shift_no ELSE NULL END) STORED,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(trade_unit_id,shift_no,valid_from,institute_id),
 UNIQUE(current_unit_guard,current_shift_guard,institute_id),
 UNIQUE(id,trade_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(trade_unit_id,trade_id,institute_id) REFERENCES trade_units(id,trade_id,institute_id),
 FOREIGN KEY(global_shift_id,shift_no) REFERENCES global_shifts(id,shift_no),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(shift_no BETWEEN 1 AND 3 AND sanctioned_capacity>0),
 CHECK(shift_no<>3 OR training_mode='DST'),
 CHECK(valid_to IS NULL OR valid_to>=valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE instructor_mappings (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_id BIGINT UNSIGNED NOT NULL,
 mapping_type ENUM('trade','subject') NOT NULL,
 identity_id BIGINT UNSIGNED NULL,
 trade_unit_shift_id BIGINT UNSIGNED NULL,
 subject_code VARCHAR(30) NULL,
 valid_from DATE NOT NULL,
 valid_to DATE NULL,
 status ENUM('active','released','revoked') NOT NULL DEFAULT 'active',
 qualification_source_id BIGINT UNSIGNED NOT NULL,
 qualification_check_json LONGTEXT NOT NULL CHECK(JSON_VALID(qualification_check_json)),
 qualification_checked_by VARCHAR(190) NOT NULL,
 nims_reference VARCHAR(190) NOT NULL,
 active_identity_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN status='active' THEN identity_id ELSE NULL END) STORED UNIQUE,
 active_staff_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN status='active' THEN staff_id ELSE NULL END) STORED UNIQUE,
 active_unit_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN status='active' AND mapping_type='trade' THEN trade_unit_shift_id ELSE NULL END) STORED UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(identity_id) REFERENCES instructor_identities(id),
 FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id),
 FOREIGN KEY(trade_unit_shift_id,institute_id) REFERENCES trade_unit_shifts(id,institute_id),
 FOREIGN KEY(qualification_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK((mapping_type='trade' AND trade_unit_shift_id IS NOT NULL AND subject_code IS NULL)
    OR (mapping_type='subject' AND trade_unit_shift_id IS NULL AND subject_code IN('ES','WCS','ED','WCS_ED'))),
 CHECK(valid_to IS NULL OR valid_to>=valid_from),
 UNIQUE(id,staff_id,institute_id),
 UNIQUE(id,trade_unit_shift_id,institute_id),
 UNIQUE(id,institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE instructor_events (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 staff_id BIGINT UNSIGNED NOT NULL,
 mapping_id BIGINT UNSIGNED NULL,
 event_type ENUM('transfer_in','transfer_out','terminated','vacancy','replacement') NOT NULL,
 occurred_on DATE NOT NULL,
 remap_due_on DATE NULL,
 resolved_on DATE NULL,
 replacement_mapping_id BIGINT UNSIGNED NULL,
 portal_reference VARCHAR(190) NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 notes VARCHAR(1000) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,institute_id),
 FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id),
 FOREIGN KEY(mapping_id,staff_id,institute_id) REFERENCES instructor_mappings(id,staff_id,institute_id),
 FOREIGN KEY(replacement_mapping_id,institute_id) REFERENCES instructor_mappings(id,institute_id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(remap_due_on IS NULL OR remap_due_on>=occurred_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global DGT CTS training calendar revisions and official milestones (no guessed dates).
CREATE TABLE cts_training_calendars (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admission_year SMALLINT UNSIGNED NOT NULL,
 session_code VARCHAR(80) NOT NULL,
 revision_code VARCHAR(80) NOT NULL,
 source_document_id BIGINT UNSIGNED NOT NULL,
 training_starts_on DATE NOT NULL,
 training_ends_on DATE NOT NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(admission_year,session_code,revision_code),
 UNIQUE(id,admission_year),
 UNIQUE(id,revision_code),
 FOREIGN KEY(source_document_id) REFERENCES global_regulatory_documents(id),
 CHECK(training_ends_on>=training_starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cts_training_calendar_milestones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 calendar_id BIGINT UNSIGNED NOT NULL,
 milestone_code VARCHAR(80) NOT NULL,
 milestone_date DATE NOT NULL,
 description VARCHAR(500) NOT NULL,
 document_section_reference VARCHAR(190) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(calendar_id,milestone_code),
 FOREIGN KEY(calendar_id) REFERENCES cts_training_calendars(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Portal shift codes are official calendar-specific data, not permanent arithmetic rules.
CREATE TABLE cts_portal_shift_mappings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 calendar_id BIGINT UNSIGNED NOT NULL,
 session_part ENUM('primary','six_month_second') NOT NULL,
 physical_shift_no TINYINT UNSIGNED NOT NULL,
 portal_shift_no TINYINT UNSIGNED NOT NULL,
 document_section_reference VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(calendar_id,session_part,physical_shift_no),
 UNIQUE(calendar_id,session_part,portal_shift_no),
 FOREIGN KEY(calendar_id) REFERENCES cts_training_calendars(id),
 CHECK(physical_shift_no BETWEEN 1 AND 3 AND portal_shift_no>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admission_intakes (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admission_year SMALLINT UNSIGNED NOT NULL,
 calendar_id BIGINT UNSIGNED NOT NULL,
 intake_code VARCHAR(80) NOT NULL,
 session_part ENUM('primary','six_month_second') NOT NULL DEFAULT 'primary',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(admission_year,intake_code,institute_id),
 UNIQUE(id,admission_year,institute_id),
 UNIQUE(id,calendar_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(calendar_id,admission_year) REFERENCES cts_training_calendars(id,admission_year),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CTS admission closing-date orders and amendments are stored as dated source-linked revisions.
CREATE TABLE admission_intake_revisions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 intake_id BIGINT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 revision_code VARCHAR(80) NOT NULL,
 opens_on DATE NOT NULL,
 closes_on DATE NOT NULL,
 approval_reference VARCHAR(190) NOT NULL,
 published_on DATE NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 supersedes_id BIGINT UNSIGNED NULL,
 current_intake_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='published' THEN intake_id ELSE NULL END) STORED,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(intake_id,revision_code,institute_id),
 UNIQUE(id,intake_id,institute_id),
 UNIQUE(current_intake_guard,institute_id),
 FOREIGN KEY(intake_id,institute_id) REFERENCES admission_intakes(id,institute_id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(supersedes_id,intake_id,institute_id) REFERENCES admission_intake_revisions(id,intake_id,institute_id),
 CHECK(closes_on>=opens_on),
 CHECK(status='draft' OR published_on IS NOT NULL),
 CHECK(supersedes_id IS NULL OR supersedes_id<>id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seat_matrix_versions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admission_year SMALLINT UNSIGNED NOT NULL,
 intake_id BIGINT UNSIGNED NOT NULL,
 version_code VARCHAR(80) NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 portal_reference VARCHAR(190) NOT NULL,
 dgt_circular_no VARCHAR(80) NULL,
 published_on DATE NULL,
 status ENUM('draft','final','superseded') NOT NULL DEFAULT 'draft',
 supersedes_id BIGINT UNSIGNED NULL,
 final_intake_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN status='final' THEN intake_id ELSE NULL END) STORED UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(intake_id,version_code,institute_id),
 UNIQUE(id,intake_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(intake_id,admission_year,institute_id) REFERENCES admission_intakes(id,admission_year,institute_id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(supersedes_id,intake_id,institute_id) REFERENCES seat_matrix_versions(id,intake_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(status='draft' OR published_on IS NOT NULL),
 CHECK(supersedes_id IS NULL OR supersedes_id<>id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seat_matrix_entries (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 version_id BIGINT UNSIGNED NOT NULL,
 intake_id BIGINT UNSIGNED NOT NULL,
 trade_unit_shift_id BIGINT UNSIGNED NOT NULL,
 portal_shift_no TINYINT UNSIGNED NOT NULL,
 seat_capacity SMALLINT UNSIGNED NOT NULL,
 admission_allowed TINYINT NOT NULL,
 consumed_previous_session TINYINT NOT NULL DEFAULT 0,
 instructor_mapping_id BIGINT UNSIGNED NULL,
 instructor_mapping_satisfied TINYINT NOT NULL DEFAULT 0,
 eligibility_reason VARCHAR(500) NULL,
 portal_itsu_reference VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(version_id,trade_unit_shift_id,institute_id),
 UNIQUE(id,trade_unit_shift_id,intake_id,institute_id),
 UNIQUE(id,trade_unit_shift_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(version_id,intake_id,institute_id) REFERENCES seat_matrix_versions(id,intake_id,institute_id),
 FOREIGN KEY(trade_unit_shift_id,institute_id) REFERENCES trade_unit_shifts(id,institute_id),
 FOREIGN KEY(instructor_mapping_id,trade_unit_shift_id,institute_id) REFERENCES instructor_mappings(id,trade_unit_shift_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(admission_allowed IN(0,1) AND consumed_previous_session IN(0,1) AND instructor_mapping_satisfied IN(0,1)),
 CHECK(admission_allowed=0 OR instructor_mapping_id IS NOT NULL),
 CHECK(portal_shift_no>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Official admission eligibility and admission window: shared policy, not institute-owned values.
CREATE TABLE global_admission_rule_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL,
 global_curriculum_id BIGINT UNSIGNED NOT NULL,
 source_document_id BIGINT UNSIGNED NOT NULL,
 state_source_document_id BIGINT UNSIGNED NULL,
 admission_window_document_id BIGINT UNSIGNED NOT NULL,
 version_code VARCHAR(80) NOT NULL,
 state_ut_code VARCHAR(20) NOT NULL DEFAULT 'BR',
 minimum_age_years TINYINT UNSIGNED NOT NULL,
 age_cutoff_date DATE NOT NULL,
 qualification_routes_json LONGTEXT NOT NULL CHECK(JSON_VALID(qualification_routes_json)),
 admission_opens_on DATE NOT NULL,
 admission_closes_on DATE NOT NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(trade_id,global_curriculum_id,version_code,state_ut_code),
 UNIQUE(id,trade_id,global_curriculum_id),
 FOREIGN KEY(global_curriculum_id,trade_id) REFERENCES global_curriculum_versions(id,trade_id),
 FOREIGN KEY(source_document_id) REFERENCES global_regulatory_documents(id),
 FOREIGN KEY(state_source_document_id) REFERENCES global_regulatory_documents(id),
 FOREIGN KEY(admission_window_document_id) REFERENCES global_regulatory_documents(id),
 CHECK(state_ut_code='BR'),
 CHECK(admission_closes_on>=admission_opens_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admission_rules (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 trade_id BIGINT UNSIGNED NOT NULL,
 curriculum_id BIGINT UNSIGNED NOT NULL,
 global_curriculum_id BIGINT UNSIGNED NOT NULL,
 global_admission_rule_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(global_admission_rule_id,institute_id),
 UNIQUE(id,trade_id,curriculum_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(curriculum_id,global_curriculum_id,institute_id) REFERENCES curriculum_versions(id,global_curriculum_id,institute_id),
 FOREIGN KEY(global_admission_rule_id,trade_id,global_curriculum_id) REFERENCES global_admission_rule_versions(id,trade_id,global_curriculum_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bihar reservation percentages and categories are official shared policy, not institute edits.
CREATE TABLE global_reservation_policies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 state_ut_code VARCHAR(20) NOT NULL DEFAULT 'BR',
 admission_year SMALLINT UNSIGNED NOT NULL,
 version_code VARCHAR(80) NOT NULL,
 source_document_id BIGINT UNSIGNED NOT NULL,
 policy_json LONGTEXT NOT NULL CHECK(JSON_VALID(policy_json)),
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(state_ut_code,admission_year,version_code),
 UNIQUE(id,admission_year),
 FOREIGN KEY(source_document_id) REFERENCES global_regulatory_documents(id),
 CHECK(state_ut_code='BR')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservation_policies (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admission_year SMALLINT UNSIGNED NOT NULL,
 global_reservation_policy_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(global_reservation_policy_id,institute_id),
 UNIQUE(id,admission_year,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(global_reservation_policy_id,admission_year) REFERENCES global_reservation_policies(id,admission_year),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE batches (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_code VARCHAR(80) NOT NULL,
 trade_unit_shift_id BIGINT UNSIGNED NOT NULL,
 trade_id BIGINT UNSIGNED NOT NULL,
 curriculum_id BIGINT UNSIGNED NOT NULL,
 seat_matrix_entry_id BIGINT UNSIGNED NOT NULL,
 admission_rule_id BIGINT UNSIGNED NOT NULL,
 admission_year SMALLINT UNSIGNED NOT NULL,
 intake_id BIGINT UNSIGNED NOT NULL,
 calendar_id BIGINT UNSIGNED NOT NULL,
 starts_on DATE NOT NULL,
 ends_on DATE NOT NULL,
 training_mode ENUM('CTS','DST') NOT NULL DEFAULT 'CTS',
 itsu_approval_snapshot_json LONGTEXT NOT NULL DEFAULT ('{}') CHECK(JSON_VALID(itsu_approval_snapshot_json)),
 capacity SMALLINT UNSIGNED NOT NULL,
 occupied_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'planned',
 unit_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN status<>'cancelled' THEN trade_unit_shift_id ELSE NULL END) STORED,
 intake_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN status<>'cancelled' THEN intake_id ELSE NULL END) STORED,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(unit_guard,intake_guard,institute_id),
 UNIQUE(id,curriculum_id,institute_id),
 UNIQUE(id,admission_rule_id,institute_id),
 UNIQUE(id,admission_year,institute_id),
 UNIQUE(id,calendar_id,institute_id),
 UNIQUE(id,trade_id,institute_id),
 UNIQUE(batch_code,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(intake_id,admission_year,institute_id) REFERENCES admission_intakes(id,admission_year,institute_id),
 FOREIGN KEY(intake_id,calendar_id,institute_id) REFERENCES admission_intakes(id,calendar_id,institute_id),
 FOREIGN KEY(trade_unit_shift_id,trade_id,institute_id) REFERENCES trade_unit_shifts(id,trade_id,institute_id),
 FOREIGN KEY(curriculum_id,trade_id,institute_id) REFERENCES curriculum_versions(id,trade_id,institute_id),
 FOREIGN KEY(seat_matrix_entry_id,trade_unit_shift_id,intake_id,institute_id) REFERENCES seat_matrix_entries(id,trade_unit_shift_id,intake_id,institute_id),
 FOREIGN KEY(admission_rule_id,trade_id,curriculum_id,institute_id) REFERENCES admission_rules(id,trade_id,curriculum_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(capacity>0 AND occupied_seats<=capacity AND ends_on>=starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE batch_years (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL,
 curriculum_id BIGINT UNSIGNED NOT NULL,
 curriculum_year_id BIGINT UNSIGNED NOT NULL,
 calendar_id BIGINT UNSIGNED NOT NULL,
 starts_on DATE NOT NULL,
 ends_on DATE NOT NULL,
 attendance_basis ENUM('minutes','working_days') NOT NULL,
 attendance_basis_source_id BIGINT UNSIGNED NOT NULL,
 calendar_status ENUM('draft','reviewed') NOT NULL DEFAULT 'draft',
 calendar_reviewed_through DATE NULL,
 calendar_reviewed_by VARCHAR(190) NULL,
 calendar_evidence_reference VARCHAR(500) NULL,
 formative_method ENUM('unconfigured','points_ratio','template_weighted') NOT NULL DEFAULT 'unconfigured',
 formative_scheme_source_id BIGINT UNSIGNED NULL,
 formative_scheme_reference VARCHAR(500) NULL,
 working_day_method ENUM('unconfigured','certified_day_register') NOT NULL DEFAULT 'unconfigured',
 working_day_method_reference VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(batch_id,curriculum_year_id,institute_id),
 UNIQUE(id,batch_id,institute_id),
 UNIQUE(id,calendar_id,institute_id),
 UNIQUE(id,curriculum_year_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(formative_scheme_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(calendar_id) REFERENCES cts_training_calendars(id),
 FOREIGN KEY(batch_id,curriculum_id,institute_id) REFERENCES batches(id,curriculum_id,institute_id),
 FOREIGN KEY(batch_id,calendar_id,institute_id) REFERENCES batches(id,calendar_id,institute_id),
 FOREIGN KEY(curriculum_year_id,curriculum_id,institute_id) REFERENCES curriculum_years(id,curriculum_id,institute_id),
 FOREIGN KEY(attendance_basis_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(ends_on>=starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_calendar_entries (
 institute_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 calendar_id BIGINT UNSIGNED NOT NULL,
 session_date DATE NOT NULL,
 component ENUM('trade_practical','trade_theory','employability','ojt_project') NOT NULL,
 scheduled_minutes SMALLINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(batch_year_id,session_date,component,institute_id),
 FOREIGN KEY(batch_year_id,calendar_id,institute_id) REFERENCES batch_years(id,calendar_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(scheduled_minutes>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subject_batch_assignments (
 institute_id BIGINT UNSIGNED NOT NULL,
 mapping_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 assigned_minutes_per_week SMALLINT UNSIGNED NOT NULL,
 principal_reference VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(mapping_id,batch_year_id,institute_id),
 FOREIGN KEY(mapping_id,institute_id) REFERENCES instructor_mappings(id,institute_id),
 FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE batch_quotas (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL,
 admission_year SMALLINT UNSIGNED NOT NULL,
 policy_id BIGINT UNSIGNED NOT NULL,
 category_code VARCHAR(40) NOT NULL,
 quota_type ENUM('vertical','horizontal') NOT NULL,
 seats SMALLINT UNSIGNED NOT NULL,
 occupied_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 eligibility_condition_json LONGTEXT NOT NULL CHECK(JSON_VALID(eligibility_condition_json)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(batch_id,category_code,quota_type,institute_id),
 UNIQUE(id,batch_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(batch_id,admission_year,institute_id) REFERENCES batches(id,admission_year,institute_id),
 FOREIGN KEY(policy_id,admission_year,institute_id) REFERENCES reservation_policies(id,admission_year,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(occupied_seats<=seats)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 local_student_code VARCHAR(60) NOT NULL,
 full_name VARCHAR(190) NOT NULL,
 date_of_birth DATE NOT NULL,
 father_name VARCHAR(190) NULL,
 mother_name VARCHAR(190) NULL,
 gender VARCHAR(30) NOT NULL,
 mobile VARCHAR(20) NULL,
 address TEXT NOT NULL,
 category_code VARCHAR(40) NULL,
 is_ews TINYINT NOT NULL DEFAULT 0,
 disability_percent DECIMAL(5,2) NULL,
 identity_mode ENUM('aadhaar','non_aadhaar') NOT NULL,
 identity_evidence_reference VARCHAR(190) NOT NULL,
 prn VARCHAR(120) NULL,
 prn_verified_on DATE NULL,
 prn_source_reference VARCHAR(190) NULL,
 apaar_id VARCHAR(30) NULL,
 apaar_consent ENUM('not_requested','granted','declined','withdrawn') NOT NULL DEFAULT 'not_requested',
 consent_reference VARCHAR(190) NULL,
 consent_on DATE NULL,
 verification_iti_mis_code VARCHAR(40) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(local_student_code,institute_id),
 UNIQUE(prn,institute_id),
 UNIQUE(apaar_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(is_ews IN(0,1)),
 CHECK(disability_percent IS NULL OR disability_percent BETWEEN 0 AND 100),
 CHECK(prn_verified_on IS NULL OR (prn IS NOT NULL AND prn_source_reference IS NOT NULL)),
 CHECK(apaar_id IS NULL OR (apaar_consent='granted' AND consent_reference IS NOT NULL AND consent_on IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_qualifications (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id BIGINT UNSIGNED NOT NULL,
 level_code VARCHAR(40) NOT NULL,
 board_name VARCHAR(190) NOT NULL,
 passing_year SMALLINT UNSIGNED NOT NULL,
 certificate_reference VARCHAR(190) NOT NULL,
 subject_results_json LONGTEXT NOT NULL CHECK(JSON_VALID(subject_results_json)),
 verified_by VARCHAR(190) NOT NULL,
 verified_on DATE NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,student_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(student_id,institute_id) REFERENCES students(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enrollments (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 admission_number VARCHAR(80) NOT NULL,
 state_registration_number VARCHAR(100) NULL,
 admitted_on DATE NOT NULL,
 status ENUM('pending_verification','active','completed','transferred','dropout','cancelled') NOT NULL DEFAULT 'pending_verification',
 admission_rule_id BIGINT UNSIGNED NOT NULL,
 qualification_id BIGINT UNSIGNED NOT NULL,
 qualification_route_code VARCHAR(80) NOT NULL,
 qualification_check_json LONGTEXT NOT NULL CHECK(JSON_VALID(qualification_check_json)),
 qualification_check_passed TINYINT NOT NULL,
 checked_by VARCHAR(190) NOT NULL,
 checked_on DATE NOT NULL,
 vertical_quota_id BIGINT UNSIGNED NULL,
 horizontal_quota_id BIGINT UNSIGNED NULL,
 quota_evidence_reference VARCHAR(190) NULL,
 active_student_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN status IN('pending_verification','active') THEN student_id ELSE NULL END) STORED UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(batch_id,student_id,institute_id),
 UNIQUE(id,batch_id,institute_id),
 UNIQUE(admission_number,institute_id),
 UNIQUE(state_registration_number,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(student_id,institute_id) REFERENCES students(id,institute_id),
 FOREIGN KEY(batch_id,admission_rule_id,institute_id) REFERENCES batches(id,admission_rule_id,institute_id),
 FOREIGN KEY(admission_rule_id,institute_id) REFERENCES admission_rules(id,institute_id),
 FOREIGN KEY(qualification_id,student_id,institute_id) REFERENCES student_qualifications(id,student_id,institute_id),
 FOREIGN KEY(vertical_quota_id,batch_id,institute_id) REFERENCES batch_quotas(id,batch_id,institute_id),
 FOREIGN KEY(horizontal_quota_id,batch_id,institute_id) REFERENCES batch_quotas(id,batch_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(qualification_check_passed IN(0,1)),
 CHECK(status NOT IN ('active','completed') OR qualification_check_passed=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_events (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 event_type ENUM('transfer','withdrawal','dropout','cancellation','readmission','correction') NOT NULL,
 effective_on DATE NOT NULL,
 reference_no VARCHAR(190) NOT NULL,
 destination_mis_code VARCHAR(40) NULL,
 reason TEXT NOT NULL,
 recorded_by VARCHAR(190) NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_sessions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 session_date DATE NOT NULL,
 component ENUM('trade_practical','trade_theory','employability','ojt_project') NOT NULL,
 scheduled_minutes SMALLINT UNSIGNED NOT NULL,
 instructor_id BIGINT UNSIGNED NOT NULL,
 locked_on DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(batch_year_id,session_date,component,institute_id),
 UNIQUE(id,batch_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(instructor_id,institute_id) REFERENCES staff(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(scheduled_minutes>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_records (
 institute_id BIGINT UNSIGNED NOT NULL,
 session_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 attended_minutes SMALLINT UNSIGNED NOT NULL,
 status ENUM('present','absent','leave','medical_leave','emergency_leave','ojt') NOT NULL,
 leave_evidence_reference VARCHAR(190) NULL,
 recorded_by VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(session_id,enrollment_id,institute_id),
 FOREIGN KEY(session_id,batch_id,institute_id) REFERENCES attendance_sessions(id,batch_id,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_corrections (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT UNSIGNED NOT NULL,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 before_json LONGTEXT NOT NULL CHECK(JSON_VALID(before_json)),
 after_json LONGTEXT NOT NULL CHECK(JSON_VALID(after_json)),
 reason TEXT NOT NULL,
 authority_reference VARCHAR(190) NOT NULL,
 approved_by VARCHAR(190) NOT NULL,
 approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,institute_id),
 FOREIGN KEY(session_id,enrollment_id,institute_id) REFERENCES attendance_records(session_id,enrollment_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE formative_assessments (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 curriculum_year_id BIGINT UNSIGNED NOT NULL,
 learning_outcome_id BIGINT UNSIGNED NOT NULL,
 assessed_on DATE NOT NULL,
 max_marks DECIMAL(7,2) NOT NULL,
 obtained_marks DECIMAL(7,2) NOT NULL,
 evidence_reference VARCHAR(190) NOT NULL,
 assessor_id BIGINT UNSIGNED NOT NULL,
 locked_on DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(enrollment_id,batch_year_id,learning_outcome_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,curriculum_year_id,institute_id) REFERENCES batch_years(id,curriculum_year_id,institute_id),
 FOREIGN KEY(learning_outcome_id,curriculum_year_id,institute_id) REFERENCES learning_outcomes(id,curriculum_year_id,institute_id),
 FOREIGN KEY(assessor_id,institute_id) REFERENCES staff(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(max_marks>0 AND obtained_marks BETWEEN 0 AND max_marks)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE formative_template_items (
 institute_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 item_code VARCHAR(80) NOT NULL,
 description VARCHAR(500) NOT NULL,
 max_marks DECIMAL(9,3) NOT NULL,
 weight_percent DECIMAL(7,4) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(batch_year_id,item_code,institute_id),
 FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id),
 CHECK(max_marks>0 AND weight_percent>0 AND weight_percent<=100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE formative_template_scores (
 institute_id BIGINT UNSIGNED NOT NULL,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 item_code VARCHAR(80) NOT NULL,
 obtained_marks DECIMAL(9,3) NOT NULL,
 assessed_on DATE NOT NULL,
 assessor_id BIGINT UNSIGNED NOT NULL,
 evidence_reference VARCHAR(500) NOT NULL,
 locked_on DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(enrollment_id,batch_year_id,item_code,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,item_code,institute_id) REFERENCES formative_template_items(batch_year_id,item_code,institute_id),
 FOREIGN KEY(assessor_id,institute_id) REFERENCES staff(id,institute_id),
 CHECK(obtained_marks>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE working_day_attendance (
 institute_id BIGINT UNSIGNED NOT NULL,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 session_date DATE NOT NULL,
 counted_present TINYINT UNSIGNED NOT NULL,
 evidence_reference VARCHAR(500) NOT NULL,
 certified_by BIGINT UNSIGNED NOT NULL,
 locked_on DATETIME NULL,
 revision_no BIGINT UNSIGNED NOT NULL DEFAULT 1 INVISIBLE,
 register_correction_id BIGINT UNSIGNED NOT NULL DEFAULT 0 INVISIBLE,
 is_current TINYINT UNSIGNED NOT NULL DEFAULT 1,
 current_enrollment_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN is_current=1 THEN enrollment_id ELSE NULL END) STORED,
 current_batch_year_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN is_current=1 THEN batch_year_id ELSE NULL END) STORED,
 current_date_guard DATE GENERATED ALWAYS AS
   (CASE WHEN is_current=1 THEN session_date ELSE NULL END) STORED,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(enrollment_id,batch_year_id,session_date,institute_id,revision_no),
 UNIQUE(current_enrollment_guard,current_batch_year_guard,current_date_guard,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(certified_by,institute_id) REFERENCES staff(id,institute_id),
 CHECK(counted_present IN(0,1)),
 CHECK(is_current IN(0,1) AND revision_no>0),
 CHECK(CHAR_LENGTH(TRIM(evidence_reference))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ojt_projects (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 mode ENUM('ojt','group_project') NOT NULL,
 title VARCHAR(255) NOT NULL,
 industry_name VARCHAR(255) NULL,
 industry_registration_reference VARCHAR(190) NULL,
 workplace_address TEXT NULL,
 industry_mentor VARCHAR(190) NULL,
 coordinator_id BIGINT UNSIGNED NOT NULL,
 starts_on DATE NOT NULL,
 ends_on DATE NOT NULL,
 required_minutes INT UNSIGNED NOT NULL,
 safety_reference VARCHAR(190) NOT NULL,
 female_safety_reference VARCHAR(190) NULL,
 completion_certificate_reference VARCHAR(190) NULL,
 status ENUM('planned','ongoing','completed') NOT NULL DEFAULT 'planned',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(enrollment_id,batch_year_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(coordinator_id,institute_id) REFERENCES staff(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(ends_on>=starts_on AND required_minutes>0),
 CHECK(mode<>'ojt' OR (industry_name IS NOT NULL AND industry_mentor IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ojt_logbook (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 entry_date DATE NOT NULL,
 attended_minutes SMALLINT UNSIGNED NOT NULL,
 activity TEXT NOT NULL,
 mentor_confirmation VARCHAR(190) NOT NULL,
 monitoring_safety_reference VARCHAR(190) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(project_id,entry_date,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(project_id,institute_id) REFERENCES ojt_projects(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(attended_minutes<=1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ojt_evaluations (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL UNIQUE,
 primary_evaluator_role ENUM('industry_mentor','trade_instructor') NOT NULL,
 primary_evaluator_name VARCHAR(190) NOT NULL,
 primary_marks DECIMAL(6,2) NOT NULL,
 external_examiner_name VARCHAR(190) NOT NULL,
 external_marks DECIMAL(6,2) NOT NULL,
 primary_max_marks DECIMAL(6,2) NOT NULL,
 external_max_marks DECIMAL(6,2) NOT NULL,
 final_grade ENUM('A1','A','B','C') NOT NULL,
 evaluated_on DATE NOT NULL,
 evaluation_source_id BIGINT UNSIGNED NOT NULL,
 signed_evidence_reference VARCHAR(190) NOT NULL,
 sidh_submission_reference VARCHAR(190) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,institute_id),
 FOREIGN KEY(project_id,institute_id) REFERENCES ojt_projects(id,institute_id),
 FOREIGN KEY(evaluation_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(primary_max_marks>0 AND external_max_marks>0),
 CHECK(primary_marks BETWEEN 0 AND primary_max_marks AND external_marks BETWEEN 0 AND external_max_marks)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Examination thresholds and eligibility requirements are official policy versions.
CREATE TABLE global_exam_rule_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_document_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(80) NOT NULL,
 cycle_type ENUM('mains','supplementary','leftover') NOT NULL,
 minimum_attendance DECIMAL(5,2) NOT NULL,
 minimum_formative DECIMAL(5,2) NOT NULL,
 principal_relaxation_cap DECIMAL(5,2) NOT NULL DEFAULT 0,
 relaxation_source_document_id BIGINT UNSIGNED NULL,
 requires_prn TINYINT NOT NULL DEFAULT 1,
 requires_cbt_fee TINYINT NOT NULL DEFAULT 1,
 requires_practical_fee TINYINT NOT NULL DEFAULT 1,
 requires_ojt_for_certificate TINYINT NOT NULL DEFAULT 1,
 practical_upload_hours SMALLINT UNSIGNED NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 status ENUM('draft','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(code),
 UNIQUE(id,cycle_type),
 FOREIGN KEY(source_document_id) REFERENCES global_regulatory_documents(id),
 FOREIGN KEY(relaxation_source_document_id) REFERENCES global_regulatory_documents(id),
 CHECK(minimum_attendance BETWEEN 0 AND 100 AND minimum_formative BETWEEN 0 AND 100),
 CHECK(principal_relaxation_cap BETWEEN 0 AND 100),
 CHECK(principal_relaxation_cap=0 OR relaxation_source_document_id IS NOT NULL),
 CHECK(requires_prn IN(0,1) AND requires_cbt_fee IN(0,1) AND requires_practical_fee IN(0,1) AND requires_ojt_for_certificate IN(0,1)),
 CHECK(effective_to IS NULL OR effective_to>=effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_rule_versions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 global_exam_rule_id BIGINT UNSIGNED NOT NULL,
 cycle_type ENUM('mains','supplementary','leftover') NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(global_exam_rule_id,institute_id),
 UNIQUE(id,cycle_type,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(global_exam_rule_id,cycle_type) REFERENCES global_exam_rule_versions(id,cycle_type),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_cycles (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(80) NOT NULL,
 cycle_type ENUM('mains','supplementary','leftover') NOT NULL,
 rule_id BIGINT UNSIGNED NOT NULL,
 schedule_source_id BIGINT UNSIGNED NOT NULL,
 eligibility_cutoff DATE NOT NULL,
 exam_starts_on DATE NOT NULL,
 activity_windows_json LONGTEXT NOT NULL CHECK(JSON_VALID(activity_windows_json)),
 status ENUM('draft','eligibility_open','eligibility_frozen','scheduled','closed') NOT NULL DEFAULT 'draft',
 frozen_by VARCHAR(190) NULL,
 frozen_on DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(code,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(rule_id,cycle_type,institute_id) REFERENCES exam_rule_versions(id,cycle_type,institute_id),
 FOREIGN KEY(schedule_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_transactions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 cycle_id BIGINT UNSIGNED NULL,
 fee_head ENUM('registration','admission','tuition','caution_deposit','cbt','practical','other') NOT NULL,
 transaction_type ENUM('payment','refund','reversal') NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 paid_on DATETIME NOT NULL,
 receipt_no VARCHAR(100) NOT NULL,
 portal_reference VARCHAR(190) NULL,
 payment_mode ENUM('cash','bank','upi','sidh_gateway') NOT NULL,
 reverses_transaction_id BIGINT UNSIGNED NULL,
 recorded_by VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(receipt_no,institute_id),
 UNIQUE(id,enrollment_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(reverses_transaction_id,enrollment_id,institute_id) REFERENCES fee_transactions(id,enrollment_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(amount>0),
 CHECK(transaction_type='payment' OR reverses_transaction_id IS NOT NULL),
 CHECK(transaction_type<>'payment' OR reverses_transaction_id IS NULL),
 CHECK(reverses_transaction_id IS NULL OR reverses_transaction_id<>id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_payment_balances (
 institute_id BIGINT UNSIGNED NOT NULL,
 payment_id BIGINT UNSIGNED NOT NULL,
 original_amount DECIMAL(10,2) NOT NULL,
 remaining_amount DECIMAL(10,2) NOT NULL,
 PRIMARY KEY(payment_id,institute_id),
 FOREIGN KEY(payment_id,institute_id) REFERENCES fee_transactions(id,institute_id),
 CHECK(original_amount>0 AND remaining_amount BETWEEN 0 AND original_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_fee_clearances (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 cycle_id BIGINT UNSIGNED NOT NULL,
 cbt_cleared TINYINT NOT NULL,
 practical_cleared TINYINT NOT NULL,
 cbt_portal_reference VARCHAR(190) NULL,
 practical_reference VARCHAR(190) NULL,
 verified_by VARCHAR(190) NOT NULL,
 verified_on DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(enrollment_id,cycle_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(cbt_cleared IN(0,1) AND practical_cleared IN(0,1)),
 CHECK(cbt_cleared=0 OR cbt_portal_reference IS NOT NULL),
 CHECK(practical_cleared=0 OR practical_reference IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_relaxations (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 cycle_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 points DECIMAL(5,2) NOT NULL,
 authority_source_id BIGINT UNSIGNED NOT NULL,
 authority_order_reference VARCHAR(190) NOT NULL,
 supporting_evidence_reference VARCHAR(190) NOT NULL,
 reason TEXT NOT NULL,
 approved_by VARCHAR(190) NOT NULL,
 approved_on DATE NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(enrollment_id,cycle_id,batch_year_id,institute_id),
 UNIQUE(id,enrollment_id,cycle_id,batch_year_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(authority_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(points>0 AND points<=100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_eligibility (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 cycle_id BIGINT UNSIGNED NOT NULL,
 scheduled_units DECIMAL(10,2) NOT NULL,
 attended_units DECIMAL(10,2) NOT NULL,
 attendance_basis ENUM('minutes','working_days') NOT NULL,
 raw_attendance DECIMAL(5,2) NOT NULL,
 effective_attendance DECIMAL(5,2) NOT NULL,
 relaxation_id BIGINT UNSIGNED NULL,
 formative_percent DECIMAL(5,2) NOT NULL,
 formative_complete TINYINT NOT NULL,
 eligibility_status ENUM('eligible','ineligible','withheld') NOT NULL,
 reason VARCHAR(1000) NULL,
 rule_snapshot_json LONGTEXT NOT NULL CHECK(JSON_VALID(rule_snapshot_json)),
 evidence_reference VARCHAR(190) NOT NULL,
 frozen_by VARCHAR(190) NOT NULL,
 frozen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(enrollment_id,cycle_id,batch_year_id,institute_id),
 UNIQUE(id,enrollment_id,cycle_id,batch_year_id,institute_id),
 UNIQUE(id,cycle_id,batch_year_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(relaxation_id,enrollment_id,cycle_id,batch_year_id,institute_id) REFERENCES attendance_relaxations(id,enrollment_id,cycle_id,batch_year_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(scheduled_units>0 AND attended_units BETWEEN 0 AND scheduled_units),
 CHECK(raw_attendance BETWEEN 0 AND 100 AND effective_attendance BETWEEN 0 AND 100 AND formative_percent BETWEEN 0 AND 100),
 CHECK(formative_complete IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hall_tickets (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 eligibility_id BIGINT UNSIGNED NOT NULL,
 ticket_number VARCHAR(120) NOT NULL,
 revision_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 source_system ENUM('SIDH','NCVT_MIS','DGT') NOT NULL,
 source_reference VARCHAR(190) NOT NULL,
 payload_sha256 CHAR(64) NOT NULL,
 exam_centre_reference VARCHAR(190) NOT NULL,
 issued_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(ticket_number,revision_no,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(eligibility_id,institute_id) REFERENCES exam_eligibility(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(payload_sha256 REGEXP '^[0-9a-fA-F]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE practical_sessions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 cycle_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 ended_at DATETIME NOT NULL,
 examiner_name VARCHAR(190) NOT NULL,
 examiner_reference VARCHAR(190) NOT NULL,
 question_paper_authority ENUM('DGT','STATE_UT') NOT NULL,
 question_paper_reference VARCHAR(190) NOT NULL,
 question_paper_source_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,cycle_id,batch_year_id,institute_id),
 UNIQUE(id,cycle_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(batch_year_id,institute_id) REFERENCES batch_years(id,institute_id),
 FOREIGN KEY(question_paper_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE practical_marks (
 institute_id BIGINT UNSIGNED NOT NULL,
 practical_session_id BIGINT UNSIGNED NOT NULL,
 eligibility_id BIGINT UNSIGNED NOT NULL,
 cycle_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 attendance_status ENUM('present','absent') NOT NULL,
 max_marks DECIMAL(7,2) NOT NULL,
 obtained_marks DECIMAL(7,2) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(practical_session_id,eligibility_id,institute_id),
 FOREIGN KEY(practical_session_id,cycle_id,batch_year_id,institute_id) REFERENCES practical_sessions(id,cycle_id,batch_year_id,institute_id),
 FOREIGN KEY(eligibility_id,cycle_id,batch_year_id,institute_id) REFERENCES exam_eligibility(id,cycle_id,batch_year_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(max_marks>0),
 CHECK((attendance_status='present' AND obtained_marks IS NOT NULL AND obtained_marks BETWEEN 0 AND max_marks)
    OR (attendance_status='absent' AND obtained_marks IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE portal_submissions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 submission_type ENUM('trainee_verification','attendance_formative','practical_marks','ojt_grade','abc_credit','nims_mapping') NOT NULL,
 cycle_id BIGINT UNSIGNED NULL,
 practical_session_id BIGINT UNSIGNED NULL,
 enrollment_id BIGINT UNSIGNED NULL,
 staff_id BIGINT UNSIGNED NULL,
 attempt_no SMALLINT UNSIGNED NOT NULL,
 submitted_at DATETIME NOT NULL,
 accepted_at DATETIME NULL,
 status ENUM('submitted','accepted','rejected') NOT NULL,
 expected_records INT UNSIGNED NOT NULL,
 submitted_records INT UNSIGNED NOT NULL,
 portal_reference VARCHAR(190) NOT NULL,
 payload_sha256 CHAR(64) NOT NULL,
 response_evidence_reference VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(practical_session_id,cycle_id,institute_id) REFERENCES practical_sessions(id,cycle_id,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id),
 FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(status<>'accepted' OR (accepted_at IS NOT NULL AND submitted_records=expected_records)),
 CHECK(submission_type<>'practical_marks' OR (practical_session_id IS NOT NULL AND cycle_id IS NOT NULL)),
 CHECK(payload_sha256 REGEXP '^[0-9a-fA-F]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE official_results (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 batch_year_id BIGINT UNSIGNED NOT NULL,
 cycle_id BIGINT UNSIGNED NOT NULL,
 result_status ENUM('pass','fail','promoted_with_back','withheld','absent','not_declared') NOT NULL,
 component_marks_json LONGTEXT NOT NULL CHECK(JSON_VALID(component_marks_json)),
 ojt_grade ENUM('A1','A','B','C') NULL,
 revision_no SMALLINT UNSIGNED NOT NULL,
 supersedes_result_id BIGINT UNSIGNED NULL,
 source_system ENUM('SIDH','NCVT_MIS','DGT') NOT NULL,
 source_reference VARCHAR(190) NOT NULL,
 payload_sha256 CHAR(64) NOT NULL,
 declared_at DATETIME NOT NULL,
 imported_by VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(enrollment_id,batch_year_id,cycle_id,revision_no,institute_id),
 UNIQUE(id,enrollment_id,batch_year_id,cycle_id,institute_id),
 UNIQUE(id,enrollment_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,batch_id,institute_id) REFERENCES enrollments(id,batch_id,institute_id),
 FOREIGN KEY(batch_year_id,batch_id,institute_id) REFERENCES batch_years(id,batch_id,institute_id),
 FOREIGN KEY(cycle_id,institute_id) REFERENCES exam_cycles(id,institute_id),
 FOREIGN KEY(supersedes_result_id,enrollment_id,batch_year_id,cycle_id,institute_id) REFERENCES official_results(id,enrollment_id,batch_year_id,cycle_id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(payload_sha256 REGEXP '^[0-9a-fA-F]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificates (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enrollment_id BIGINT UNSIGNED NOT NULL,
 official_result_id BIGINT UNSIGNED NOT NULL,
 document_type ENUM('NTC','marksheet') NOT NULL,
 certificate_number VARCHAR(120) NOT NULL,
 revision_no SMALLINT UNSIGNED NOT NULL,
 supersedes_certificate_id BIGINT UNSIGNED NULL,
 status ENUM('valid','revoked') NOT NULL,
 apaar_id_as_issued VARCHAR(30) NULL,
 credits_as_issued DECIMAL(8,2) NULL,
 ojt_grade_as_issued ENUM('A1','A','B','C') NULL,
 source_system ENUM('SIDH','NCVT_MIS','DGT') NOT NULL,
 source_reference VARCHAR(190) NOT NULL,
 payload_sha256 CHAR(64) NOT NULL,
 verification_reference VARCHAR(255) NOT NULL,
 issued_on DATE NOT NULL,
 imported_by VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(certificate_number,revision_no,institute_id),
 UNIQUE(id,enrollment_id,document_type,institute_id),
 UNIQUE(id,enrollment_id,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(enrollment_id,institute_id) REFERENCES enrollments(id,institute_id),
 FOREIGN KEY(official_result_id,enrollment_id,institute_id) REFERENCES official_results(id,enrollment_id,institute_id),
 FOREIGN KEY(supersedes_certificate_id,enrollment_id,document_type,institute_id) REFERENCES certificates(id,enrollment_id,document_type,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(credits_as_issued IS NULL OR credits_as_issued>=0),
 CHECK(payload_sha256 REGEXP '^[0-9a-fA-F]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_transfers (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 certificate_id BIGINT UNSIGNED NOT NULL,
 apaar_id VARCHAR(30) NOT NULL,
 credits DECIMAL(8,2) NOT NULL,
 abc_reference VARCHAR(190) NOT NULL,
 transferred_at DATETIME NOT NULL,
 submission_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(certificate_id,abc_reference,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(certificate_id,institute_id) REFERENCES certificates(id,institute_id),
 FOREIGN KEY(submission_id,institute_id) REFERENCES portal_submissions(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(credits>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_assets (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 asset_code VARCHAR(80) NOT NULL,
 resource_requirement_id BIGINT UNSIGNED NULL,
 name VARCHAR(255) NOT NULL,
 specification TEXT NULL,
 quantity DECIMAL(10,3) NOT NULL,
 serviceable_quantity DECIMAL(10,3) NOT NULL,
 location VARCHAR(190) NOT NULL,
 checked_on DATE NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(asset_code,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(resource_requirement_id,institute_id) REFERENCES resource_requirements(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(quantity>=0 AND serviceable_quantity BETWEEN 0 AND quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compliance_evidence (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 evidence_type ENUM('affiliation','renewal','building','fire','power','safety','equipment','staff','DST_mou','inspection','grading','admission_window','late_nims_mapping') NOT NULL,
 trade_unit_shift_id BIGINT UNSIGNED NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 reference_no VARCHAR(190) NOT NULL,
 issued_on DATE NOT NULL,
 expires_on DATE NULL,
 status ENUM('valid','expired','superseded','pending') NOT NULL,
 evidence_reference VARCHAR(500) NOT NULL,
 measurements_json LONGTEXT NULL CHECK(JSON_VALID(measurements_json)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(id,institute_id),
 FOREIGN KEY(trade_unit_shift_id,institute_id) REFERENCES trade_unit_shifts(id,institute_id),
 FOREIGN KEY(source_id,institute_id) REFERENCES regulatory_sources(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id),
 CHECK(expires_on IS NULL OR expires_on>=issued_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- AUTH ----------

CREATE TABLE auth_permissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(120) NOT NULL UNIQUE,
 description VARCHAR(500) NOT NULL,
 module VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code ENUM('principal','administration','instructor','student') NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 description VARCHAR(500) NULL,
 is_system TINYINT NOT NULL DEFAULT 1 CHECK(is_system IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_role_permissions (
 role_id BIGINT UNSIGNED NOT NULL,
 permission_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(role_id,permission_id),
 FOREIGN KEY(role_id) REFERENCES auth_roles(id) ON DELETE CASCADE,
 FOREIGN KEY(permission_id) REFERENCES auth_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_users (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(120) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 must_change_password TINYINT NOT NULL DEFAULT 0 CHECK(must_change_password IN(0,1)),
 failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 locked_until DATETIME NULL,
 status ENUM('active','locked','disabled') NOT NULL DEFAULT 'active',
 last_login_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(username,institute_id),
 UNIQUE(id,institute_id),
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_user_roles (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL,
 valid_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 valid_to DATETIME NULL,
 granted_by BIGINT UNSIGNED NULL,
 current_user_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN valid_to IS NULL THEN user_id ELSE NULL END) STORED,
 current_role_guard BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN valid_to IS NULL THEN role_id ELSE NULL END) STORED,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(user_id,role_id,valid_from,institute_id),
 UNIQUE(current_user_guard,current_role_guard,institute_id),
 FOREIGN KEY(user_id,institute_id) REFERENCES auth_users(id,institute_id),
 FOREIGN KEY(role_id) REFERENCES auth_roles(id),
 FOREIGN KEY(granted_by,institute_id) REFERENCES auth_users(id,institute_id),
 CHECK(valid_to IS NULL OR valid_to>valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_staff_links (
 institute_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 staff_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,institute_id),
 UNIQUE(staff_id,institute_id),
 FOREIGN KEY(user_id,institute_id) REFERENCES auth_users(id,institute_id),
 FOREIGN KEY(staff_id,institute_id) REFERENCES staff(id,institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_student_links (
 institute_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 student_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,institute_id),
 UNIQUE(student_id,institute_id),
 FOREIGN KEY(user_id,institute_id) REFERENCES auth_users(id,institute_id),
 FOREIGN KEY(student_id,institute_id) REFERENCES students(id,institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_sessions (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 session_token_hash CHAR(64) NOT NULL,
 refresh_token_hash CHAR(64) NULL,
 issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 ip_address VARCHAR(45) NULL,
 user_agent VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(session_token_hash,institute_id),
 FOREIGN KEY(user_id,institute_id) REFERENCES auth_users(id,institute_id),
 CHECK(expires_at>issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_password_resets (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL,
 issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(token_hash,institute_id),
 FOREIGN KEY(user_id,institute_id) REFERENCES auth_users(id,institute_id),
 CHECK(expires_at>issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_audit_log (
 institute_id BIGINT UNSIGNED NOT NULL,
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(80) NOT NULL,
 event_data_json LONGTEXT NOT NULL CHECK(JSON_VALID(event_data_json)),
 ip_address VARCHAR(45) NULL,
 user_agent VARCHAR(500) NULL,
 occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(institute_id) REFERENCES institute(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_user_permissions (
 institute_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 permission_id BIGINT UNSIGNED NOT NULL,
 grant_type ENUM('grant','deny') NOT NULL,
 granted_by BIGINT UNSIGNED NULL,
 granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,permission_id,institute_id),
 FOREIGN KEY(user_id,institute_id) REFERENCES auth_users(id,institute_id),
 FOREIGN KEY(permission_id) REFERENCES auth_permissions(id),
 FOREIGN KEY(granted_by,institute_id) REFERENCES auth_users(id,institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- DATA-INTEGRITY GUARDS ----------
-- Official source checks occur at record creation/editing. Later source supersession
-- does not silently rewrite historical records; consumers must re-evaluate currency.
-- Run this file through the MySQL CLI; DELIMITER is a CLI directive.
DELIMITER $$

CREATE TRIGGER trg_trade_unit_shifts_insert_integrity
BEFORE INSERT ON trade_unit_shifts
FOR EACH ROW
BEGIN
 IF (NEW.affiliation_status='approved') AND NOT (EXISTS (SELECT 1 FROM global_shifts gs JOIN global_regulatory_documents d ON d.id=gs.source_document_id WHERE gs.id=NEW.global_shift_id AND gs.shift_no=NEW.shift_no AND gs.status IN ('active','superseded') AND d.status IN ('published','superseded')) AND EXISTS (SELECT 1 FROM regulatory_sources r JOIN global_regulatory_documents d ON d.id=r.global_document_id WHERE r.id=NEW.source_id AND r.institute_id=NEW.institute_id AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unreviewed or withdrawn regulatory source';
 END IF;
END$$

CREATE TRIGGER trg_trade_unit_shifts_update_integrity
BEFORE UPDATE ON trade_unit_shifts
FOR EACH ROW
BEGIN
 IF (NEW.affiliation_status='approved') AND NOT (EXISTS (SELECT 1 FROM global_shifts gs JOIN global_regulatory_documents d ON d.id=gs.source_document_id WHERE gs.id=NEW.global_shift_id AND gs.shift_no=NEW.shift_no AND gs.status IN ('active','superseded') AND d.status IN ('published','superseded')) AND EXISTS (SELECT 1 FROM regulatory_sources r JOIN global_regulatory_documents d ON d.id=r.global_document_id WHERE r.id=NEW.source_id AND r.institute_id=NEW.institute_id AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unreviewed or withdrawn regulatory source';
 END IF;
END$$

CREATE TRIGGER trg_admission_intakes_insert_integrity
BEFORE INSERT ON admission_intakes
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM cts_training_calendars c
  JOIN global_regulatory_documents d ON d.id=c.source_document_id
  WHERE c.id=NEW.calendar_id AND c.admission_year=NEW.admission_year
    AND c.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intake requires applicable official CTS calendar';
 END IF;
END$$

CREATE TRIGGER trg_admission_intakes_update_integrity
BEFORE UPDATE ON admission_intakes
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM cts_training_calendars c
  JOIN global_regulatory_documents d ON d.id=c.source_document_id
  WHERE c.id=NEW.calendar_id AND c.admission_year=NEW.admission_year
    AND c.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intake requires applicable official CTS calendar';
 END IF;
END$$

CREATE TRIGGER trg_seat_matrix_versions_insert_integrity
BEFORE INSERT ON seat_matrix_versions
FOR EACH ROW
BEGIN
 IF (NEW.status='final') AND NOT (EXISTS (SELECT 1 FROM regulatory_sources r JOIN global_regulatory_documents d ON d.id=r.global_document_id WHERE r.id=NEW.source_id AND r.institute_id=NEW.institute_id AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unreviewed or withdrawn regulatory source';
 END IF;
END$$

CREATE TRIGGER trg_seat_matrix_versions_update_integrity
BEFORE UPDATE ON seat_matrix_versions
FOR EACH ROW
BEGIN
 IF (NEW.status='final') AND NOT (EXISTS (SELECT 1 FROM regulatory_sources r JOIN global_regulatory_documents d ON d.id=r.global_document_id WHERE r.id=NEW.source_id AND r.institute_id=NEW.institute_id AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unreviewed or withdrawn regulatory source';
 END IF;
END$$

CREATE TRIGGER trg_admission_rules_insert_integrity
BEFORE INSERT ON admission_rules
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM global_admission_rule_versions p
  JOIN global_regulatory_documents d ON d.id=p.source_document_id
  JOIN global_regulatory_documents w ON w.id=p.admission_window_document_id
  LEFT JOIN global_regulatory_documents st ON st.id=p.state_source_document_id
  WHERE p.id=NEW.global_admission_rule_id
    AND p.trade_id=NEW.trade_id AND p.global_curriculum_id=NEW.global_curriculum_id
    AND p.status IN ('published','superseded')
    AND d.status IN ('published','superseded') AND w.status IN ('published','superseded')
    AND (p.state_source_document_id IS NULL OR st.status IN ('published','superseded'))
    AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL
    AND w.reviewed_at IS NOT NULL AND w.reviewed_by IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admission must reference official published policy';
 END IF;
END$$

CREATE TRIGGER trg_admission_rules_update_integrity
BEFORE UPDATE ON admission_rules
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM global_admission_rule_versions p
  JOIN global_regulatory_documents d ON d.id=p.source_document_id
  JOIN global_regulatory_documents w ON w.id=p.admission_window_document_id
  LEFT JOIN global_regulatory_documents st ON st.id=p.state_source_document_id
  WHERE p.id=NEW.global_admission_rule_id
    AND p.trade_id=NEW.trade_id AND p.global_curriculum_id=NEW.global_curriculum_id
    AND p.status IN ('published','superseded')
    AND d.status IN ('published','superseded') AND w.status IN ('published','superseded')
    AND (p.state_source_document_id IS NULL OR st.status IN ('published','superseded'))
    AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL
    AND w.reviewed_at IS NOT NULL AND w.reviewed_by IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admission must reference official published policy';
 END IF;
END$$

CREATE TRIGGER trg_reservation_policies_insert_integrity
BEFORE INSERT ON reservation_policies
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM global_reservation_policies p
  JOIN global_regulatory_documents d ON d.id=p.source_document_id
  WHERE p.id=NEW.global_reservation_policy_id AND p.admission_year=NEW.admission_year
    AND p.state_ut_code='BR' AND p.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reservation requires official Bihar policy';
 END IF;
END$$

CREATE TRIGGER trg_reservation_policies_update_integrity
BEFORE UPDATE ON reservation_policies
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM global_reservation_policies p
  JOIN global_regulatory_documents d ON d.id=p.source_document_id
  WHERE p.id=NEW.global_reservation_policy_id AND p.admission_year=NEW.admission_year
    AND p.state_ut_code='BR' AND p.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reservation requires official Bihar policy';
 END IF;
END$$

CREATE TRIGGER trg_exam_rule_versions_insert_integrity
BEFORE INSERT ON exam_rule_versions
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM global_exam_rule_versions g
  JOIN global_regulatory_documents d ON d.id=g.source_document_id
  LEFT JOIN global_regulatory_documents rd ON rd.id=g.relaxation_source_document_id
  WHERE g.id=NEW.global_exam_rule_id AND g.cycle_type=NEW.cycle_type
    AND g.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
    AND (g.relaxation_source_document_id IS NULL OR (rd.status IN ('published','superseded') AND rd.reviewed_at IS NOT NULL))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Exam rule requires reviewed official policy';
 END IF;
END$$

CREATE TRIGGER trg_exam_rule_versions_update_integrity
BEFORE UPDATE ON exam_rule_versions
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM global_exam_rule_versions g
  JOIN global_regulatory_documents d ON d.id=g.source_document_id
  LEFT JOIN global_regulatory_documents rd ON rd.id=g.relaxation_source_document_id
  WHERE g.id=NEW.global_exam_rule_id AND g.cycle_type=NEW.cycle_type
    AND g.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
    AND (g.relaxation_source_document_id IS NULL OR (rd.status IN ('published','superseded') AND rd.reviewed_at IS NOT NULL))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Exam rule requires reviewed official policy';
 END IF;
END$$

CREATE TRIGGER trg_training_calendar_entries_insert_integrity
BEFORE INSERT ON training_calendar_entries
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM cts_training_calendars c
  JOIN global_regulatory_documents d ON d.id=c.source_document_id
  WHERE c.id=NEW.calendar_id AND c.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar schedule requires reviewed CTS source';
 END IF;
END$$

CREATE TRIGGER trg_training_calendar_entries_update_integrity
BEFORE UPDATE ON training_calendar_entries
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM cts_training_calendars c
  JOIN global_regulatory_documents d ON d.id=c.source_document_id
  WHERE c.id=NEW.calendar_id AND c.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Calendar schedule requires reviewed CTS source';
 END IF;
END$$

CREATE TRIGGER trg_batches_insert_integrity
BEFORE INSERT ON batches
FOR EACH ROW
BEGIN
 IF NOT EXISTS (SELECT 1
  FROM seat_matrix_entries e
  JOIN seat_matrix_versions v ON v.id=e.version_id AND v.intake_id=e.intake_id AND v.institute_id=e.institute_id
  JOIN regulatory_sources r ON r.id=v.source_id AND r.institute_id=v.institute_id
  JOIN global_regulatory_documents d ON d.id=r.global_document_id
  JOIN trade_unit_shifts u ON u.id=NEW.trade_unit_shift_id AND u.institute_id=NEW.institute_id
  JOIN admission_intakes ai ON ai.id=NEW.intake_id AND ai.institute_id=NEW.institute_id
  JOIN curriculum_versions ic ON ic.id=NEW.curriculum_id AND ic.institute_id=NEW.institute_id
  JOIN global_curriculum_versions cur ON cur.id=ic.global_curriculum_id
  WHERE e.id=NEW.seat_matrix_entry_id
    AND e.trade_unit_shift_id=NEW.trade_unit_shift_id
    AND e.intake_id=NEW.intake_id
    AND e.institute_id=NEW.institute_id
    AND e.admission_allowed=1
    AND e.seat_capacity>=NEW.capacity
    AND u.sanctioned_capacity>=NEW.capacity
    AND u.affiliation_status='approved'
    AND u.status='active'
    AND u.valid_from<=NEW.starts_on
    AND (u.valid_to IS NULL OR u.valid_to>=NEW.starts_on)
    AND u.training_mode=NEW.training_mode
    AND (ai.session_part='primary' OR cur.duration_months=6)
    AND EXISTS (
      SELECT 1 FROM admission_intake_revisions ir
      JOIN regulatory_sources rs ON rs.id=ir.source_id AND rs.institute_id=ir.institute_id
      JOIN global_regulatory_documents rd ON rd.id=rs.global_document_id
      WHERE ir.intake_id=NEW.intake_id AND ir.institute_id=NEW.institute_id
        AND ir.status IN ('published','superseded')
        AND rd.status IN ('published','superseded') AND rd.reviewed_at IS NOT NULL
    )
    AND v.status='final'
    AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Batch requires final permitted seat matrix';
 END IF;
END$$

CREATE TRIGGER trg_batches_update_integrity
BEFORE UPDATE ON batches
FOR EACH ROW
BEGIN
 IF (NEW.seat_matrix_entry_id<>OLD.seat_matrix_entry_id OR NEW.intake_id<>OLD.intake_id OR NEW.trade_unit_shift_id<>OLD.trade_unit_shift_id OR NEW.capacity<>OLD.capacity OR NEW.institute_id<>OLD.institute_id OR NEW.starts_on<>OLD.starts_on OR NEW.training_mode<>OLD.training_mode OR NEW.curriculum_id<>OLD.curriculum_id OR NEW.calendar_id<>OLD.calendar_id) AND NOT EXISTS (SELECT 1
  FROM seat_matrix_entries e
  JOIN seat_matrix_versions v ON v.id=e.version_id AND v.intake_id=e.intake_id AND v.institute_id=e.institute_id
  JOIN regulatory_sources r ON r.id=v.source_id AND r.institute_id=v.institute_id
  JOIN global_regulatory_documents d ON d.id=r.global_document_id
  JOIN trade_unit_shifts u ON u.id=NEW.trade_unit_shift_id AND u.institute_id=NEW.institute_id
  JOIN admission_intakes ai ON ai.id=NEW.intake_id AND ai.institute_id=NEW.institute_id
  JOIN curriculum_versions ic ON ic.id=NEW.curriculum_id AND ic.institute_id=NEW.institute_id
  JOIN global_curriculum_versions cur ON cur.id=ic.global_curriculum_id
  WHERE e.id=NEW.seat_matrix_entry_id
    AND e.trade_unit_shift_id=NEW.trade_unit_shift_id
    AND e.intake_id=NEW.intake_id
    AND e.institute_id=NEW.institute_id
    AND e.admission_allowed=1
    AND e.seat_capacity>=NEW.capacity
    AND u.sanctioned_capacity>=NEW.capacity
    AND u.affiliation_status='approved'
    AND u.status='active'
    AND u.valid_from<=NEW.starts_on
    AND (u.valid_to IS NULL OR u.valid_to>=NEW.starts_on)
    AND u.training_mode=NEW.training_mode
    AND (ai.session_part='primary' OR cur.duration_months=6)
    AND EXISTS (
      SELECT 1 FROM admission_intake_revisions ir
      JOIN regulatory_sources rs ON rs.id=ir.source_id AND rs.institute_id=ir.institute_id
      JOIN global_regulatory_documents rd ON rd.id=rs.global_document_id
      WHERE ir.intake_id=NEW.intake_id AND ir.institute_id=NEW.institute_id
        AND ir.status IN ('published','superseded')
        AND rd.status IN ('published','superseded') AND rd.reviewed_at IS NOT NULL
    )
    AND v.status='final'
    AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Batch change requires final permitted seat matrix';
 END IF;
END$$

-- Affiliation effective date is inclusive: adjacent revisions start the following day.
CREATE TRIGGER trg_trade_unit_shifts_insert_period
BEFORE INSERT ON trade_unit_shifts
FOR EACH ROW
BEGIN
 DECLARE v_parent BIGINT UNSIGNED;
 SELECT id INTO v_parent FROM trade_units
  WHERE id=NEW.trade_unit_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF EXISTS (
  SELECT 1 FROM trade_unit_shifts s
  WHERE s.institute_id=NEW.institute_id
    AND s.trade_unit_id=NEW.trade_unit_id
    AND s.shift_no=NEW.shift_no
    AND s.valid_from <= COALESCE(NEW.valid_to, CAST('9999-12-31' AS DATE))
    AND COALESCE(s.valid_to, CAST('9999-12-31' AS DATE)) >= NEW.valid_from
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Overlapping ITSU affiliation dates';
 END IF;
END$$

CREATE TRIGGER trg_trade_unit_shifts_update_period
BEFORE UPDATE ON trade_unit_shifts
FOR EACH ROW
BEGIN
 DECLARE v_parent BIGINT UNSIGNED;
 SELECT id INTO v_parent FROM trade_units
  WHERE id=NEW.trade_unit_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF EXISTS (
  SELECT 1 FROM trade_unit_shifts s
  WHERE s.id<>OLD.id
    AND s.institute_id=NEW.institute_id
    AND s.trade_unit_id=NEW.trade_unit_id
    AND s.shift_no=NEW.shift_no
    AND s.valid_from <= COALESCE(NEW.valid_to, CAST('9999-12-31' AS DATE))
    AND COALESCE(s.valid_to, CAST('9999-12-31' AS DATE)) >= NEW.valid_from
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Overlapping ITSU affiliation dates';
 END IF;
END$$

-- Role assignment timestamps follow [valid_from, valid_to); adjacent ranges are allowed.
CREATE TRIGGER trg_auth_user_roles_insert_period
BEFORE INSERT ON auth_user_roles
FOR EACH ROW
BEGIN
 DECLARE v_parent BIGINT UNSIGNED;
 SELECT id INTO v_parent FROM auth_users
  WHERE id=NEW.user_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF EXISTS (
  SELECT 1 FROM auth_user_roles r
  WHERE r.user_id=NEW.user_id AND r.role_id=NEW.role_id
    AND r.institute_id=NEW.institute_id
    AND r.valid_from < COALESCE(NEW.valid_to, CAST('9999-12-31 23:59:59' AS DATETIME))
    AND COALESCE(r.valid_to, CAST('9999-12-31 23:59:59' AS DATETIME)) > NEW.valid_from
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Overlapping user role assignments';
 END IF;
END$$

CREATE TRIGGER trg_auth_user_roles_update_period
BEFORE UPDATE ON auth_user_roles
FOR EACH ROW
BEGIN
 DECLARE v_parent BIGINT UNSIGNED;
 SELECT id INTO v_parent FROM auth_users
  WHERE id=NEW.user_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF EXISTS (
  SELECT 1 FROM auth_user_roles r
  WHERE r.id<>OLD.id AND r.user_id=NEW.user_id AND r.role_id=NEW.role_id
    AND r.institute_id=NEW.institute_id
    AND r.valid_from < COALESCE(NEW.valid_to, CAST('9999-12-31 23:59:59' AS DATETIME))
    AND COALESCE(r.valid_to, CAST('9999-12-31 23:59:59' AS DATETIME)) > NEW.valid_from
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Overlapping user role assignments';
 END IF;
END$$

CREATE TRIGGER trg_regulatory_sources_insert_master
BEFORE INSERT ON regulatory_sources
FOR EACH ROW
BEGIN
 IF NOT EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.global_document_id AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute source requires reviewed official document';
 END IF;
END$$

CREATE TRIGGER trg_regulatory_sources_update_master
BEFORE UPDATE ON regulatory_sources
FOR EACH ROW
BEGIN
 IF NOT EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.global_document_id AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute source requires reviewed official document';
 END IF;
END$$

CREATE TRIGGER trg_global_curriculum_versions_insert_master
BEFORE INSERT ON global_curriculum_versions
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CTS curriculum requires official document';
 END IF;
END$$

CREATE TRIGGER trg_global_curriculum_versions_update_master
BEFORE UPDATE ON global_curriculum_versions
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CTS curriculum requires official document';
 END IF;
END$$

CREATE TRIGGER trg_cts_training_calendars_insert_master
BEFORE INSERT ON cts_training_calendars
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CTS calendar requires official document';
 END IF;
END$$

CREATE TRIGGER trg_cts_training_calendars_update_master
BEFORE UPDATE ON cts_training_calendars
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CTS calendar requires official document';
 END IF;
END$$

CREATE TRIGGER trg_global_shifts_insert_master
BEFORE INSERT ON global_shifts
FOR EACH ROW
BEGIN
 IF NEW.status='active' AND NOT EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Global shift requires official document';
 END IF;
END$$

CREATE TRIGGER trg_global_shifts_update_master
BEFORE UPDATE ON global_shifts
FOR EACH ROW
BEGIN
 IF NEW.status='active' AND NOT EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Global shift requires official document';
 END IF;
END$$

CREATE TRIGGER trg_curriculum_versions_insert_master
BEFORE INSERT ON curriculum_versions
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM global_curriculum_versions c
  JOIN global_regulatory_documents d ON d.id=c.source_document_id
  WHERE c.id=NEW.global_curriculum_id AND c.trade_id=NEW.trade_id
    AND c.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute curriculum requires official CTS master';
 END IF;
END$$

CREATE TRIGGER trg_curriculum_versions_update_master
BEFORE UPDATE ON curriculum_versions
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM global_curriculum_versions c
  JOIN global_regulatory_documents d ON d.id=c.source_document_id
  WHERE c.id=NEW.global_curriculum_id AND c.trade_id=NEW.trade_id
    AND c.status IN ('published','superseded')
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Institute curriculum requires official CTS master';
 END IF;
END$$

CREATE TRIGGER trg_batch_years_insert_master
BEFORE INSERT ON batch_years
FOR EACH ROW
BEGIN
 IF NEW.calendar_status='reviewed' AND NOT EXISTS (SELECT 1 FROM cts_training_calendars c WHERE c.id=NEW.calendar_id AND c.status IN ('published','superseded')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed batch year requires official calendar';
 END IF;
END$$

CREATE TRIGGER trg_batch_years_update_master
BEFORE UPDATE ON batch_years
FOR EACH ROW
BEGIN
 IF NEW.calendar_status='reviewed' AND NOT EXISTS (SELECT 1 FROM cts_training_calendars c WHERE c.id=NEW.calendar_id AND c.status IN ('published','superseded')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed batch year requires official calendar';
 END IF;
END$$

CREATE TRIGGER trg_global_admission_rule_versions_insert_source
BEFORE INSERT ON global_admission_rule_versions
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT (
    EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL)
    AND EXISTS (SELECT 1 FROM global_regulatory_documents w WHERE w.id=NEW.admission_window_document_id AND w.status IN ('published','superseded') AND w.reviewed_at IS NOT NULL AND w.reviewed_by IS NOT NULL)
    AND (NEW.state_source_document_id IS NULL OR EXISTS (SELECT 1 FROM global_regulatory_documents ext WHERE ext.id=NEW.state_source_document_id AND ext.status IN ('published','superseded') AND ext.reviewed_at IS NOT NULL AND ext.reviewed_by IS NOT NULL))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy needs reviewed official document';
 END IF;
END$$

CREATE TRIGGER trg_global_admission_rule_versions_update_source
BEFORE UPDATE ON global_admission_rule_versions
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT (
    EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL)
    AND EXISTS (SELECT 1 FROM global_regulatory_documents w WHERE w.id=NEW.admission_window_document_id AND w.status IN ('published','superseded') AND w.reviewed_at IS NOT NULL AND w.reviewed_by IS NOT NULL)
    AND (NEW.state_source_document_id IS NULL OR EXISTS (SELECT 1 FROM global_regulatory_documents ext WHERE ext.id=NEW.state_source_document_id AND ext.status IN ('published','superseded') AND ext.reviewed_at IS NOT NULL AND ext.reviewed_by IS NOT NULL))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy needs reviewed official document';
 END IF;
END$$

CREATE TRIGGER trg_global_reservation_policies_insert_source
BEFORE INSERT ON global_reservation_policies
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT (
    EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL)
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy needs reviewed official document';
 END IF;
END$$

CREATE TRIGGER trg_global_reservation_policies_update_source
BEFORE UPDATE ON global_reservation_policies
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT (
    EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL)
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy needs reviewed official document';
 END IF;
END$$

CREATE TRIGGER trg_global_exam_rule_versions_insert_source
BEFORE INSERT ON global_exam_rule_versions
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT (
    EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL)
    AND (NEW.relaxation_source_document_id IS NULL OR EXISTS (SELECT 1 FROM global_regulatory_documents ext WHERE ext.id=NEW.relaxation_source_document_id AND ext.status IN ('published','superseded') AND ext.reviewed_at IS NOT NULL AND ext.reviewed_by IS NOT NULL))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy needs reviewed official document';
 END IF;
END$$

CREATE TRIGGER trg_global_exam_rule_versions_update_source
BEFORE UPDATE ON global_exam_rule_versions
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT (
    EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL)
    AND (NEW.relaxation_source_document_id IS NULL OR EXISTS (SELECT 1 FROM global_regulatory_documents ext WHERE ext.id=NEW.relaxation_source_document_id AND ext.status IN ('published','superseded') AND ext.reviewed_at IS NOT NULL AND ext.reviewed_by IS NOT NULL))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy needs reviewed official document';
 END IF;
END$$

CREATE TRIGGER trg_admission_intake_revisions_insert_source
BEFORE INSERT ON admission_intake_revisions
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS (
  SELECT 1 FROM regulatory_sources r
  JOIN global_regulatory_documents d ON d.id=r.global_document_id
  WHERE r.id=NEW.source_id AND r.institute_id=NEW.institute_id
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admission revision requires reviewed official order';
 END IF;
END$$

CREATE TRIGGER trg_admission_intake_revisions_update_source
BEFORE UPDATE ON admission_intake_revisions
FOR EACH ROW
BEGIN
 IF NEW.status='published' AND NOT EXISTS (
  SELECT 1 FROM regulatory_sources r
  JOIN global_regulatory_documents d ON d.id=r.global_document_id
  WHERE r.id=NEW.source_id AND r.institute_id=NEW.institute_id
    AND d.status IN ('published','superseded')
    AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admission revision requires reviewed official order';
 END IF;
END$$

-- CTS 2026 Annexure II: 6-month Session II uses portal 6,7,8 for affiliated shifts 1,2,3.
CREATE TRIGGER trg_seat_matrix_entries_insert_portal_shift
BEFORE INSERT ON seat_matrix_entries
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM trade_unit_shifts u
  JOIN admission_intakes ai ON ai.id=NEW.intake_id AND ai.institute_id=NEW.institute_id
  JOIN cts_portal_shift_mappings m
    ON m.calendar_id=ai.calendar_id AND m.session_part=ai.session_part
    AND m.physical_shift_no=u.shift_no AND m.portal_shift_no=NEW.portal_shift_no
  WHERE u.id=NEW.trade_unit_shift_id AND u.institute_id=NEW.institute_id
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal shift not listed for official CTS intake calendar';
 END IF;
END$$

-- CTS 2026 Annexure II: 6-month Session II uses portal 6,7,8 for affiliated shifts 1,2,3.
CREATE TRIGGER trg_seat_matrix_entries_update_portal_shift
BEFORE UPDATE ON seat_matrix_entries
FOR EACH ROW
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM trade_unit_shifts u
  JOIN admission_intakes ai ON ai.id=NEW.intake_id AND ai.institute_id=NEW.institute_id
  JOIN cts_portal_shift_mappings m
    ON m.calendar_id=ai.calendar_id AND m.session_part=ai.session_part
    AND m.physical_shift_no=u.shift_no AND m.portal_shift_no=NEW.portal_shift_no
  WHERE u.id=NEW.trade_unit_shift_id AND u.institute_id=NEW.institute_id
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Portal shift not listed for official CTS intake calendar';
 END IF;
END$$

-- Previously published official rows preserve all factual fields; status may move only forward.
CREATE TRIGGER trg_global_regulatory_documents_freeze_update
BEFORE UPDATE ON global_regulatory_documents
FOR EACH ROW
BEGIN
 IF OLD.status='published' AND NEW.status NOT IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official record cannot return to draft';
 END IF;
 IF OLD.status IN ('published','superseded','withdrawn') AND (
  NOT (OLD.authority <=> NEW.authority)
  OR NOT (OLD.state_ut_code <=> NEW.state_ut_code)
  OR NOT (OLD.document_type <=> NEW.document_type)
  OR NOT (OLD.title <=> NEW.title)
  OR NOT (OLD.reference_no <=> NEW.reference_no)
  OR NOT (OLD.issued_on <=> NEW.issued_on)
  OR NOT (OLD.source_url <=> NEW.source_url)
  OR NOT (OLD.evidence_reference <=> NEW.evidence_reference)
  OR NOT (OLD.sha256 <=> NEW.sha256)
  OR NOT (OLD.supersedes_id <=> NEW.supersedes_id)
  OR NOT (OLD.reviewed_by <=> NEW.reviewed_by)
  OR NOT (OLD.reviewed_at <=> NEW.reviewed_at)
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official historic record must not be rewritten';
 END IF;
 IF OLD.status IN ('superseded','withdrawn','retired') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official retired revision cannot be reactivated';
 END IF;
END$$

CREATE TRIGGER trg_global_regulatory_documents_freeze_delete
BEFORE DELETE ON global_regulatory_documents
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official revision cannot be deleted';
 END IF;
END$$

-- Previously published official rows preserve all factual fields; status may move only forward.
CREATE TRIGGER trg_global_curriculum_versions_freeze_update
BEFORE UPDATE ON global_curriculum_versions
FOR EACH ROW
BEGIN
 IF OLD.status='published' AND NEW.status NOT IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official record cannot return to draft';
 END IF;
 IF OLD.status IN ('published','superseded','withdrawn') AND (
  NOT (OLD.trade_id <=> NEW.trade_id)
  OR NOT (OLD.source_document_id <=> NEW.source_document_id)
  OR NOT (OLD.version_code <=> NEW.version_code)
  OR NOT (OLD.duration_months <=> NEW.duration_months)
  OR NOT (OLD.nsqf_level <=> NEW.nsqf_level)
  OR NOT (OLD.unit_strength <=> NEW.unit_strength)
  OR NOT (OLD.effective_from <=> NEW.effective_from)
  OR (NOT (OLD.effective_to <=> NEW.effective_to) AND NOT (OLD.effective_to IS NULL AND NEW.effective_to IS NOT NULL AND NEW.status IN ('superseded','withdrawn')))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official historic record must not be rewritten';
 END IF;
 IF OLD.status IN ('superseded','withdrawn','retired') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official retired revision cannot be reactivated';
 END IF;
END$$

CREATE TRIGGER trg_global_curriculum_versions_freeze_delete
BEFORE DELETE ON global_curriculum_versions
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official revision cannot be deleted';
 END IF;
END$$

-- Previously published official rows preserve all factual fields; status may move only forward.
CREATE TRIGGER trg_cts_training_calendars_freeze_update
BEFORE UPDATE ON cts_training_calendars
FOR EACH ROW
BEGIN
 IF OLD.status='published' AND NEW.status NOT IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official record cannot return to draft';
 END IF;
 IF OLD.status IN ('published','superseded','withdrawn') AND (
  NOT (OLD.admission_year <=> NEW.admission_year)
  OR NOT (OLD.session_code <=> NEW.session_code)
  OR NOT (OLD.revision_code <=> NEW.revision_code)
  OR NOT (OLD.source_document_id <=> NEW.source_document_id)
  OR NOT (OLD.training_starts_on <=> NEW.training_starts_on)
  OR NOT (OLD.training_ends_on <=> NEW.training_ends_on)
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official historic record must not be rewritten';
 END IF;
 IF OLD.status IN ('superseded','withdrawn','retired') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official retired revision cannot be reactivated';
 END IF;
END$$

CREATE TRIGGER trg_cts_training_calendars_freeze_delete
BEFORE DELETE ON cts_training_calendars
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official revision cannot be deleted';
 END IF;
END$$

-- Previously published official rows preserve all factual fields; status may move only forward.
CREATE TRIGGER trg_trade_master_revisions_freeze_update
BEFORE UPDATE ON trade_master_revisions
FOR EACH ROW
BEGIN
 IF OLD.status='active' AND NEW.status NOT IN ('active','superseded','retired') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official record cannot return to draft';
 END IF;
 IF OLD.status IN ('active','superseded','retired') AND (
  NOT (OLD.trade_id <=> NEW.trade_id)
  OR NOT (OLD.source_document_id <=> NEW.source_document_id)
  OR NOT (OLD.version_code <=> NEW.version_code)
  OR NOT (OLD.official_name <=> NEW.official_name)
  OR NOT (OLD.trade_type <=> NEW.trade_type)
  OR NOT (OLD.effective_from <=> NEW.effective_from)
  OR (NOT (OLD.effective_to <=> NEW.effective_to) AND NOT (OLD.effective_to IS NULL AND NEW.effective_to IS NOT NULL AND NEW.status IN ('superseded','retired')))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official historic record must not be rewritten';
 END IF;
 IF OLD.status IN ('superseded','withdrawn','retired') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official retired revision cannot be reactivated';
 END IF;
END$$

CREATE TRIGGER trg_trade_master_revisions_freeze_delete
BEFORE DELETE ON trade_master_revisions
FOR EACH ROW
BEGIN
 IF OLD.status IN ('active','superseded','retired') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official revision cannot be deleted';
 END IF;
END$$

-- Previously published official rows preserve all factual fields; status may move only forward.
CREATE TRIGGER trg_global_shifts_freeze_update
BEFORE UPDATE ON global_shifts
FOR EACH ROW
BEGIN
 IF OLD.status='active' AND NEW.status NOT IN ('active','superseded','retired') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official record cannot return to draft';
 END IF;
 IF OLD.status IN ('active','superseded','retired') AND (
  NOT (OLD.shift_no <=> NEW.shift_no)
  OR NOT (OLD.shift_code <=> NEW.shift_code)
  OR NOT (OLD.display_name <=> NEW.display_name)
  OR NOT (OLD.revision_code <=> NEW.revision_code)
  OR NOT (OLD.source_document_id <=> NEW.source_document_id)
  OR NOT (OLD.effective_from <=> NEW.effective_from)
  OR (NOT (OLD.effective_to <=> NEW.effective_to) AND NOT (OLD.effective_to IS NULL AND NEW.effective_to IS NOT NULL AND NEW.status IN ('superseded','retired')))
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official historic record must not be rewritten';
 END IF;
 IF OLD.status IN ('superseded','withdrawn','retired') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official retired revision cannot be reactivated';
 END IF;
END$$

CREATE TRIGGER trg_global_shifts_freeze_delete
BEFORE DELETE ON global_shifts
FOR EACH ROW
BEGIN
 IF OLD.status IN ('active','superseded','retired') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official revision cannot be deleted';
 END IF;
END$$

-- Previously published official rows preserve all factual fields; status may move only forward.
CREATE TRIGGER trg_admission_intake_revisions_freeze_update
BEFORE UPDATE ON admission_intake_revisions
FOR EACH ROW
BEGIN
 IF OLD.status='published' AND NEW.status NOT IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official record cannot return to draft';
 END IF;
 IF OLD.status IN ('published','superseded','withdrawn') AND (
  NOT (OLD.institute_id <=> NEW.institute_id)
  OR NOT (OLD.intake_id <=> NEW.intake_id)
  OR NOT (OLD.source_id <=> NEW.source_id)
  OR NOT (OLD.revision_code <=> NEW.revision_code)
  OR NOT (OLD.opens_on <=> NEW.opens_on)
  OR NOT (OLD.closes_on <=> NEW.closes_on)
  OR NOT (OLD.approval_reference <=> NEW.approval_reference)
  OR NOT (OLD.published_on <=> NEW.published_on)
  OR NOT (OLD.supersedes_id <=> NEW.supersedes_id)
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official historic record must not be rewritten';
 END IF;
 IF OLD.status IN ('superseded','withdrawn','retired') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official retired revision cannot be reactivated';
 END IF;
END$$

CREATE TRIGGER trg_admission_intake_revisions_freeze_delete
BEFORE DELETE ON admission_intake_revisions
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official revision cannot be deleted';
 END IF;
END$$

-- Previously published official rows preserve all factual fields; status may move only forward.
CREATE TRIGGER trg_seat_matrix_versions_freeze_update
BEFORE UPDATE ON seat_matrix_versions
FOR EACH ROW
BEGIN
 IF OLD.status='final' AND NEW.status NOT IN ('final','superseded') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official record cannot return to draft';
 END IF;
 IF OLD.status IN ('final','superseded') AND (
  NOT (OLD.institute_id <=> NEW.institute_id)
  OR NOT (OLD.admission_year <=> NEW.admission_year)
  OR NOT (OLD.intake_id <=> NEW.intake_id)
  OR NOT (OLD.version_code <=> NEW.version_code)
  OR NOT (OLD.source_id <=> NEW.source_id)
  OR NOT (OLD.portal_reference <=> NEW.portal_reference)
  OR NOT (OLD.dgt_circular_no <=> NEW.dgt_circular_no)
  OR NOT (OLD.published_on <=> NEW.published_on)
  OR NOT (OLD.supersedes_id <=> NEW.supersedes_id)
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official historic record must not be rewritten';
 END IF;
 IF OLD.status IN ('superseded','withdrawn','retired') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official retired revision cannot be reactivated';
 END IF;
END$$

CREATE TRIGGER trg_seat_matrix_versions_freeze_delete
BEFORE DELETE ON seat_matrix_versions
FOR EACH ROW
BEGIN
 IF OLD.status IN ('final','superseded') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official revision cannot be deleted';
 END IF;
END$$

CREATE TRIGGER trg_global_curriculum_years_freeze_update
BEFORE UPDATE ON global_curriculum_years
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM global_curriculum_versions c WHERE c.id=OLD.global_curriculum_id AND c.status IN ('published','superseded','withdrawn')) AND (NOT (OLD.global_curriculum_id <=> NEW.global_curriculum_id) OR NOT (OLD.year_no <=> NEW.year_no) OR NOT (OLD.trade_practical_minutes <=> NEW.trade_practical_minutes) OR NOT (OLD.trade_theory_minutes <=> NEW.trade_theory_minutes) OR NOT (OLD.employability_minutes <=> NEW.employability_minutes) OR NOT (OLD.ojt_project_minutes <=> NEW.ojt_project_minutes) OR NOT (OLD.credits <=> NEW.credits)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published syllabus detail cannot be changed';
 END IF;
END$$

CREATE TRIGGER trg_global_curriculum_years_freeze_delete
BEFORE DELETE ON global_curriculum_years
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM global_curriculum_versions c WHERE c.id=OLD.global_curriculum_id AND c.status IN ('published','superseded','withdrawn')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published syllabus detail cannot be deleted';
 END IF;
END$$

CREATE TRIGGER trg_global_learning_outcomes_freeze_update
BEFORE UPDATE ON global_learning_outcomes
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM global_curriculum_years cy JOIN global_curriculum_versions c ON c.id=cy.global_curriculum_id WHERE cy.id=OLD.global_curriculum_year_id AND c.status IN ('published','superseded','withdrawn')) AND (NOT (OLD.global_curriculum_year_id <=> NEW.global_curriculum_year_id) OR NOT (OLD.lo_code <=> NEW.lo_code) OR NOT (OLD.nos_code <=> NEW.nos_code) OR NOT (OLD.outcome_text <=> NEW.outcome_text) OR NOT (OLD.assessment_criteria <=> NEW.assessment_criteria) OR NOT (OLD.formative_max_marks <=> NEW.formative_max_marks)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published syllabus detail cannot be changed';
 END IF;
END$$

CREATE TRIGGER trg_global_learning_outcomes_freeze_delete
BEFORE DELETE ON global_learning_outcomes
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM global_curriculum_years cy JOIN global_curriculum_versions c ON c.id=cy.global_curriculum_id WHERE cy.id=OLD.global_curriculum_year_id AND c.status IN ('published','superseded','withdrawn')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published syllabus detail cannot be deleted';
 END IF;
END$$

CREATE TRIGGER trg_global_resource_requirements_freeze_update
BEFORE UPDATE ON global_resource_requirements
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM global_curriculum_versions c WHERE c.id=OLD.global_curriculum_id AND c.status IN ('published','superseded','withdrawn')) AND (NOT (OLD.global_curriculum_id <=> NEW.global_curriculum_id) OR NOT (OLD.resource_code <=> NEW.resource_code) OR NOT (OLD.resource_name <=> NEW.resource_name) OR NOT (OLD.specification <=> NEW.specification) OR NOT (OLD.resource_type <=> NEW.resource_type) OR NOT (OLD.base_quantity <=> NEW.base_quantity) OR NOT (OLD.additional_unit_quantity <=> NEW.additional_unit_quantity) OR NOT (OLD.measurement_unit <=> NEW.measurement_unit)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published syllabus detail cannot be changed';
 END IF;
END$$

CREATE TRIGGER trg_global_resource_requirements_freeze_delete
BEFORE DELETE ON global_resource_requirements
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM global_curriculum_versions c WHERE c.id=OLD.global_curriculum_id AND c.status IN ('published','superseded','withdrawn')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published syllabus detail cannot be deleted';
 END IF;
END$$

CREATE TRIGGER trg_institute_affiliation_history_insert_period
BEFORE INSERT ON institute_affiliation_history
FOR EACH ROW
BEGIN
 DECLARE v_parent BIGINT UNSIGNED;
 SELECT id INTO v_parent FROM institute WHERE id=NEW.institute_id FOR UPDATE;
 IF NOT EXISTS (SELECT 1 FROM regulatory_sources r
  JOIN global_regulatory_documents d ON d.id=r.global_document_id
  WHERE r.id=NEW.source_id AND r.institute_id=NEW.institute_id
    AND d.status IN ('published','superseded') AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Affiliation change needs reviewed official order';
 END IF;
 IF EXISTS (SELECT 1 FROM institute_affiliation_history h
  WHERE h.institute_id=NEW.institute_id
    
    AND h.effective_from<=COALESCE(NEW.effective_to,CAST('9999-12-31' AS DATE))
    AND COALESCE(h.effective_to,CAST('9999-12-31' AS DATE))>=NEW.effective_from) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Overlapping institute affiliation periods';
 END IF;
 
END$$

CREATE TRIGGER trg_institute_affiliation_history_update_period
BEFORE UPDATE ON institute_affiliation_history
FOR EACH ROW
BEGIN
 DECLARE v_parent BIGINT UNSIGNED;
 SELECT id INTO v_parent FROM institute WHERE id=NEW.institute_id FOR UPDATE;
 IF EXISTS (SELECT 1 FROM institute_affiliation_history h
  WHERE h.institute_id=NEW.institute_id
    AND h.id<>OLD.id
    AND h.effective_from<=COALESCE(NEW.effective_to,CAST('9999-12-31' AS DATE))
    AND COALESCE(h.effective_to,CAST('9999-12-31' AS DATE))>=NEW.effective_from) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Overlapping institute affiliation periods';
 END IF;
 IF NOT (OLD.institute_id <=> NEW.institute_id)
   OR NOT (OLD.source_id <=> NEW.source_id)
   OR NOT (OLD.change_type <=> NEW.change_type)
   OR NOT (OLD.approved_name <=> NEW.approved_name)
   OR NOT (OLD.approved_address <=> NEW.approved_address)
   OR NOT (OLD.affiliation_status <=> NEW.affiliation_status)
   OR NOT (OLD.affiliation_order_reference <=> NEW.affiliation_order_reference)
   OR NOT (OLD.effective_from <=> NEW.effective_from)
   OR (OLD.effective_to IS NOT NULL AND NOT (OLD.effective_to <=> NEW.effective_to))
   OR (OLD.effective_to IS NULL AND NEW.effective_to IS NOT NULL AND NEW.effective_to<OLD.effective_from)
 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Affiliation evidence is historical; close old period only';
 END IF;
END$$

CREATE TRIGGER trg_trade_master_revisions_insert_period
BEFORE INSERT ON trade_master_revisions
FOR EACH ROW
BEGIN
 DECLARE v_trade BIGINT UNSIGNED;
 SELECT id INTO v_trade FROM trades WHERE id=NEW.trade_id FOR UPDATE;
 IF NEW.status='active' AND NOT EXISTS (
  SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id
   AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trade version needs official published master source';
 END IF;
 IF NEW.status<>'draft' AND EXISTS (
  SELECT 1 FROM trade_master_revisions v WHERE v.trade_id=NEW.trade_id
   
   AND v.status<>'draft'
   AND v.effective_from<=COALESCE(NEW.effective_to,CAST('9999-12-31' AS DATE))
   AND COALESCE(v.effective_to,CAST('9999-12-31' AS DATE))>=NEW.effective_from
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Overlapping official Trade Master effective dates';
 END IF;
END$$

CREATE TRIGGER trg_trade_master_revisions_update_period
BEFORE UPDATE ON trade_master_revisions
FOR EACH ROW
BEGIN
 DECLARE v_trade BIGINT UNSIGNED;
 SELECT id INTO v_trade FROM trades WHERE id=NEW.trade_id FOR UPDATE;
 IF NEW.status='active' AND NOT EXISTS (
  SELECT 1 FROM global_regulatory_documents d WHERE d.id=NEW.source_document_id
   AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL AND d.reviewed_by IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trade version needs official published master source';
 END IF;
 IF NEW.status<>'draft' AND EXISTS (
  SELECT 1 FROM trade_master_revisions v WHERE v.trade_id=NEW.trade_id
   AND v.id<>OLD.id
   AND v.status<>'draft'
   AND v.effective_from<=COALESCE(NEW.effective_to,CAST('9999-12-31' AS DATE))
   AND COALESCE(v.effective_to,CAST('9999-12-31' AS DATE))>=NEW.effective_from
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Overlapping official Trade Master effective dates';
 END IF;
END$$

CREATE TRIGGER trg_seat_matrix_entries_update_final
BEFORE UPDATE ON seat_matrix_entries
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM seat_matrix_versions v
  WHERE v.id=OLD.version_id AND v.institute_id=OLD.institute_id AND v.status IN ('final','superseded'))
 OR EXISTS (SELECT 1 FROM seat_matrix_versions v
  WHERE v.id=NEW.version_id AND v.institute_id=NEW.institute_id AND v.status IN ('final','superseded')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final Seat Matrix rows cannot be rewritten; create new version';
 END IF;
END$$

CREATE TRIGGER trg_seat_matrix_entries_delete_final
BEFORE DELETE ON seat_matrix_entries
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM seat_matrix_versions v
  WHERE v.id=OLD.version_id AND v.institute_id=OLD.institute_id AND v.status IN ('final','superseded')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final Seat Matrix rows cannot be rewritten; create new version';
 END IF;
END$$

CREATE TRIGGER trg_seat_matrix_entries_insert_final_guard
BEFORE INSERT ON seat_matrix_entries
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM seat_matrix_versions v WHERE v.id=NEW.version_id
  AND v.institute_id=NEW.institute_id AND v.status IN ('final','superseded')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Final Seat Matrix cannot accept new rows; create revision';
 END IF;
END$$

CREATE TRIGGER trg_enrollments_insert_admission_window
BEFORE INSERT ON enrollments
FOR EACH ROW
BEGIN
 IF NEW.status NOT IN ('cancelled') AND NOT EXISTS (
  SELECT 1 FROM batches b
  JOIN admission_intake_revisions ir ON ir.intake_id=b.intake_id AND ir.institute_id=b.institute_id
  JOIN regulatory_sources r ON r.id=ir.source_id AND r.institute_id=ir.institute_id
  JOIN global_regulatory_documents d ON d.id=r.global_document_id
  WHERE b.id=NEW.batch_id AND b.institute_id=NEW.institute_id
    AND NEW.admitted_on BETWEEN ir.opens_on AND ir.closes_on
    AND ir.status IN ('published','superseded')
    AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admission date is outside documented official window';
 END IF;
END$$

CREATE TRIGGER trg_enrollments_update_admission_window
BEFORE UPDATE ON enrollments
FOR EACH ROW
BEGIN
 IF (NOT (NEW.batch_id <=> OLD.batch_id) OR NOT (NEW.admitted_on <=> OLD.admitted_on)
     OR NOT (NEW.institute_id <=> OLD.institute_id)
     OR (OLD.status='cancelled' AND NEW.status<>'cancelled'))
   AND NEW.status<>'cancelled' AND NOT EXISTS (
  SELECT 1 FROM batches b
  JOIN admission_intake_revisions ir ON ir.intake_id=b.intake_id AND ir.institute_id=b.institute_id
  JOIN regulatory_sources r ON r.id=ir.source_id AND r.institute_id=ir.institute_id
  JOIN global_regulatory_documents d ON d.id=r.global_document_id
  WHERE b.id=NEW.batch_id AND b.institute_id=NEW.institute_id
    AND NEW.admitted_on BETWEEN ir.opens_on AND ir.closes_on
    AND ir.status IN ('published','superseded')
    AND d.status IN ('published','superseded') AND d.reviewed_at IS NOT NULL
 ) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admission date is outside documented official window';
 END IF;
END$$

CREATE TRIGGER trg_admission_intakes_freeze_session
BEFORE UPDATE ON admission_intakes
FOR EACH ROW
BEGIN
 IF (NOT (OLD.session_part <=> NEW.session_part) OR NOT (OLD.calendar_id <=> NEW.calendar_id)
     OR NOT (OLD.admission_year <=> NEW.admission_year) OR NOT (OLD.intake_code <=> NEW.intake_code))
   AND EXISTS (SELECT 1 FROM seat_matrix_versions v WHERE v.intake_id=OLD.id AND v.institute_id=OLD.institute_id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published intake identity cannot change after seat matrix';
 END IF;
END$$

CREATE TRIGGER trg_cts_portal_shift_mappings_update_freeze
BEFORE UPDATE ON cts_portal_shift_mappings
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM admission_intakes ai
  JOIN seat_matrix_versions v ON v.intake_id=ai.id AND v.institute_id=ai.institute_id
  WHERE ai.calendar_id=OLD.calendar_id AND ai.session_part=OLD.session_part
    AND v.status IN ('final','superseded')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official portal shift mapping used by final matrix is historical';
 END IF;
END$$

CREATE TRIGGER trg_cts_portal_shift_mappings_delete_freeze
BEFORE DELETE ON cts_portal_shift_mappings
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM admission_intakes ai
  JOIN seat_matrix_versions v ON v.intake_id=ai.id AND v.institute_id=ai.institute_id
  WHERE ai.calendar_id=OLD.calendar_id AND ai.session_part=OLD.session_part
    AND v.status IN ('final','superseded')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official portal shift mapping used by final matrix is historical';
 END IF;
END$$

CREATE TRIGGER trg_global_admission_rule_versions_freeze_update
BEFORE UPDATE ON global_admission_rule_versions
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') AND (NOT (OLD.trade_id <=> NEW.trade_id)
  OR NOT (OLD.global_curriculum_id <=> NEW.global_curriculum_id)
  OR NOT (OLD.source_document_id <=> NEW.source_document_id)
  OR NOT (OLD.state_source_document_id <=> NEW.state_source_document_id)
  OR NOT (OLD.admission_window_document_id <=> NEW.admission_window_document_id)
  OR NOT (OLD.version_code <=> NEW.version_code)
  OR NOT (OLD.state_ut_code <=> NEW.state_ut_code)
  OR NOT (OLD.minimum_age_years <=> NEW.minimum_age_years)
  OR NOT (OLD.age_cutoff_date <=> NEW.age_cutoff_date)
  OR NOT (OLD.qualification_routes_json <=> NEW.qualification_routes_json)
  OR NOT (OLD.admission_opens_on <=> NEW.admission_opens_on)
  OR NOT (OLD.admission_closes_on <=> NEW.admission_closes_on)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official policy must not be rewritten';
 END IF;
 IF OLD.status='published' AND NEW.status NOT IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy cannot return to draft';
 END IF;
 IF OLD.status IN ('superseded','withdrawn') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Superseded policy cannot be reactivated';
 END IF;
END$

CREATE TRIGGER trg_global_admission_rule_versions_freeze_delete
BEFORE DELETE ON global_admission_rule_versions
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official policy cannot be deleted';
 END IF;
END$

CREATE TRIGGER trg_global_reservation_policies_freeze_update
BEFORE UPDATE ON global_reservation_policies
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') AND (NOT (OLD.state_ut_code <=> NEW.state_ut_code)
  OR NOT (OLD.admission_year <=> NEW.admission_year)
  OR NOT (OLD.version_code <=> NEW.version_code)
  OR NOT (OLD.source_document_id <=> NEW.source_document_id)
  OR NOT (OLD.policy_json <=> NEW.policy_json)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official policy must not be rewritten';
 END IF;
 IF OLD.status='published' AND NEW.status NOT IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy cannot return to draft';
 END IF;
 IF OLD.status IN ('superseded','withdrawn') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Superseded policy cannot be reactivated';
 END IF;
END$

CREATE TRIGGER trg_global_reservation_policies_freeze_delete
BEFORE DELETE ON global_reservation_policies
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official policy cannot be deleted';
 END IF;
END$

CREATE TRIGGER trg_global_exam_rule_versions_freeze_update
BEFORE UPDATE ON global_exam_rule_versions
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') AND (NOT (OLD.source_document_id <=> NEW.source_document_id)
  OR NOT (OLD.code <=> NEW.code)
  OR NOT (OLD.cycle_type <=> NEW.cycle_type)
  OR NOT (OLD.minimum_attendance <=> NEW.minimum_attendance)
  OR NOT (OLD.minimum_formative <=> NEW.minimum_formative)
  OR NOT (OLD.principal_relaxation_cap <=> NEW.principal_relaxation_cap)
  OR NOT (OLD.relaxation_source_document_id <=> NEW.relaxation_source_document_id)
  OR NOT (OLD.requires_prn <=> NEW.requires_prn)
  OR NOT (OLD.requires_cbt_fee <=> NEW.requires_cbt_fee)
  OR NOT (OLD.requires_practical_fee <=> NEW.requires_practical_fee)
  OR NOT (OLD.requires_ojt_for_certificate <=> NEW.requires_ojt_for_certificate)
  OR NOT (OLD.practical_upload_hours <=> NEW.practical_upload_hours)
  OR NOT (OLD.effective_from <=> NEW.effective_from)
  OR NOT (OLD.effective_to <=> NEW.effective_to)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official policy must not be rewritten';
 END IF;
 IF OLD.status='published' AND NEW.status NOT IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published policy cannot return to draft';
 END IF;
 IF OLD.status IN ('superseded','withdrawn') AND NEW.status<>OLD.status THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Superseded policy cannot be reactivated';
 END IF;
END$

CREATE TRIGGER trg_global_exam_rule_versions_freeze_delete
BEFORE DELETE ON global_exam_rule_versions
FOR EACH ROW
BEGIN
 IF OLD.status IN ('published','superseded','withdrawn') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published official policy cannot be deleted';
 END IF;
END$

CREATE TRIGGER trg_cts_training_calendar_milestones_freeze_insert
BEFORE INSERT ON cts_training_calendar_milestones
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM cts_training_calendars c WHERE c.id=NEW.calendar_id AND c.status<>'draft') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official published calendar cannot accept new milestones';
 END IF;
END$

CREATE TRIGGER trg_cts_training_calendar_milestones_freeze_update
BEFORE UPDATE ON cts_training_calendar_milestones
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM cts_training_calendars c WHERE c.id=OLD.calendar_id AND c.status<>'draft') OR EXISTS (SELECT 1 FROM cts_training_calendars c WHERE c.id=NEW.calendar_id AND c.status<>'draft') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Official calendar milestone history cannot be rewritten';
 END IF;
END$

CREATE TRIGGER trg_cts_training_calendar_milestones_freeze_delete
BEFORE DELETE ON cts_training_calendar_milestones
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM cts_training_calendars c WHERE c.id=OLD.calendar_id AND c.status<>'draft') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Published calendar milestone cannot be deleted';
 END IF;
END$

DELIMITER ;
