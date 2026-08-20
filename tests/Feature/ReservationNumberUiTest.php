<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ReservationNumberUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');

        $this->admin = User::factory()->admin()->create(['name' => 'IRA']);
        $this->actingAs($this->admin);
    }

    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'reservation_date' => Carbon::now()->startOfMonth()->addDays(5),
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    /** Spec nomor 29. */
    public function test_the_number_is_shown_in_the_table(): void
    {
        $r = $this->reservation();

        Livewire::test(ListReservations::class)
            ->assertTableColumnStateSet('reservation_number', $r->reservation_number, $r);
    }

    public function test_the_number_can_be_searched(): void
    {
        $wanted = $this->reservation(['guest_name' => 'Dicari']);
        $other = $this->reservation(['guest_name' => 'Bukan Ini']);

        Livewire::test(ListReservations::class)
            ->searchTable($wanted->reservation_number)
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_number_is_shown_on_the_detail_page(): void
    {
        $r = $this->reservation();

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee($r->reservation_number);
    }

    /**
     * Nomor ditetapkan sistem. Menawarkannya sebagai field yang bisa diisi akan
     * mengundang pengguna menimpanya.
     */
    public function test_the_number_is_not_offered_as_a_form_field(): void
    {
        Livewire::test(CreateReservation::class)
            ->assertFormFieldDoesNotExist('reservation_number');
    }
}
