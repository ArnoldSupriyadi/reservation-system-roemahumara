<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rencana melewatkan dua baris ini. Tanpa seeder, ->admin() melempar
        // RoleDoesNotExist sebelum test sempat berjalan.
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    public function test_staff_cannot_open_master_pages(): void
    {
        $this->actingAs($this->staff())
            ->get('/cms/areas')
            ->assertForbidden();
    }

    public function test_admin_can_open_master_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/cms/areas')->assertOk();
        $this->actingAs($admin)->get('/cms/event-types')->assertOk();
        $this->actingAs($admin)->get('/cms/menu-styles')->assertOk();
    }

    public function test_staff_cannot_open_user_management(): void
    {
        $this->actingAs($this->staff())
            ->get('/cms/users')
            ->assertForbidden();
    }

    public function test_admin_can_open_user_management(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/cms/users')
            ->assertOk();
    }

    public function test_area_in_use_cannot_be_deleted(): void
    {
        $area = Area::create(['name' => 'VIP 1']);
        Reservation::factory()->create(['area_id' => $area->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $area->delete();
    }
}
