# Multi-Institute ITI ERP

Frozen V1.0 scope and release acceptance baseline: [Project Goal](PROJECT_GOAL_V1.md).

Local UAT setup and repeatable backend verification: [staging instructions](deployment/STAGING.md).

Persistent dummy-data demo: [demo and staff UAT guide](deployment/DEMO-UAT.md).
Execution evidence: [verification reports](deployment/reports/README.md).

```text
backend/     PHP API, configuration, database schema and tests
frontend/    Empty, reserved for future frontend work
compose.yaml PHP 8.5 + Apache and MariaDB containers
```

Backend setup and API instructions: [backend/README.md](backend/README.md).
Run backend commands from the `backend/` directory. Apache DocumentRoot must
point to `backend/public/`.

The frontend directory is intentionally empty. Git does not track empty
directories; recreate it after cloning if needed.

## Docker (local development)

Use Docker Desktop with Linux containers. Run these commands from the project
root. Copy `.env.example` to `.env` and set two different strong database passwords.
This root environment file configures Docker; `backend/.env` is for local XAMPP.
Docker injects backend database settings, so you do not need `backend/.env`.

```powershell
Copy-Item .env.example .env
# Edit .env before starting containers.
docker compose config --quiet
docker compose up --build -d --wait
```

Open [API health](http://localhost:8080/api/v1/health). Change BACKEND_PORT if
8080 is already used. Only the API is published, bound to this computer's loopback
interface; the database stays inside the Docker network. Frontend remains empty.

The backend uses the [official PHP image](https://hub.docker.com/_/php) and the
database uses the [official MariaDB image](https://hub.docker.com/_/mariadb).
Compose [waits for database health](https://docs.docker.com/compose/how-tos/startup-order/)
before starting the API. The initial schema is imported only when the data volume
is empty. Schema edits require a reviewed migration on existing databases; they
are not reapplied at every restart. Draft RBAC grants are deliberately not imported
automatically. After reviewing them, import with:

```powershell
Get-Content -Raw backend/database/seeders/rbac_seed.sql | docker compose exec -T database sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

Create the first developer using JSON credentials on stdin:
`docker compose exec -T backend php bin/create-developer.php`. Follow the
[authentication setup](backend/README.md#first-developer-account); do not put
passwords in command arguments or tracked files.

```powershell
docker compose exec -T backend php tests/backend.php
docker compose logs --tail=100 backend database
docker compose down
```

Database data survives `docker compose down` in a named volume. Avoid `down -v`
unless intentionally deleting this environment's database. Backend code is baked
into the image; rebuild after source changes with `docker compose up --build -d`.
The application image uses the built-in autoloader; Composer is not required.
Auth database integration tests need a disposable server and privileges to create
their temporary database; the app database user intentionally lacks those privileges.

This setup is for local development. PHP 8.5 image build and foundation HTTP
tests are verified; use the isolated suite below after changes.
Production deployment needs HTTPS,
managed secrets, backups and the outstanding business/authorization workflows.

## Isolated foundation tests

From the project root, run the full PHP 8.5 suite on a separate database:

```powershell
docker compose -p iti-erp-tests -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from tests
docker compose -p iti-erp-tests -f compose.test.yaml down
node --test backend/tests/schema-contracts.test.mjs
```

This test stack uses a separate network and temporary database storage, with no
host ports or application data volumes. Its credentials are test-only. The suite
covers authentication, request validation, five-role institute-profile access,
tenant isolation, transaction rollback and JSON/error headers. GitHub Actions
has no push or pull-request triggers. Run the workflow manually from the Actions
tab using Run workflow when needed; local Docker tests also run only on request.

## Manual production deployment and recovery

See [deployment/PRODUCTION.md](deployment/PRODUCTION.md) for the opt-in HTTPS perimeter, database readiness, encrypted backups and isolated restore drill. These configurations do **not** activate production, deployment, CI testing or backups automatically.
