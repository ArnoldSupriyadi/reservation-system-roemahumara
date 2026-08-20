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
