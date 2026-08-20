<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    private function allowed(User $user): bool
    {
        return $user->is_active && $user->can(Ability::ManageRole->value);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->allowed($user);
    }

    /**
     * Role bawaan tidak boleh dihapus. Menghapus "admin" akan mengunci
     * semua orang keluar dari pengaturan; menghapus "staff" membuat
     * pengguna yang memakainya kehilangan seluruh akses.
     */
    public function delete(User $user, Role $role): bool
    {
        return $this->allowed($user) && ! in_array($role->name, ['admin', 'staff'], true);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
