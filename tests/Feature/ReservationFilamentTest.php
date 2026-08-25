<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Filament\Resources\Reservations\ReservationResource;
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

    private Area $area;

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

        // Area kini wajib diisi. Dibuat terpisah dari VIP 1 yang dipakai
        // occupyArea(), supaya form bawaan tidak bentrok dengan sendirinya.
        $this->area = Area::create(['name' => 'REGULAR']);
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'phone' => '0811-2233-445',
            'pic_id' => $this->staff->id,
            'area_id' => $this->area->id,
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
        $this->assertSame('12:00', (string) $r->start_time);
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

        $this->assertSame('12:00', (string) $r->start_time);
        $this->assertSame('15:00', (string) $r->end_time);
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
        $r = Reservation::factory()->create(['pax' => 5, 'pic_id' => $this->staff->id, 'area_id' => $this->area->id]);
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
        $r = Reservation::factory()->create(['pax' => 5, 'pic_id' => $this->staff->id, 'area_id' => $this->area->id]);

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

    /** Reservasi lain yang menempati VIP 1 pada 2026-08-07 jam 12.00. */
    private function occupyArea(): Area
    {
        $area = Area::create(['name' => 'VIP 1']);

        Reservation::factory()->create([
            'area_id' => $area->id,
            'reservation_date' => '2026-08-07',
            'start_time' => '12:00:00',
            'guest_name' => 'Tamu Lebih Dulu',
        ]);

        return $area;
    }

    /**
     * Bentrok area tidak dilarang, tapi menuntut penjelasan. Sampai 2026-08-22
     * ia hanya diperingatkan dan tetap tersimpan; sekarang Remark wajib diisi.
     *
     * Melarang keras terdengar lebih aman, tapi akan mendorong staf mengosongkan
     * kolom Area supaya bisa menyimpan — dan begitu Area kosong, pengecekan
     * bentrok mati total untuk baris itu.
     */
    public function test_an_area_conflict_without_a_remark_is_refused(): void
    {
        $area = $this->occupyArea();

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData([
                'area_id' => $area->id,
                'start_time' => '13.00',
                'remark' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['remark']);

        $this->assertSame(1, Reservation::count(), 'Tidak boleh tersimpan lebih dulu lalu ditolak.');
    }

    public function test_an_area_conflict_saves_once_the_remark_explains_it(): void
    {
        $area = $this->occupyArea();

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData([
                'area_id' => $area->id,
                'start_time' => '13.00',
                'remark' => 'Sekat VIP 1 dan VIP 2 dibuka, dipakai satu rombongan.',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Reservation::count());
    }

    /**
     * Task 14 Step 7 poin 4. Peringatannya tetap muncul setelah tersimpan —
     * kewajiban mengisi Remark tidak menghapus kebutuhan melihat bentrok
     * dengan siapa.
     */
    public function test_an_overlapping_area_raises_a_warning_naming_the_other_guest(): void
    {
        $area = $this->occupyArea();

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData([
                'area_id' => $area->id,
                'start_time' => '13.00',
                'remark' => 'Acara berurutan, bongkar-pasang 30 menit.',
            ]))
            ->call('create')
            ->assertNotified('Area bentrok');
    }

    /** Remark berisi spasi saja bukan penjelasan. */
    public function test_a_blank_remark_does_not_count_as_an_explanation(): void
    {
        $area = $this->occupyArea();

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData([
                'area_id' => $area->id,
                'start_time' => '13.00',
                'remark' => '   ',
            ]))
            ->call('create')
            ->assertHasFormErrors(['remark']);

        $this->assertSame(1, Reservation::count());
    }

    /** Aturannya harus sama di Edit, bukan hanya di Create. */
    public function test_editing_into_a_conflict_also_requires_a_remark(): void
    {
        $area = $this->occupyArea();

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => '13.00']))
            ->call('create');

        $mine = Reservation::where('guest_name', 'Bapak Wanda')->sole();

        Livewire::test(EditReservation::class, ['record' => $mine->getKey()])
            ->fillForm(['area_id' => $area->id])
            ->call('save')
            ->assertHasFormErrors(['remark']);

        $this->assertSame(
            $this->area->id,
            $mine->fresh()->area_id,
            'Perubahan tidak boleh tersimpan; areanya harus tetap yang semula.'
        );

        Livewire::test(EditReservation::class, ['record' => $mine->getKey()])
            ->fillForm(['area_id' => $area->id, 'remark' => 'Sekat dibuka.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($area->id, $mine->fresh()->area_id);
    }

    /** Reservasi batal tidak memakai tempat, jadi tidak menuntut penjelasan. */
    public function test_a_cancelled_reservation_does_not_demand_a_remark(): void
    {
        $area = Area::create(['name' => 'VIP 1']);

        Reservation::factory()->create([
            'area_id' => $area->id,
            'reservation_date' => '2026-08-07',
            'start_time' => '12:00:00',
            'guest_name' => 'Tamu Batal',
            'status' => ReservationStatus::Cancelled,
        ]);

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData([
                'area_id' => $area->id,
                'start_time' => '13.00',
                'remark' => null,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Reservation::count());
    }

    /**
     * Jam yang sama di area berbeda bukan bentrok, dan karenanya tidak menuntut
     * Remark. Menggantikan test lama yang menguji reservasi tanpa area — sejak
     * area wajib diisi, keadaan itu tidak bisa lagi dicapai lewat form. Sisi
     * area-kosong pada ConflictChecker tetap diuji di ConflictCheckerTest.
     */
    public function test_the_same_hour_in_a_different_area_is_not_a_conflict(): void
    {
        $this->occupyArea();

        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => '12.00', 'remark' => null]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotNotified('Area bentrok');

        $this->assertSame(2, Reservation::count());
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

    /**
     * Selesai menyimpan, kembali ke daftar.
     *
     * Disetel di tingkat panel lewat resourceCreatePageRedirect(), bukan
     * getRedirectUrl() per halaman — jadi test ini sekaligus membuktikan
     * setelan panelnya terpasang, bukan hanya satu halaman yang kebetulan benar.
     */
    public function test_creating_redirects_back_to_the_list(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ReservationResource::getUrl('index'));
    }

    public function test_editing_redirects_back_to_the_list(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create');

        $r = Reservation::sole();

        Livewire::test(EditReservation::class, ['record' => $r->getKey()])
            ->fillForm(['pax' => 9])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ReservationResource::getUrl('index'));

        $this->assertSame(9, $r->fresh()->pax);
    }
}
