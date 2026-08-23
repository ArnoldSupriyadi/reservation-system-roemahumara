<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\User;

class MenuPolicy
{
    protected function ability(): Ability
    {
        return Ability::ManageMaster;
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

    public function delete(User $user): bool
    {
        return $this->allowed($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allowed($user);
    }
}
