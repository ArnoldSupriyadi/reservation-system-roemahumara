<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\EventType;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rencana melewatkan dua baris ini. Tanpa seeder, ->admin() melempar
        // RoleDoesNotExist sebelum test sempat berjalan.
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    public function test_staff_cannot_open_master_pages(): void
    {
        $this->actingAs($this->staff())
            ->get('/cms/areas')
            ->assertForbidden();
    }

    public function test_admin_can_open_master_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/cms/areas')->assertOk();
        $this->actingAs($admin)->get('/cms/event-types')->assertOk();
        $this->actingAs($admin)->get('/cms/menus')->assertOk();
        $this->actingAs($admin)->get('/cms/menu-categories')->assertOk();
    }

    public function test_staff_cannot_open_user_management(): void
    {
        $this->actingAs($this->staff())
            ->get('/cms/users')
            ->assertForbidden();
    }

    public function test_admin_can_open_user_management(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/cms/users')
            ->assertOk();
    }

    public function test_area_in_use_cannot_be_deleted(): void
    {
        $area = Area::create(['name' => 'VIP 1']);
        Reservation::factory()->create(['area_id' => $area->id]);

        $this->expectException(QueryException::class);

        $area->delete();
    }

    /**
     * Atribut tambahan disertakan karena tiap master punya kolom wajibnya
     * sendiri — Menu butuh kategori sejak menu style diganti daftar hidangan.
     *
     * @return array<string, array{0: string, 1: class-string, 2: array<string, string>}>
     */
    public static function masterPages(): array
    {
        return [
            'area' => ['/cms/areas', Area::class, []],
            'jenis acara' => ['/cms/event-types', EventType::class, []],
            'menu' => ['/cms/menus', Menu::class, []],
            'kategori menu' => ['/cms/menu-categories', MenuCategory::class, []],
        ];
    }

    /**
     * Nomor urut baris tampil di ketiga daftar master.
     *
     * Diperiksa lewat HTML yang benar-benar ter-render karena rowIndex()
     * mengambil nilainya dari rowLoop yang hanya ada saat Blade merender baris.
     * Kalau kolomnya berhenti bekerja, kolomnya tetap muncul dan hanya selnya
     * yang kosong — memeriksa keberadaan kolom tidak akan menangkap itu.
     */
    #[DataProvider('masterPages')]
    public function test_master_lists_are_numbered(string $url, string $model, array $tambahan): void
    {
        /*
         * Menu butuh kategori yang benar-benar ada; relasinya restrictOnDelete.
         *
         * Id-nya diambil dari baris yang baru dibuat, TIDAK dipatok 1.
         * AUTO_INCREMENT MySQL tidak ikut di-rollback antar test, jadi id 1
         * hanya benar saat berkas ini dijalankan sendirian — dalam suite penuh
         * angkanya sudah berjalan dan menunya gagal tersimpan diam-diam.
         *
         * Kategorinya dibuat HANYA untuk daftar menu; kalau ikut dibuat saat
         * menguji daftar kategori, ia jadi baris keempat.
         */
        if ($model === Menu::class) {
            $tambahan['menu_category_id'] = MenuCategory::create(['name' => 'Hidangan Pembuka'])->id;
        }

        foreach (['SATU', 'DUA', 'TIGA'] as $nama) {
            $model::create(['name' => $nama] + $tambahan);
        }

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get($url)
            ->assertOk()
            ->getContent();

        preg_match('/<tbody.*?<\/tbody>/s', $html, $tbody);
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $tbody[0] ?? '', $rows);

        $numbers = array_map(function (string $row) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

            // Sel pertama. Berbeda dengan daftar reservasi, tabel master tidak
            // punya bulk action sehingga tidak ada sel centang yang mendahului.
            return trim(strip_tags($cells[1][0] ?? ''));
        }, $rows[1]);

        $this->assertSame(['1', '2', '3'], $numbers);
    }
}
