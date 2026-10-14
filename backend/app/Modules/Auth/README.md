# Auth module

`Http/` contains the API controller and authentication middleware. `Application/` owns login and session workflows. `Domain/` enforces permission and institute access. `Infrastructure/` contains password hashing and the transactional database-wide login throttle. No compatibility aliases or parallel auth master are retained.


MFA/TOTP, one-time recovery codes, rotating hashed refresh tokens, verified SMTP recovery emails and cluster-throttle configuration are documented in [AUTH_SECURITY.md](AUTH_SECURITY.md). Production activation requires the complete baseline 000 schema or a separately reviewed populated-database upgrade, verified mail, key custody and security UAT.
