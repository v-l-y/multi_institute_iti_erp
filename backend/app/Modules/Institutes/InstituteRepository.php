<?php
declare(strict_types=1);
namespace App\Modules\Institutes;

use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

final readonly class InstituteRepository
{
    public function __construct(private PDO $connection) {}

    public function profile(AuthContext $context): array
    {
        $context->requirePermission('institute.profile.view');
        if ($context->instituteId === null) {
            throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
        }
        $statement = $this->connection->prepare('SELECT id,name,mis_code,district_name,management_type,contact_phone,contact_email FROM institute WHERE id=?');
        $statement->execute([$context->instituteId]);
        $profile = $statement->fetch();
        if ($profile === false) {
            throw new HttpException('NOT_FOUND', 'Institute not found.', 404);
        }
        return $profile;
    }
}
