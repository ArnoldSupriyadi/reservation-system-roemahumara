<?php

return [

    /*
     * Durasi yang diasumsikan untuk reservasi yang tidak punya end_time,
     * dipakai HANYA untuk mendeteksi tumpang tindih area.
     * Nilai ini tidak pernah disimpan ke database.
     */
    'default_duration_minutes' => (int) env('RESERVATION_DEFAULT_DURATION', 120),

    /*
     * Sandi awal untuk akun yang dibuat seeder.
     *
     * Dibaca dari .env, TIDAK ditulis di sini. Repositori ini publik, dan sandi
     * sungguhan yang masuk ke kode akan terbit ke internet secara permanen —
     * riwayat git menyimpannya meski barisnya nanti dihapus.
     *
     * Nilai cadangannya sengaja 'password': jelas terlihat placeholder, sehingga
     * lupa menyetel INITIAL_USER_PASSWORD menghasilkan sandi yang mencurigakan,
     * bukan sandi lemah yang menyamar sebagai sandi sungguhan.
     */
    'initial_password' => env('INITIAL_USER_PASSWORD', 'password'),

];
