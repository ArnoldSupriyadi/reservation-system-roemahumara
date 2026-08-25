<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tautan kalender publik di sidebar CMS.
 *
 * Ada supaya staf tidak perlu mengetik URL-nya sendiri saat ingin memeriksa
 * bagaimana sebuah reservasi terbaca dari luar.
 */
class PublicCalendarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $staf = User::factory()->create();
        $staf->assignRole('staff');
        $this->actingAs($staf);

        Filament::setCurrentPanel('cms');
    }

    private function tautan(): NavigationItem
    {
        $items = collect(Filament::getPanel('cms')->getNavigationItems())
            ->filter(fn ($item) => $item->getLabel() === 'Kalender publik');

        $this->assertCount(1, $items, 'Tautan kalender publik harus ada tepat satu di sidebar.');

        return $items->first();
    }

    public function test_the_sidebar_links_to_the_public_calendar(): void
    {
        $this->assertSame(route('public.calendar'), $this->tautan()->getUrl());
    }

    /**
     * Tab baru, dan itu disengaja: membukanya di tab yang sama akan membuang
     * halaman CMS yang sedang dikerjakan, termasuk formulir yang belum tersimpan.
     */
    public function test_it_opens_in_a_new_tab(): void
    {
        $this->assertTrue($this->tautan()->shouldOpenUrlInNewTab());
    }

    public function test_it_sits_in_the_reservation_group(): void
    {
        $this->assertSame('Reservasi', $this->tautan()->getGroup());
    }

    /**
     * URL-nya dibangun saat sidebar dirender, bukan saat panel didaftarkan.
     *
     * Kalau dibangun di luar closure, route() dipanggil sewaktu provider boot —
     * sebelum APP_URL sempat berlaku pada perintah artisan, sehingga tautannya
     * bisa menunjuk ke localhost di server produksi.
     */
    public function test_the_url_follows_app_url(): void
    {
        config(['app.url' => 'https://reservation.roemahumara.com']);
        url()->forceRootUrl(config('app.url'));
        // forceRootUrl hanya mengganti host; skemanya punya penyetel sendiri.
        url()->forceScheme('https');

        $this->assertStringStartsWith('https://reservation.roemahumara.com', $this->tautan()->getUrl());
    }
}
