<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    /**
     * Aturan dasar: akun harus aktif, DAN rolenya harus memuat kemampuan
     * yang diminta.
     *
     * Perhatikan bahwa nama role tidak pernah disebut di berkas ini.
     * Menambah role baru cukup memberinya kemampuan lewat UI.
     */
    private function allows(User $user, Ability $ability): bool
    {
        return $user->is_active && $user->can($ability->value);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewReservation);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Ability::ViewReservation);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::CreateReservation);
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Ability::UpdateReservation);
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Ability::DeleteReservation);
    }

    /**
     * Dipakai Filament untuk DeleteBulkAction. Tanpa method ini, tombol
     * hapus massal tetap muncul untuk staf.
     */
    public function deleteAny(User $user): bool
    {
        return $this->allows($user, Ability::DeleteReservation);
    }

    /**
     * $reservation bernilai null saat status confirmed dipilih pada form
     * pembuatan, ketika barisnya belum ada.
     */
    public function confirm(User $user, ?Reservation $reservation = null): bool
    {
        return $this->allows($user, Ability::ConfirmReservation);
    }
}
