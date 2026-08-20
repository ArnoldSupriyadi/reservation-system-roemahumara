<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Models\Reservation;
use App\Models\User;
use App\Policies\AreaPolicy;
use App\Policies\EventTypePolicy;
use App\Policies\MenuStylePolicy;
use App\Policies\ReservationPolicy;
use App\Policies\UserPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReservationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ReservationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->policy = new ReservationPolicy();
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    public function test_active_staff_can_read_and_write(): void
    {
        $staff = $this->staff();
        $r = Reservation::factory()->create();

        $this->assertTrue($this->policy->viewAny($staff));
        $this->assertTrue($this->policy->view($staff, $r));
        $this->assertTrue($this->policy->create($staff));
        $this->assertTrue($this->policy->update($staff, $r));
    }

    public function test_staff_cannot_delete_or_confirm(): void
    {
        $staff = $this->staff();
        $r = Reservation::factory()->create();

        $this->assertFalse($this->policy->delete($staff, $r));
        $this->assertFalse($this->policy->deleteAny($staff));
        $this->assertFalse($this->policy->confirm($staff, $r));
    }

    public function test_admin_can_delete_and_confirm(): void
    {
        $admin = User::factory()->admin()->create();
        $r = Reservation::factory()->create();

        $this->assertTrue($this->policy->delete($admin, $r));
        $this->assertTrue($this->policy->deleteAny($admin));
        $this->assertTrue($this->policy->confirm($admin, $r));
    }

    public function test_master_and_user_policies_follow_abilities(): void
    {
        $staff = $this->staff();
        $admin = User::factory()->admin()->create();

        foreach ([new AreaPolicy(), new EventTypePolicy(), new MenuStylePolicy(), new UserPolicy()] as $policy) {
            $this->assertFalse($policy->viewAny($staff), $policy::class.' seharusnya menolak staf.');
            $this->assertTrue($policy->viewAny($admin), $policy::class.' seharusnya mengizinkan admin.');
        }
    }

    public function test_inactive_user_can_do_nothing(): void
    {
        $inactive = User::factory()->inactive()->create();
        $inactive->assignRole('staff');
        $r = Reservation::factory()->create();

        $this->assertFalse($this->policy->viewAny($inactive));
        $this->assertFalse($this->policy->create($inactive));
        $this->assertFalse($this->policy->update($inactive, $r));
    }

    public function test_inactive_admin_can_do_nothing(): void
    {
        $inactive = User::factory()->admin()->inactive()->create();
        $r = Reservation::factory()->create();

        $this->assertFalse($this->policy->delete($inactive, $r));
        $this->assertFalse($this->policy->confirm($inactive, $r));
    }

    /**
     * Inilah alasan spatie dipasang. Role baru dengan kombinasi permission
     * yang belum pernah ada harus langsung bekerja tanpa menyentuh Policy.
     */
    public function test_a_new_role_works_without_changing_any_policy(): void
    {
        $manager = Role::create(['name' => 'manajer']);
        $manager->givePermissionTo([
            Ability::ViewReservation->value,
            Ability::UpdateReservation->value,
            Ability::ConfirmReservation->value,
        ]);

        $user = User::factory()->create();
        $user->assignRole('manajer');

        $r = Reservation::factory()->create();

        $this->assertTrue($this->policy->confirm($user, $r), 'Manajer seharusnya boleh confirm.');
        $this->assertFalse($this->policy->delete($user, $r), 'Manajer seharusnya tidak boleh hapus.');
        $this->assertFalse((new UserPolicy())->viewAny($user), 'Manajer seharusnya tidak boleh kelola pengguna.');
    }
}
