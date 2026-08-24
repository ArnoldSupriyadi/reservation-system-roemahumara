<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Membaca INITIAL_USER_PASSWORD, dan menolak mentah kalau masih placeholder.
 *
 * Dipakai bersama DatabaseSeeder dan StaffSeeder. Jangan menyalinnya kembali ke
 * masing-masing seeder — salinan seperti itu berangsur berbeda dan menghasilkan
 * penjagaan yang berlaku saat membuat admin tapi diam saat membuat staf.
 */
trait ReadsInitialPassword
{
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
     * Kenapa berhenti, bukan sekadar memperingatkan: peringatan di layar terlewat
     * begitu perintahnya dijalankan dari skrip, dan akibatnya baru terasa
     * berhari-hari kemudian saat orang mencoba login — dengan pesan Filament yang
     * sama persis untuk email tidak terdaftar, sandi salah, dan akun nonaktif,
     * sehingga penyebabnya mustahil dibedakan dari layar. firstOrCreate tidak
     * pernah memperbaiki akun yang terlanjur jadi, jadi satu-satunya pembetulan
     * adalah mengganti sandi manual satu per satu. Itu yang terjadi 2026-08-24.
     */
    private function initialPassword(): string
    {
        $password = config('reservation.initial_password');

        if (blank($password) || in_array($password, self::PLACEHOLDERS, true)) {
            throw new RuntimeException(
                'INITIAL_USER_PASSWORD di .env belum diisi (nilainya sekarang: '
                .(blank($password) ? 'kosong' : "'{$password}'").'). '
                .'Isi dengan sandi sungguhan, jalankan `php artisan config:clear`, lalu ulangi. '
                .'Sandi itu dipakai untuk akun yang dibuat seeder dan tidak bisa diperbaiki '
                .'dengan menjalankan seeder lagi — seeder melewati akun yang sudah ada.'
            );
        }

        return $password;
    }
}
