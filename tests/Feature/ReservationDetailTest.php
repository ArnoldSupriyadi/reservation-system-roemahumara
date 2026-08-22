<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Menggantikan verifikasi manual Task 15 Step 5 dengan pemeriksaan otomatis.
 *
 * Halaman ini merender audit-timeline.blade.php sungguhan, sehingga kesalahan
 * di dalam template ikut tertangkap.
 */
class ReservationDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');

        $this->admin = User::factory()->admin()->create(['name' => 'IRA']);
        $this->actingAs($this->admin);

        $this->area = Area::create(['name' => 'VIP 1']);
    }

    /**
     * Area diisi karena beberapa test di berkas ini menyimpan lewat form Edit,
     * dan area wajib sejak 2026-08-22. Factory sendiri masih membiarkannya null.
     */
    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'area_id' => $this->area->id,
        ], $overrides));
    }

    public function test_a_range_is_labelled_as_such(): void
    {
        $r = $this->reservation([
            'guest_name' => 'Dharmadi',
            'start_time' => '12:00:00',
            'end_time' => '15:00:00',
        ]);

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee('12:00–15:00 (rentang)');
    }

    public function test_a_single_time_is_labelled_as_such(): void
    {
        $r = $this->reservation([
            'guest_name' => 'Ibu There',
            'start_time' => '12:00:00',
            'end_time' => null,
        ]);

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee('12:00 (jam tunggal)');
    }

    /**
     * Aturan #4 CLAUDE.md. Kedua baris remark harus tampil utuh di detail.
     */
    public function test_remark_is_shown_in_full_including_the_second_line(): void
    {
        $r = $this->reservation([
            'remark' => "Pakai VIP 1 + VIP 2 + FOYER FnB sekaligus, sekat dibuka jam 11.30.\nGrand total sudah termasuk tax & service 21%.",
        ]);

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee('sekat dibuka jam 11.30.')
            ->assertSee('Grand total sudah termasuk tax & service 21%.');
    }

    public function test_a_reservation_without_remark_says_so(): void
    {
        $r = $this->reservation(['remark' => null]);

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee('Tidak ada remark.');
    }

    public function test_the_history_names_the_editor_and_shows_old_and_new_pax(): void
    {
        $r = $this->reservation(['pax' => 40]);

        Livewire::test(EditReservation::class, ['record' => $r->getKey()])
            ->fillForm(['pax' => 45])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee('IRA')
            ->assertSee('mengubah')
            ->assertSee('Pax')
            ->assertSee('40')
            ->assertSee('45');
    }

    public function test_a_changed_remark_keeps_both_the_old_and_the_new_text_whole(): void
    {
        $r = $this->reservation(['remark' => 'Catatan awal yang lama.']);

        Livewire::test(EditReservation::class, ['record' => $r->getKey()])
            ->fillForm(['remark' => "Baris pertama yang baru.\nBaris kedua yang baru."])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee('Catatan awal yang lama.')
            ->assertSee('Baris pertama yang baru.')
            ->assertSee('Baris kedua yang baru.');
    }

    public function test_clearing_a_field_is_recorded_as_kosong(): void
    {
        $r = $this->reservation(['company' => 'PT Contoh Sejahtera']);

        Livewire::test(EditReservation::class, ['record' => $r->getKey()])
            ->fillForm(['company' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee('Company')
            ->assertSee('PT Contoh Sejahtera')
            ->assertSee('kosong');
    }

    public function test_a_brand_new_reservation_shows_its_creation_not_an_empty_history(): void
    {
        $r = $this->reservation();

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee('membuat reservasi')
            ->assertDontSee('Belum ada riwayat perubahan.');
    }

    public function test_staff_is_not_offered_the_delete_button(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $r = $this->reservation();

        $this->actingAs($staff);
        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertActionHidden('delete');

        $this->actingAs($this->admin);
        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertActionVisible('delete');
    }
}
