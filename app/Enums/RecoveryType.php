<?php

namespace App\Enums;

class RecoveryType
{
    public const DAMAGE = 'damage';
    public const LOSS = 'loss';
    public const FINE = 'fine';
    public const ADVANCE = 'advance';
    public const LOANS = 'loans';

    public static function values(): array
    {
        return [
            self::DAMAGE,
            self::LOSS,
            self::FINE,
            self::ADVANCE,
            self::LOANS,
        ];
    }
}
