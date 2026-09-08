<?php

namespace Tests\Feature;

use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Models\Area;
use App\Models\User;
use Database\Seeders\AreaPhotoSeeder;
use Database\Seeders\MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AreaPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_gives_every_master_area_a_photo(): void
    {
        Storage::fake('public');
        $this->seed(MasterSeeder::class);

        $this->seed(AreaPhotoSeeder::class);

        $this->assertSame(10, Area::whereNotNull('photo_path')->count());
    }

    /**
     * Foto yang sudah ada tidak boleh ditimpa foto bawaan.
     *
     * Tanpa penjaga ini, menjalankan seeder sekali lagi — hal yang wajar
     * dilakukan setelah menambah area baru — akan diam-diam mengembalikan foto
     * yang baru saja diunggah staf ke foto bawaan repo.
     */
    public function test_seeder_leaves_a_photo_that_is_already_set_alone(): void
    {
        Storage::fake('public');
        $this->seed(MasterSeeder::class);

        $outdoor = Area::where('name', 'OUTDOOR')->firstOrFail();
        $outdoor->photo_path = 'area/unggahan-staf.jpg';
        $outdoor->save();

        $this->seed(AreaPhotoSeeder::class);

        $this->assertSame(
            'area/unggahan-staf.jpg',
            $outdoor->fresh()->photo_path,
            'Seeder menimpa foto yang sudah diunggah staf.'
        );
    }

    /**
     * Nama area yang tidak ketemu harus MELEMPAR, bukan dilewati.
     *
     * Pelajaran yang sama dengan MasterSeeder::MELIPUTI: daftar nama yang tidak
     * lagi cocok dengan master akan keluar tanpa suara, seedernya dilaporkan
     * sukses, dan sebagian area tidak pernah dapat foto tanpa satu pun tanda.
     */
    public function test_seeder_throws_when_an_area_name_no_longer_exists(): void
    {
        Storage::fake('public');
        $this->seed(MasterSeeder::class);
        Area::where('name', 'OUTDOOR')->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OUTDOOR/');

        $this->seed(AreaPhotoSeeder::class);
    }

    /**
     * Pesannya menyebut SELURUH nama yang meleset, bukan yang pertama saja.
     *
     * Ditemukan saat menjalankannya sungguhan di database dev yang daftar
     * areanya versi lama: empat nama meleset, tapi seeder berhenti di yang
     * pertama. Memperbaikinya berarti empat putaran jalankan–gagal–betulkan,
     * padahal keempatnya sudah diketahui sejak awal.
     */
    public function test_the_error_names_every_area_that_is_missing(): void
    {
        Storage::fake('public');
        $this->seed(MasterSeeder::class);
        Area::whereIn('name', ['FOYE', 'SOFA', 'GRAND BALLROOM'])->delete();

        try {
            $this->seed(AreaPhotoSeeder::class);
            $this->fail('Seeder seharusnya melempar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('FOYE', $e->getMessage());
            $this->assertStringContainsString('SOFA', $e->getMessage());
            $this->assertStringContainsString('GRAND BALLROOM', $e->getMessage());
        }
    }

    /**
     * Gagal berarti TIDAK ADA yang ditulis, bukan sebagian.
     *
     * Sebelum perbaikan ini seeder menulis foto sampai nama yang meleset lalu
     * berhenti, meninggalkan database setengah terisi — keadaan yang tidak
     * pernah diminta siapa pun dan tidak terlihat dari pesan errornya.
     */
    public function test_nothing_is_written_when_any_area_is_missing(): void
    {
        Storage::fake('public');
        $this->seed(MasterSeeder::class);

        // VIP 1 ada di urutan pertama daftar FOTO, jadi tanpa pemeriksaan di
        // muka ia sudah terlanjur dapat foto sebelum FOYE bikin gagal.
        Area::where('name', 'FOYE')->delete();

        try {
            $this->seed(AreaPhotoSeeder::class);
        } catch (\RuntimeException) {
            // Diharapkan.
        }

        $this->assertSame(
            0,
            Area::whereNotNull('photo_path')->count(),
            'Seeder menulis sebagian foto padahal ada nama yang meleset.'
        );
    }

    /**
     * Area tanpa foto mengembalikan null, bukan URL yang menunjuk entah ke mana.
     *
     * Nilai itulah yang dipakai form untuk memutuskan menyembunyikan
     * pratinjaunya. Kalau ia mengembalikan string kosong atau URL folder,
     * yang muncul di layar adalah ikon gambar rusak — lebih buruk daripada
     * tidak menampilkan apa-apa.
     */
    public function test_an_area_without_a_photo_has_no_photo_url(): void
    {
        $area = Area::create(['name' => 'TANPA FOTO']);

        $this->assertNull($area->photoUrl());
    }

    public function test_the_photo_url_points_at_the_stored_file(): void
    {
        Storage::fake('public');
        $area = Area::create(['name' => 'DENGAN FOTO', 'photo_path' => 'area/OUTDOOR.jpg']);

        $this->assertStringContainsString('area/OUTDOOR.jpg', $area->photoUrl());
    }

    /**
     * Path disimpan relatif, URL dibangun saat dirender.
     *
     * Menyimpan URL penuh akan membekukan APP_URL lama ke dalam baris database,
     * dan foto akan menunjuk ke localhost setelah sistem pindah ke domain —
     * persis jenis kesalahan yang sudah dicatat di CLAUDE.md soal tautan
     * kalender publik di sidebar.
     */
    public function test_the_stored_column_holds_a_relative_path_not_a_url(): void
    {
        Storage::fake('public');
        $area = Area::create(['name' => 'DENGAN FOTO', 'photo_path' => 'area/OUTDOOR.jpg']);

        $this->assertSame('area/OUTDOOR.jpg', $area->fresh()->photo_path);
    }

    /**
     * Menyiapkan staf yang boleh membuka form Create.
     *
     * Tanpa RolePermissionSeeder, assignRole melempar RoleDoesNotExist; tanpa
     * peran staff, halaman Create menolak dengan 403 sebelum sempat diuji.
     */
    private function masukSebagaiStaf(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');

        $staf = User::factory()->create();
        $staf->assignRole('staff');
        $this->actingAs($staf);
    }

    public function test_the_reservation_form_shows_the_photo_of_the_chosen_area(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        $area = Area::create(['name' => 'OUTDOOR', 'photo_path' => 'area/OUTDOOR.jpg']);

        Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->assertSee($area->photoUrl());
    }

    /**
     * Area tanpa foto tidak boleh meninggalkan bekas apa pun di form.
     *
     * Yang dijaga di sini bukan kerapian, melainkan ikon gambar rusak: sebuah
     * <img> dengan src kosong tetap dirender peramban sebagai gambar gagal, dan
     * staf akan membacanya sebagai "sistemnya error", bukan "areanya belum
     * difoto".
     */
    public function test_the_reservation_form_shows_nothing_when_the_area_has_no_photo(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        $area = Area::create(['name' => 'BELUM DIFOTO']);

        Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->assertDontSee('/storage/area/');
    }

    /**
     * Staf yang berwenang bisa mengganti fotonya sendiri lewat /cms/areas,
     * tanpa developer dan tanpa deploy.
     *
     * Yang diperiksa adalah akibatnya — berkasnya benar-benar mendarat di disk
     * dan path-nya tersimpan — bukan sekadar bahwa formnya punya kolom.
     */
    public function test_an_admin_can_upload_a_photo_for_an_area(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');
        $this->actingAs(User::factory()->admin()->create());

        $area = Area::create(['name' => 'OUTDOOR']);

        Livewire::test(ManageAreas::class)
            ->callTableAction('edit', $area, [
                'name' => 'OUTDOOR',
                'is_active' => true,
                'photo_path' => [UploadedFile::fake()->image('outdoor-baru.jpg')],
            ])
            ->assertHasNoTableActionErrors();

        $tersimpan = $area->fresh()->photo_path;

        $this->assertNotNull($tersimpan, 'Foto yang diunggah tidak tersimpan.');
        Storage::disk('public')->assertExists($tersimpan);
    }

    /**
     * Daftar area menampilkan fotonya, supaya sekali lihat ketahuan area mana
     * yang belum sempat difoto — tanpa membuka satu per satu.
     */
    public function test_the_area_list_shows_the_photo_of_each_area(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');
        $this->actingAs(User::factory()->admin()->create());

        // Berkasnya benar-benar ditaruh, bukan hanya path-nya diisi:
        // ImageColumn mengecek keberadaan berkas dan tidak merender apa pun
        // kalau tidak ketemu. Sifat itu menguntungkan — path yang menggantung
        // menghasilkan sel kosong, bukan ikon gambar rusak.
        Storage::disk('public')->put('area/OUTDOOR.jpg', 'isi-gambar');
        Area::create(['name' => 'OUTDOOR', 'photo_path' => 'area/OUTDOOR.jpg']);

        // Lewat komponen Livewire-nya, bukan GET halaman: tabel Filament
        // dirender Livewire, sehingga HTML permintaan pertama belum memuat
        // satu baris pun. Pola yang sama dipakai ReservationsTableTest.
        Livewire::test(ManageAreas::class)->assertSee('area/OUTDOOR.jpg');
    }
}
