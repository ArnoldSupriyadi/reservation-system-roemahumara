<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Akun staf Roemah Umara.
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
 * KATA SANDI AWAL SAMA UNTUK SEMUA ORANG dan tertulis di berkas ini. Itu permintaan
 * eksplisit pemilik sistem pada 2026-08-22, diambil sesudah alternatif sandi acak
 * per akun ditawarkan. Konsekuensinya perlu diketahui siapa pun yang membaca ini:
 * satu orang yang tahu sandinya bisa masuk sebagai siapa saja, memakai nama rekannya
 * sebagai PIC, dan activity_log akan menunjuk orang yang keliru. Ganti sebelum
 * sistem dipakai sungguhan.
 */
class StaffSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password';

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

        foreach (self::STAFF as $name => $email) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
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

        $this->command?->info('Akun staf: '.count(self::STAFF).' orang, sandi awal "'.self::DEFAULT_PASSWORD.'".');
        $this->command?->warn('Sandi awalnya sama untuk semua. Ganti sebelum dipakai sungguhan.');
    }
}
