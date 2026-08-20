<?php

namespace App\Support;

class TimeInput
{
    /**
     * Ubah berbagai gaya penulisan jam menjadi format H:i.
     * Mengembalikan null jika input tidak bisa diparse.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // 11 | 11.00 | 11:00 | 11:00:00 | 1130
        if (! preg_match('/^(\d{1,2})(?:[.:]?(\d{2}))?(?::\d{2})?$/', $value, $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = isset($m[2]) ? (int) $m[2] : 0;

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Pecah input yang mungkin berupa rentang menjadi start dan end.
     *
     * @return array{start: ?string, end: ?string}
     */
    public static function split(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return ['start' => null, 'end' => null];
        }

        // Terima tanda hubung biasa maupun en dash.
        $parts = preg_split('/\s*[-–]\s*/u', trim($value));

        return [
            'start' => self::normalize($parts[0] ?? null),
            'end' => self::normalize($parts[1] ?? null),
        ];
    }
}
