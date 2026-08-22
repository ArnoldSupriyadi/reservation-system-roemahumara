<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Menjalankan DatabaseSeeder utuh, bukan sub-seeder satu per satu.
 *
 * DatabaseSeeder memakai WithoutModelEvents, sementara spatie membatalkan cache
 * permission hanya lewat model event saved/deleted. Menguji RolePermissionSeeder
 * secara terpisah tidak pernah melewati jalur itu, sehingga kegagalannya baru
 * muncul saat `php artisan migrate:fresh --seed` dijalankan sungguhan.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_seed_assigns_permissions_despite_muted_model_events(): void
    {
        $this->seed();

        $this->assertCount(8, Role::findByName('admin')->permissions);
        $this->assertCount(3, Role::findByName('staff')->permissions);
    }

    public function test_full_seed_fills_the_master_tables(): void
    {
        $this->seed();

        $this->assertSame(12, Area::count());
        $this->assertSame(6, EventType::count());
        $this->assertSame(2, MenuStyle::count());
    }

    public function test_full_seed_produces_a_usable_admin_login(): void
    {
        $this->seed();

        $user = User::where('email', 'roemahmumara@gmail.com')->firstOrFail();

        $this->assertSame('Admin Roemah Umara', $user->name);
        $this->assertTrue(Hash::check(config('reservation.initial_password'), $user->password), 'Sandi awal harus mengikuti INITIAL_USER_PASSWORD.');
        $this->assertTrue($user->is_active, 'Akun tidak aktif ditolak middleware Filament dengan 403.');
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->can(Ability::DeleteReservation->value));
    }

    /**
     * db:seed harus aman diulang. Sebelumnya memakai factory()->create() biasa,
     * sehingga jalan kedua gagal karena email admin unik — dan gagalnya di tengah
     * jalan, setelah role dan master terlanjur dibuat.
     */
    public function test_seeding_twice_neither_fails_nor_duplicates_the_admin(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(1, User::where('email', 'roemahmumara@gmail.com')->count());
    }
}
