<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    /**
     * Dengan UTC, riwayat perubahan menampilkan jam tujuh jam lebih lambat
     * daripada jam dinding pengguna, dan tidak ada yang menandainya sebagai
     * kesalahan.
     */
    public function test_the_application_runs_on_jakarta_time(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));

        $this->assertSame(
            Carbon::now('Asia/Jakarta')->format('Y-m-d H:i'),
            now()->format('Y-m-d H:i'),
            'now() harus sama dengan jam dinding WIB.'
        );
    }

    public function test_month_names_are_rendered_in_indonesian(): void
    {
        $this->assertSame('id', config('app.locale'));

        $this->assertSame(
            'Agustus 2026',
            Carbon::parse('2026-08-01')->translatedFormat('F Y')
        );
    }
}
