<?php

return [

    /*
     * Durasi yang diasumsikan untuk reservasi yang tidak punya end_time,
     * dipakai HANYA untuk mendeteksi tumpang tindih area.
     * Nilai ini tidak pernah disimpan ke database.
     */
    'default_duration_minutes' => (int) env('RESERVATION_DEFAULT_DURATION', 120),

    /*
     * Sandi akun admin pertama, dipakai HANYA oleh DatabaseSeeder saat akun itu
     * belum ada. Akun staf tidak lagi lahir dari seeder — dibuat lewat
     * /cms/users, masing-masing dengan sandinya sendiri.
     *
     * Dibaca dari .env, TIDAK ditulis di sini. Repositori ini publik, dan sandi
     * sungguhan yang masuk ke kode akan terbit ke internet secara permanen —
     * riwayat git menyimpannya meski barisnya nanti dihapus.
     *
     * TIDAK ADA nilai cadangan, dan itu disengaja. Sebelumnya cadangannya
     * 'password' dengan alasan "jelas terlihat placeholder". Alasan itu keliru:
     * yang terjadi pada pemasangan 2026-08-24 adalah akun admin lahir bersandi
     * placeholder tanpa satu pun tanda, lalu login ditolak dengan pesan
     * "Kredensial yang diberikan tidak dapat ditemukan" — pesan yang sama
     * persis dengan email tidak terdaftar dan akun nonaktif, sehingga
     * penyebabnya tidak bisa dibedakan dari layar. Tanpa cadangan,
     * DatabaseSeeder berhenti dengan pesan yang menyebut penyebabnya.
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
