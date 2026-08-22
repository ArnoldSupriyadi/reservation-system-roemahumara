<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 18 Step 8 — skenario yang menjadi alasan seluruh keputusan memakai spatie.
 *
 * Sembilan langkahnya dijalankan lewat UI Filament sungguhan, bukan lewat
 * pemanggilan model langsung: role dibuat lewat halaman Role, penugasannya
 * lewat halaman Pengguna. Tidak ada satu baris kode pun yang disentuh di
 * antara langkah-langkah ini — itulah yang sedang dibuktikan.
 */
class NewRoleEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');

        $this->admin = User::factory()->admin()->create(['name' => 'IRA']);
        $this->staff = User::factory()->create(['name' => 'Staf Uji']);
        $this->staff->assignRole('staff');
    }

    /**
     * Pivot role_has_permissions menyimpan id, bukan nama.
     *
     * @param  array<int, Ability>  $abilities
     * @return array<int, int>
     */
    private function permissionIds(array $abilities): array
    {
        return Permission::query()
            ->whereIn('name', array_map(fn (Ability $a) => $a->value, $abilities))
            ->pluck('id')
            ->all();
    }

    /** Langkah 1: daftar role menampilkan jumlah kemampuan dan penggunanya. */
    public function test_the_role_list_counts_abilities_and_users(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageRoles::class)
            ->assertCanSeeTableRecords([Role::findByName('admin'), Role::findByName('staff')])
            ->assertTableColumnStateSet('permissions_count', 8, Role::findByName('admin'))
            ->assertTableColumnStateSet('permissions_count', 3, Role::findByName('staff'))
            ->assertTableColumnStateSet('users_count', 1, Role::findByName('staff'));
    }

    /** Langkah 2 sampai 7, dijalankan berurutan sebagai satu cerita. */
    public function test_a_manager_role_created_through_the_ui_takes_effect_at_once(): void
    {
        $this->actingAs($this->admin);

        // Langkah 2: buat role manajer lewat halaman Role.
        Livewire::test(ManageRoles::class)
            ->callAction('create', [
                'name' => 'Manajer',
                'permissions' => $this->permissionIds([
                    Ability::ViewReservation,
                    Ability::CreateReservation,
                    Ability::UpdateReservation,
                    Ability::ConfirmReservation,
                ]),
            ])
            ->assertHasNoActionErrors();

        $manager = Role::findByName('manajer');
        $this->assertCount(4, $manager->permissions, 'Empat kemampuan harus tersimpan.');

        // Langkah 3: ubah role staf menjadi Manajer lewat halaman Pengguna.
        Livewire::test(EditUser::class, ['record' => $this->staff->getKey()])
            ->fillForm(['roles' => $manager->id])
            ->call('save')
            ->assertHasNoFormErrors();

        // Langkah 4: masuk sebagai akun itu.
        $this->staff->refresh();
        $this->assertTrue($this->staff->hasRole('manajer'));
        $this->actingAs($this->staff);

        // Langkah 5: opsi CONFIRMED sekarang muncul, padahal sebelumnya tidak.
        Livewire::test(CreateReservation::class)
            ->assertFormFieldExists(
                'status',
                fn ($field) => array_keys($field->getOptions()) === ['tentative', 'confirmed', 'cancelled'],
            );

        // Langkah 6: tombol Hapus reservasi tetap tidak muncul.
        $reservation = Reservation::factory()->create([
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        Livewire::test(ViewReservation::class, ['record' => $reservation->getKey()])
            ->assertActionHidden('delete');

        Livewire::test(ListReservations::class)
            ->assertTableBulkActionHidden('delete');

        // Langkah 7: menu master, pengguna, dan role tetap tertutup.
        $this->get('/cms/areas')->assertForbidden();
        $this->get('/cms/event-types')->assertForbidden();
        $this->get('/cms/menu-styles')->assertForbidden();
        $this->get('/cms/users')->assertForbidden();
        $this->get('/cms/roles')->assertForbidden();

        // Yang boleh, tetap boleh.
        $this->get('/cms/reservations')->assertOk();
    }

    /** Langkah 5 dibuktikan sebagai perubahan, bukan keadaan awal. */
    public function test_the_confirmed_option_was_genuinely_absent_before(): void
    {
        $this->actingAs($this->staff);

        Livewire::test(CreateReservation::class)
            ->assertFormFieldExists(
                'status',
                fn ($field) => array_keys($field->getOptions()) === ['tentative', 'cancelled'],
            );
    }

    /** Langkah 8: role bawaan tidak menawarkan tombol Hapus. */
    public function test_a_builtin_role_offers_no_delete_button(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageRoles::class)
            ->assertActionHidden(TestAction::make('delete')->table(Role::findByName('admin')))
            ->assertActionHidden(TestAction::make('delete')->table(Role::findByName('staff')));
    }

    /** Langkah 9: role buatan sendiri bisa dihapus. */
    public function test_a_custom_role_can_be_deleted_once_nobody_uses_it(): void
    {
        $this->actingAs($this->admin);

        $manager = Role::create(['name' => 'manajer']);
        $manager->givePermissionTo(Ability::ViewReservation->value);

        Livewire::test(ManageRoles::class)
            ->assertActionVisible(TestAction::make('delete')->table($manager))
            ->callAction(TestAction::make('delete')->table($manager));

        $this->assertNull(Role::where('name', 'manajer')->first());
    }

    /**
     * Aturan #8 CLAUDE.md. Filament menyimpan kemampuan lewat sync() pada pivot,
     * yang tidak memicu event model, sehingga cache spatie tidak ikut dibersihkan
     * sendiri. Tanpa flush di after(), hak akses baru tidak berlaku sampai cache
     * kedaluwarsa — dan gejalanya terlihat seperti "perubahan tidak tersimpan".
     */
    public function test_changing_abilities_takes_effect_without_waiting_for_the_cache(): void
    {
        $this->actingAs($this->admin);

        $manager = Role::create(['name' => 'manajer']);
        $manager->givePermissionTo(Ability::ViewReservation->value);

        $user = User::factory()->create();
        $user->assignRole('manajer');

        // Hangatkan cache dengan keadaan lama.
        $this->assertFalse($user->can(Ability::ConfirmReservation->value));

        Livewire::test(ManageRoles::class)
            ->callAction(TestAction::make('edit')->table($manager), [
                'name' => 'manajer',
                'permissions' => $this->permissionIds([
                    Ability::ViewReservation,
                    Ability::ConfirmReservation,
                ]),
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue(
            $user->fresh()->can(Ability::ConfirmReservation->value),
            'Kemampuan baru harus langsung berlaku, bukan menunggu cache kedaluwarsa.'
        );
    }
}
