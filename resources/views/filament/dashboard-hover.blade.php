{{--
    Sorotan baris pada tabel widget dashboard.

    Disuntikkan sebagai <style> lewat render hook, BUKAN kelas Tailwind di Blade —
    alasannya sama dengan tabs-full-width.blade.php: Filament membangun CSS-nya
    sendiri di public/css/filament, terpisah dari app.css, dan hanya memuat kelas
    yang dipakai view bawaannya.

    DIBATASI ke .fi-wi-table, kelas milik view table-widget. Tabel di halaman
    resource tidak ikut berubah, termasuk daftar reservasi yang barisnya sudah
    bergaris-garis (striped) — dua efek itu bertumpuk akan membuat barisnya
    terbaca kotor.
--}}
<style>
    .fi-wi-table .fi-ta-row {
        /* Transition di dua arah, jadi sorotannya meredup saat kursor pergi
           alih-alih putus mendadak. */
        transition: background-color 150ms ease, box-shadow 150ms ease;
    }

    .fi-wi-table .fi-ta-row:hover {
        background-color: var(--primary-50);
        /* Garis di tepi kiri: penanda yang tetap terbaca oleh mata yang sulit
           membedakan perubahan warna latar setipis ini. */
        box-shadow: inset 3px 0 0 0 var(--primary-500);
    }

    .fi-dark .fi-wi-table .fi-ta-row:hover {
        /* Amber-50 di atas latar gelap terlalu menyilaukan; yang dipakai adalah
           lapisan putih tipis, sehingga tetap terbaca sebagai "baris ini". */
        background-color: rgb(255 255 255 / 0.05);
    }

    /* Kursor pointer hanya kalau barisnya memang bisa diklik. Filament menandai
       baris ber-recordUrl dengan .fi-clickable (lihat index.blade.php:2222). */
    .fi-wi-table .fi-ta-row.fi-clickable:hover {
        cursor: pointer;
    }
</style>
