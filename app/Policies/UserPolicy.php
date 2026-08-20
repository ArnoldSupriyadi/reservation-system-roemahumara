<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\User;

class UserPolicy
{
    protected function ability(): Ability
    {
        return Ability::ManageUser;
    }

    private function allowed(User $user): bool
    {
        return $user->is_active && $user->can($this->ability()->value);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user): bool
    {
        return $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user);
    }

    public function update(User $user): bool
    {
        return $this->allowed($user);
    }

    /**
     * Pengguna tidak pernah boleh dihapus. Kolom reservations.pic_id dan
     * created_by memakai restrictOnDelete, sehingga penghapusan akan selalu
     * gagal di level database. Menonaktifkan lewat is_active adalah jalur
     * yang benar, dan menutupnya di sini membuat tombol Hapus tidak muncul.
     */
    public function delete(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
