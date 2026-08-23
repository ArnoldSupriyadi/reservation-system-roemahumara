<?php

namespace App\Providers\Filament;

use App\Http\Controllers\ReservationPdfController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class CmsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('cms')
            ->path('cms')
            ->login()
            ->brandName('Roemah Umara Reservation')
            // Markup sendiri, bukan url gambar: komponen logo Filament mengganti
            // teks nama begitu sebuah logo dipasang, sehingga "Reservation" tidak
            // akan pernah terbaca di halaman login. 'auto' dipakai karena tinggi
            // dari Filament diterapkan ke seluruh blok dan akan memotong barisnya.
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('auto')
            // Filament hanya merender satu <link rel="icon">, jadi dipilih yang
            // 32px — ukuran yang dipakai tab peramban di layar biasa maupun
            // Retina. Berkasnya dibuat scripts/buat-aset-logo.php dari logo gold.
            ->favicon(asset('img/favicon-32.png'))
            ->navigationGroups([
                'Reservasi',
                'Master',
                'Pengaturan',
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            // Selesai membuat atau mengubah, kembali ke daftar — bukan berhenti di
            // halaman edit seperti bawaan Filament. Disetel di tingkat panel supaya
            // berlaku untuk semua resource sekaligus, termasuk yang dibuat nanti;
            // meng-override getRedirectUrl() satu per satu akan terlewat pada
            // resource berikutnya. Penghapusan sudah kembali ke daftar dengan
            // sendirinya, karena catatannya tidak lagi ada untuk ditampilkan.
            ->resourceCreatePageRedirect('index')
            ->resourceEditPageRedirect('index')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            /*
             * Dokumen cetak reservasi. Di dalam panel, bukan di routes/web.php,
             * supaya ikut middleware dan pengalihan login milik panel — dengan
             * middleware 'auth' biasa, tamu yang membuka URL-nya mendapat 500
             * karena Laravel mencari route bernama 'login' yang tidak ada di
             * sini. Kewenangan per baris tetap diperiksa policy di controllernya.
             */
            ->authenticatedRoutes(fn () => Route::get(
                'reservations/{reservation}/pdf',
                ReservationPdfController::class
            )->name('reservations.pdf'))
            // Tab bulan di daftar reservasi dibuat selebar tabelnya. Disuntikkan
            // sebagai <style> karena CSS Filament dibangun terpisah dan tidak
            // memuat kelas Tailwind milik app.css.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.tabs-full-width'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
