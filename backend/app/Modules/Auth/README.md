# Auth Module

- `Http/` — controllers and access middleware.
- `Application/` — authentication, sessions and password-change workflows.
- `Domain/` — permission and institute-scoped authorization context.
- `Infrastructure/` — password hashing and worker-local login throttle.

Root-level PHP aliases are temporary compatibility bridges during a namespace migration. They will be removed after dependent modules/tests use the new paths.
