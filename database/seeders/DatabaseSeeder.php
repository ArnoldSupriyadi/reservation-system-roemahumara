<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Nilai yang tidak pernah boleh jadi sandi sungguhan.
     *
     * Ketiganya pernah benar-benar terpasang: 'CHANGE_ME_INITIAL_PASSWORD' dari
     * .env.production.example, 'ganti-nilai-ini-di-env' dari .env.example, dan
     * 'password' dari nilai cadangan config yang sekarang sudah dihapus.
     */
    private const PLACEHOLDERS = [
        'CHANGE_ME_INITIAL_PASSWORD',
        'ganti-nilai-ini-di-env',
        'password',
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            MasterSeeder::class,
        ]);

        $this->createAdmin();
    }

    /**
     * Akun admin Roemah Umara — satu-satunya akun yang lahir dari seeder.
     *
     * Akun staf sengaja TIDAK dibuat di sini maupun di seeder lain. Dulu ada
     * StaffSeeder yang membuat sepuluh akun sekaligus dengan satu sandi bersama;
     * itu dihapus 2026-08-24 karena sandi bersama membuat activity_log menunjuk
     * orang yang keliru, dan karena jumlahnya cuma sepuluh — membuatnya lewat
     * /cms/users lebih jelas daripada lewat .env. Yang ini tetap perlu ada:
     * di server yang baru dipasang belum ada satu pun akun untuk login, jadi
     * admin pertama harus lahir dari sini.
     *
     * firstOrCreate, bukan factory()->create(): seeder ini harus aman dijalankan
     * ulang. Dengan create() biasa, `db:seed` kedua kali gagal karena email-nya
     * unik — dan gagalnya di tengah jalan, setelah role dan master terlanjur
     * dibuat.
     */
    private function createAdmin(): void
    {
        $admin = User::where('email', 'roemahumara@gmail.com')->first();

        if (! $admin) {
            $admin = User::create([
                'email' => 'roemahumara@gmail.com',
                'name' => 'Admin Roemah Umara',
                'password' => Hash::make($this->initialPassword()),
                'is_active' => true,
            ]);
        }

        $admin->assignRole('admin');

        // Aturan #8 CLAUDE.md. Tanpa ini hak akses yang baru melekat belum
        // terbaca, dan admin barunya terlihat seperti tidak punya wewenang apa pun.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Sandi admin pertama, ditolak mentah kalau masih placeholder.
     *
     * Diperiksa di sini, bukan di run(): nilainya hanya berpengaruh saat akun
     * admin belum ada. Memeriksanya lebih awal akan membuat `db:seed` ulang
     * gagal di server yang sudah berjalan — padahal di sana sandinya sudah
     * diganti lewat panel dan .env tidak lagi relevan.
     *
     * Kenapa berhenti, bukan sekadar memperingatkan: peringatan di layar
     * terlewat begitu perintahnya dijalankan dari skrip, dan akibatnya baru
     * terasa berhari-hari kemudian saat orang mencoba login. firstOrCreate
     * tidak pernah memperbaiki akun yang terlanjur jadi, jadi satu-satunya
     * pembetulan adalah mengganti sandi manual satu per satu.
     */
    private function initialPassword(): string
    {
        $password = config('reservation.initial_password');

        if (blank($password) || in_array($password, self::PLACEHOLDERS, true)) {
            throw new RuntimeException(
                'INITIAL_USER_PASSWORD di .env belum diisi (nilainya sekarang: '
                .(blank($password) ? 'kosong' : "'{$password}'").'). '
                .'Isi dengan sandi sungguhan, jalankan `php artisan config:clear`, lalu ulangi. '
                .'Sandi itu dipakai untuk akun admin roemahumara@gmail.com dan tidak bisa '
                .'diperbaiki dengan menjalankan seeder lagi — seeder melewati akun yang sudah ada.'
            );
        }

        return $password;
    }
}
