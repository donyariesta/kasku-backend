<?php

namespace App\Support;

final class PaymentCode
{
    public const MONTHLY_PAYMENT = 1;

    public const DONATION = 2;

    public const COLLECTIVE_PAYMENT = 3;

    public static function isValid(int $code): bool
    {
        return in_array($code, [self::MONTHLY_PAYMENT, self::DONATION, self::COLLECTIVE_PAYMENT], true);
    }
}
