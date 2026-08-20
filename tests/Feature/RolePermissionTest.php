<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_every_ability_exists_as_a_permission(): void
    {
        $this->assertSame(
            Ability::values(),
            Permission::orderByRaw('FIELD(name, "'.implode('","', Ability::values()).'")')
                ->pluck('name')
                ->all()
        );
    }

    public function test_two_roles_are_seeded(): void
    {
        $this->assertSame(['admin', 'staff'], Role::orderBy('name')->pluck('name')->all());
    }

    public function test_staff_can_read_and_write_but_not_delete(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->assertTrue($staff->can(Ability::ViewReservation->value));
        $this->assertTrue($staff->can(Ability::CreateReservation->value));
        $this->assertTrue($staff->can(Ability::UpdateReservation->value));

        $this->assertFalse($staff->can(Ability::DeleteReservation->value));
        $this->assertFalse($staff->can(Ability::ConfirmReservation->value));
        $this->assertFalse($staff->can(Ability::ManageMaster->value));
        $this->assertFalse($staff->can(Ability::ManageUser->value));
        $this->assertFalse($staff->can(Ability::ManageRole->value));
    }

    public function test_admin_has_every_ability(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (Ability::cases() as $ability) {
            $this->assertTrue(
                $admin->can($ability->value),
                "Admin seharusnya punya {$ability->value}."
            );
        }
    }

    public function test_a_new_role_can_be_created_without_touching_code(): void
    {
        $manager = Role::create(['name' => 'manajer']);
        $manager->givePermissionTo([
            Ability::ViewReservation->value,
            Ability::CreateReservation->value,
            Ability::UpdateReservation->value,
            Ability::ConfirmReservation->value,
        ]);

        $user = User::factory()->create();
        $user->assignRole('manajer');

        $this->assertTrue($user->can(Ability::ConfirmReservation->value));
        $this->assertFalse($user->can(Ability::DeleteReservation->value));
        $this->assertFalse($user->can(Ability::ManageUser->value));
    }

    public function test_active_scope_excludes_inactive_users(): void
    {
        User::factory()->create(['name' => 'Ira']);
        User::factory()->inactive()->create(['name' => 'Mantan Staf']);

        $this->assertSame(['Ira'], User::query()->active()->pluck('name')->all());
    }
}
