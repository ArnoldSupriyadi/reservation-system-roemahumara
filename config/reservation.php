<?php

return [

    /*
     * Durasi yang diasumsikan untuk reservasi yang tidak punya end_time,
     * dipakai HANYA untuk mendeteksi tumpang tindih area.
     * Nilai ini tidak pernah disimpan ke database.
     */
    'default_duration_minutes' => (int) env('RESERVATION_DEFAULT_DURATION', 120),

    /*
     * Penimpa sandi awal akun seeder, opsional.
     *
     * Sandinya sendiri TIDAK di sini — ia konstanta SANDI_BAWAAN di trait
     * Database\Seeders\Concerns\ReadsInitialPassword, supaya `db:seed` di mesin
     * yang .env-nya belum disunting tetap jalan. Isi nilai ini hanya kalau
     * memang ingin sandi lain, misalnya di server yang terbuka ke internet;
     * kosong berarti "pakai yang bawaan", bukan lagi "berhenti".
     *
     * Kosongkan juga kalau ragu: sandi sungguhan yang masuk ke .env aman (berkas
     * itu tidak ikut git), tapi sandi yang masuk ke KODE terbit permanen —
     * riwayat git menyimpannya meski barisnya nanti dihapus.
     */
    'initial_password' => env('INITIAL_USER_PASSWORD'),

    /*
     * Jam paling pagi yang boleh dipesan. Ditegakkan berpasangan dengan
     * jam_tutup, lewat jalur yang sama persis.
     */
    'jam_buka' => env('RESERVATION_OPENING_TIME', '08:00'),

    /*
     * Jam paling malam yang boleh dipesan.
     *
     * Ditegakkan di form Filament dan di ReservationWriter — dua lapis, sama
     * seperti kewajiban menjelaskan bentrok di Remark. TIDAK ditegakkan sebagai
     * CHECK constraint di database: ini aturan bisnis, bukan bentuk data, dan
     * jam tutup venue lebih mungkin berubah daripada struktur tabelnya.
     *
     * Keduanya berlaku untuk jam mulai maupun jam selesai. Batas atas juga yang membuat
     * acara tidak pernah melewati tengah malam — tanpa itu, reservasi 22:00-01:00
     * membuat ConflictChecker menghitung jendela terbalik (end lebih kecil
     * daripada start) dan pengecekan bentrok untuk barisnya mati tanpa peringatan.
     */
    'jam_tutup' => env('RESERVATION_CLOSING_TIME', '22:00'),

    /*
     * Identitas venue untuk kop dokumen cetak.
     *
     * Di sini, bukan di dalam Blade: alamat berubah lebih sering daripada tata
     * letak dokumennya, dan mengubahnya seharusnya tidak menuntut menyentuh
     * berkas tampilan.
     */
    'venue' => [
        'name' => 'Roemah Umara Reservation',
        'address' => 'Jl. RC. Veteran Raya No.Lot 51, RT.4/RW.12, Bintaro, '
            .'Pesanggrahan, Jakarta Selatan, 12330',
    ],

];
