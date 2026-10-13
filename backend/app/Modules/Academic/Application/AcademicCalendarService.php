<?php
declare(strict_types=1);
namespace App\Modules\Academic\Application;

use App\Http\HttpException;
use App\Modules\Academic\Domain\CalendarPage;
use App\Modules\Academic\Infrastructure\AcademicCalendarRepository;
use App\Modules\Auth\Domain\AuthContext;

final readonly class AcademicCalendarService
{
    public function __construct(private AcademicCalendarRepository $repository) {}

    public function page(array $query, AuthContext $actor): array
    {
        $actor->requirePermission('calendar.view');
        $institute = $actor->instituteId
            ?? throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
        $size = CalendarPage::integer($query, 'page_size', 50, 100);
        $after = CalendarPage::integer($query, 'after_id', 0, PHP_INT_MAX);
        return $this->repository->page($institute, $after, $size);
    }
}
