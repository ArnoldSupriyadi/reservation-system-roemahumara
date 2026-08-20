<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');
    }

    public function test_staff_cannot_open_role_page(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/cms/roles')->assertForbidden();
    }

    public function test_admin_can_open_role_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/cms/roles')
            ->assertOk();
    }

    public function test_builtin_roles_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertFalse($admin->can('delete', Role::findByName('admin')));
        $this->assertFalse($admin->can('delete', Role::findByName('staff')));
    }

    public function test_custom_role_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $custom = Role::create(['name' => 'manajer']);

        $this->assertTrue($admin->can('delete', $custom));
    }

    public function test_new_role_takes_effect_immediately(): void
    {
        $manager = Role::create(['name' => 'manajer']);
        $manager->givePermissionTo(Ability::ConfirmReservation->value);

        $user = User::factory()->create();
        $user->assignRole('manajer');

        $this->assertTrue($user->can(Ability::ConfirmReservation->value));
    }
}
