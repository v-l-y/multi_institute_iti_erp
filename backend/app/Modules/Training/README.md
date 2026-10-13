# Training plans, progress and auditable voids

These are institute-internal records tied to reviewed academic calendars, instructor assignments and curriculum learning outcomes. No government marks or certificate data are generated.

**Independent approval:** `POST /api/v1/training/plans` creates/edits drafts with an immutable `training.plan.draft` event recording the authenticated editor. `POST /api/v1/training/plans/approve` requires `training.plan.manage`, Principal role and a reviewer different from the creator, instructor, and latest editor. Missing edit history fails closed. A Principal who edits a teacher's draft cannot approve until that teacher has reviewed and re-submitted it.

**Progress corrections:** `POST /api/v1/training/progress` requires positive attended minutes. An authorized Principal can append a correction with `supersedes_id` and a documented `correction_reason`; earlier rows remain immutable.

**Mistaken progress:** `POST /api/v1/training/progress/void` requires the Principal role and `training.correct`, with this JSON body:

```json
{"progress_id":123,"evidence_reference":"LOCAL-ORDER-123","correction_reason":"Recorded against the wrong session"}
```

Voiding creates an immutable institute activity event (`training.progress.void`) with evidence, actor, reason and effective minutes 0. It never overwrites or deletes `training_progress`. Only the latest unsuperseded entry may be voided; a duplicate void or correction of a voided entry is rejected. Default `GET /api/v1/training/progress` excludes voided/superseded entries; `?include_history=1` exposes retained history with `is_voided`. A fresh documented training entry with a new evidence reference may still be recorded later.

Void is not an official regulatory correction; its scope is the institute's internal training register.
