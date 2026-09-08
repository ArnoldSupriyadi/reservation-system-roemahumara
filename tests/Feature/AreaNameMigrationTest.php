<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Migrasi data yang menyamakan nama area dengan MasterSeeder.
 *
 * Server yang dipasang sebelum 2026-08-28 memegang daftar lama (ALL BALLROOM
 * 1-4). Deploy menjalankan migrate tapi tidak pernah db:seed, jadi perubahan
 * daftar master tidak pernah sampai ke sana dengan sendirinya.
 *
 * Diuji dengan memanggil up() langsung pada data berbentuk lama. Saat
 * RefreshDatabase menjalankan seluruh migrasi di awal test, tabel areas masih
 * kosong sehingga migrasi ini tidak melakukan apa-apa — data lamanya baru
 * dibuat di dalam test.
 */
class AreaNameMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrasi(): object
    {
        return require database_path('migrations/2026_09_08_000002_samakan_nama_area_dengan_master.php');
    }

    private function areaVersiLama(): void
    {
        foreach ([
            'VIP 1', 'VIP 2', 'FOYER', 'KORIDOR', 'SOFA REGULAR', 'REGULAR',
            'OUTDOOR', 'BALLROOM 1', 'BALLROOM 2', 'BALLROOM 3', 'BALLROOM 4',
            'ALL BALLROOM',
        ] as $nama) {
            Area::create(['name' => $nama]);
        }
    }

    public function test_it_renames_the_four_outdated_names(): void
    {
        $this->areaVersiLama();

        $this->migrasi()->up();

        $this->assertSame(
            ['FOYE', 'INDOOR', 'SOFA', 'GRAND BALLROOM'],
            Area::whereIn('name', ['FOYE', 'INDOOR', 'SOFA', 'GRAND BALLROOM'])
                ->orderByRaw("FIELD(name,'FOYE','INDOOR','SOFA','GRAND BALLROOM')")
                ->pluck('name')
                ->all()
        );

        $this->assertSame(
            0,
            Area::whereIn('name', ['FOYER', 'REGULAR', 'SOFA REGULAR', 'ALL BALLROOM'])->count(),
            'Nama lama masih tersisa.'
        );
    }

    /**
     * Rename, BUKAN hapus-lalu-buat: id harus tetap, supaya reservasi yang
     * sudah menunjuk area itu ikut terbawa dan tidak menggantung.
     */
    public function test_renaming_keeps_the_id_so_reservations_follow(): void
    {
        $this->areaVersiLama();
        $foyer = Area::where('name', 'FOYER')->firstOrFail();
        $idSemula = $foyer->id;

        $this->migrasi()->up();

        $this->assertSame($idSemula, Area::where('name', 'FOYE')->value('id'));
    }

    public function test_it_deletes_ballroom_3_and_4_when_nothing_uses_them(): void
    {
        $this->areaVersiLama();

        $this->migrasi()->up();

        $this->assertSame(
            0,
            Area::whereIn('name', ['BALLROOM 3', 'BALLROOM 4'])->count(),
            'BALLROOM 3/4 seharusnya dihapus ketika tidak dipakai.'
        );
    }

    /**
     * Yang masih dipakai TIDAK dihapus, dan deploy tidak boleh jatuh karenanya.
     *
     * Menghapus area yang punya reservasi ditolak foreign key. Migrasi yang
     * memaksanya akan menggagalkan seluruh deploy di produksi — harga yang
     * terlalu mahal untuk merapikan daftar master. Gantinya dinonaktifkan:
     * tidak muncul lagi di form reservasi baru, tapi reservasi lamanya utuh.
     */
    public function test_a_ballroom_that_is_still_used_is_deactivated_instead_of_deleted(): void
    {
        $this->areaVersiLama();
        $b3 = Area::where('name', 'BALLROOM 3')->firstOrFail();

        // Lewat factory: reservation_number itu NOT NULL dan ditetapkan
        // NumberSequence, bukan nilai yang boleh dikarang di test.
        Reservation::factory()->create([
            'guest_name' => 'Bapak Nugroho',
            'area_id' => $b3->id,
        ]);

        $this->migrasi()->up();

        $b3 = $b3->fresh();

        $this->assertNotNull($b3, 'BALLROOM 3 yang masih dipakai tidak boleh dihapus.');
        $this->assertFalse($b3->is_active, 'Seharusnya dinonaktifkan supaya tidak dipilih lagi.');
        $this->assertSame(1, Reservation::count(), 'Reservasi tidak boleh ikut hilang.');
    }

    /**
     * Aman dijalankan pada database yang namanya sudah benar.
     *
     * Migrasi memang hanya jalan sekali, tapi mesin baru menjalankannya pada
     * tabel yang sudah diisi MasterSeeder versi terbaru — dan di sana keempat
     * nama lama itu tidak ada sama sekali.
     */
    public function test_it_does_nothing_when_the_names_are_already_correct(): void
    {
        foreach (['VIP 1', 'FOYE', 'INDOOR', 'SOFA', 'GRAND BALLROOM'] as $nama) {
            Area::create(['name' => $nama]);
        }

        $this->migrasi()->up();

        $this->assertSame(5, Area::count());
        $this->assertSame(5, Area::where('is_active', true)->count());
    }

    public function test_down_puts_the_old_names_back(): void
    {
        $this->areaVersiLama();
        $migrasi = $this->migrasi();
        $migrasi->up();

        $migrasi->down();

        $this->assertSame(
            4,
            Area::whereIn('name', ['FOYER', 'REGULAR', 'SOFA REGULAR', 'ALL BALLROOM'])->count()
        );
    }
}
