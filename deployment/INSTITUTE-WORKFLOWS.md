# Institute management workflows

Scope is internal institute management. CSV exports and printable receipts are internal records, without government portal compatibility claims.

Student profile corrections allow only name, address, mobile and email with a reason and audit history. Principal-reviewed enrollment transitions support dropout, transfer and completion, retain historical intake consumption and require a dated internal authority reference. Transfer requires a destination; completion requires the batch end date. Same-batch readmission is not implemented.

Private documents accept strict base64 PDF/PNG/JPEG up to 5 MiB decoded, with an 8 MiB upload request limit. Administrator and Principal uploads enforce institute ownership. Student reads enforce own-record access. Downloads verify size and SHA-256 and use attachment disposition. Archive retains files and immutable metadata. MIME checks do not provide antivirus scanning.

Staging stores private documents and local recovery outbox under /var/lib/iti-private in a persistent Docker volume. Back up this volume together with its matching database. Outbox JSON contains sensitive reset tokens: operator access only, never HTTP or Git exposure. No email is sent. Recovery tokens expire after 30 minutes, are single-use, have a 10-minute account cooldown and IP rate limit. Successful reset revokes sessions. Production recovery defaults to disabled.

Principal-reviewed academic corrections append immutable history, require a reason/reference and reject stale correction versions. Original recorders cannot approve their own correction. Current views and future eligibility freezes use corrections; existing frozen snapshots remain unchanged. Audit readers can access paginated institute correction history.

Internal CSV routes: /api/v1/students/export, /api/v1/fees/statements/export and its /me variant. Follow X-Next-After-ID for additional pages. Formula cells are neutralized. /api/v1/fees/receipts/print and its /me variant return escaped printable HTML from the receipt identity snapshot; browser Print / Save as PDF is supported, not server-generated PDF delivery.

Run ops/verify-backend.ps1 for isolated verification and ops/check-staging.ps1 after the existing staging schema has been upgraded. Real staff UAT, deployment TLS and verified backups including private files remain release requirements.

## Instructional training and progress

GET /api/v1/training/plans is the paginated lesson/demonstration/practical timetable register. GET /api/v1/training/progress is the effective trainee progress register; include_history=1 retains all superseded observations. Institute managers see authorized institute records, instructors only their plans, and students only their own enrolled batch/progress.

POST /api/v1/training/plans creates or revises a draft (optional plan_id). Required fields: batch_year_id, learning_outcome_id, instructor_id, session_date, component, activity_type (lesson/demonstration/practical), title, instructions, start_minute (minutes after midnight), planned_minutes. The instructor must have an active subject_batch_assignments link. Planning stays inside reviewed daily component minutes and rejects overlapping instructor/batch slots.

POST /api/v1/training/plans/approve accepts plan_id and review_reference. An independent Principal reviews the plan; approved content is immutable. Drafts can be revised, but approved records cannot be silently overwritten.

POST /api/v1/training/progress accepts plan_id, enrollment_id, progress_status (started/practised/completed), attended_minutes, recorded_on, evidence_reference and notes. Assigned instructors record active trainees; dates cannot be future and minutes cannot exceed the plan allocation. For a Principal-reviewed append-only correction, also provide supersedes_id and correction_reason, with a new evidence reference. Stale/duplicate corrections are rejected and history is preserved. These observations do not change frozen attendance/formative/eligibility or assert official competency/certification.

Draft role grants add training.plan.manage/training.view for Principal/administrator, training.record.assigned for instructors, training.view.own for students and training.correct for Principal. Review production policy before importing grants.

## Fee read registers

Paginated institute-scoped GET endpoints now cover /api/v1/fees/heads, structures, structure-items, assessments, demands, refunds, waivers and reversals. They require fee.view, support after_id/page_size (max 100), and expose selected business fields without payment fingerprints or secrets. Student-specific registers accept student_id only after institute ownership verification; structure-items accepts structure_id. Own fee statement/receipt endpoints retain existing boundaries.

## Paired recovery

Run ops/backup-restore-local.ps1 for the persistent demo or add -Environment staging. It stops the selected source backend briefly to quiesce application writes, takes SQL plus private documents/outbox in one encrypted GnuPG AES256 bundle, and resumes the backend even if backup fails. Run it during maintenance with all other writers stopped. Plaintext exists only in worker tmpfs.

Each run independently restores into a unique no-port database/private volume, verifies all table row counts, trigger count, every private file hash, and private_documents size/SHA metadata, then removes only the disposable restore environment/image. Encrypted archives remain under ignored backups/. The random encryption key is in ignored .demo-private/backup-ENVIRONMENT.key. Store archive and key in separate protected recovery locations; losing the key makes recovery impossible. Off-machine copying and scheduled retention remain operator tasks.

Fresh installations use consolidated baseline 000. All former 001–020 objects are included in the canonical schema and guard files; --baseline-current verifies them and records one 000 ledger entry. Existing demo databases are preserved and need a separate transition. See the migration guide.

Draft plans can be deleted with POST /api/v1/training/plans/delete, providing plan_id and reason. Only an authorized manager or original instructor-author can delete an unapproved draft; the audit retains its content. Approved plans and progress history are preserved.

The paired worker also has an explicit production maintenance overlay. On a configured production host, use -Environment production -SourceProject YOUR_ACTUAL_COMPOSE_PROJECT after owner authorization and quiescing every writer. It uses production_private and protected production settings. This mode is provided for deployment preparation; only local demo recovery has been executed here.
