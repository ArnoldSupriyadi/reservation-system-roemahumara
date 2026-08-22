<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
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
        $this->actingAs($admin)->get('/cms/menu-styles')->assertOk();
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

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function masterPages(): array
    {
        return [
            'area' => ['/cms/areas', Area::class],
            'jenis acara' => ['/cms/event-types', EventType::class],
            'menu style' => ['/cms/menu-styles', MenuStyle::class],
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
    public function test_master_lists_are_numbered(string $url, string $model): void
    {
        $model::create(['name' => 'SATU']);
        $model::create(['name' => 'DUA']);
        $model::create(['name' => 'TIGA']);

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
