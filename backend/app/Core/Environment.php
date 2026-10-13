<?php
declare(strict_types=1);

namespace App\Core;

final class Environment
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new \RuntimeException('Invalid environment key.');
            }
            if (getenv($key) === false) {
                $value = trim($value);
                if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[0] === $value[strlen($value) - 1]) {
                    $value = substr($value, 1, -1);
                }
                putenv($key . '=' . $value);
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
