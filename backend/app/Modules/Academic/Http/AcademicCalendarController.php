<?php
declare(strict_types=1);
namespace App\Modules\Academic\Http;

use App\Core\Database;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Academic\Application\AcademicCalendarService;
use App\Modules\Auth\Domain\AuthContext;

/** Read-only calendar registry; does not infer government approval. */
final readonly class AcademicCalendarController
{
    public function __construct(private Database $database) {}

    public function index(Request $request, AuthContext $actor): Response
    {
        $service = new AcademicCalendarService($this->database);
        return new Response(['success' => true, 'data' => $service->page($request->query, $actor)]);
    }
}
