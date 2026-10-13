<?php
declare(strict_types=1);
namespace App\Modules\Auth\Infrastructure;

use App\Http\HttpException;

// Atomic, shared across PHP workers on this single backend instance.
final readonly class LoginThrottle
{
    public function __construct(private string $directory, private int $limit = 30, private int $windowSeconds = 60) {}

    public function check(?string $ip): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new HttpException('AUTH_UNAVAILABLE', 'Authentication temporarily unavailable.', 503);
        }

        $file = @fopen($this->directory . '/' . hash('sha256', $ip ?? 'unknown') . '.json', 'c+');
        if ($file === false) {
            throw new HttpException('AUTH_UNAVAILABLE', 'Authentication temporarily unavailable.', 503);
        }

        try {
            if (!flock($file, LOCK_EX)) {
                throw new HttpException('AUTH_UNAVAILABLE', 'Authentication temporarily unavailable.', 503);
            }

            $now = time();
            $state = json_decode(stream_get_contents($file), true);
            if (!is_array($state) || ($state['until'] ?? 0) <= $now) {
                $state = ['until' => $now + $this->windowSeconds, 'count' => 0];
            }

            if (($state['count'] ?? 0) >= $this->limit) {
                throw new HttpException('RATE_LIMITED', 'Too many login requests. Try again shortly.', 429);
            }

            $state['count'] = ($state['count'] ?? 0) + 1;
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, json_encode($state, JSON_THROW_ON_ERROR)) === false || !fflush($file)) {
                throw new HttpException('AUTH_UNAVAILABLE', 'Authentication temporarily unavailable.', 503);
            }
        } catch (\JsonException | \Throwable $e) {
            throw new HttpException('AUTH_UNAVAILABLE', 'Authentication temporarily unavailable.', 503);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
