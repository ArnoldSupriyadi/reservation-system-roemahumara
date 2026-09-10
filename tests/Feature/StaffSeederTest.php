<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Daftar ini sengaja ditulis ulang di sini, bukan dibaca dari seedernya.
     * Membacanya dari sana membuat test ini selalu setuju dengan apa pun isinya —
     * termasuk entri yang salah ketik atau kehilangan emailnya.
     *
     * @var array<int, string>
     */
    private const AWALAN = [
        'denry', 'jimmy', 'difa', 'agus', 'ivo',
        'cassie', 'joesoef', 'ira', 'thea', 'ucr',
    ];

    public function test_it_creates_every_staff_account(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StaffSeeder::class);

        $this->assertSame(count(self::AWALAN), User::count());

        foreach (self::AWALAN as $awalan) {
            $this->assertTrue(
                User::where('email', $awalan.'@roemahumara.com')->exists(),
                "Akun {$awalan} tidak dibuat."
            );
        }
    }

    /**
     * Setiap entri harus punya nama DAN email. Menulis satu nilai tanpa kunci —
     * `'UCR',` alih-alih `'UCR' => 'ucr@...'` — menghasilkan pengguna bernama "0"
     * beralamat "UCR", dan tidak ada yang menghalanginya sampai ada yang mencoba
     * masuk.
     */
    public function test_no_account_has_a_numeric_name_or_a_malformed_email(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StaffSeeder::class);

        foreach (User::all() as $user) {
            $this->assertFalse(is_numeric($user->name), "Nama '{$user->name}' tampak seperti kunci larik yang hilang.");
            $this->assertNotFalse(
                filter_var($user->email, FILTER_VALIDATE_EMAIL),
                "Email '{$user->email}' tidak berbentuk email."
            );
        }
    }

    public function test_every_account_can_actually_sign_in_and_work(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StaffSeeder::class);

        $user = User::where('email', 'denry@roemahumara.com')->sole();

        $this->assertTrue(Hash::check(config('reservation.initial_password'), $user->password), 'Sandi awal harus mengikuti INITIAL_USER_PASSWORD.');
        $this->assertTrue($user->is_active, 'Akun tidak aktif akan ditolak middleware Filament dengan 403.');

        // Berperan staff saja tidak cukup kalau permissionnya belum terbaca.
        // Cache spatie yang tidak dibersihkan membuat pemeriksaan ini gagal.
        $this->assertTrue($user->hasRole('staff'));
        $this->assertTrue($user->can('reservation.create'));
        $this->assertFalse($user->can('reservation.delete'), 'Staf tidak boleh menghapus.');
    }

    /**
     * Aman diulang, dan yang lebih penting: TIDAK mengembalikan sandi yang sudah
     * diganti sendiri oleh penggunanya. Itu sebabnya seedernya memakai
     * firstOrCreate, bukan updateOrCreate.
     */
    public function test_running_it_again_neither_duplicates_nor_resets_passwords(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StaffSeeder::class);

        $user = User::where('email', 'ira@roemahumara.com')->sole();
        $user->password = Hash::make('sandi-baru-yang-dipilih-sendiri');
        $user->save();

        $this->seed(StaffSeeder::class);

        $this->assertSame(count(self::AWALAN), User::count(), 'Tidak boleh ada akun kembar.');
        $this->assertTrue(
            Hash::check('sandi-baru-yang-dipilih-sendiri', $user->fresh()->password),
            'Sandi yang sudah diganti tidak boleh dikembalikan ke sandi awal.'
        );
    }

    /**
     * .env kosong atau berisi placeholder jatuh ke sandi bawaan, tidak melempar.
     *
     * Sampai 2026-09-10 keadaan ini menghentikan seeder — lihat alasan lengkapnya
     * di DatabaseSeederTest. Di sini yang dijaga akibatnya untuk sepuluh orang
     * sekaligus: mereka semua lahir dengan sandi yang sama dan diketahui, bukan
     * dengan placeholder yang tidak bisa dipakai masuk.
     *
     * @param  string  $password  nilai .env yang tidak boleh dipakai apa adanya
     */
    #[DataProvider('placeholderPasswords')]
    public function test_a_blank_or_placeholder_env_falls_back_to_the_built_in_password(string $password): void
    {
        $this->seed(RolePermissionSeeder::class);

        config(['reservation.initial_password' => $password]);

        $this->seed(StaffSeeder::class);

        $this->assertSame(10, User::count(), 'Sepuluh akun staf harus tetap terbentuk.');

        foreach (User::all() as $user) {
            $this->assertTrue(
                Hash::check('Umara2026!', $user->password),
                "Sandi {$user->email} harus sandi bawaan seeder."
            );
        }
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
     * Sepuluh akun lahir dengan sandi yang sama persis, bukan sebagian.
     *
     * initialPassword() dibaca sekali di luar foreach justru untuk ini. Kalau ia
     * pindah ke dalam perulangan, perubahan config di tengah jalan akan membelah
     * sepuluh akun jadi dua kelompok bersandi berbeda — tanpa satu pun error.
     */
    public function test_every_account_gets_the_same_password(): void
    {
        $this->seed(RolePermissionSeeder::class);

        config(['reservation.initial_password' => 'sandi-produksi-yang-lain']);

        $this->seed(StaffSeeder::class);

        $this->assertSame(10, User::count());

        foreach (User::all() as $user) {
            $this->assertTrue(Hash::check('sandi-produksi-yang-lain', $user->password));
        }
    }

    /**
     * Tanpa role staff, seeder berhenti dengan peringatan alih-alih melempar
     * RoleDoesNotExist yang tidak menjelaskan apa yang harus dijalankan dulu.
     */
    public function test_it_stops_politely_when_the_staff_role_is_missing(): void
    {
        $this->seed(StaffSeeder::class);

        $this->assertSame(0, User::count());
    }
}
