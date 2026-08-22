<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

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
     * Akun admin Roemah Umara.
     *
     * firstOrCreate, bukan factory()->create(): seeder ini harus aman dijalankan
     * ulang. Dengan create() biasa, `db:seed` kedua kali gagal karena email-nya
     * unik — dan gagalnya di tengah jalan, setelah role dan master terlanjur
     * dibuat.
     *
     * Sandi awalnya sama dengan akun staf. Ganti sebelum sistem dipakai
     * sungguhan; lihat catatan di StaffSeeder.
     */
    private function createAdmin(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'roemahmumara@gmail.com'],
            [
                'name' => 'Admin Roemah Umara',
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );

        $admin->assignRole('admin');

        // Aturan #8 CLAUDE.md. Tanpa ini hak akses yang baru melekat belum
        // terbaca, dan admin barunya terlihat seperti tidak punya wewenang apa pun.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
