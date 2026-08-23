<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Menggantikan verifikasi manual Task 13 Step 4 dengan pemeriksaan otomatis.
 */
class ReservationsTableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Reservation $singleTime;

    private Reservation $range;

    private Reservation $noRemark;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');

        $this->admin = User::factory()->admin()->create(['name' => 'IRA']);

        // Dua area, bukan satu. Test filter area di bawah butuh baris yang
        // benar-benar berada di area berbeda; sejak area wajib, membiarkan yang
        // lain memakai bawaan factory membuat semuanya jatuh ke area yang sama
        // dan filternya lolos tanpa membuktikan apa pun.
        $lain = Area::create(['name' => 'REGULAR']);
        $area = Area::create(['name' => 'VIP 1']);

        $month = Carbon::now()->startOfMonth();

        $this->singleTime = Reservation::factory()->create([
            'reservation_date' => $month->copy()->addDays(7),
            'area_id' => $lain->id,
            'guest_name' => 'Ibu There',
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'start_time' => '12:00:00',
            'pax' => 5,
            'remark' => 'MAIN CONTRACTOR ROEMAH UMARA',
        ]);

        $this->range = Reservation::factory()->create([
            'reservation_date' => $month->copy()->addDays(8),
            'guest_name' => 'Dharmadi',
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'start_time' => '12:00:00',
            'end_time' => '15:00:00',
            'status' => 'confirmed',
            'pax' => 40,
            'area_id' => $area->id,
            'remark' => "Pakai VIP 1 + VIP 2 + FOYER FnB sekaligus, sekat dibuka jam 11.30.\nGrand total sudah termasuk tax & service 21%.",
        ]);

        $this->noRemark = Reservation::factory()->create([
            'reservation_date' => $month->copy()->addDays(9),
            'area_id' => $lain->id,
            'guest_name' => 'Tanti',
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'start_time' => '11:00:00',
            'pax' => 3,
        ]);
    }

    public function test_the_list_page_renders(): void
    {
        $this->actingAs($this->admin);

        $this->get('/cms/reservations')->assertOk();
    }

    public function test_the_current_month_tab_is_active_by_default(): void
    {
        $this->actingAs($this->admin);

        $page = Livewire::test(ListReservations::class);

        $this->assertSame(Carbon::now()->format('Y-m'), $page->instance()->getDefaultActiveTab());
        $this->assertArrayHasKey('all', $page->instance()->getTabs());
        $this->assertCount(8, $page->instance()->getTabs(), 'Tujuh bulan plus tab Semua.');

        $page->assertCanSeeTableRecords([$this->singleTime, $this->range, $this->noRemark]);
    }

    public function test_a_single_time_is_shown_without_a_dash(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->assertTableColumnFormattedStateSet('start_time', '12:00', $this->singleTime);
    }

    public function test_a_range_is_shown_with_both_ends(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->assertTableColumnFormattedStateSet('start_time', '12:00–15:00', $this->range);
    }

    /**
     * Baris header wajib ada. Filament mematikan <thead> untuk seluruh tabel
     * begitu ada satu komponen layout (Split, Stack, Panel) di level atas —
     * lihat HasColumns::pushColumns. Tanpa header, angka pax dan nomor
     * reservasi tampil tanpa keterangan dan pembacanya harus menebak.
     */
    public function test_the_table_keeps_its_header_row(): void
    {
        $this->actingAs($this->admin);

        $table = Livewire::test(ListReservations::class)->instance()->getTable();

        $this->assertFalse(
            $table->hasColumnsLayout(),
            'Ada komponen layout di level atas; baris header hilang.'
        );

        $this->get('/cms/reservations')
            ->assertSee('Pax')
            ->assertSee('No.')
            ->assertSee('Nama tamu');
    }

    /**
     * Nomor urut baris, terpisah dari nomor reservasi.
     *
     * Diperiksa lewat HTML yang benar-benar ter-render, bukan lewat
     * assertTableColumnFormattedStateSet(): rowIndex() mengambil nilainya dari
     * rowLoop yang hanya disuntikkan Blade saat merender baris, sehingga helper
     * itu selalu melihatnya kosong. Kalau kolomnya suatu saat berhenti bekerja,
     * gejalanya persis sama — kolomnya tetap ada, selnya saja yang kosong — jadi
     * memeriksa keberadaan kolom tidak akan menangkapnya.
     */
    public function test_rows_are_numbered_in_display_order(): void
    {
        $this->actingAs($this->admin);

        // defaultSort reservation_date: singleTime (hari ke-7), range (ke-8),
        // noRemark (ke-9).
        $this->assertSame(['1', '2', '3'], $this->renderedRowNumbers());
    }

    /**
     * Nomor urut mengikuti tampilan, nomor reservasi menempel pada reservasinya.
     * Membalik urutan membuktikan keduanya memang tidak sama.
     */
    public function test_the_row_number_follows_the_sort_while_the_reservation_number_does_not(): void
    {
        $this->actingAs($this->admin);

        $html = Livewire::test(ListReservations::class)
            ->sortTable('reservation_date', 'desc')
            ->html();

        $baris = $this->rowsFrom($html);

        $this->assertSame('1', $this->cell($baris[0], 1));
        $this->assertStringContainsString(
            $this->noRemark->reservation_number,
            $this->cell($baris[0], 2),
            'Yang paling akhir tanggalnya jadi baris 1, tapi nomor reservasinya tetap miliknya sendiri.'
        );
    }

    /** @return array<int, string> */
    private function renderedRowNumbers(): array
    {
        $html = $this->get('/cms/reservations')->assertOk()->getContent();

        return array_map(fn (string $row) => $this->cell($row, 1), $this->rowsFrom($html));
    }

    /** @return array<int, string> */
    private function rowsFrom(string $html): array
    {
        preg_match('/<tbody.*?<\/tbody>/s', $html, $tbody);
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $tbody[0] ?? '', $rows);

        return $rows[1];
    }

    /** Sel ke-$n dalam satu baris; indeks 0 adalah sel centang massal. */
    private function cell(string $row, int $n): string
    {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

        return trim(preg_replace('/\s+/', ' ', strip_tags($cells[1][$n] ?? '')));
    }

    /** Kedua kolom nomor harus bisa dibedakan di header. */
    public function test_the_row_number_and_the_reservation_number_are_labelled_apart(): void
    {
        $this->actingAs($this->admin);

        $this->get('/cms/reservations')
            ->assertSee('No.')
            ->assertSee('No. Reservasi')
            ->assertSee($this->singleTime->reservation_number);
    }

    /**
     * Aturan #4 CLAUDE.md: remark selalu ditampilkan penuh. Membandingkan
     * dengan nilai utuh sekaligus membuktikan tidak ada limit() atau words().
     */
    public function test_remark_is_rendered_in_full_including_both_lines(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->assertTableColumnStateSet('remark', $this->singleTime->remark, $this->singleTime)
            ->assertTableColumnStateSet('remark', $this->range->remark, $this->range);

        $this->assertStringContainsString('sekat dibuka jam 11.30.', $this->range->remark);
        $this->assertStringContainsString('tax & service 21%', $this->range->remark);
    }

    public function test_a_row_without_remark_has_nothing_to_show(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->assertTableColumnStateSet('remark', null, $this->noRemark);
    }

    public function test_search_reaches_into_the_remark(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->searchTable('contractor')
            ->assertCanSeeTableRecords([$this->singleTime])
            ->assertCanNotSeeTableRecords([$this->range, $this->noRemark]);
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->filterTable('status', 'confirmed')
            ->assertCanSeeTableRecords([$this->range])
            ->assertCanNotSeeTableRecords([$this->singleTime, $this->noRemark]);

        Livewire::test(ListReservations::class)
            ->filterTable('area_id', $this->range->area_id)
            ->assertCanSeeTableRecords([$this->range])
            ->assertCanNotSeeTableRecords([$this->singleTime]);

        Livewire::test(ListReservations::class)
            ->filterTable('undetermined_status', true)
            ->assertCanSeeTableRecords([$this->singleTime, $this->noRemark])
            ->assertCanNotSeeTableRecords([$this->range]);
    }

    /**
     * Tab bulan dibuat selebar tabel lewat <style> yang disuntikkan render hook,
     * bukan kelas Tailwind — CSS Filament dibangun terpisah dan tidak memuatnya.
     */
    public function test_the_month_tabs_are_styled_full_width(): void
    {
        $this->actingAs($this->admin);

        $html = $this->get('/cms/reservations')->assertOk()->getContent();

        $this->assertStringContainsString('.fi-resource-reservations .fi-tabs', $html);
        $this->assertStringContainsString('flex: 1 1 0', $html);
    }

    /**
     * Gayanya HARUS dibatasi ke halaman reservasi. Tanpa pembatas, setiap tab di
     * panel ikut melar — termasuk pada resource yang dibuat nanti, jauh dari
     * perubahan ini dan tanpa ada yang menghubungkannya.
     */
    public function test_the_tab_style_is_scoped_to_this_resource(): void
    {
        $this->actingAs($this->admin);

        $html = $this->get('/cms/reservations')->assertOk()->getContent();

        preg_match_all('/\.fi-tabs[a-z-]*\s*\{/', $html, $cocok);

        foreach ($cocok[0] as $selektor) {
            $posisi = strpos($html, $selektor);
            $sebelum = substr($html, max(0, $posisi - 60), 60);

            $this->assertStringContainsString(
                'fi-resource-reservations',
                $sebelum,
                "Selektor {$selektor} tidak dibatasi ke halaman reservasi."
            );
        }
    }

    /**
     * Tab bulan sengaja hanya tiga bulan ke belakang sampai tiga ke depan, plus
     * Semua. Itu BUKAN bug — tapi reservasi di luar jendela itu hanya bisa
     * dicapai lewat tab Semua, dan untuk acara yang dipesan jauh hari (wedding)
     * itu perlu diingat.
     */
    public function test_the_month_tabs_span_three_months_either_side_of_today(): void
    {
        $this->actingAs($this->admin);

        $tabs = array_keys(Livewire::test(ListReservations::class)->instance()->getTabs());

        $this->assertSame('all', array_pop($tabs), 'Tab Semua harus paling akhir.');
        $this->assertCount(7, $tabs);

        $this->assertSame(Carbon::now()->startOfMonth()->subMonths(3)->format('Y-m'), $tabs[0]);
        $this->assertSame(Carbon::now()->format('Y-m'), $tabs[3], 'Bulan berjalan di tengah.');
        $this->assertSame(Carbon::now()->startOfMonth()->addMonths(3)->format('Y-m'), $tabs[6]);
    }

    /** Reservasi di luar jendela tab tetap terjangkau lewat tab Semua. */
    public function test_a_reservation_outside_the_window_is_still_reachable(): void
    {
        $this->actingAs($this->admin);

        $jauh = Reservation::factory()->create([
            'reservation_date' => Carbon::now()->startOfMonth()->addMonths(8),
            'guest_name' => 'PESAN JAUH HARI',
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        Livewire::test(ListReservations::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$jauh]);
    }

    /**
     * Sekali klik pada judul kolom Tanggal harus langsung mengubah urutan.
     *
     * Sebelum 2026-08-23 tidak begitu. Filament menyimpan urutan aktif di
     * properti tableSort, sementara defaultSort() diterapkan langsung ke kueri
     * tanpa mengisi properti itu. Akibatnya tabel TAMPIL terurut menaik padahal
     * tableSort masih null, dan klik pertama — yang menyetel "menaik" — tidak
     * mengubah apa pun yang terlihat. Baru klik kedua terasa bekerja.
     */
    public function test_one_click_on_the_date_header_actually_changes_the_order(): void
    {
        $this->actingAs($this->admin);

        $sebelum = Livewire::test(ListReservations::class);

        $awal = $this->guestNamesFrom($sebelum->html());
        $this->assertSame(['Ibu There', 'Dharmadi', 'Tanti'], $awal, 'Bawaannya menaik.');

        $sesudah = $this->guestNamesFrom(
            $sebelum->call('sortTable', 'reservation_date')->html()
        );

        $this->assertNotSame($awal, $sesudah, 'Klik pertama tidak mengubah urutan sama sekali.');
        $this->assertSame(['Tanti', 'Dharmadi', 'Ibu There'], $sesudah, 'Klik pertama harus memberi yang terbaru dulu.');
    }

    /**
     * SETIAP klik harus mengubah urutan, bukan hanya yang pertama.
     *
     * Filament memutar tiga keadaan: menaik, menurun, lalu tanpa urutan — dan
     * yang ketiga tampil persis sama dengan bawaan. Tanpa penanganan, keluhan
     * yang sama muncul lagi begitu pengguna mengklik satu putaran penuh.
     */
    public function test_every_click_keeps_changing_the_order(): void
    {
        $this->actingAs($this->admin);

        $halaman = Livewire::test(ListReservations::class);
        $sebelumnya = $this->guestNamesFrom($halaman->html());

        foreach (range(1, 4) as $klik) {
            $sekarang = $this->guestNamesFrom(
                $halaman->call('sortTable', 'reservation_date')->html()
            );

            $this->assertNotSame($sebelumnya, $sekarang, "Klik ke-{$klik} tidak mengubah apa pun.");

            $sebelumnya = $sekarang;
        }
    }

    /** Kolom lain tetap bisa diurutkan, dan klik pertamanya pun langsung terasa. */
    public function test_sorting_by_another_column_still_works(): void
    {
        $this->actingAs($this->admin);

        $halaman = Livewire::test(ListReservations::class);
        $awal = $this->guestNamesFrom($halaman->html());

        $sesudah = $this->guestNamesFrom($halaman->call('sortTable', 'pax')->html());

        $this->assertNotSame($awal, $sesudah);
        // pax 5, 40, 3 -> menaik: Tanti (3), Ibu There (5), Dharmadi (40)
        $this->assertSame(['Tanti', 'Ibu There', 'Dharmadi'], $sesudah);
    }

    /** @return array<int, string> */
    private function guestNamesFrom(string $html): array
    {
        return array_values(array_filter(array_map(
            fn (string $row) => $this->cell($row, 5),
            $this->rowsFrom($html),
        )));
    }

    // ---------------------------------------------------------------------
    // Filter bulan dan rentang tanggal. Diuji dari tab Semua, karena tab bulan
    // di atas tabel menyaring lebih dulu dan akan menutupi hasilnya.
    // ---------------------------------------------------------------------

    /** Reservasi jauh di depan, di luar jendela tab. */
    private function jauhDiDepan(): Reservation
    {
        return Reservation::factory()->create([
            'reservation_date' => Carbon::now()->startOfMonth()->addMonths(8)->addDays(4),
            'guest_name' => 'PESAN JAUH HARI',
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_the_month_filter_narrows_to_one_month(): void
    {
        $this->actingAs($this->admin);
        $jauh = $this->jauhDiDepan();

        Livewire::test(ListReservations::class)
            ->set('activeTab', 'all')
            ->filterTable('bulan', $jauh->reservation_date->format('Y-m'))
            ->assertCanSeeTableRecords([$jauh])
            ->assertCanNotSeeTableRecords([$this->singleTime, $this->range, $this->noRemark]);
    }

    /**
     * Daftar bulannya dibangun dari data, bukan rentang tetap — itu yang membuat
     * reservasi di luar jendela tab tetap punya jalan masuk sendiri.
     */
    public function test_the_month_options_include_months_outside_the_tab_window(): void
    {
        $this->actingAs($this->admin);
        $jauh = $this->jauhDiDepan();

        $html = $this->get('/cms/reservations')->assertOk()->getContent();

        $this->assertStringContainsString(
            $jauh->reservation_date->translatedFormat('F Y'),
            $html,
            'Bulan di luar jendela tab harus tetap bisa dipilih di filter.'
        );
    }

    public function test_the_date_range_filter_narrows_between_two_dates(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->set('activeTab', 'all')
            ->filterTable('rentang_tanggal', [
                'dari' => $this->range->reservation_date->toDateString(),
                'sampai' => $this->range->reservation_date->toDateString(),
            ])
            ->assertCanSeeTableRecords([$this->range])
            ->assertCanNotSeeTableRecords([$this->singleTime, $this->noRemark]);
    }

    /** Satu ujung saja sudah menyaring; tidak perlu mengisi keduanya. */
    public function test_only_the_start_date_is_enough(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->set('activeTab', 'all')
            ->filterTable('rentang_tanggal', ['dari' => $this->noRemark->reservation_date->toDateString()])
            ->assertCanSeeTableRecords([$this->noRemark])
            ->assertCanNotSeeTableRecords([$this->singleTime, $this->range]);
    }

    public function test_only_the_end_date_is_enough(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListReservations::class)
            ->set('activeTab', 'all')
            ->filterTable('rentang_tanggal', ['sampai' => $this->singleTime->reservation_date->toDateString()])
            ->assertCanSeeTableRecords([$this->singleTime])
            ->assertCanNotSeeTableRecords([$this->range, $this->noRemark]);
    }

    /**
     * Filter tanggal yang aktif harus terlihat sebagai indikator. Tanpa itu,
     * tabel kosong terbaca sebagai "tidak ada data" — bukan "sedang tersaring" —
     * dan pengguna mengira datanya hilang.
     */
    public function test_an_active_date_filter_is_visible_on_screen(): void
    {
        $this->actingAs($this->admin);

        $html = Livewire::test(ListReservations::class)
            ->set('activeTab', 'all')
            ->filterTable('rentang_tanggal', ['dari' => '2026-12-01', 'sampai' => '2026-12-31'])
            ->html();

        $this->assertStringContainsString('Dari 01 Des 2026', $html);
        $this->assertStringContainsString('Sampai 31 Des 2026', $html);
    }

    public function test_staff_is_not_offered_bulk_delete_but_admin_is(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff);
        Livewire::test(ListReservations::class)
            ->assertTableBulkActionHidden('delete');

        $this->actingAs($this->admin);
        Livewire::test(ListReservations::class)
            ->assertTableBulkActionVisible('delete');
    }
}
