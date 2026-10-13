# Academic module

The existing `GET /api/v1/academic/calendars` endpoint is implemented through:

- `Http/AcademicCalendarController.php` — request/response adapter.
- `Application/AcademicCalendarService.php` — unchanged calendar permission and institute-context checks.
- `Domain/CalendarPage.php` — pagination input validation (not a regulatory rule).
- `Infrastructure/AcademicCalendarRepository.php` — existing institute-filtered admission-intake and official calendar query.

No synthetic academic calendar or syllabus data is created. Official source status is returned as stored.
