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
        $area = Area::create(['name' => 'VIP 1']);
        $month = Carbon::now()->startOfMonth();

        $this->singleTime = Reservation::factory()->create([
            'reservation_date' => $month->copy()->addDays(7),
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
