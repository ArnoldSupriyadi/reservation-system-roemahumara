<?php

namespace Tests\Feature;

use App\Exceptions\InvalidReservationException;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Models\Area;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use App\Support\Jam;
use Database\Seeders\MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jam tutup venue: 22:00, dari config('reservation.jam_tutup').
 *
 * Ditegakkan dua lapis — form Filament dengan pesan yang menunjuk kolomnya, dan
 * ReservationWriter untuk jalur yang melewati form (seeder, tinker, kode yang
 * ditulis nanti). Sengaja BUKAN CHECK constraint di database: ini aturan bisnis,
 * bukan bentuk data, dan jam tutup lebih mungkin berubah daripada tabelnya.
 */
class ClosingTimeTest extends TestCase
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
            'start_time' => '11:00',
        ], $ubah);
    }

    public function test_the_closing_time_comes_from_config(): void
    {
        $this->assertSame('22:00', (string) Jam::tutup());
    }

    /**
     * Tepat pukul tutup masih boleh — "sampai pukul 22:00" berarti 22:00 termasuk.
     */
    public function test_exactly_at_closing_time_is_allowed(): void
    {
        $r = app(ReservationWriter::class)->create(
            $this->data(['start_time' => '19:00', 'end_time' => '22:00']),
            (string) Str::uuid(),
            $this->staf
        );

        $this->assertSame('22:00', (string) $r->end_time);
    }

    /**
     * @param  string  $jam  jam selesai yang lebih malam daripada jam tutup
     */
    #[DataProvider('jamSetelahTutup')]
    public function test_the_writer_refuses_an_end_time_after_closing(string $jam): void
    {
        $this->expectException(InvalidReservationException::class);
        $this->expectExceptionMessage('melewati jam tutup 22:00');

        app(ReservationWriter::class)->create(
            $this->data(['start_time' => '19:00', 'end_time' => $jam]),
            (string) Str::uuid(),
            $this->staf
        );
    }

    /** @return array<string, array{string}> */
    public static function jamSetelahTutup(): array
    {
        return [
            'satu menit lewat' => ['22:01'],
            'setengah jam lewat' => ['22:30'],
            'tengah malam kurang semenit' => ['23:59'],
        ];
    }

    /**
     * Jam MULAI ikut dibatasi, bukan hanya jam selesai.
     *
     * Reservasi tanpa jam selesai diasumsikan berdurasi default oleh
     * ConflictChecker, jadi jam mulai 23:00 menghasilkan jendela yang berakhir
     * esok hari — persis keadaan yang batas ini ada untuk mencegahnya.
     */
    public function test_the_writer_refuses_a_start_time_after_closing(): void
    {
        $this->expectException(InvalidReservationException::class);
        $this->expectExceptionMessage('Jam mulai 23:00 melewati jam tutup 22:00');

        app(ReservationWriter::class)->create(
            $this->data(['start_time' => '23:00']),
            (string) Str::uuid(),
            $this->staf
        );
    }

    /**
     * Berlaku juga saat mengubah, bukan hanya saat membuat. Kalau hanya di
     * create, jam apa pun bisa masuk lewat satu kali edit.
     */
    public function test_editing_past_closing_time_is_refused_too(): void
    {
        $r = app(ReservationWriter::class)->create(
            $this->data(['start_time' => '19:00', 'end_time' => '21:00']),
            (string) Str::uuid(),
            $this->staf
        );

        $this->expectException(InvalidReservationException::class);

        app(ReservationWriter::class)->update($r, ['end_time' => '23:00'], $r->version, $this->staf);
    }

    /**
     * Batasnya mengikuti config, bukan angka yang diketik ulang di dalam kode.
     */
    public function test_the_limit_follows_the_config(): void
    {
        config(['reservation.jam_tutup' => '17:00']);

        $this->expectException(InvalidReservationException::class);
        $this->expectExceptionMessage('melewati jam tutup 17:00');

        app(ReservationWriter::class)->create(
            $this->data(['start_time' => '15:00', 'end_time' => '18:00']),
            (string) Str::uuid(),
            $this->staf
        );
    }

    public function test_the_form_refuses_an_end_time_after_closing(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['start_time' => '19:00', 'has_end_time' => true, 'end_time' => '23:00']))
            ->call('create')
            ->assertHasFormErrors(['end_time']);

        $this->assertSame(0, Reservation::count());
    }

    /**
     * Rentang yang diketik di kolom Jam mulai ikut diperiksa kedua ujungnya.
     *
     * Kalau hanya jam mulainya, "19.00-23.00" lolos dari form dan baru ditolak
     * writer dengan pesan yang jauh lebih kaku dan tidak menunjuk kolom.
     */
    public function test_the_form_checks_both_ends_of_a_typed_range(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['start_time' => '19.00-23.00']))
            ->call('create')
            ->assertHasFormErrors(['start_time']);

        $this->assertSame(0, Reservation::count());
    }

    public function test_the_form_still_accepts_a_normal_evening(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['start_time' => '19.00-22.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('22:00', (string) Reservation::sole()->end_time);
    }
}
