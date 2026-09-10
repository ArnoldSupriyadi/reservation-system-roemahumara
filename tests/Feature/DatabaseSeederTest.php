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
     * Kebalikan dari penjaga lama, dan itu perubahan sadar (2026-09-10).
     *
     * Sampai 2026-09-10 nilai kosong atau placeholder membuat seeder MELEMPAR.
     * Penjagaan itu menjawab kejadian 2026-08-24 — akun admin lahir bersandi
     * placeholder tanpa satu pun tanda — tapi ongkosnya ditanggung setiap kali
     * `db:seed` dijalankan di mesin yang .env-nya belum disunting, termasuk
     * ketika yang dibutuhkan cuma tabel master.
     *
     * Sekarang jatuh ke sandi bawaan seeder. Masalah 2026-08-24 tetap tertutup,
     * lewat jalan lain: sandi yang terbentuk selalu DIKETAHUI. Karena itu yang
     * diuji di sini nilai harfiahnya — kalau konstantanya diganti tanpa
     * memperbarui CLAUDE.md, test ini yang berbunyi.
     *
     * @param  string  $password  nilai .env yang tidak boleh dipakai apa adanya
     */
    #[DataProvider('placeholderPasswords')]
    public function test_a_blank_or_placeholder_env_falls_back_to_the_built_in_password(string $password): void
    {
        config(['reservation.initial_password' => $password]);

        $this->seed();

        $user = User::where('email', 'roemahumara@gmail.com')->firstOrFail();

        $this->assertTrue(
            Hash::check('Umara2026!', $user->password),
            'Sandi bawaan seeder harus tetap Umara2026! selama CLAUDE.md menyebut nilai itu.'
        );
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
     * .env yang terisi tetap menang atas sandi bawaan.
     *
     * Itu satu-satunya cara memasang server yang terbuka ke internet tanpa
     * memakai sandi yang ada di dalam repositori.
     */
    public function test_a_filled_env_overrides_the_built_in_password(): void
    {
        config(['reservation.initial_password' => 'sandi-produksi-yang-lain']);

        $this->seed();

        $user = User::where('email', 'roemahumara@gmail.com')->firstOrFail();

        $this->assertTrue(Hash::check('sandi-produksi-yang-lain', $user->password));
        $this->assertFalse(Hash::check('Umara2026!', $user->password));
    }

    /**
     * Seeder tidak pernah memperbaiki akun yang sudah ada.
     *
     * Di server yang sudah berjalan, sandi admin sudah diganti lewat panel dan
     * .env tidak lagi relevan; `db:seed` di sana harus tetap boleh menambah data
     * master tanpa menyentuh akunnya.
     */
    public function test_seeding_again_never_touches_the_existing_admin(): void
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
