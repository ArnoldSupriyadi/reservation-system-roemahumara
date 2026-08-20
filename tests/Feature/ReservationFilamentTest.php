<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ReservationFilamentTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Rencana melewatkan dua baris berikut. Tanpa seeder, ->admin() melempar
        // RoleDoesNotExist; tanpa assignRole, staf tidak punya reservation.create
        // sehingga halaman Create menolak dengan 403 sebelum sempat diuji.
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');

        $this->staff = User::factory()->create(['name' => 'IRA']);
        $this->staff->assignRole('staff');
        $this->actingAs($this->staff);
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'phone' => '0811-2233-445',
            'pic_id' => $this->staff->id,
            'start_time' => '12.00',
            'end_time' => null,
            'pax' => 3,
            'status' => null,
            'remark' => null,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    public function test_staff_can_create_a_reservation(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::sole();

        $this->assertSame('08112233445', $r->phone, 'Nomor HP harus dinormalkan.');
        $this->assertSame('12:00:00', $r->start_time);
        $this->assertNull($r->end_time);
        $this->assertSame(1, $r->version);
        $this->assertSame($this->staff->id, $r->created_by);
        $this->assertNotNull($r->idempotency_key);
    }

    public function test_range_typed_into_start_time_is_split(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => '12.00-15.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::sole();

        $this->assertSame('12:00:00', $r->start_time);
        $this->assertSame('15:00:00', $r->end_time);
    }

    public function test_na_phone_is_rejected(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['phone' => 'NA']))
            ->call('create')
            ->assertHasFormErrors(['phone']);

        $this->assertSame(0, Reservation::count());
    }

    public function test_duplicate_shows_a_readable_error(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create');

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasFormErrors(['guest_name']);

        $this->assertSame(1, Reservation::count());
    }

    public function test_staff_cannot_set_confirmed(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['status' => 'confirmed']))
            ->call('create')
            ->assertHasFormErrors(['status']);

        $this->assertSame(0, Reservation::count());
    }

    public function test_admin_can_set_confirmed(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['status' => 'confirmed']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('confirmed', Reservation::sole()->status->value);
    }

    public function test_edit_increments_version_and_logs_the_change(): void
    {
        $r = Reservation::factory()->create(['pax' => 5, 'pic_id' => $this->staff->id]);
        $before = $r->activities()->count();

        Livewire::test(EditReservation::class, ['record' => $r->getKey()])
            ->fillForm(['pax' => 8])
            ->call('save')
            ->assertHasNoFormErrors();

        $r->refresh();

        $this->assertSame(8, $r->pax);
        $this->assertSame(2, $r->version);
        $this->assertSame($this->staff->id, $r->updated_by);
        $this->assertSame($before + 1, $r->activities()->count());
    }

    public function test_stale_version_is_rejected_and_changes_nothing(): void
    {
        $r = Reservation::factory()->create(['pax' => 5, 'pic_id' => $this->staff->id]);

        $page = Livewire::test(EditReservation::class, ['record' => $r->getKey()]);

        // Orang lain menyimpan lebih dulu.
        $r->pax = 8;
        $r->version = 2;
        $r->save();

        $page->fillForm(['pax' => 10])
            ->call('save')
            ->assertHasFormErrors(['version']);

        $this->assertSame(8, $r->fresh()->pax);
    }

    public function test_area_overlap_still_saves(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        Reservation::factory()->create([
            'area_id' => $area->id,
            'reservation_date' => '2026-08-07',
            'start_time' => '12:00:00',
            'guest_name' => 'Tamu Lebih Dulu',
        ]);

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['area_id' => $area->id, 'start_time' => '13.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Reservation::count());
    }

    /**
     * Task 14 Step 7 poin 4. Bentrok area memberi tahu, bukan menolak — jadi
     * yang perlu dibuktikan adalah peringatannya benar-benar muncul, bukan
     * sekadar penyimpanannya berhasil.
     */
    public function test_an_overlapping_area_raises_a_warning_naming_the_other_guest(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        Reservation::factory()->create([
            'area_id' => $area->id,
            'reservation_date' => '2026-08-07',
            'start_time' => '12:00:00',
            'guest_name' => 'Tamu Lebih Dulu',
        ]);

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['area_id' => $area->id, 'start_time' => '13.00']))
            ->call('create')
            ->assertNotified('Area bentrok');
    }

    public function test_a_reservation_without_an_area_raises_no_warning(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'start_time' => '12:00:00',
            'guest_name' => 'Tamu Lebih Dulu',
        ]);

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => '13.00']))
            ->call('create')
            ->assertNotNotified('Area bentrok');
    }

    /**
     * Task 14 Step 7 poin 5. Menekan Simpan dua kali cepat mengirim state form
     * yang sama, termasuk idempotency_key yang sama, sehingga hanya satu
     * reservasi boleh terbentuk.
     */
    public function test_pressing_save_twice_creates_only_one_reservation(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create')
            ->call('create');

        $this->assertSame(1, Reservation::count());
    }
}
