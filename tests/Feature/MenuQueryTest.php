<?php

namespace Tests\Feature;

use App\Filament\Resources\Menus\Pages\ManageMenus;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Menjaga potongan-potongan kueri menu tetap aman dipadukan.
 *
 * Sampai 2026-08-23 inMenuOrder() melakukan join ke menu_categories. Kedua
 * tabel sama-sama punya kolom is_active dan name, sehingga setiap kondisi yang
 * tidak menyebut nama tabel ditolak MySQL dengan "Column ... is ambiguous".
 *
 * Yang membuatnya lolos sampai ke produksi: tiap potongan kueri baik-baik saja
 * sendirian. Ledakannya hanya terjadi ketika scope ini dipadukan dengan
 * ->active() atau dengan pencarian tabel Filament — dan tidak ada test yang
 * memadukan keduanya.
 */
class MenuQueryTest extends TestCase
{
    use RefreshDatabase;

    private function menu(string $nama, string $kategori = 'Aneka Nasi'): Menu
    {
        return Menu::create([
            'name' => $nama,
            'menu_category_id' => MenuCategory::firstOrCreate(['name' => $kategori])->id,
        ]);
    }

    /**
     * Inilah kueri yang meledak di halaman Edit reservasi pada 2026-08-23:
     * SQLSTATE[23000] 1052 Column 'is_active' in where clause is ambiguous.
     */
    public function test_active_can_be_combined_with_the_category_join(): void
    {
        $this->menu('Nasi Umara');

        $hasil = Menu::query()->active()->inMenuOrder()->get();

        $this->assertCount(1, $hasil);
    }

    /** Urutan pemanggilan tidak boleh berpengaruh. */
    public function test_the_order_of_the_scopes_does_not_matter(): void
    {
        $this->menu('Nasi Umara');

        $this->assertCount(1, Menu::query()->inMenuOrder()->active()->get());
    }

    /** Menu tidak aktif tetap tersaring, bukan sekadar tidak melempar galat. */
    public function test_it_still_filters_out_inactive_menus(): void
    {
        $this->menu('Nasi Umara');
        $mati = $this->menu('Menu Lama');
        $mati->is_active = false;
        $mati->save();

        $nama = Menu::query()->active()->inMenuOrder()->pluck('name');

        $this->assertContains('Nasi Umara', $nama);
        $this->assertNotContains('Menu Lama', $nama);
    }

    /**
     * Kategori yang dinonaktifkan tidak boleh ikut menyaring menunya — dua
     * kolom is_active yang berbeda arti, dan menyamakannya akan menyembunyikan
     * menu aktif hanya karena kategorinya dinonaktifkan.
     */
    public function test_an_inactive_category_does_not_hide_its_active_menus(): void
    {
        $menu = $this->menu('Nasi Umara');
        $menu->category->update(['is_active' => false]);

        $this->assertCount(1, Menu::query()->active()->inMenuOrder()->get());
    }

    /**
     * Halaman Edit reservasi yang SUDAH punya menu — inilah yang meledak.
     *
     * Reservasi tanpa menu tidak cukup: repeaternya kosong, sehingga daftar
     * pilihan menu tidak pernah dievaluasi dan kueri yang bermasalah tidak
     * pernah dijalankan. Versi pertama test ini memakai reservasi kosong dan
     * lolos bahkan sebelum bug diperbaiki.
     */
    public function test_the_edit_page_opens_for_a_reservation_that_has_menus(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(MenuSeeder::class);
        Filament::setCurrentPanel('cms');

        $admin = User::factory()->admin()->create();
        $r = Reservation::factory()->create([
            'pic_id' => $admin->id,
            'created_by' => $admin->id,
        ]);
        $r->menus()->sync([
            Menu::where('name', 'Nasi Umara')->value('id') => ['pax' => 30, 'remark' => null],
        ]);

        $this->actingAs($admin)
            ->get("/cms/reservations/{$r->id}/edit")
            ->assertOk()
            ->assertSee('Nasi Umara');

        // Repeaternya juga harus bertahan saat Livewire memperbarui komponen —
        // di situlah galat aslinya muncul, pada POST /livewire/update.
        Livewire::actingAs($admin)
            ->test(EditReservation::class, ['record' => $r->getKey()])
            ->assertFormSet(fn (array $state) => count($state['menu_items']) === 1)
            ->fillForm(['pax' => 41])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(41, $r->fresh()->pax);
        $this->assertSame(1, $r->fresh()->menus()->count(), 'Menu tidak boleh hilang saat menyimpan.');
    }

    /** Halaman Create juga membangun daftar menu yang sama. */
    public function test_the_reservation_create_page_opens(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(MenuSeeder::class);
        Filament::setCurrentPanel('cms');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/cms/reservations/create')
            ->assertOk();
    }

    /**
     * Pencarian di daftar menu berjalan di atas join yang sama, dan kedua tabel
     * sama-sama punya kolom name.
     */
    public function test_searching_the_menu_list_does_not_break_on_the_join(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(MenuSeeder::class);
        Filament::setCurrentPanel('cms');

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageMenus::class)
            ->searchTable('Nasi Umara')
            ->assertCanSeeTableRecords(Menu::where('name', 'Nasi Umara')->get());
    }
}
