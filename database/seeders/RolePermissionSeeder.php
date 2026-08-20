<?php

namespace Database\Seeders;

use App\Enums\Ability;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Ability::values() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Spatie hanya membatalkan cache permission lewat model event saved/deleted.
        // DatabaseSeeder memakai WithoutModelEvents, sehingga delapan permission di
        // atas tercipta tanpa cache pernah dibersihkan, dan syncPermissions() di bawah
        // akan membaca cache kosong lalu melempar PermissionDoesNotExist.
        // Spatie hanya membatalkan cache permission lewat model event saved/deleted.
        // DatabaseSeeder memakai WithoutModelEvents, sehingga delapan permission di
        // atas tercipta tanpa cache pernah dibersihkan, dan syncPermissions() di bawah
        // akan membaca cache kosong lalu melempar PermissionDoesNotExist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions(Ability::values());

        $staff = Role::findOrCreate('staff', 'web');
        $staff->syncPermissions([
            Ability::ViewReservation->value,
            Ability::CreateReservation->value,
            Ability::UpdateReservation->value,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
