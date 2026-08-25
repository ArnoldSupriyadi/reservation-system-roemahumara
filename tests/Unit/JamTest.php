<?php

namespace Tests\Unit;

use App\Support\Jam;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JamTest extends TestCase
{
    /**
     * Semua ejaan yang diterima form berakhir pada nilai yang sama.
     *
     * @param  string  $ejaan  bentuk yang mungkin diketik atau datang dari MySQL
     */
    #[DataProvider('ejaanJamSebelas')]
    public function test_every_spelling_of_eleven_becomes_the_same_value(string $ejaan): void
    {
        $jam = Jam::dari($ejaan);

        $this->assertSame(11, $jam->jam);
        $this->assertSame(0, $jam->menit);
        $this->assertSame('11:00', (string) $jam);
    }

    /** @return array<string, array{string}> */
    public static function ejaanJamSebelas(): array
    {
        return [
            'jam saja' => ['11'],
            'titik' => ['11.00'],
            'titik dua' => ['11:00'],
            'dari MySQL' => ['11:00:00'],
            'tanpa pemisah' => ['1100'],
            'berspasi' => ['  11:00  '],
        ];
    }

    /**
     * Yang tidak bisa dibaca mengembalikan null, tidak melempar.
     *
     * Penolakan input adalah tugas validasi form. Melempar di sini akan membuat
     * halaman daftar tumbang gara-gara satu baris lama yang datanya aneh.
     *
     * @param  mixed  $sampah  nilai yang bukan jam
     */
    #[DataProvider('bukanJam')]
    public function test_unreadable_input_becomes_null(mixed $sampah): void
    {
        $this->assertNull(Jam::dari($sampah));
    }

    /** @return array<string, array{mixed}> */
    public static function bukanJam(): array
    {
        return [
            'null' => [null],
            'kosong' => [''],
            'spasi' => ['   '],
            'kata' => ['besok'],
            'jam mustahil' => ['25:00'],
            'menit mustahil' => ['11:75'],
            'larik' => [['11:00']],
            'objek lain' => [new \stdClass],
        ];
    }

    public function test_it_accepts_itself(): void
    {
        $jam = Jam::dari('09:30');

        $this->assertSame($jam, Jam::dari($jam), 'Jam yang sudah jadi dikembalikan apa adanya.');
    }

    public function test_minutes_since_midnight(): void
    {
        $this->assertSame(0, Jam::dari('00:00')->menitSejakTengahMalam());
        $this->assertSame(690, Jam::dari('11:30')->menitSejakTengahMalam());
        $this->assertSame(1439, Jam::dari('23:59')->menitSejakTengahMalam());
    }

    /**
     * Dua bentuk, dua alasan.
     *
     * H:i dilihat manusia dan mengisi kotak isian jam. H:i:s masuk ke
     * activity_log, sebangun dengan entri yang tercatat sebelum kelas ini ada.
     */
    public function test_the_two_shapes_are_deliberate(): void
    {
        $jam = Jam::dari('11:00');

        $this->assertSame('11:00', (string) $jam, 'Yang dilihat manusia.');
        $this->assertSame('11:00:00', $jam->jsonSerialize(), 'Yang masuk ke activity_log.');
        $this->assertSame('"11:00:00"', json_encode($jam), 'json_encode ikut jsonSerialize().');
    }

    public function test_an_unknown_format_is_refused_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Jam::dari('11:00')->format('d M Y');
    }

    public function test_comparison(): void
    {
        $pagi = Jam::dari('09:00');
        $siang = Jam::dari('13:00');

        $this->assertTrue($pagi->sebelum($siang));
        $this->assertFalse($siang->sebelum($pagi));
        $this->assertTrue($pagi->sama(Jam::dari('9')));
        $this->assertFalse($pagi->sama(null));
    }
}
