<?php
declare(strict_types=1);
namespace App\Modules\Auth\Infrastructure;

use App\Http\HttpException;
use PDO;
use Throwable;

/** Atomic fixed-window rate limit shared across all API workers/instances. */
final readonly class DatabaseThrottle
{
    public function __construct(private PDO $db) {}

    public function check(string $operation, ?string $ip, int $limit, int $window = 60, string $identity = ''): void
    {
        if (!preg_match('/^[a-z_]{1,30}$/D', $operation) || $limit < 1 || $limit > 65534 || $window < 10) {
            throw new \InvalidArgumentException('Invalid throttle policy.');
        }
        // Missing client IP is a common shared key: fail-closed, not unlimited.
        $key=hash('sha256',($ip ?? 'unknown').'|'.$identity);
        $bucket=intdiv(time(), $window)*$window;
        try {
            $q=$this->db->prepare('INSERT INTO auth_rate_limits
                (operation_code,identity_hash,bucket_start,attempts) VALUES (?,?,?,1)
                ON DUPLICATE KEY UPDATE attempts=LEAST(65535,attempts+1)');
            $q->execute([$operation,$key,$bucket]);
            $q=$this->db->prepare('SELECT attempts FROM auth_rate_limits
                WHERE operation_code=? AND identity_hash=? AND bucket_start=?');
            $q->execute([$operation,$key,$bucket]);
            $count=$q->fetchColumn();
            if ($count === false) throw new \RuntimeException('Throttle counter unavailable.');
        } catch (Throwable $error) {
            // Never downgrade to an instance-local throttle after a DB failure.
            throw new HttpException('AUTH_UNAVAILABLE','Authentication protection unavailable.',503);
        }
        if ((int)$count > $limit) {
            throw new HttpException('RATE_LIMITED','Too many attempts. Try again later.',429);
        }
    }
}
