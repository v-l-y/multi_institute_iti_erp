<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use Throwable;

final readonly class Transaction
{
    public function __construct(private PDO $connection) {}

    public function run(callable $work): mixed
    {
        if ($this->connection->inTransaction()) {
            throw new \LogicException('Nested transactions are not supported.');
        }
        $this->connection->beginTransaction();
        try {
            $result = $work($this->connection);
            $this->connection->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }
}
