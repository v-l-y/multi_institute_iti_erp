# Authentication security: MFA, rotating sessions, email recovery, cluster throttle

This is institute-management authentication only; no regulatory decision is
made by this subsystem. Feature code is committed, but **not automatically
enabled/certified** until the schema is upgraded and the operator securely
configures it. Never use production credentials in Git.

## Deployment order — mandatory

1. Back up the populated database and rehearse migration in an isolated copy.
   Apply reviewed migrations **015–020** using \`php bin/migrate.php --status\`
   followed by an explicit \`php bin/migrate.php --apply\` (when the ledger is
   already present). Fresh schema installs contain these objects; use
   \`--baseline-current\` only on a truly matching fresh canonical installation.
2. **Do not enable traffic before schema migration.** The database throttle
   deliberately returns HTTP 503 instead of falling back to worker-local
   counters if \`auth_rate_limits\` does not exist.
3. Configure HTTPS and trusted reverse proxy client IP handling.
   All nodes must use the same database and (for MFA) the same immutable
   32-byte encryption key.
4. Configure secure SMTP and verified recovery addresses (below), then carry
   out manual authenticated account/UAT checks before public release.

### Production environment values

\`\`\`dotenv
# Generate locally: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
AUTH_MFA_ENCRYPTION_KEY=<base64-of-32-secure-random-bytes>

AUTH_RECOVERY_MODE=smtp
AUTH_SMTP_HOST=smtp.example.org
AUTH_SMTP_PORT=587
AUTH_SMTP_USER=<smtp-username>
AUTH_SMTP_PASSWORD=<secret-from-deployment-vault>
AUTH_SMTP_FROM=no-reply@example.org
\`\`\`

Never check secrets into a repository. SMTP supports STARTTLS with certificate
and hostname verification only. There is **no** plaintext socket, PHP
\`mail()\`, or unverified-address fallback. SMTP failure is operationally
signaled and logged without leaking whether an account exists.
\`AUTH_RECOVERY_MODE=local_outbox\` with \`AUTH_RECOVERY_OUTBOX\` remains
available for an intentionally isolated local test environment. It writes
sensitive delivery payloads to private files; never expose this directory as
web content.

## MFA / TOTP

- Authenticator-app RFC 6238 TOTP: **30-second steps, SHA1, six digits,
  current ±1 step**. The server stores encrypted keys using libsodium
  secretbox under \`AUTH_MFA_ENCRYPTION_KEY\`. Access requires this same key on
  all instances. Losing the key makes already enrolled accounts unable to
  authenticate: securely back it up, and plan controlled key rotation.
- Account Security → \`POST /api/v1/auth/mfa/enroll\` verifies the current
  password, returns the one-time setup secret/otpauth URI to the authenticated
  user. Complete enrollment with \`POST /api/v1/auth/mfa/confirm\` and the
  6-digit TOTP within 10 minutes. Recovery codes are returned only on success
  (8 random 12-hex codes, SHA-256 hashes only stored); save them offline.
- A verified TOTP-enabled account must submit \`otp_code\` or one unused
  \`recovery_code\` together with password at \`POST /api/v1/auth/login\`.
  Replaying a previous TOTP time-step or used recovery code is rejected.
  Failed MFA counts against account lockout and centralized throttles.
- Other sessions are revoked on enrollment confirmation. Disabling requires
  current password plus valid TOTP or backup recovery code, revoking the other
  sessions. No SMS OTP fallback or recovery email bypass of enrolled MFA exists.
- MFA enrollment is **opt-in** in this release; it is not a blanket guarantee
  that all principals/developers have enrolled. An operator must enforce an
  approved enrollment policy and verify coverage before announcing universal
  two-factor protection.

## Refresh token rotation

- Login returns \`access_token\` and \`refresh_token\`, each 64 hex chars.
  The raw tokens are never stored by backend; only SHA-256 hashes are stored.
- Access session expires after 60 minutes, or after 15 minutes of inactivity
  without an authorized access request. \`POST /api/v1/auth/refresh\` accepts
  JSON \`{ "refresh_token":"..." }\` and returns replacement tokens.
- Each refresh obtains a database row lock, logs the old refresh hash in
  \`auth_refresh_replays\`, rotates both session credentials, and keeps the
  initial **7-day absolute refresh expiry** unchanged. Old-token reuse revokes
  that entire session. Concurrent refresh requests must deduplicate client-
  side; one token cannot be consumed twice.
- Frontend keeps both tokens **in JavaScript memory only**. No refresh cookie,
  localStorage, sessionStorage, token in URL, or long-lived browser persistence.
  Reload still requires login. This means a continuously open session can
  renew within seven days: product/operations should explicitly approve this
  session policy before production.
- Logout/password changes/account disable revoke matching sessions. Changing
  permissions still takes effect on the next authenticated request or refresh.

## Email identity, verification and password recovery

- Users open **Account Security**, enter a recovery email and current password.
  A six-digit verification challenge is sent via secure delivery. Confirm
  within ten minutes via \`/auth/recovery-email/confirm\`. Five failed
  confirmations expire the opportunity.
- Only a **verified** \`auth_recovery_emails\` address is eligible for
  \`POST /auth/forgot-password\`. Response stays generic whether the username
  exists, is unverified, or is throttled.
- A 64-hex one-time reset token is sent to that verified inbox, expires in
  30 minutes and is stored SHA-256 hashed. Reset invalidates all sessions.
  Password recovery **does not disable enrolled MFA**; login still requires
  authenticator or a backup recovery code.
- Verified email replacement and recovery of lost MFA *without* saved codes
  require a controlled, separately reviewed institute identity-proofing
  procedure. No insecure self-service bypass is provided.

## Central multi-instance throttling

The \`auth_rate_limits\` database table uses atomic INSERT/UPDATE fixed
windows by operation and salted-by-context SHA-256 IP identity keys.
Login: 30/IP/minute. Recovery/OTP enroll and confirm: 5/IP/minute.
MFA confirmation also uses 5/account/10 minutes across all nodes.
Refresh: 30/IP/minute. Failure to write/read the shared counters denies the
request (503), never downgrades to local files.

Operator must schedule retention maintenance, for example **during an
approved maintenance window** (not via public API):

\`\`\`sql
DELETE FROM auth_rate_limits WHERE updated_at < UTC_TIMESTAMP() - INTERVAL 2 DAY;
DELETE FROM auth_refresh_replays WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY;
\`\`\`

Ensure the external reverse proxy replaces X-Forwarded-For; only trusted proxy
CIDRs may supply client IP to the application. DB-based throttling protects
multiple PHP instances only when they point to the **same transactional DB**.

## Route summary

- Public: \`POST /auth/login\`, \`POST /auth/refresh\`,
  \`POST /auth/forgot-password\`, \`POST /auth/reset-password\`.
- Authenticated: \`GET /auth/mfa\`, \`POST /auth/mfa/enroll\`,
  \`POST /auth/mfa/confirm\`, \`POST /auth/mfa/disable\`,
  \`GET /auth/recovery-email\`, \`POST /auth/recovery-email/start\`,
  \`POST /auth/recovery-email/confirm\`.

All routes use the \`/api/v1\` prefix; MFA and recovery-email management
require an active authenticated account. Authenticated routes remain blocked
during mandatory initial password change except the existing documented
password-change/logout/profile exceptions.

## Verification needed before release

- Fresh schema and upgraded populated schema (including baseline/DDL checks).
- Correct TOTP with clock skew, replay and account lockout; rollback on failed
  enrollment; MFA lost-key scenario and session revocation.
- Refresh concurrency, stolen/replayed refresh, account disable, password
  change, token expiry and 7-day hard deadline.
- Verified/unverified SMTP recovery; TLS validation failure; mail outage
  behavior; proof that recovery cannot bypass MFA.
- Multi-node throttle concurrency, DB outage fail-closed behavior, trusted
  proxy identity and retention purge behavior.
- Full browser and keyboard accessibility UAT.

**No local, Docker, CI or automated tests were run in this integration.**
