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
 * SANDI AWALNYA SAMA UNTUK SEPULUH ORANG, dibaca dari INITIAL_USER_PASSWORD di .env.
 * Nilainya sengaja TIDAK ditulis di sini: repositori ini publik, dan sandi sungguhan
 * yang masuk ke kode akan terbit ke internet secara permanen — riwayat git
 * menyimpannya meski barisnya nanti dihapus. Sejak 2026-08-25 seeder ini BERHENTI
 * kalau nilainya kosong atau masih placeholder (lihat trait ReadsInitialPassword),
 * jadi lupa mengisinya tidak lagi menghasilkan sepuluh akun yang tidak bisa dimasuki.
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

        // Dibaca sekali di luar perulangan: kalau nilainya belum diisi, seeder
        // harus berhenti SEBELUM satu pun akun terbentuk. Memanggilnya di dalam
        // perulangan akan meninggalkan sebagian akun jadi dan sebagian belum.
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
            'Sandi awalnya diambil dari INITIAL_USER_PASSWORD di .env dan sama untuk semua orang. '
            .'Minta setiap orang menggantinya sendiri.'
        );
    }
}
