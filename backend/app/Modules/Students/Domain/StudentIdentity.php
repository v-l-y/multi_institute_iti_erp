<?php
declare(strict_types=1);
namespace App\Modules\Students\Domain;

use DateTimeImmutable;

final class StudentIdentity
{
    public static function validIdentityModeAndBirthDate(string $mode, string $birth): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
        return in_array($mode, ['aadhaar', 'non_aadhaar'], true)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $birth) === 1
            && $date !== false && $date->format('Y-m-d') === $birth;
    }
}
