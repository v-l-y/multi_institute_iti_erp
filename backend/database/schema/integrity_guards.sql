-- Database record protection. Import AFTER iti_erp_schema.sql.
-- Fresh installs and tests must load this file; existing databases use the reviewed upgrade.
DELIMITER $$

CREATE TRIGGER global_regulatory_documents_frozen_update BEFORE UPDATE ON global_regulatory_documents
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.authority<=>OLD.authority) OR NOT (NEW.source_system<=>OLD.source_system) OR NOT (NEW.state_ut_code<=>OLD.state_ut_code) OR NOT (NEW.document_type<=>OLD.document_type) OR NOT (NEW.title<=>OLD.title) OR NOT (NEW.reference_no<=>OLD.reference_no) OR NOT (NEW.issued_on<=>OLD.issued_on) OR NOT (NEW.source_url<=>OLD.source_url) OR NOT (NEW.evidence_reference<=>OLD.evidence_reference) OR NOT (NEW.sha256<=>OLD.sha256) OR NOT (NEW.supersedes_id<=>OLD.supersedes_id) OR NOT (NEW.reviewed_by<=>OLD.reviewed_by) OR NOT (NEW.reviewed_at<=>OLD.reviewed_at) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='published' AND NEW.status IN ('published','superseded','withdrawn')) OR (OLD.status='superseded' AND NEW.status IN ('superseded','withdrawn')) OR (OLD.status='withdrawn' AND NEW.status='withdrawn'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_regulatory_documents_frozen_delete BEFORE DELETE ON global_regulatory_documents
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_curriculum_versions_frozen_update BEFORE UPDATE ON global_curriculum_versions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.trade_id<=>OLD.trade_id) OR NOT (NEW.source_document_id<=>OLD.source_document_id) OR NOT (NEW.version_code<=>OLD.version_code) OR NOT (NEW.duration_months<=>OLD.duration_months) OR NOT (NEW.nsqf_level<=>OLD.nsqf_level) OR NOT (NEW.unit_strength<=>OLD.unit_strength) OR NOT (NEW.effective_from<=>OLD.effective_from) OR NOT (NEW.effective_to<=>OLD.effective_to) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='published' AND NEW.status IN ('published','superseded','withdrawn')) OR (OLD.status='superseded' AND NEW.status IN ('superseded','withdrawn')) OR (OLD.status='withdrawn' AND NEW.status='withdrawn'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_curriculum_versions_frozen_delete BEFORE DELETE ON global_curriculum_versions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_admission_rule_versions_frozen_update BEFORE UPDATE ON global_admission_rule_versions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.trade_id<=>OLD.trade_id) OR NOT (NEW.global_curriculum_id<=>OLD.global_curriculum_id) OR NOT (NEW.source_document_id<=>OLD.source_document_id) OR NOT (NEW.state_source_document_id<=>OLD.state_source_document_id) OR NOT (NEW.admission_window_document_id<=>OLD.admission_window_document_id) OR NOT (NEW.version_code<=>OLD.version_code) OR NOT (NEW.state_ut_code<=>OLD.state_ut_code) OR NOT (NEW.minimum_age_years<=>OLD.minimum_age_years) OR NOT (NEW.age_cutoff_date<=>OLD.age_cutoff_date) OR NOT (NEW.qualification_routes_json<=>OLD.qualification_routes_json) OR NOT (NEW.admission_opens_on<=>OLD.admission_opens_on) OR NOT (NEW.admission_closes_on<=>OLD.admission_closes_on) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='published' AND NEW.status IN ('published','superseded','withdrawn')) OR (OLD.status='superseded' AND NEW.status IN ('superseded','withdrawn')) OR (OLD.status='withdrawn' AND NEW.status='withdrawn'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_admission_rule_versions_frozen_delete BEFORE DELETE ON global_admission_rule_versions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_reservation_policies_frozen_update BEFORE UPDATE ON global_reservation_policies
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.state_ut_code<=>OLD.state_ut_code) OR NOT (NEW.admission_year<=>OLD.admission_year) OR NOT (NEW.version_code<=>OLD.version_code) OR NOT (NEW.source_document_id<=>OLD.source_document_id) OR NOT (NEW.policy_json<=>OLD.policy_json) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='published' AND NEW.status IN ('published','superseded','withdrawn')) OR (OLD.status='superseded' AND NEW.status IN ('superseded','withdrawn')) OR (OLD.status='withdrawn' AND NEW.status='withdrawn'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_reservation_policies_frozen_delete BEFORE DELETE ON global_reservation_policies
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_exam_rule_versions_frozen_update BEFORE UPDATE ON global_exam_rule_versions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.source_document_id<=>OLD.source_document_id) OR NOT (NEW.code<=>OLD.code) OR NOT (NEW.cycle_type<=>OLD.cycle_type) OR NOT (NEW.minimum_attendance<=>OLD.minimum_attendance) OR NOT (NEW.minimum_formative<=>OLD.minimum_formative) OR NOT (NEW.principal_relaxation_cap<=>OLD.principal_relaxation_cap) OR NOT (NEW.relaxation_source_document_id<=>OLD.relaxation_source_document_id) OR NOT (NEW.requires_prn<=>OLD.requires_prn) OR NOT (NEW.requires_cbt_fee<=>OLD.requires_cbt_fee) OR NOT (NEW.requires_practical_fee<=>OLD.requires_practical_fee) OR NOT (NEW.requires_ojt_for_certificate<=>OLD.requires_ojt_for_certificate) OR NOT (NEW.practical_upload_hours<=>OLD.practical_upload_hours) OR NOT (NEW.effective_from<=>OLD.effective_from) OR NOT (NEW.effective_to<=>OLD.effective_to) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='published' AND NEW.status IN ('published','superseded','withdrawn')) OR (OLD.status='superseded' AND NEW.status IN ('superseded','withdrawn')) OR (OLD.status='withdrawn' AND NEW.status='withdrawn'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER global_exam_rule_versions_frozen_delete BEFORE DELETE ON global_exam_rule_versions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER seat_matrix_versions_frozen_update BEFORE UPDATE ON seat_matrix_versions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.institute_id<=>OLD.institute_id) OR NOT (NEW.id<=>OLD.id) OR NOT (NEW.admission_year<=>OLD.admission_year) OR NOT (NEW.intake_id<=>OLD.intake_id) OR NOT (NEW.version_code<=>OLD.version_code) OR NOT (NEW.source_id<=>OLD.source_id) OR NOT (NEW.portal_reference<=>OLD.portal_reference) OR NOT (NEW.dgt_circular_no<=>OLD.dgt_circular_no) OR NOT (NEW.published_on<=>OLD.published_on) OR NOT (NEW.supersedes_id<=>OLD.supersedes_id) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='final' AND NEW.status IN ('final','superseded')) OR (OLD.status='superseded' AND NEW.status='superseded'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER seat_matrix_versions_frozen_delete BEFORE DELETE ON seat_matrix_versions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER admission_intake_revisions_frozen_update BEFORE UPDATE ON admission_intake_revisions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.institute_id<=>OLD.institute_id) OR NOT (NEW.id<=>OLD.id) OR NOT (NEW.intake_id<=>OLD.intake_id) OR NOT (NEW.source_id<=>OLD.source_id) OR NOT (NEW.revision_code<=>OLD.revision_code) OR NOT (NEW.opens_on<=>OLD.opens_on) OR NOT (NEW.closes_on<=>OLD.closes_on) OR NOT (NEW.approval_reference<=>OLD.approval_reference) OR NOT (NEW.published_on<=>OLD.published_on) OR NOT (NEW.supersedes_id<=>OLD.supersedes_id) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='published' AND NEW.status IN ('published','superseded','withdrawn')) OR (OLD.status='superseded' AND NEW.status IN ('superseded','withdrawn')) OR (OLD.status='withdrawn' AND NEW.status='withdrawn'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER admission_intake_revisions_frozen_delete BEFORE DELETE ON admission_intake_revisions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER student_fee_assessments_frozen_update BEFORE UPDATE ON student_fee_assessments
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.institute_id<=>OLD.institute_id) OR NOT (NEW.student_id<=>OLD.student_id) OR NOT (NEW.enrollment_id<=>OLD.enrollment_id) OR NOT (NEW.structure_id<=>OLD.structure_id) OR NOT (NEW.assessment_no<=>OLD.assessment_no) OR NOT (NEW.assessed_on<=>OLD.assessed_on) OR NOT (NEW.created_by<=>OLD.created_by) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='issued' AND NEW.status='issued') OR (OLD.status='cancelled' AND NEW.status='cancelled'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER student_fee_assessments_frozen_delete BEFORE DELETE ON student_fee_assessments
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER fee_structures_frozen_update BEFORE UPDATE ON fee_structures
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.institute_id<=>OLD.institute_id) OR NOT (NEW.trade_id<=>OLD.trade_id) OR NOT (NEW.version_no<=>OLD.version_no) OR NOT (NEW.effective_from<=>OLD.effective_from) OR NOT (NEW.effective_to<=>OLD.effective_to) OR NOT (NEW.created_by<=>OLD.created_by) OR NOT (NEW.activated_by<=>OLD.activated_by) OR NOT (NEW.activated_at<=>OLD.activated_at) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='active' AND NEW.status IN ('active','inactive')) OR (OLD.status='inactive' AND NEW.status='inactive'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER fee_structures_frozen_delete BEFORE DELETE ON fee_structures
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER official_results_immutable_update BEFORE UPDATE ON official_results
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER official_results_immutable_delete BEFORE DELETE ON official_results
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER certificates_immutable_update BEFORE UPDATE ON certificates
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER certificates_immutable_delete BEFORE DELETE ON certificates
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_payments_immutable_update BEFORE UPDATE ON fee_payments
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_payments_immutable_delete BEFORE DELETE ON fee_payments
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_payment_allocations_immutable_update BEFORE UPDATE ON fee_payment_allocations
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_payment_allocations_immutable_delete BEFORE DELETE ON fee_payment_allocations
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_payment_reversals_immutable_update BEFORE UPDATE ON fee_payment_reversals
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_payment_reversals_immutable_delete BEFORE DELETE ON fee_payment_reversals
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_cash_transfers_immutable_update BEFORE UPDATE ON fee_cash_transfers
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_cash_transfers_immutable_delete BEFORE DELETE ON fee_cash_transfers
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_cash_closings_immutable_update BEFORE UPDATE ON fee_cash_closings
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_cash_closings_immutable_delete BEFORE DELETE ON fee_cash_closings
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER institute_activity_log_immutable_update BEFORE UPDATE ON institute_activity_log
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER institute_activity_log_immutable_delete BEFORE DELETE ON institute_activity_log
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER seat_matrix_entries_parent_insert BEFORE INSERT ON seat_matrix_entries
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM seat_matrix_versions WHERE id=NEW.version_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER seat_matrix_entries_parent_update BEFORE UPDATE ON seat_matrix_entries
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM seat_matrix_versions WHERE id=NEW.version_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
 SELECT status INTO parent_state FROM seat_matrix_versions WHERE id=OLD.version_id AND institute_id=OLD.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER seat_matrix_entries_parent_delete BEFORE DELETE ON seat_matrix_entries
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM seat_matrix_versions WHERE id=OLD.version_id AND institute_id=OLD.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER student_fee_assessment_items_parent_insert BEFORE INSERT ON student_fee_assessment_items
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM student_fee_assessments WHERE id=NEW.assessment_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER student_fee_assessment_items_parent_update BEFORE UPDATE ON student_fee_assessment_items
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM student_fee_assessments WHERE id=NEW.assessment_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
 SELECT status INTO parent_state FROM student_fee_assessments WHERE id=OLD.assessment_id AND institute_id=OLD.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER student_fee_assessment_items_parent_delete BEFORE DELETE ON student_fee_assessment_items
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM student_fee_assessments WHERE id=OLD.assessment_id AND institute_id=OLD.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER fee_structure_items_parent_insert BEFORE INSERT ON fee_structure_items
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM fee_structures WHERE id=NEW.structure_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER fee_structure_items_parent_update BEFORE UPDATE ON fee_structure_items
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM fee_structures WHERE id=NEW.structure_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
 SELECT status INTO parent_state FROM fee_structures WHERE id=OLD.structure_id AND institute_id=OLD.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER fee_structure_items_parent_delete BEFORE DELETE ON fee_structure_items
FOR EACH ROW
BEGIN
 DECLARE parent_state VARCHAR(30);
 SELECT status INTO parent_state FROM fee_structures WHERE id=OLD.structure_id AND institute_id=OLD.institute_id FOR UPDATE;
 IF parent_state IS NULL OR parent_state<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER fee_refunds_workflow_update BEFORE UPDATE ON fee_refunds
FOR EACH ROW
BEGIN
 IF OLD.status IN ('paid','rejected','cancelled') OR (NOT (NEW.id<=>OLD.id) OR NOT (NEW.institute_id<=>OLD.institute_id) OR NOT (NEW.student_id<=>OLD.student_id) OR NOT (NEW.demand_id<=>OLD.demand_id) OR NOT (NEW.allocation_id<=>OLD.allocation_id) OR NOT (NEW.refund_no<=>OLD.refund_no) OR NOT (NEW.amount<=>OLD.amount) OR NOT (NEW.reason<=>OLD.reason) OR NOT (NEW.refund_mode<=>OLD.refund_mode) OR NOT (NEW.idempotency_key<=>OLD.idempotency_key) OR NOT (NEW.request_fingerprint<=>OLD.request_fingerprint) OR NOT (NEW.requested_by<=>OLD.requested_by) OR NOT (NEW.requested_at<=>OLD.requested_at) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='pending' AND NEW.status IN ('approved','rejected','cancelled')) OR (OLD.status='approved' AND NEW.status IN ('paid','cancelled'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
 IF OLD.status='approved' AND NEW.status='paid' AND (NOT (NEW.decided_by<=>OLD.decided_by) OR NOT (NEW.decided_at<=>OLD.decided_at)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER fee_refunds_workflow_delete BEFORE DELETE ON fee_refunds
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER fee_waivers_workflow_update BEFORE UPDATE ON fee_waivers
FOR EACH ROW
BEGIN
 IF OLD.status IN ('approved','rejected','cancelled') OR (NOT (NEW.id<=>OLD.id) OR NOT (NEW.institute_id<=>OLD.institute_id) OR NOT (NEW.student_id<=>OLD.student_id) OR NOT (NEW.assessment_id<=>OLD.assessment_id) OR NOT (NEW.waiver_no<=>OLD.waiver_no) OR NOT (NEW.amount<=>OLD.amount) OR NOT (NEW.reason<=>OLD.reason) OR NOT (NEW.requested_by<=>OLD.requested_by) OR NOT (NEW.requested_at<=>OLD.requested_at) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT (OLD.status='pending' AND NEW.status IN ('approved','rejected','cancelled')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER fee_waivers_workflow_delete BEFORE DELETE ON fee_waivers
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction';
END$$

CREATE TRIGGER attendance_sessions_locked_update BEFORE UPDATE ON attendance_sessions
FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER attendance_sessions_locked_delete BEFORE DELETE ON attendance_sessions
FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER formative_assessments_locked_update BEFORE UPDATE ON formative_assessments
FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER formative_assessments_locked_delete BEFORE DELETE ON formative_assessments
FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER formative_template_scores_locked_update BEFORE UPDATE ON formative_template_scores
FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER formative_template_scores_locked_delete BEFORE DELETE ON formative_template_scores
FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER attendance_records_session_insert BEFORE INSERT ON attendance_records
FOR EACH ROW
BEGIN
 DECLARE session_lock DATETIME;
 DECLARE session_minutes INT;
 SELECT locked_on,scheduled_minutes INTO session_lock,session_minutes FROM attendance_sessions WHERE id=NEW.session_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF session_lock IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
 IF NEW.attended_minutes>session_minutes THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance exceeds scheduled session minutes'; END IF;
END$$

CREATE TRIGGER attendance_records_session_update BEFORE UPDATE ON attendance_records
FOR EACH ROW
BEGIN
 DECLARE session_lock DATETIME;
 DECLARE session_minutes INT;
 SELECT locked_on,scheduled_minutes INTO session_lock,session_minutes FROM attendance_sessions WHERE id=NEW.session_id AND institute_id=NEW.institute_id FOR UPDATE;
 IF session_lock IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
 IF NEW.attended_minutes>session_minutes THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Attendance exceeds scheduled session minutes'; END IF;
 SELECT locked_on INTO session_lock FROM attendance_sessions WHERE id=OLD.session_id AND institute_id=OLD.institute_id FOR UPDATE;
 IF session_lock IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER attendance_records_session_delete BEFORE DELETE ON attendance_records
FOR EACH ROW
BEGIN
 DECLARE session_lock DATETIME;
 DECLARE session_minutes INT;
 SELECT locked_on,scheduled_minutes INTO session_lock,session_minutes FROM attendance_sessions WHERE id=OLD.session_id AND institute_id=OLD.institute_id FOR UPDATE;
 IF session_lock IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: use an audited revision or correction'; END IF;
END$$

CREATE TRIGGER formative_template_scores_max_insert BEFORE INSERT ON formative_template_scores
FOR EACH ROW
BEGIN
 DECLARE maximum DECIMAL(9,3);
 SELECT max_marks INTO maximum FROM formative_template_items WHERE batch_year_id=NEW.batch_year_id AND item_code=NEW.item_code AND institute_id=NEW.institute_id FOR UPDATE;
 IF NEW.obtained_marks>maximum THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Score exceeds template maximum'; END IF;
END$$

CREATE TRIGGER formative_template_scores_max_update BEFORE UPDATE ON formative_template_scores
FOR EACH ROW
BEGIN
 DECLARE maximum DECIMAL(9,3);
 SELECT max_marks INTO maximum FROM formative_template_items WHERE batch_year_id=NEW.batch_year_id AND item_code=NEW.item_code AND institute_id=NEW.institute_id FOR UPDATE;
 IF NEW.obtained_marks>maximum THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Score exceeds template maximum'; END IF;
END$$


CREATE TRIGGER exam_eligibility_immutable_update BEFORE UPDATE ON exam_eligibility
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen eligibility: create an audited exam correction';
END$$

CREATE TRIGGER exam_eligibility_immutable_delete BEFORE DELETE ON exam_eligibility
FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen eligibility: preserve the original evidence';
END$$

CREATE TRIGGER formative_template_items_used_update BEFORE UPDATE ON formative_template_items
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM formative_template_scores WHERE batch_year_id=OLD.batch_year_id AND item_code=OLD.item_code AND institute_id=OLD.institute_id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template has scores: create a new reviewed template version';
 END IF;
END$$

CREATE TRIGGER formative_template_items_used_delete BEFORE DELETE ON formative_template_items
FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM formative_template_scores WHERE batch_year_id=OLD.batch_year_id AND item_code=OLD.item_code AND institute_id=OLD.institute_id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Template has scores: preserve its maximum and weight';
 END IF;
END$$


CREATE TRIGGER cts_training_calendars_content_update BEFORE UPDATE ON cts_training_calendars
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.admission_year<=>OLD.admission_year) OR NOT (NEW.session_code<=>OLD.session_code) OR NOT (NEW.revision_code<=>OLD.revision_code) OR NOT (NEW.source_document_id<=>OLD.source_document_id) OR NOT (NEW.training_starts_on<=>OLD.training_starts_on) OR NOT (NEW.training_ends_on<=>OLD.training_ends_on) OR NOT (NEW.upload_profile_json<=>OLD.upload_profile_json) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='published' AND NEW.status IN ('published','superseded','withdrawn')) OR (OLD.status='superseded' AND NEW.status IN ('superseded','withdrawn')) OR (OLD.status='withdrawn' AND NEW.status='withdrawn'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: create a reviewed revision'; END IF;
END$$

CREATE TRIGGER cts_training_calendars_content_delete BEFORE DELETE ON cts_training_calendars
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: create a reviewed revision'; END IF;
END$$

CREATE TRIGGER trade_master_revisions_content_update BEFORE UPDATE ON trade_master_revisions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' AND ((NOT (NEW.id<=>OLD.id) OR NOT (NEW.trade_id<=>OLD.trade_id) OR NOT (NEW.source_document_id<=>OLD.source_document_id) OR NOT (NEW.version_code<=>OLD.version_code) OR NOT (NEW.official_name<=>OLD.official_name) OR NOT (NEW.trade_type<=>OLD.trade_type) OR NOT (NEW.effective_from<=>OLD.effective_from) OR NOT (NEW.effective_to<=>OLD.effective_to) OR NOT (NEW.created_at<=>OLD.created_at)) OR NOT ((OLD.status='active' AND NEW.status IN ('active','superseded','retired')) OR (OLD.status='superseded' AND NEW.status IN ('superseded','retired')) OR (OLD.status='retired' AND NEW.status='retired'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: create a reviewed revision'; END IF;
END$$

CREATE TRIGGER trade_master_revisions_content_delete BEFORE DELETE ON trade_master_revisions
FOR EACH ROW
BEGIN
 IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: create a reviewed revision'; END IF;
END$$

CREATE TRIGGER working_day_attendance_locked_update BEFORE UPDATE ON working_day_attendance
FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL AND (NOT (NEW.enrollment_id<=>OLD.enrollment_id) OR NOT (NEW.batch_year_id<=>OLD.batch_year_id) OR NOT (NEW.batch_id<=>OLD.batch_id) OR NOT (NEW.institute_id<=>OLD.institute_id) OR NOT (NEW.session_date<=>OLD.session_date) OR NOT (NEW.counted_present<=>OLD.counted_present) OR NOT (NEW.evidence_reference<=>OLD.evidence_reference) OR NOT (NEW.certified_by<=>OLD.certified_by) OR NOT (NEW.locked_on<=>OLD.locked_on) OR NOT (NEW.revision_no<=>OLD.revision_no) OR NOT (NEW.register_correction_id<=>OLD.register_correction_id) OR NEW.is_current>OLD.is_current) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: create a reviewed revision'; END IF;
END$$

CREATE TRIGGER working_day_attendance_locked_delete BEFORE DELETE ON working_day_attendance
FOR EACH ROW
BEGIN
 IF OLD.locked_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Frozen record: create a reviewed revision'; END IF;
END$$
DELIMITER ;
