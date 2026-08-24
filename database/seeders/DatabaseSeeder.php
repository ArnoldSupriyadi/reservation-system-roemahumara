<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\ReadsInitialPassword;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use ReadsInitialPassword;
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
     * Akun admin Roemah Umara — satu-satunya akun yang lahir dari seeder.
     *
     * Akun staf ada di StaffSeeder, yang sengaja tidak ikut `db:seed` polos —
     * sistem yang baru dipasang di tempat lain tidak seharusnya berisi nama
     * sepuluh orang ini. Yang ini justru harus ikut: di server yang baru
     * dipasang belum ada satu pun akun untuk login, jadi admin pertama harus
     * lahir dari sini.
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
}
