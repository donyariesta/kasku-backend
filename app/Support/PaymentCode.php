<?php

namespace App\Support;

final class PaymentCode
{
    public const MONTHLY_PAYMENT = 1;
    public const DONATION = 2;
    public const COLLECTIVE_PAYMENT = 3;
    public const SPONSORSHIP = 4;

    public static function isValid(int $code): bool
    {
        return in_array($code, [self::MONTHLY_PAYMENT, self::DONATION, self::COLLECTIVE_PAYMENT], true);
    }

    public static function getOptions(): array
    {
        return [
            self::MONTHLY_PAYMENT => 'Monthly Payment',
            self::DONATION => 'Donation',
            self::SPONSORSHIP => 'Sponsorship',
            self::COLLECTIVE_PAYMENT => 'Collective Payment',
        ];
    }

    public static function getName(int $code): string
    {
        return self::getOptions()[$code] ?? 'Unknown';
    }
}
