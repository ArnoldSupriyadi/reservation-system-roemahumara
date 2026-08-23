<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

class LoginBrandTest extends TestCase
{
    private function html(): string
    {
        return $this->get('/cms/login')->assertOk()->getContent();
    }

    public function test_the_login_page_shows_the_logo_and_the_full_name(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('img/logo-gold.png', $html, 'Logo tidak ter-render.');
        $this->assertStringContainsString('Reservation', $html);
        $this->assertStringContainsString('Roemah Umara Reservation', $html, 'Nama lengkap tidak ada.');
    }

    public function test_the_brand_name_reaches_the_browser_tab(): void
    {
        $this->assertStringContainsString('Roemah Umara Reservation', $this->html());

        Filament::setCurrentPanel('cms');
        $this->assertSame('Roemah Umara Reservation', Filament::getBrandName());
    }

    /**
     * Bagian yang paling mudah rusak diam-diam.
     *
     * Filament membangun CSS-nya sendiri di public/css/filament, terpisah dari
     * app.css halaman publik, dan hanya memuat kelas yang dipakai view bawaannya.
     * Kelas Tailwind seperti flex-col atau h-10 TIDAK ada di sana. Memakainya
     * menghasilkan halaman yang HTML-nya benar tapi tampil tanpa gaya sama
     * sekali — logo 900px memenuhi layar — dan tidak ada satu pun test biasa
     * yang menangkapnya, karena semua assertion tentang isi HTML tetap lulus.
     */
    public function test_the_brand_is_styled_inline_not_with_tailwind_classes(): void
    {
        $brand = file_get_contents(resource_path('views/filament/brand.blade.php'));

        $this->assertStringContainsString('style="display: flex', $brand);

        foreach (['class="flex', 'class="h-10', 'class="text-'] as $terlarang) {
            $this->assertStringNotContainsString(
                $terlarang,
                $brand,
                'Kelas Tailwind tidak tersedia di CSS Filament; pakai inline style.'
            );
        }
    }

    /** Logo login harus versi ringan, bukan berkas aslinya yang 700 KB. */
    public function test_the_login_logo_is_a_web_sized_file(): void
    {
        $path = public_path('img/logo-gold.png');

        $this->assertFileExists($path);
        $this->assertLessThan(150_000, filesize($path), 'Logo login terlalu berat.');

        [$lebar] = getimagesize($path);
        $this->assertSame(900, $lebar);
    }

    /** Halaman login tetap terbuka tanpa masuk — kalau tidak, tidak ada yang bisa login. */
    public function test_the_page_is_reachable_without_signing_in(): void
    {
        $this->assertGuest();
        $this->get('/cms/login')->assertOk();
    }
}
