<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Menggantikan verifikasi manual Task 12 Step 4 dengan pemeriksaan otomatis.
 */
class ReservationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    public function test_the_reservation_pages_render_for_staff(): void
    {
        $this->actingAs($this->staff());

        $this->get('/cms/reservations')->assertOk();
        $this->get('/cms/reservations/create')->assertOk();
    }

    public function test_the_form_exposes_every_field_the_spec_asks_for(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(CreateReservation::class)
            ->assertFormFieldExists('reservation_date')
            ->assertFormFieldExists('guest_name')
            ->assertFormFieldExists('company')
            ->assertFormFieldExists('phone')
            ->assertFormFieldExists('email')
            ->assertFormFieldExists('pic_id')
            ->assertFormFieldExists('event_type_id')
            ->assertFormFieldExists('area_id')
            ->assertFormFieldExists('pax')
            ->assertFormFieldExists('start_time')
            ->assertFormFieldExists('status')
            ->assertFormFieldExists('remark');
    }

    public function test_end_time_stays_hidden_until_the_toggle_is_switched_on(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(CreateReservation::class)
            ->assertFormFieldHidden('end_time')
            ->set('data.has_end_time', true)
            ->assertFormFieldVisible('end_time')
            ->set('data.end_time', '15.00')
            ->set('data.has_end_time', false)
            ->assertFormFieldHidden('end_time')
            ->assertSet('data.end_time', null);
    }

    public function test_staff_is_not_offered_the_confirmed_status(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(CreateReservation::class)
            ->assertFormFieldExists(
                'status',
                fn ($field) => array_keys($field->getOptions()) === ['tentative', 'cancelled'],
            );
    }

    public function test_a_user_who_may_confirm_is_offered_the_confirmed_status(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateReservation::class)
            ->assertFormFieldExists(
                'status',
                fn ($field) => array_keys($field->getOptions()) === ['tentative', 'confirmed', 'cancelled'],
            );
    }

    /**
     * Tanpa User mengimplementasikan FilamentUser, middleware Filament menolak
     * semua orang dengan 403 di luar environment local — panel terkunci total
     * begitu dideploy. Test ini berjalan di environment testing, sehingga
     * kelulusannya sekaligus membuktikan gerbang itu terpasang.
     */
    public function test_an_inactive_user_cannot_reach_the_panel_at_all(): void
    {
        $inactive = User::factory()->inactive()->create();
        $inactive->assignRole('admin');

        $this->actingAs($inactive);

        $this->get('/cms/reservations')->assertForbidden();
    }

    public function test_the_idempotency_key_is_prefilled_and_survives_the_open_page(): void
    {
        $this->actingAs($this->staff());

        $page = Livewire::test(CreateReservation::class);

        $key = $page->get('data.idempotency_key');

        $this->assertNotNull($key, 'Hidden idempotency_key harus terisi saat form dibuka.');
        $this->assertSame(
            $key,
            $page->set('data.guest_name', 'Bapak Wanda')->get('data.idempotency_key'),
            'Kunci idempotency tidak boleh berubah selama halaman masih terbuka.'
        );
    }
}
