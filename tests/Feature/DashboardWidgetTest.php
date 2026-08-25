<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Filament\Widgets\TodayWidget;
use App\Filament\Widgets\UpcomingReservationsWidget;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $staf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(MasterSeeder::class);

        $this->staf = User::factory()->create();
        $this->staf->assignRole('staff');
        $this->actingAs($this->staf);
    }

    /**
     * Lewat factory, bukan Reservation::create(): nomor reservasi dialokasikan
     * NumberSequence dan kolomnya NOT NULL, jadi penyisipan telanjang ditolak
     * database.
     */
    private function buatReservasi(array $atribut = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'reservation_date' => today()->addDay(),
            'start_time' => '11:00',
            'guest_name' => 'Tamu Uji',
            'pax' => 10,
            'area_id' => Area::first()->id,
            'pic_id' => $this->staf->id,
        ], $atribut));
    }

    public function test_the_widget_lists_upcoming_reservations(): void
    {
        $besok = $this->buatReservasi(['guest_name' => 'Acara Besok']);

        Livewire::test(UpcomingReservationsWidget::class)
            ->assertCanSeeTableRecords([$besok]);
    }

    /**
     * Mulai hari ini, bukan besok: acara yang berlangsung hari ini justru yang
     * paling sering ditanyakan, dan menghilangkannya lewat tengah malam akan
     * mengosongkan dashboard tepat di pagi acara.
     */
    public function test_todays_reservation_is_still_listed(): void
    {
        $hariIni = $this->buatReservasi(['reservation_date' => today(), 'guest_name' => 'Acara Hari Ini']);

        Livewire::test(UpcomingReservationsWidget::class)
            ->assertCanSeeTableRecords([$hariIni]);
    }

    public function test_past_reservations_are_left_out(): void
    {
        $kemarin = $this->buatReservasi(['reservation_date' => today()->subDay(), 'guest_name' => 'Sudah Lewat']);

        Livewire::test(UpcomingReservationsWidget::class)
            ->assertCanNotSeeTableRecords([$kemarin]);
    }

    /**
     * Aturan #9 CLAUDE.md. Reservasi batal tidak memakai tempat, jadi
     * memampangkannya di ringkasan sepuluh baris hanya mendorong keluar acara
     * yang sungguh berjalan.
     */
    public function test_cancelled_reservations_are_left_out(): void
    {
        $batal = $this->buatReservasi([
            'guest_name' => 'Sudah Batal',
            'status' => ReservationStatus::Cancelled->value,
        ]);

        Livewire::test(UpcomingReservationsWidget::class)
            ->assertCanNotSeeTableRecords([$batal]);
    }

    /**
     * Status NULL berarti "belum ditentukan", bukan batal — reservasi yang
     * statusnya belum diisi tetap memakai tempat dan harus terlihat. Ditulis
     * sebagai test tersendiri karena `status != 'cancelled'` di SQL TIDAK
     * mengembalikan baris ber-NULL, dan bolongnya senyap.
     */
    public function test_reservations_without_a_status_are_still_listed(): void
    {
        $belumDitentukan = $this->buatReservasi(['status' => null, 'guest_name' => 'Belum Ditentukan']);

        Livewire::test(UpcomingReservationsWidget::class)
            ->assertCanSeeTableRecords([$belumDitentukan]);
    }

    /**
     * Sepuluh baris terdekat, sisanya tidak ikut.
     *
     * Yang diperiksa baris yang benar-benar dirender, bukan
     * assertCountTableRecords: hitungan itu memakai query COUNT tersendiri yang
     * mengabaikan LIMIT, jadi ia melaporkan seluruh isi tabel dan tidak pernah
     * bisa membuktikan pemotongannya.
     */
    public function test_it_shows_the_ten_nearest_and_no_more(): void
    {
        $semua = collect(range(1, 12))->map(fn (int $i) => $this->buatReservasi([
            'reservation_date' => today()->addDays($i),
            'guest_name' => "Tamu {$i}",
        ]));

        Livewire::test(UpcomingReservationsWidget::class)
            ->assertCanSeeTableRecords($semua->take(10))
            ->assertCanNotSeeTableRecords($semua->skip(10));
    }

    /**
     * Aturan #4 CLAUDE.md: remark tampil penuh, tanpa limit dan tanpa
     * disembunyikan di balik toggle.
     */
    public function test_the_remark_column_is_never_truncated(): void
    {
        $panjang = str_repeat('Pembayaran DP 50% lewat transfer BCA. ', 10);
        $this->buatReservasi(['remark' => $panjang]);

        Livewire::test(UpcomingReservationsWidget::class)
            ->assertSee(trim($panjang));
    }

    /**
     * Aturan #3 CLAUDE.md: yang diperiksa Ability, bukan nama role. Role tanpa
     * hak lihat reservasi tidak boleh melihat nama tamu dan remark hanya karena
     * ia membuka dashboard.
     */
    public function test_a_role_without_view_permission_cannot_see_the_widget(): void
    {
        Role::create(['name' => 'tanpa-hak', 'guard_name' => 'web']);

        $orang = User::factory()->create();
        $orang->assignRole('tanpa-hak');
        $this->actingAs($orang);

        $this->assertFalse(UpcomingReservationsWidget::canView());
    }

    public function test_staff_can_see_the_widget(): void
    {
        $this->assertTrue(UpcomingReservationsWidget::canView());
    }

    /**
     * Rentang minggu berhenti di ujung minggu ini, tidak melimpah ke minggu
     * depan.
     *
     * Tanggalnya dipatok ke Rabu supaya testnya tidak berubah arti tergantung
     * hari kapan ia dijalankan — di hari Minggu, "akhir minggu" adalah hari itu
     * sendiri dan tidak ada satu pun tanggal yang bisa diuji di belakangnya.
     */
    public function test_the_weekly_range_stops_at_the_end_of_this_week(): void
    {
        $this->travelTo(today()->startOfWeek()->addDays(2)->setTime(9, 0));

        $dalamMinggu = $this->buatReservasi([
            'reservation_date' => today()->endOfWeek(),
            'guest_name' => 'Masih Minggu Ini',
        ]);
        $mingguDepan = $this->buatReservasi([
            'reservation_date' => today()->endOfWeek()->addDay(),
            'guest_name' => 'Sudah Minggu Depan',
        ]);

        Livewire::test(UpcomingReservationsWidget::class)
            ->call('pilihRentang', 'minggu')
            ->assertCanSeeTableRecords([$dalamMinggu])
            ->assertCanNotSeeTableRecords([$mingguDepan]);
    }

    public function test_the_monthly_range_stops_at_the_end_of_this_month(): void
    {
        $this->travelTo(today()->startOfMonth()->addDays(3)->setTime(9, 0));

        $dalamBulan = $this->buatReservasi([
            'reservation_date' => today()->endOfMonth(),
            'guest_name' => 'Masih Bulan Ini',
        ]);
        $bulanDepan = $this->buatReservasi([
            'reservation_date' => today()->endOfMonth()->addDay(),
            'guest_name' => 'Sudah Bulan Depan',
        ]);

        Livewire::test(UpcomingReservationsWidget::class)
            ->call('pilihRentang', 'bulan')
            ->assertCanSeeTableRecords([$dalamBulan])
            ->assertCanNotSeeTableRecords([$bulanDepan]);
    }

    /**
     * Batas sepuluh baris hanya berlaku pada rentang Terdekat. Minggu dan bulan
     * punya ujung tanggalnya sendiri; memotongnya lagi di angka sepuluh akan
     * menyembunyikan acara yang justru diminta pengguna saat menekan tombolnya.
     */
    public function test_the_monthly_range_is_not_capped_at_ten(): void
    {
        $this->travelTo(today()->startOfMonth()->setTime(9, 0));

        $semua = collect(range(1, 12))->map(fn (int $i) => $this->buatReservasi([
            'reservation_date' => today()->addDays($i),
            'guest_name' => "Tamu {$i}",
        ]));

        Livewire::test(UpcomingReservationsWidget::class)
            ->call('pilihRentang', 'bulan')
            ->assertCanSeeTableRecords($semua);
    }

    /**
     * Rentang minggu dan bulan memuat satu periode PENUH, termasuk yang lampau.
     *
     * Sempat dipotong dari hari ini, dan itu keliru: pertanyaan "bulan ini ada
     * apa saja" hampir selalu berarti seluruh bulannya, dan memotongnya membuat
     * "Bulan ini" nyaris kosong setiap akhir bulan.
     */
    public function test_the_monthly_range_includes_dates_already_past(): void
    {
        $this->travelTo(today()->startOfMonth()->addDays(10)->setTime(9, 0));

        $lampau = $this->buatReservasi([
            'reservation_date' => today()->startOfMonth(),
            'guest_name' => 'Awal Bulan Lampau',
        ]);

        Livewire::test(UpcomingReservationsWidget::class)
            ->call('pilihRentang', 'bulan')
            ->assertCanSeeTableRecords([$lampau]);
    }

    public function test_the_weekly_range_includes_dates_already_past(): void
    {
        $this->travelTo(today()->startOfWeek()->addDays(3)->setTime(9, 0));

        $lampau = $this->buatReservasi([
            'reservation_date' => today()->startOfWeek(),
            'guest_name' => 'Awal Minggu Lampau',
        ]);

        Livewire::test(UpcomingReservationsWidget::class)
            ->call('pilihRentang', 'minggu')
            ->assertCanSeeTableRecords([$lampau]);
    }

    /**
     * Tapi Terdekat tetap bersih dari yang lampau — itu justru gunanya, dan
     * itulah yang ditawarkan peringatan sebagai jalan keluar.
     */
    public function test_the_nearest_range_still_excludes_the_past(): void
    {
        $this->travelTo(today()->startOfMonth()->addDays(10)->setTime(9, 0));

        $lampau = $this->buatReservasi([
            'reservation_date' => today()->subDay(),
            'guest_name' => 'Kemarin',
        ]);

        Livewire::test(UpcomingReservationsWidget::class)
            ->assertCanNotSeeTableRecords([$lampau]);
    }

    /**
     * Peringatannya muncul sebelum ada satu pun baris lampau di layar.
     *
     * Sengaja bergantung pada RENTANG yang dipilih, bukan pada isi datanya:
     * peringatan yang muncul-hilang tergantung data membuat pengguna berhenti
     * mempercayainya, dan di tanggal 1 setiap bulan ia tidak akan pernah muncul
     * meski rentangnya persis sama.
     */
    public function test_the_range_that_includes_the_past_is_announced(): void
    {
        Livewire::test(UpcomingReservationsWidget::class)
            ->assertDontSee('termasuk reservasi yang tanggalnya sudah lewat')
            ->call('pilihRentang', 'bulan')
            ->assertSee('seluruh bulan ini')
            ->assertSee('termasuk reservasi yang tanggalnya sudah lewat')
            ->call('pilihRentang', 'minggu')
            ->assertSee('seluruh minggu ini')
            ->call('pilihRentang', 'terdekat')
            ->assertDontSee('termasuk reservasi yang tanggalnya sudah lewat');
    }

    /**
     * Peringatan menjelaskan daftarnya secara keseluruhan; penanda per baris
     * yang menjawab "yang mana" tanpa pembacanya membandingkan setiap tanggal
     * dengan hari ini di kepalanya.
     */
    public function test_each_past_row_is_marked_on_its_own(): void
    {
        $this->travelTo(today()->startOfMonth()->addDays(10)->setTime(9, 0));

        $tanggal = today()->startOfMonth();
        $this->buatReservasi(['reservation_date' => $tanggal, 'guest_name' => 'Lampau']);

        // Nama harinya ikut diperiksa, bukan cuma frasa "sudah lewat": frasa itu
        // muncul juga di pita peringatan, jadi memeriksanya sendirian akan lulus
        // meski penanda per barisnya hilang sama sekali.
        Livewire::test(UpcomingReservationsWidget::class)
            ->call('pilihRentang', 'bulan')
            ->assertSee($tanggal->translatedFormat('l').' · sudah lewat');
    }

    public function test_the_clock_widget_renders_todays_date(): void
    {
        Livewire::test(TodayWidget::class)
            ->assertSee(today()->translatedFormat('l, j F Y'));
    }
}
