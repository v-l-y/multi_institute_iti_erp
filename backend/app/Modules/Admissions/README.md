# Admissions Module

- `Http/` — institute-scoped admissions registry controller.
- `Application/` — draft, review, activation/cancellation and trainee export services.
- `Domain/` — pure trainee upload validation based on reviewed profile data.
- `Infrastructure/` — reserved for extracted adapters; no production code has been moved here yet.

Routes remain in `backend/routes/api.php`, and shared utilities remain in `backend/app/Core/` and `backend/app/Http/`. This refactor does not change endpoints, permissions, SQL or official-source rules.
