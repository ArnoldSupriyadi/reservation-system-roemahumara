<?php

namespace Tests\Feature;

use App\Filament\Pages\ReservationCalendar;
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
 * Menggantikan verifikasi manual Task 16 Step 4 dengan pemeriksaan otomatis.
 */
class ReservationCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Reservation $singleTime;

    private Reservation $range;

    private Reservation $noStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');

        $this->admin = User::factory()->admin()->create(['name' => 'IRA']);
        $this->actingAs($this->admin);

        $area = Area::create(['name' => 'VIP 1']);
        $month = Carbon::now()->startOfMonth();

        $this->singleTime = Reservation::factory()->create([
            'reservation_date' => $month->copy()->addDays(7),
            'guest_name' => 'Ibu There',
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'start_time' => '12:00:00',
            'status' => 'tentative',
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

        $this->noStatus = Reservation::factory()->create([
            'reservation_date' => $month->copy()->addDays(9),
            'guest_name' => 'Tanti',
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'start_time' => '11:00:00',
            'pax' => 3,
        ]);
    }

    public function test_the_calendar_page_renders(): void
    {
        $this->get(ReservationCalendar::getUrl())->assertOk();
    }

    public function test_the_chips_show_time_and_guest_name(): void
    {
        Livewire::test(ReservationCalendar::class)
            ->assertSee('12:00')
            ->assertSee('Ibu There')
            ->assertSee('Dharmadi')
            ->assertSee('Tanti');
    }

    public function test_the_detail_panel_is_empty_until_a_chip_is_clicked(): void
    {
        Livewire::test(ReservationCalendar::class)
            ->assertSee('Klik salah satu reservasi di kalender')
            ->call('select', $this->range->id)
            ->assertDontSee('Klik salah satu reservasi di kalender');
    }

    /**
     * Pengecualian aturan #4 CLAUDE.md: chip kalender tidak memuat remark,
     * yang menggantinya adalah panel detail ini — dan di sana remark utuh.
     */
    public function test_the_detail_panel_shows_the_whole_remark(): void
    {
        Livewire::test(ReservationCalendar::class)
            ->call('select', $this->range->id)
            ->assertSee('sekat dibuka jam 11.30.')
            ->assertSee('Grand total sudah termasuk tax & service 21%.');
    }

    public function test_the_detail_panel_labels_single_times_and_ranges(): void
    {
        Livewire::test(ReservationCalendar::class)
            ->call('select', $this->singleTime->id)
            ->assertSee('12:00 (jam tunggal)');

        Livewire::test(ReservationCalendar::class)
            ->call('select', $this->range->id)
            ->assertSee('12:00–15:00 (rentang)');
    }

    public function test_a_reservation_without_remark_says_so_in_the_panel(): void
    {
        Livewire::test(ReservationCalendar::class)
            ->call('select', $this->noStatus->id)
            ->assertSee('Tidak ada remark.');
    }

    public function test_moving_to_another_month_clears_the_selection(): void
    {
        $page = Livewire::test(ReservationCalendar::class)
            ->call('select', $this->range->id)
            ->assertSet('selectedId', $this->range->id);

        $page->call('shiftMonth', 1)
            ->assertSet('selectedId', null)
            ->assertSet('month', Carbon::now()->startOfMonth()->addMonth()->format('Y-m'))
            ->assertSee('Klik salah satu reservasi di kalender');

        $page->call('shiftMonth', -1)
            ->assertSet('month', Carbon::now()->format('Y-m'));
    }

    public function test_only_the_active_month_is_listed(): void
    {
        Reservation::factory()->create([
            'reservation_date' => Carbon::now()->startOfMonth()->addMonth()->addDays(3),
            'guest_name' => 'Tamu Bulan Depan',
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $page = Livewire::test(ReservationCalendar::class);

        $this->assertCount(3, $page->instance()->getReservationsProperty());
        $page->assertDontSee('Tamu Bulan Depan');

        $page->call('shiftMonth', 1)->assertSee('Tamu Bulan Depan');
    }

    /**
     * Halaman ini hanya butuh viewAny, sehingga staf tetap boleh membukanya.
     */
    public function test_staff_may_open_the_calendar_but_an_inactive_user_may_not(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff);
        $this->assertTrue(ReservationCalendar::canAccess());
        $this->get(ReservationCalendar::getUrl())->assertOk();

        $inactive = User::factory()->inactive()->create();
        $inactive->assignRole('admin');

        $this->actingAs($inactive);
        $this->assertFalse(ReservationCalendar::canAccess());
    }

    public function test_the_month_label_is_written_in_indonesian(): void
    {
        $label = Livewire::test(ReservationCalendar::class)
            ->set('month', '2026-08')
            ->instance()
            ->getMonthLabelProperty();

        $this->assertSame('Agustus 2026', $label);
    }
}
