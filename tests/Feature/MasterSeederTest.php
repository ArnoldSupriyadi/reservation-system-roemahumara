<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\EventType;
use App\Models\Menu;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_fills_all_three_masters(): void
    {
        $this->seed(MasterSeeder::class);

        $this->assertSame(10, Area::count());
        $this->assertSame(10, EventType::count());
        $this->assertSame(137, Menu::count(), 'Seluruh hidangan dari menu.json.');
    }

    /**
     * Urutannya kini mengikuti id, bukan sort_order yang sudah dihapus. Yang
     * dijaga tetap sama: urutan larik di MasterSeeder menentukan urutan tampil,
     * karena id mengikuti urutan penyisipan.
     */
    public function test_areas_are_ordered_as_in_the_spreadsheet(): void
    {
        $this->seed(MasterSeeder::class);

        $this->assertSame(
            [
                'VIP 1', 'VIP 2', 'FOYE', 'INDOOR', 'SOFA', 'KORIDOR', 'OUTDOOR',
                'BALLROOM 1', 'BALLROOM 2', 'GRAND BALLROOM',
            ],
            Area::orderBy('id')->pluck('name')->all()
        );
    }

    /**
     * Yang dijaga di sini bukan isi tabel area_overlaps, melainkan akibatnya:
     * memesan keseluruhan membuat tiap bagiannya ikut terpakai, dan sebaliknya.
     *
     * linkBallrooms() pernah gagal diam-diam — nama area di daftar master
     * diganti tanpa methodnya ikut diubah, sehingga ia mencari baris yang sudah
     * tidak ada lalu keluar tanpa suara. Seeder tetap dilaporkan sukses,
     * sementara pengecekan bentrok ballroom mati total.
     */
    public function test_seeder_links_grand_ballroom_to_its_parts_both_ways(): void
    {
        $this->seed(MasterSeeder::class);

        $grand = Area::where('name', 'GRAND BALLROOM')->sole();
        $satu = Area::where('name', 'BALLROOM 1')->sole();
        $dua = Area::where('name', 'BALLROOM 2')->sole();

        $this->assertEqualsCanonicalizing(
            [$grand->id, $satu->id, $dua->id],
            $grand->occupiedAreaIds(),
            'Memesan GRAND BALLROOM memakai kedua bagiannya.'
        );

        // Arah sebaliknya diuji terpisah: relasi satu arah tetap lolos assert di
        // atas, dan justru sisi inilah yang diam saat pengguna memesan dari
        // bagian, bukan dari keseluruhan.
        $this->assertEqualsCanonicalizing(
            [$satu->id, $grand->id],
            $satu->occupiedAreaIds(),
            'Memesan BALLROOM 1 membuat GRAND BALLROOM ikut terpakai.'
        );

        $this->assertEqualsCanonicalizing(
            [$dua->id, $grand->id],
            $dua->occupiedAreaIds(),
            'BALLROOM 2 begitu pula — dan tidak menyeret BALLROOM 1, '
            .'karena dua bagian yang berbeda sengaja tidak saling meliputi.'
        );
    }

    public function test_active_scope_filters_inactive_rows(): void
    {
        Area::create(['name' => 'VIP 3', 'is_active' => true]);
        Area::create(['name' => 'GUDANG', 'is_active' => false]);

        $this->assertSame(['VIP 3'], Area::query()->active()->pluck('name')->all());
    }
}
