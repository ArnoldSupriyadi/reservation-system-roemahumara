<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
     * Penjaga yang lahir dari kejadian 2026-08-24 pada akun admin.
     *
     * Di sini taruhannya sepuluh kali lipat: satu kali jalan dengan .env yang
     * belum diisi akan menghasilkan sepuluh akun bersandi placeholder sekaligus,
     * dan firstOrCreate tidak akan memperbaiki satu pun dari mereka.
     *
     * @param  string  $password  nilai .env yang tidak boleh diterima
     */
    #[DataProvider('placeholderPasswords')]
    public function test_it_refuses_a_placeholder_password(string $password): void
    {
        $this->seed(RolePermissionSeeder::class);

        config(['reservation.initial_password' => $password]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INITIAL_USER_PASSWORD');

        $this->seed(StaffSeeder::class);
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
     * Berhenti SEBELUM satu pun akun terbentuk, bukan di tengah perulangan.
     *
     * Kalau initialPassword() dipanggil di dalam foreach, akun pertama sudah
     * terlanjur jadi saat penjaganya berbunyi — meninggalkan database separuh
     * terisi yang lebih membingungkan daripada tidak terisi sama sekali.
     */
    public function test_refusing_leaves_no_half_created_accounts(): void
    {
        $this->seed(RolePermissionSeeder::class);

        config(['reservation.initial_password' => 'CHANGE_ME_INITIAL_PASSWORD']);

        try {
            $this->seed(StaffSeeder::class);
        } catch (RuntimeException) {
            // Yang diperiksa keadaan database sesudahnya, bukan pesannya.
        }

        $this->assertSame(0, User::count(), 'Tidak boleh ada akun yang terlanjur dibuat.');
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
