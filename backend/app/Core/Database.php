<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private readonly array $config) {}

    public function connection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $this->connection = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $this->config['host'], $this->config['port'], $this->config['database']),
            $this->config['username'],
            $this->config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $this->connection->exec("SET time_zone = '+00:00'");
        return $this->connection;
    }
}
