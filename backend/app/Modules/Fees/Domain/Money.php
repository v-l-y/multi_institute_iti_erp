<?php
declare(strict_types=1);
namespace App\Modules\Fees\Domain;

use App\Http\HttpException;

final class Money
{
    public static function cents(string $amount, bool $positive = false): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D', $amount, $parts)) {
            throw new HttpException('INVALID_AMOUNT', 'Amount must be a decimal with at most two fractional digits.', 422);
        }
        $value = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($positive && $value === 0) {
            throw new HttpException('INVALID_AMOUNT', 'Amount must be positive.', 422);
        }
        return $value;
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0) {
            throw new \LogicException('Negative financial amount.');
        }
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
