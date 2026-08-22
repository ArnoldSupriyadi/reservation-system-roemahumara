<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Favicon gampang lenyap tanpa ada yang melapor — tab tetap terbuka, halaman
 * tetap jalan, hanya ikonnya berganti kertas kosong. Test ini memeriksa
 * keduanya: tautannya ter-render, dan berkas yang ditunjuk benar-benar ada.
 */
class FaviconTest extends TestCase
{
    /** @return array<int, string> */
    private function berkas(): array
    {
        return [
            public_path('favicon.ico'),
            public_path('img/favicon-16.png'),
            public_path('img/favicon-32.png'),
            public_path('img/favicon-180.png'),
        ];
    }

    public function test_the_public_page_links_every_icon_size(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('favicon.ico', $html);
        $this->assertStringContainsString('img/favicon-32.png', $html);
        $this->assertStringContainsString('img/favicon-16.png', $html);
        $this->assertStringContainsString('apple-touch-icon', $html);
    }

    public function test_the_cms_panel_declares_a_favicon(): void
    {
        Filament::setCurrentPanel('cms');

        $this->assertStringContainsString(
            'img/favicon-32.png',
            (string) Filament::getFavicon(),
            'Panel cms tidak menyetel favicon.'
        );
    }

    /**
     * Tautan yang menunjuk berkas hilang menghasilkan 404 diam-diam: peramban
     * cuma menampilkan ikon kosong, tidak ada error di mana pun.
     */
    public function test_every_linked_file_actually_exists(): void
    {
        foreach ($this->berkas() as $path) {
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path), basename($path).' kosong.');
        }
    }

    /**
     * favicon.ico bawaan Laravel berukuran 0 byte dan tetap "ada". Memeriksa
     * keberadaannya saja tidak cukup — yang dijaga di sini isinya benar-benar
     * ikon berisi dua ukuran.
     */
    public function test_the_ico_is_a_real_icon_with_two_sizes(): void
    {
        $ico = file_get_contents(public_path('favicon.ico'));

        // Header ICO: reserved 0, type 1, lalu jumlah gambar.
        $header = unpack('vreserved/vtype/vcount', $ico);

        $this->assertSame(0, $header['reserved']);
        $this->assertSame(1, $header['type'], 'Bukan berkas ICO.');
        $this->assertSame(2, $header['count'], 'ICO harus memuat ukuran 16 dan 32.');
    }

    public function test_the_png_icons_are_square_and_the_right_size(): void
    {
        foreach ([16, 32, 180] as $ukuran) {
            [$lebar, $tinggi] = getimagesize(public_path("img/favicon-{$ukuran}.png"));

            $this->assertSame($ukuran, $lebar);
            $this->assertSame($ukuran, $tinggi, "favicon-{$ukuran}.png tidak persegi.");
        }
    }
}
