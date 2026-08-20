<?php

namespace App\Enums;

/**
 * Daftar kemampuan yang dikenali sistem.
 *
 * Ini adalah KODE, bukan data. Menambah kemampuan baru selalu lewat commit,
 * karena setiap kemampuan harus punya Policy yang memakainya. Yang boleh
 * diubah admin lewat UI adalah role dan kemampuan apa saja yang dimuatnya.
 *
 * Sengaja dinamai menurut kemampuan bisnis, bukan per-CRUD-per-Resource.
 * Delapan baris di sini menggantikan sekitar empat puluh permission yang
 * akan di-generate Filament Shield.
 */
enum Ability: string
{
    case ViewReservation = 'reservation.view';
    case CreateReservation = 'reservation.create';
    case UpdateReservation = 'reservation.update';
    case DeleteReservation = 'reservation.delete';
    case ConfirmReservation = 'reservation.confirm';
    case ManageMaster = 'master.manage';
    case ManageUser = 'user.manage';
    case ManageRole = 'role.manage';

    public function label(): string
    {
        return match ($this) {
            self::ViewReservation => 'Lihat reservasi',
            self::CreateReservation => 'Tambah reservasi',
            self::UpdateReservation => 'Ubah reservasi',
            self::DeleteReservation => 'Hapus reservasi',
            self::ConfirmReservation => 'Tetapkan status CONFIRMED',
            self::ManageMaster => 'Kelola master area, event, menu',
            self::ManageUser => 'Kelola pengguna',
            self::ManageRole => 'Kelola role dan hak akses',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> untuk dipakai sebagai opsi form */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
