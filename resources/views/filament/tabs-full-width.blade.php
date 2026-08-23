{{--
    Tab bulan di daftar reservasi dibuat selebar tabelnya.

    Disuntikkan sebagai <style> lewat render hook, BUKAN kelas Tailwind di Blade.
    Filament membangun CSS-nya sendiri di public/css/filament, terpisah dari
    app.css halaman publik, dan hanya memuat kelas yang dipakai view bawaannya —
    kelas seperti flex-1 atau w-full tidak ada di sana. Menambahkannya menuntut
    tema Filament kustom berikut langkah build tersendiri.

    DIBATASI ke .fi-resource-reservations. Tanpa pembatas itu, setiap tab di
    panel ikut melar — termasuk yang dibuat nanti pada resource lain, di tempat
    yang tidak ada hubungannya dengan perubahan ini.
--}}
<style>
    /* Bawaan Filament: display:flex + max-width:100%, tapi itemnya tidak
       tumbuh sehingga barisnya menggerombol di kiri. */
    .fi-resource-reservations .fi-tabs {
        width: 100%;
    }

    /* flex-basis 0 membuat kedelapan tab berbagi lebar sama rata, bukan
       mengikuti panjang namanya — "Mei 2026" dan "September 2026" jadi sama
       lebar, dan barisnya terbaca sebagai satu deret utuh. */
    .fi-resource-reservations .fi-tabs-item {
        flex: 1 1 0;
        min-width: 0;
        justify-content: center;
    }

    /* Nama bulan tidak boleh terpotong jadi dua baris saat layarnya menyempit;
       .fi-tabs bawaan sudah overflow-x:auto, jadi barisnya menggeser. */
    .fi-resource-reservations .fi-tabs-item-label {
        white-space: nowrap;
    }
</style>
