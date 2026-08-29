<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Models\Area;
use App\Models\EventType;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

        $this->assertSame(10, Area::count());
        $this->assertSame(10, EventType::count());
        $this->assertSame(137, Menu::count(), 'Seluruh hidangan dari menu.json.');
    }

    public function test_full_seed_produces_a_usable_admin_login(): void
    {
        $this->seed();

        $user = User::where('email', 'roemahumara@gmail.com')->firstOrFail();

        $this->assertSame('Admin Roemah Umara', $user->name);
        $this->assertTrue(Hash::check(config('reservation.initial_password'), $user->password), 'Sandi awal harus mengikuti INITIAL_USER_PASSWORD.');
        $this->assertTrue($user->is_active, 'Akun tidak aktif ditolak middleware Filament dengan 403.');
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->can(Ability::DeleteReservation->value));
    }

    /**
     * Penjaga yang lahir dari kejadian 2026-08-24.
     *
     * .env.production.example berisi INITIAL_USER_PASSWORD=CHANGE_ME_INITIAL_PASSWORD,
     * RUNBOOK menyuruh menyalin berkas itu jadi .env, dan langkah menggantinya
     * terlewat. Akun admin lahir bersandi placeholder tanpa satu pun tanda, lalu
     * login ditolak dengan pesan yang sama persis dengan "email tidak terdaftar"
     * — sehingga penyebabnya mustahil dibedakan dari layar.
     *
     * @param  string  $password  nilai .env yang tidak boleh diterima
     */
    #[DataProvider('placeholderPasswords')]
    public function test_seeding_refuses_a_placeholder_password(string $password): void
    {
        config(['reservation.initial_password' => $password]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INITIAL_USER_PASSWORD');

        $this->seed();
    }

    /** @return array<string, array{string}> */
    public static function placeholderPasswords(): array
    {
        return [
            'kosong' => [''],
            'dari .env.production.example' => ['CHANGE_ME_INITIAL_PASSWORD'],
            'dari .env.example' => ['ganti-nilai-ini-di-env'],
            'bekas nilai cadangan config' => ['password'],
        ];
    }

    /**
     * Yang ditolak adalah pembuatan akun, bukan seluruh seeder.
     *
     * Di server yang sudah berjalan, sandi admin sudah diganti lewat panel dan
     * .env tidak lagi relevan. Menolak `db:seed` di sana akan menghalangi
     * penambahan data master — padahal seeder ini justru dirancang aman diulang.
     */
    public function test_a_placeholder_password_is_tolerated_once_the_admin_exists(): void
    {
        $this->seed();

        config(['reservation.initial_password' => 'CHANGE_ME_INITIAL_PASSWORD']);

        $this->seed();

        $this->assertSame(1, User::where('email', 'roemahumara@gmail.com')->count());
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

        $this->assertSame(1, User::where('email', 'roemahumara@gmail.com')->count());
    }
}
