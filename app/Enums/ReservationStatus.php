<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Tentative => 'TENTATIVE',
            self::Confirmed => 'CONFIRMED',
        };
    }
}
