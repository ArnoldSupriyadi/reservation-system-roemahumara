<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Menutup butir spec bagian 11 yang belum diuji task mana pun:
 * nomor 2 (field wajib), 4 dan 18 (urutan jam), 20 (variasi penulisan jam),
 * serta bagian kedua nomor 14 (pengalihan setelah submit berulang).
 */
class ReservationValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** Spec 11 nomor 2. */
    public static function requiredFields(): array
    {
        return [
            'tanggal' => ['reservation_date'],
            'nama tamu' => ['guest_name'],
            'telepon' => ['phone'],
            'PIC' => ['pic_id'],
            'jam mulai' => ['start_time'],
            'pax' => ['pax'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_a_missing_required_field_is_refused(string $field): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData([$field => null]))
            ->call('create')
            ->assertHasFormErrors([$field]);

        $this->assertSame(0, Reservation::count());
    }

    /** Spec 11 nomor 4 dan 18. */
    public function test_an_end_time_before_the_start_time_is_refused(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData([
                'has_end_time' => true,
                'start_time' => '15.00',
                'end_time' => '10.00',
            ]))
            ->call('create')
            ->assertHasFormErrors(['end_time']);

        $this->assertSame(0, Reservation::count());
    }

    /** Spec 11 nomor 18: sama persis juga ditolak, bukan hanya lebih awal. */
    public function test_an_end_time_equal_to_the_start_time_is_refused(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData([
                'has_end_time' => true,
                'start_time' => '12.00',
                'end_time' => '12.00',
            ]))
            ->call('create')
            ->assertHasFormErrors(['end_time']);

        $this->assertSame(0, Reservation::count());
    }

    /**
     * Rentang terbalik yang diketik pada satu kolom juga harus ditolak.
     * Kalau lolos, ConflictChecker menghitung jendela [900, 600] — akhir
     * mendahului awal — dan deteksi bentrok meleset tanpa memberi tanda.
     */
    public function test_an_inverted_range_typed_into_one_field_is_refused(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => '15.00-10.00']))
            ->call('create')
            ->assertHasFormErrors(['start_time']);

        $this->assertSame(0, Reservation::count());
    }

    public function test_a_proper_range_typed_into_one_field_still_passes(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => '12.00-15.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::sole();

        $this->assertSame('12:00:00', $r->start_time);
        $this->assertSame('15:00:00', $r->end_time);
    }

    public function test_an_unparseable_time_is_refused(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => 'siang']))
            ->call('create')
            ->assertHasFormErrors(['start_time']);

        $this->assertSame(0, Reservation::count());
    }

    /** Spec 11 nomor 20. */
    public static function timeSpellings(): array
    {
        return [
            'jam saja' => ['11'],
            'titik' => ['11.00'],
            'titik dua' => ['11:00'],
        ];
    }

    #[DataProvider('timeSpellings')]
    public function test_every_spelling_of_eleven_is_stored_the_same(string $spelling): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => $spelling]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('11:00:00', Reservation::sole()->start_time);
    }

    /** Spec 11 nomor 14, bagian kedua: submit ulang mengalihkan ke record itu. */
    public function test_a_repeated_submit_redirects_to_the_same_record(): void
    {
        $data = $this->formData();

        Livewire::test(CreateReservation::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $first = Reservation::sole();

        Livewire::test(CreateReservation::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect("/cms/reservations/{$first->id}");

        $this->assertSame(1, Reservation::count());
    }

    /**
     * Spec 11 nomor 11: pesan duplikat menyebut nama dan tanggal,
     * bukan sekadar menandai field-nya merah.
     */
    public function test_the_duplicate_message_names_the_guest_and_the_date(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create');

        $page = Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasFormErrors(['guest_name']);

        $message = $page->errors()->get('data.guest_name')[0] ?? '';

        $this->assertStringContainsString('Bapak Wanda', $message);
        $this->assertStringContainsString('07/08/2026', $message);
    }
}
