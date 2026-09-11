<?php

namespace Tests\Feature;

use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Models\Area;
use App\Models\Reservation;
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

    /** Berkas yang dirender saat areanya belum punya foto. */
    private const PLACEHOLDER = 'img/no-image.svg';

    /** Penanda markup pembesar foto; ada hanya kalau fotonya bisa diklik. */
    private const PEMBESAR = 'ru-area-photo-dialog';

    /** Tombol tutup pada foto yang sedang diperbesar. */
    private const TOMBOL_TUTUP = 'ru-area-photo-close';

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
        Storage::disk('public')->put('area/OUTDOOR.jpg', 'isi-gambar');
        $area = Area::create(['name' => 'DENGAN FOTO', 'photo_path' => 'area/OUTDOOR.jpg']);

        $this->assertStringContainsString('area/OUTDOOR.jpg', $area->photoUrl());
    }

    /**
     * Kolomnya terisi, berkasnya tidak ada: null, bukan URL yang menunjuk ke
     * berkas yang sudah hilang.
     *
     * photoUrl() yang hanya memeriksa kolomnya akan mengembalikan URL yang sah
     * secara bentuk tapi menunjuk 404, dan peramban merendernya sebagai ikon
     * gambar rusak. Null-lah yang membuat form jatuh ke placeholder.
     */
    public function test_the_photo_url_is_null_when_the_file_is_gone(): void
    {
        Storage::fake('public');
        $area = Area::create(['name' => 'FOTONYA HILANG', 'photo_path' => 'area/HILANG.jpg']);

        $this->assertNull($area->photoUrl());
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
        Storage::disk('public')->put('area/OUTDOOR.jpg', 'isi-gambar');
        $area = Area::create(['name' => 'OUTDOOR', 'photo_path' => 'area/OUTDOOR.jpg']);

        Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->assertSee($area->photoUrl());
    }

    /**
     * Area tanpa foto menampilkan placeholder, bukan ruang kosong.
     *
     * Sampai 2026-09-10 pratinjaunya disembunyikan seluruhnya. Yang dijaga
     * waktu itu adalah ikon gambar rusak: sebuah <img> dengan src kosong tetap
     * dirender peramban sebagai gambar gagal, dan staf membacanya sebagai
     * "sistemnya error", bukan "areanya belum difoto". Placeholder bertulisan
     * menjawab kekhawatiran yang sama dengan lebih baik — ia mengatakannya,
     * alih-alih menyisakan ruang kosong yang juga bisa dibaca sebagai form
     * yang belum selesai memuat.
     */
    public function test_the_reservation_form_shows_a_placeholder_when_the_area_has_no_photo(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        $area = Area::create(['name' => 'BELUM DIFOTO']);

        Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->assertSee(self::PLACEHOLDER)
            ->assertDontSee('/storage/area/');
    }

    /**
     * Selama areanya belum dipilih, tidak ada pratinjau sama sekali — juga
     * bukan placeholder.
     *
     * Placeholder menjawab pertanyaan "mana fotonya?", dan pertanyaan itu baru
     * ada setelah staf memilih area. Memunculkannya lebih awal membuat form
     * terbuka dengan kotak yang seolah melaporkan ada sesuatu yang hilang,
     * padahal belum ada yang diminta.
     */
    public function test_the_reservation_form_shows_no_preview_until_an_area_is_chosen(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();

        Livewire::test(CreateReservation::class)
            ->assertDontSee(self::PLACEHOLDER)
            ->assertDontSee('/storage/area/');
    }

    /**
     * photo_path terisi tapi berkasnya sudah tidak ada: placeholder, bukan
     * ikon gambar rusak.
     *
     * Keadaan ini bukan mengada-ada. Foto unggahan tinggal di storage/, yang
     * TIDAK ikut git dan di-exclude dari rsync deploy — server yang dipasang
     * ulang datang dengan baris database utuh dan folder fotonya kosong.
     * Tanpa penjagaan ini, tepat kasus itulah yang menghasilkan ikon gambar
     * rusak yang jadi alasan aturan lama.
     */
    public function test_a_photo_path_without_its_file_falls_back_to_the_placeholder(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        $area = Area::create(['name' => 'FOTONYA HILANG', 'photo_path' => 'area/HILANG.jpg']);

        Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->assertSee(self::PLACEHOLDER)
            ->assertDontSee('area/HILANG.jpg');
    }

    /**
     * Fotonya bisa dibuka lebih besar dengan mengkliknya.
     *
     * Thumbnail 180px cukup untuk membedakan FOYE dari KORIDOR, tapi tidak
     * untuk melihat penataan meja atau di mana pintunya. Yang diperiksa di sini
     * markup pembesarnya ikut dirender berikut url fotonya — bukan bahwa
     * kliknya bekerja, karena itu terjadi di peramban.
     */
    public function test_the_photo_of_the_chosen_area_can_be_opened_larger(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        Storage::disk('public')->put('area/OUTDOOR.jpg', 'isi-gambar');
        $area = Area::create(['name' => 'OUTDOOR', 'photo_path' => 'area/OUTDOOR.jpg']);

        Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->assertSee(self::PEMBESAR)
            ->assertSee($area->photoUrl());
    }

    /**
     * Foto yang diperbesar punya tombol tutup yang terlihat.
     *
     * Esc dan klik-latar tetap bekerja, tapi keduanya tidak terlihat: yang
     * belum pernah memakainya tidak tahu keduanya ada, dan yang membuka lewat
     * layar sentuh tidak punya Esc sama sekali. Tombolnya sengaja mencolok —
     * lingkaran putih bergaris di pojok fotonya — karena tombol tutup yang
     * menyatu dengan gambar di belakangnya sama saja dengan tidak ada.
     */
    public function test_the_enlarged_photo_has_a_visible_close_button(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        Storage::disk('public')->put('area/OUTDOOR.jpg', 'isi-gambar');
        $area = Area::create(['name' => 'OUTDOOR', 'photo_path' => 'area/OUTDOOR.jpg']);

        Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->assertSee(self::TOMBOL_TUTUP);
    }

    /**
     * Tombol tutup tidak boleh membawa <form> sendiri, dan wajib type="button".
     *
     * Skema Filament dirender DI DALAM <form wire:submit="create">. Sebuah
     * <form> di dalam <form> adalah HTML tidak sah: parser peramban membuang
     * tag bagian dalam diam-diam, tombolnya jatuh jadi milik form Filament di
     * luarnya, dan type="submit" membuat kliknya MENCOBA MENYIMPAN RESERVASI
     * alih-alih menutup dialog. Itu bug sungguhan yang pernah terjadi
     * (2026-09-10) dan lolos dari test sebelumnya.
     *
     * Lolosnya bukan kebetulan: pembuangan itu terjadi di parser peramban,
     * sedangkan HTML dari server memang berisi form bersarangnya. Test yang
     * hanya memeriksa keberadaan tombolnya tidak akan pernah menangkap ini —
     * yang harus diperiksa adalah HTML-nya tidak pernah bersarang sejak awal.
     */
    public function test_the_close_button_never_nests_a_form(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        Storage::disk('public')->put('area/OUTDOOR.jpg', 'isi-gambar');
        $area = Area::create(['name' => 'OUTDOOR', 'photo_path' => 'area/OUTDOOR.jpg']);

        $html = Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->html();

        $this->assertStringNotContainsString(
            '<form',
            substr($html, strpos($html, 'ru-area-photo-dialog')),
            'Markup pembesar foto memuat <form> bersarang di dalam form Filament.'
        );

        $this->assertStringContainsString(
            '<button type="button" class="'.self::TOMBOL_TUTUP.'"',
            $html,
            'Tombol tutup harus type="button" supaya tidak pernah menyimpan reservasi.'
        );
    }

    /**
     * Placeholder TIDAK bisa diklik.
     *
     * Tidak ada yang bisa diperbesar dari tulisan "No image", dan kursor
     * zoom-in di atasnya menjanjikan sesuatu yang tidak ada. Staf yang
     * mengkliknya lalu tidak mendapat apa-apa akan mengira sistemnya
     * menggantung.
     */
    public function test_the_placeholder_cannot_be_opened_larger(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        $area = Area::create(['name' => 'BELUM DIFOTO']);

        Livewire::test(CreateReservation::class)
            ->fillForm(['area_id' => $area->id])
            ->assertSee(self::PLACEHOLDER)
            ->assertDontSee(self::PEMBESAR);
    }

    /**
     * Halaman Edit mendapat perilaku yang sama, dan itu bukan kebetulan:
     * skemanya satu, dipakai bersama Create dan Edit.
     *
     * Test ini menjaga agar skema bersama itu tidak diam-diam bercabang jadi
     * dua perilaku — jenis pembelahan yang sama dengan yang dilarang aturan #12
     * CLAUDE.md untuk penjagaan bentrok area.
     */
    public function test_the_edit_page_can_open_the_photo_larger_too(): void
    {
        Storage::fake('public');
        $this->masukSebagaiStaf();
        Storage::disk('public')->put('area/OUTDOOR.jpg', 'isi-gambar');
        $area = Area::create(['name' => 'OUTDOOR', 'photo_path' => 'area/OUTDOOR.jpg']);
        $reservasi = Reservation::factory()->create(['area_id' => $area->id]);

        Livewire::test(EditReservation::class, ['record' => $reservasi->getKey()])
            ->assertSee(self::PEMBESAR)
            ->assertSee($area->photoUrl());
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
