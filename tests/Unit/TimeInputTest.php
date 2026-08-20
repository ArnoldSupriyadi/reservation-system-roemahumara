<?php

namespace Tests\Unit;

use App\Support\TimeInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeInputTest extends TestCase
{
    public static function normalizeCases(): array
    {
        return [
            'jam saja' => ['11', '11:00'],
            'titik' => ['11.00', '11:00'],
            'titik dua' => ['11:00', '11:00'],
            'dengan detik' => ['12:00:00', '12:00'],
            'menit bukan nol' => ['11.30', '11:30'],
            'satu digit jam' => ['9.00', '09:00'],
            'spasi di ujung' => ['  14.00  ', '14:00'],
            'tengah malam' => ['0.00', '00:00'],
            'jam terakhir' => ['23.59', '23:59'],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function test_normalize_accepts_common_formats(string $input, string $expected): void
    {
        $this->assertSame($expected, TimeInput::normalize($input));
    }

    public static function invalidCases(): array
    {
        return [
            'kosong' => [''],
            'null' => [null],
            'hanya spasi' => ['   '],
            'jam di luar rentang' => ['25.00'],
            'menit di luar rentang' => ['11.75'],
            'huruf' => ['siang'],
            'strip saja' => ['-'],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_normalize_returns_null_for_invalid_input(?string $input): void
    {
        $this->assertNull(TimeInput::normalize($input));
    }

    public function test_split_returns_start_only_for_single_time(): void
    {
        $this->assertSame(['start' => '11:00', 'end' => null], TimeInput::split('11.00'));
    }

    public function test_split_separates_a_range(): void
    {
        $this->assertSame(['start' => '12:00', 'end' => '15:00'], TimeInput::split('12.00-15.00'));
    }

    public function test_split_tolerates_spaces_around_the_dash(): void
    {
        $this->assertSame(['start' => '12:00', 'end' => '15:00'], TimeInput::split('12.00 - 15.00'));
    }

    public function test_split_handles_en_dash(): void
    {
        $this->assertSame(['start' => '12:00', 'end' => '15:00'], TimeInput::split('12.00 – 15.00'));
    }

    public function test_split_returns_nulls_for_unparseable_input(): void
    {
        $this->assertSame(['start' => null, 'end' => null], TimeInput::split('NA'));
    }

    public function test_split_ignores_a_third_segment(): void
    {
        $this->assertSame(['start' => '12:00', 'end' => '15:00'], TimeInput::split('12.00-15.00-18.00'));
    }
}
