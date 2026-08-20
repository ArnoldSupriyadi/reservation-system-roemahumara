<?php

namespace App\Support;

class ReservationInput
{
    /**
     * Bersihkan data form sebelum disimpan.
     * Kunci yang tidak dikenal dibiarkan apa adanya.
     */
    public static function normalize(array $data): array
    {
        foreach (['guest_name', 'company', 'remark'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = self::text($data[$key]);
            }
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = self::phone($data['phone']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = self::email($data['email']);
        }

        if (array_key_exists('status', $data)) {
            $data['status'] = self::text($data['status']);
        }

        if (array_key_exists('start_time', $data)) {
            $split = TimeInput::split($data['start_time']);
            $explicitEnd = TimeInput::normalize($data['end_time'] ?? null);

            $data['start_time'] = $split['start'];
            $data['end_time'] = $explicitEnd ?? $split['end'];
        }

        return $data;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function phone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    private static function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return ($value === '' || $value === 'na' || $value === '-') ? null : $value;
    }
}
