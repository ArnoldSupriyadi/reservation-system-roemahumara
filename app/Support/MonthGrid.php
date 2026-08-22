<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Aritmetika grid kalender bulanan, dipakai bersama oleh halaman kalender staf
 * dan halaman kalender publik.
 */
class MonthGrid
{
    /**
     * Sel kalender dengan minggu dimulai hari Senin.
     * Sel kosong di awal bulan bernilai null.
     *
     * @return array<int, array{day: ?int, iso: ?string}>
     */
    public static function cells(string $month): array
    {
        $first = self::firstDay($month);

        // dayOfWeek: 0 = Minggu. Geser agar Senin menjadi 0.
        $lead = ($first->dayOfWeek + 6) % 7;

        $cells = array_fill(0, $lead, ['day' => null, 'iso' => null]);

        for ($day = 1; $day <= $first->daysInMonth; $day++) {
            $cells[] = [
                'day' => $day,
                'iso' => sprintf('%s-%02d', $month, $day),
            ];
        }

        return $cells;
    }

    public static function label(string $month): string
    {
        return self::firstDay($month)->translatedFormat('F Y');
    }

    public static function shift(string $month, int $delta): string
    {
        return self::firstDay($month)->addMonths($delta)->format('Y-m');
    }

    /**
     * Kembalikan Y-m yang sah. Masukan kosong atau tidak sah jatuh ke bulan berjalan,
     * sehingga halaman publik tidak bisa dijatuhkan lewat query string.
     */
    public static function normalize(?string $month): string
    {
        if ($month === null || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return Carbon::now()->format('Y-m');
        }

        return $month;
    }

    private static function firstDay(string $month): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
    }
}
