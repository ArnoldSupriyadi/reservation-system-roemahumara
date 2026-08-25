<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Models\Area;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bantuan pengisian jam: daftar saran, dan gema hasil bacaan.
 *
 * Dipilih alih-alih TimePicker. Alasannya dicatat di ReservationForm: kolom ini
 * menerima "12.00-15.00" dan memecahnya sendiri, dan TimePicker bawaannya
 * merender <input type="time"> yang ditampilkan browser menurut locale sistem —
 * di komputer berlocale Amerika kotaknya berbunyi 07:00 PM.
 */
class TimeFieldHelpersTest extends TestCase
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

    /** @return array<string, mixed> */
    private function data(array $ubah = []): array
    {
        return array_merge([
            'reservation_date' => today()->addDay()->toDateString(),
            'guest_name' => 'Tamu Uji',
            'phone' => '08120000000',
            'pic_id' => $this->staf->id,
            'event_type_id' => EventType::first()->id,
            'area_id' => Area::first()->id,
            'pax' => 10,
        ], $ubah);
    }

    /**
     * Saran berhenti di jam tutup, jadi tidak pernah menawarkan jam yang akan
     * ditolak validasi begitu dipilih.
     */
    public function test_the_suggestions_stop_at_closing_time(): void
    {
        Livewire::test(CreateReservation::class)
            ->assertSee('22:00')
            ->assertDontSee('23:00');
    }

    public function test_the_suggestions_follow_the_configured_closing_time(): void
    {
        config(['reservation.jam_tutup' => '17:00']);

        Livewire::test(CreateReservation::class)
            ->assertSee('17:00')
            ->assertDontSee('18:00');
    }

    /**
     * Gema menampakkan arti ketikan sebelum disimpan.
     *
     * Salah ketik yang paling mahal bukan yang ditolak sistem, melainkan yang
     * diterima dengan arti lain: mengetik 9 untuk maksud jam sembilan malam
     * tersimpan sebagai 09:00 tanpa ada yang keberatan.
     */
    public function test_a_bare_hour_is_echoed_as_a_full_time(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['start_time' => '9']))
            ->assertSee('Dibaca 09:00');
    }

    public function test_a_typed_range_is_echoed_as_two_times(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['start_time' => '12.00-15.00']))
            ->assertSee('Dibaca 12:00–15:00');
    }

    public function test_an_unreadable_time_is_echoed_as_such(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['start_time' => 'siang']))
            ->assertSee('Tidak dikenali');
    }

    /**
     * Gema menyebut jam tutup sebelum tombol Simpan ditekan. Validasinya sendiri
     * tetap berjalan saat menyimpan — gema ini mendahului, bukan menggantikan.
     */
    public function test_a_time_past_closing_is_flagged_in_the_echo(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['start_time' => '19.00-23.00']))
            ->assertSee('melewati jam tutup 22:00');
    }

    public function test_the_end_time_field_echoes_too(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data([
                'start_time' => '19:00',
                'has_end_time' => true,
                'end_time' => '21',
            ]))
            ->assertSee('Dibaca 21:00');
    }

    /**
     * Yang penting tidak berubah: mengetik bebas tetap tersimpan benar.
     */
    public function test_free_typing_still_works(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['start_time' => '19.00-22.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::sole();

        $this->assertSame('19:00', (string) $r->start_time);
        $this->assertSame('22:00', (string) $r->end_time);
    }
}
