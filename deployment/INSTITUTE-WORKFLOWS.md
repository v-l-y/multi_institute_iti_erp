# Institute management workflows

Scope is internal institute management. CSV exports and printable receipts are internal records, without government portal compatibility claims.

Student profile corrections allow only name, address, mobile and email with a reason and audit history. Principal-reviewed enrollment transitions support dropout, transfer and completion, retain historical intake consumption and require a dated internal authority reference. Transfer requires a destination; completion requires the batch end date. Same-batch readmission is not implemented.

Private documents accept strict base64 PDF/PNG/JPEG up to 5 MiB decoded, with an 8 MiB upload request limit. Administrator and Principal uploads enforce institute ownership. Student reads enforce own-record access. Downloads verify size and SHA-256 and use attachment disposition. Archive retains files and immutable metadata. MIME checks do not provide antivirus scanning.

Staging stores private documents and local recovery outbox under /var/lib/iti-private in a persistent Docker volume. Back up this volume together with its matching database. Outbox JSON contains sensitive reset tokens: operator access only, never HTTP or Git exposure. No email is sent. Recovery tokens expire after 30 minutes, are single-use, have a 10-minute account cooldown and IP rate limit. Successful reset revokes sessions. Production recovery defaults to disabled.

Principal-reviewed academic corrections append immutable history, require a reason/reference and reject stale correction versions. Original recorders cannot approve their own correction. Current views and future eligibility freezes use corrections; existing frozen snapshots remain unchanged. Audit readers can access paginated institute correction history.

Internal CSV routes: /api/v1/students/export, /api/v1/fees/statements/export and its /me variant. Follow X-Next-After-ID for additional pages. Formula cells are neutralized. /api/v1/fees/receipts/print and its /me variant return escaped printable HTML from the receipt identity snapshot; browser Print / Save as PDF is supported, not server-generated PDF delivery.

Run ops/verify-backend.ps1 for isolated verification and ops/check-staging.ps1 after the existing staging schema has been upgraded. Real staff UAT, deployment TLS and verified backups including private files remain release requirements.
