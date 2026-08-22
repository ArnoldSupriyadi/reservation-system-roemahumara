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

    /**
     * Istilah untuk halaman publik. CONFIRMED dan TENTATIVE adalah kosakata
     * internal yang tidak perlu dibawa ke luar.
     */
    public function publicLabel(): string
    {
        return match ($this) {
            self::Tentative => 'Sedang dijajaki',
            self::Confirmed => 'Terisi',
        };
    }
}
