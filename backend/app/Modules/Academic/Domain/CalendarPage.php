<?php
declare(strict_types=1);
namespace App\Modules\Academic\Domain;

use App\Http\HttpException;

/** Existing registry cursor-validation semantics, not an academic scheduling rule. */
final class CalendarPage
{
    public static function integer(array $query, string $key, int $default, int $max): int
    {
        if (!array_key_exists($key, $query)) {
            return $default;
        }
        $raw = $query[$key];
        $value = (is_string($raw) || is_int($raw))
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>$max]])
            : false;
        if ($value === false) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return $value;
    }
}
