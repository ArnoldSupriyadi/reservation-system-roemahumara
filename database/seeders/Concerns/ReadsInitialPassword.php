<?php

namespace Database\Seeders\Concerns;

/**
 * Sandi awal untuk akun yang lahir dari seeder.
 *
 * Dipakai bersama DatabaseSeeder dan StaffSeeder. Jangan menyalinnya kembali ke
 * masing-masing seeder — salinan seperti itu berangsur berbeda dan menghasilkan
 * sandi yang berlaku saat membuat admin tapi lain saat membuat staf.
 */
trait ReadsInitialPassword
{
    /**
     * Sandi bawaan, ditulis tegas di sini sejak 2026-09-10.
     *
     * Sebelumnya nilainya WAJIB datang dari INITIAL_USER_PASSWORD di .env dan
     * seeder berhenti melempar kalau kosong. Penjagaan itu menjawab kejadian
     * 2026-08-24 dengan benar — akun admin lahir bersandi placeholder tanpa satu
     * pun tanda — tapi ongkosnya ditanggung setiap kali seeder dijalankan: mesin
     * yang .env-nya belum disunting tidak bisa menjalankan `db:seed` sama
     * sekali, termasuk ketika yang dibutuhkan cuma tabel master.
     *
     * Nilai bawaan yang tertulis menutup masalah 2026-08-24 lewat jalan lain:
     * sandi yang terbentuk selalu DIKETAHUI, bukan tebakan, jadi login yang
     * ditolak tidak pernah lagi berarti "sandinya entah apa". Bedanya dengan
     * cadangan 'password' yang dulu dihapus — yang itu placeholder yang menyamar
     * jadi sandi dan tidak tercatat di mana pun; yang ini sandi sungguhan yang
     * tertulis di CLAUDE.md.
     *
     * Dua hal yang harus disadari:
     *
     * - Nilai ini masuk riwayat git dan tidak bisa ditarik kembali. Ia sandi
     *   AWAL, bukan sandi tetap — setiap orang mengganti punyanya sendiri lewat
     *   menu profil setelah masuk pertama kali.
     * - Di server yang terbuka ke internet, isi INITIAL_USER_PASSWORD di .env
     *   untuk menimpanya SEBELUM seeder dijalankan pertama kali. Sesudah akunnya
     *   jadi, seeder tidak pernah memperbaiki sandi yang terlanjur terpasang.
     */
    private const SANDI_BAWAAN = 'Umara2026!';

    /**
     * Nilai yang tidak pernah boleh jadi sandi sungguhan.
     *
     * Ketiganya pernah benar-benar terpasang: 'CHANGE_ME_INITIAL_PASSWORD' dari
     * .env.production.example, 'ganti-nilai-ini-di-env' dari .env.example, dan
     * 'password' dari nilai cadangan config yang sudah dihapus. Sekarang ketiganya
     * tidak lagi menghentikan seeder — ia jatuh ke SANDI_BAWAAN. Daftarnya tetap
     * ada justru supaya placeholder tidak pernah jadi sandi yang sungguh dipakai.
     */
    private const PLACEHOLDERS = [
        'CHANGE_ME_INITIAL_PASSWORD',
        'ganti-nilai-ini-di-env',
        'password',
    ];

    /**
     * Sandi dari .env kalau ada isinya, kalau tidak sandi bawaan.
     *
     * Tidak pernah melempar: seeder yang berhenti karena .env belum disunting
     * menghalangi pekerjaan yang tidak ada hubungannya dengan akun sama sekali,
     * seperti mengisi tabel master di mesin baru.
     */
    private function initialPassword(): string
    {
        $password = config('reservation.initial_password');

        if (blank($password) || in_array($password, self::PLACEHOLDERS, true)) {
            return self::SANDI_BAWAAN;
        }

        return $password;
    }
}
