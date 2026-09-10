<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\ReadsInitialPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sepuluh akun staf Roemah Umara.
 *
 * Sengaja TIDAK dipanggil dari DatabaseSeeder, sama seperti ReservationDemoSeeder:
 * `db:seed` polos harus tetap menghasilkan sistem yang bisa dipasang di mana saja.
 * Jalankan sendiri saat memang menyiapkan sistem untuk tim ini:
 *
 *     php artisan db:seed --class=StaffSeeder
 *
 * Aman diulang. Memakai firstOrCreate, bukan updateOrCreate — kalau ada yang sudah
 * mengganti sandinya sendiri, menjalankan seeder ini lagi tidak mengembalikannya ke
 * sandi awal.
 *
 * SANDI AWALNYA SAMA UNTUK SEPULUH ORANG: sandi bawaan di trait
 * ReadsInitialPassword, atau nilai INITIAL_USER_PASSWORD di .env kalau berkas itu
 * diisi. Sejak 2026-09-10 nilai .env yang kosong tidak lagi menghentikan seeder —
 * ia jatuh ke sandi bawaan, yang tercatat di CLAUDE.md dan karena itu selalu
 * diketahui. Alasannya ada di trait; yang perlu disadari di sini: sandi bawaan itu
 * ikut git, jadi di server yang terbuka ke internet isi .env dulu sebelum
 * menjalankan seeder ini pertama kali.
 *
 * Sandi bersama tetap punya konsekuensi yang perlu diketahui: satu orang yang tahu
 * sandinya bisa masuk sebagai siapa saja, memakai nama rekannya sebagai PIC, dan
 * activity_log akan menunjuk orang yang keliru. Minta setiap orang menggantinya
 * sendiri lewat menu profil setelah masuk pertama kali.
 */
class StaffSeeder extends Seeder
{
    use ReadsInitialPassword;

    /** @var array<string, string> nama => email */
    private const STAFF = [
        'Denry' => 'denry@roemahumara.com',
        'Jimmy' => 'jimmy@roemahumara.com',
        'Difa' => 'difa@roemahumara.com',
        'Agus Maulana' => 'agus@roemahumara.com',
        'Ivo' => 'ivo@roemahumara.com',
        'Cassie' => 'cassie@roemahumara.com',
        'Joesoef (Pak Ucup)' => 'joesoef@roemahumara.com',
        'Ira Arifin' => 'ira@roemahumara.com',
        'Thea Harun' => 'thea@roemahumara.com',
        'UCR' => 'ucr@roemahumara.com',
    ];

    public function run(): void
    {
        if (! Role::where('name', 'staff')->where('guard_name', 'web')->exists()) {
            $this->command?->warn('Role staff belum ada. Jalankan `php artisan db:seed --class=RolePermissionSeeder` dulu.');

            return;
        }

        // Dibaca sekali di luar perulangan, bukan di dalamnya: sepuluh akun ini
        // harus lahir dengan sandi yang sama persis, dan config bisa saja diubah
        // di tengah jalan oleh kode lain yang berjalan sesudahnya.
        $password = $this->initialPassword();

        foreach (self::STAFF as $name => $email) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'is_active' => true,
                ],
            );

            // Aman diulang: spatie tidak menggandakan role yang sudah melekat.
            $user->assignRole('staff');
        }

        // Aturan #8 CLAUDE.md. Tanpa ini, hak akses yang baru diberikan belum
        // terbaca sampai cache kedaluwarsa, dan gejalanya terlihat seperti
        // "sistem tidak menyimpan perubahan".
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Akun staf: '.count(self::STAFF).' orang.');
        $this->command?->warn(
            'Sandi awalnya sama untuk semua orang — sandi bawaan seeder, atau '
            .'INITIAL_USER_PASSWORD di .env kalau diisi. Minta setiap orang '
            .'menggantinya sendiri lewat menu profil.'
        );
    }
}
