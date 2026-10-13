<?php
declare(strict_types=1);
namespace App\Modules\Staff\Domain;

use DateTimeImmutable;

final class StaffRecord
{
    public static function validCategoryAndJoiningDate(string $category, string $joinedOn): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $joinedOn);
        return in_array($category, ['teaching', 'non_teaching'], true)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $joinedOn) === 1
            && $date !== false && $date->format('Y-m-d') === $joinedOn;
    }
}
