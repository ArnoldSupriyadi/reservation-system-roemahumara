<?php

namespace Tests\Feature;

use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Menggantikan verifikasi manual Task 17 Step 7 poin 3 sampai 9.
 */
class MasterCrudTest extends TestCase
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

    public function test_a_master_name_is_stored_in_upper_case(): void
    {
        Livewire::test(ManageAreas::class)
            ->callAction('create', ['name' => '  vip 3  ', 'sort_order' => 8, 'is_active' => true])
            ->assertHasNoActionErrors();

        $this->assertSame('VIP 3', Area::latest('id')->value('name'));
    }

    public function test_a_duplicate_master_name_is_refused(): void
    {
        Area::create(['name' => 'VIP 3', 'sort_order' => 8]);

        Livewire::test(ManageAreas::class)
            ->callAction('create', ['name' => 'VIP 3', 'sort_order' => 9, 'is_active' => true])
            ->assertHasActionErrors(['name']);

        $this->assertSame(1, Area::where('name', 'VIP 3')->count());
    }

    /**
     * Tanpa penanganan di DeleteAction, ini menghasilkan halaman error 500
     * alih-alih pesan yang bisa dipahami staf.
     */
    public function test_deleting_a_master_in_use_warns_instead_of_crashing(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);
        Reservation::factory()->create([
            'area_id' => $area->id,
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        Livewire::test(ManageAreas::class)
            ->callAction(TestAction::make('delete')->table($area))
            ->assertNotified('Tidak bisa dihapus');

        $this->assertSame(1, Area::whereKey($area->id)->count(), 'Areanya harus tetap ada.');
    }

    public function test_an_unused_master_can_still_be_deleted(): void
    {
        $area = Area::create(['name' => 'GUDANG', 'sort_order' => 9]);

        Livewire::test(ManageAreas::class)
            ->callAction(TestAction::make('delete')->table($area));

        $this->assertSame(0, Area::whereKey($area->id)->count());
    }

    public function test_a_deactivated_master_disappears_from_the_reservation_form(): void
    {
        $active = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);
        $inactive = Area::create(['name' => 'GUDANG', 'sort_order' => 2, 'is_active' => false]);

        Livewire::test(CreateReservation::class)
            ->assertFormFieldExists('area_id', function ($field) use ($active, $inactive) {
                $options = $field->getOptions();

                return array_key_exists($active->id, $options)
                    && ! array_key_exists($inactive->id, $options);
            });
    }

    public function test_a_new_user_can_be_created_and_can_sign_in(): void
    {
        // Select tunggal menyimpan skalar, bukan array. Mengisinya dengan array
        // membuat role tidak tersimpan sama sekali tanpa error apa pun.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Staf Baru',
                'email' => 'staf.baru@umara.test',
                'password' => 'rahasia123',
                'roles' => Role::findByName('staff')->id,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'staf.baru@umara.test')->sole();

        $this->assertTrue($user->hasRole('staff'));
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('rahasia123', $user->password), 'Password harus tersimpan ter-hash.');
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('cms')));
    }

    public function test_editing_a_user_without_touching_the_password_keeps_it(): void
    {
        $user = User::factory()->create(['password' => Hash::make('rahasia123')]);
        $user->assignRole('staff');

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Nama Baru', 'password' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Nama Baru', $user->name);
        $this->assertTrue(Hash::check('rahasia123', $user->password), 'Password lama harus bertahan.');
    }

    public function test_a_role_can_be_swapped_from_the_edit_form(): void
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertFormSet(['roles' => Role::findByName('staff')->id])
            ->fillForm(['roles' => Role::findByName('admin')->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('staff'), 'Role lama harus tergantikan, bukan bertambah.');
    }

    public function test_you_cannot_switch_off_your_own_account(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
            ->assertFormFieldDisabled('is_active');

        $other = User::factory()->create();
        $other->assignRole('staff');

        Livewire::test(EditUser::class, ['record' => $other->getKey()])
            ->assertFormFieldEnabled('is_active');
    }

    public function test_the_user_pages_offer_no_way_to_delete_anyone(): void
    {
        $other = User::factory()->create();
        $other->assignRole('staff');

        Livewire::test(ListUsers::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(EditUser::class, ['record' => $other->getKey()])
            ->assertActionDoesNotExist('delete');
    }
}
