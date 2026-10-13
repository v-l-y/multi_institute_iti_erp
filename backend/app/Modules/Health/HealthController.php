<?php
declare(strict_types=1);

namespace App\Modules\Health;

use App\Http\Response;

final class HealthController
{
    public function __invoke(): Response
    {
        return new Response(['success' => true, 'data' => ['service' => 'iti-erp-api', 'status' => 'ok']]);
    }
}
