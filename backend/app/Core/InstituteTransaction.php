<?php
declare(strict_types=1);
namespace App\Core;

use App\Http\HttpException;
use App\Modules\Auth\AuthContext;
use PDO;

/** Serialize institute operations before reading balances/counters, and audit in the same transaction. */
final readonly class InstituteTransaction
{
    public function __construct(private PDO $db) {}

    public function run(AuthContext $actor, int $instituteId, string $permission, callable $work): mixed
    {
        $actor->requireInstitute($instituteId);
        $actor->requirePermission($permission);
        return (new Transaction($this->db))->run(function () use ($actor, $instituteId, $work) {
            $lock = $this->db->prepare('SELECT id FROM institute WHERE id=? FOR UPDATE');
            $lock->execute([$instituteId]);
            if ($lock->fetchColumn() === false) {
                throw new HttpException('NOT_FOUND', 'Institute not found.', 404);
            }
            $user = $this->db->prepare('SELECT id FROM auth_users WHERE id=? AND institute_id=? AND status=\'active\' FOR UPDATE');
            $user->execute([$actor->userId, $instituteId]);
            if ($user->fetchColumn() === false) {
                throw new HttpException('FORBIDDEN', 'Active institute account required.', 403);
            }
            return $work($this->db);
        });
    }

    public function audit(AuthContext $actor, int $instituteId, string $action, string $table, int $id, array $data): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Operational audit requires a transaction.');
        }
        $statement = $this->db->prepare('INSERT INTO institute_activity_log (institute_id,actor_user_id,action_code,entity_table,entity_id,event_data_json) VALUES (?,?,?,?,?,?)');
        $statement->execute([$instituteId, $actor->userId, $action, $table, $id, json_encode($data, JSON_THROW_ON_ERROR)]);
    }
}
