{{--
    Brand panel CMS: logo gold berikut kata "Reservation" di bawahnya.

    Dibuat sebagai markup sendiri, bukan sekadar ->brandLogo(url), karena
    komponen logo Filament MENGGANTI teks nama begitu sebuah logo dipasang —
    lihat vendor/filament/filament/resources/views/components/logo.blade.php.
    Dengan url saja, nama "Roemah Umara Reservation" hanya tersisa di atribut
    alt dan judul tab, tidak pernah terbaca di halaman login.

    Logonya sendiri sudah memuat tulisan ROEMAH UMARA, jadi yang ditambahkan di
    sini cukup kata keduanya. Versi gold dipilih karena panel punya mode gelap;
    logo hitam akan lenyap di sana.

    GAYANYA INLINE, BUKAN KELAS TAILWIND. Filament membangun CSS-nya sendiri di
    public/css/filament/filament/app.css, terpisah dari app.css milik halaman
    publik, dan isinya hanya kelas yang dipakai view bawaan Filament. Kelas
    seperti flex-col, items-center, h-10, atau uppercase TIDAK ada di sana —
    sudah diperiksa. Memakainya menghasilkan halaman yang HTML-nya benar tapi
    tampil tanpa gaya, dengan logo selebar 900px. Menambahkannya menuntut tema
    Filament kustom berikut langkah build tersendiri; untuk sepetak markup
    sekecil ini, inline lebih murah dan tidak bisa diam-diam rusak.

    Tingginya juga diatur di sini, bukan lewat ->brandLogoHeight(), sebab
    pembungkus dari Filament menerapkan height pada seluruh blok — kalau
    dipatok, baris "Reservation" ikut terpotong. Panel menyetelnya 'auto'.
--}}
<div style="display: flex; flex-direction: column; align-items: center; gap: 0.35rem;">
    <img
        src="{{ asset('img/logo-gold.png') }}"
        alt="{{ filament()->getBrandName() }}"
        width="900"
        height="422"
        style="height: 2.75rem; width: auto;"
    >

    {{-- Abu-abu tengah: terbaca cukup di latar terang maupun gelap, sehingga
         tidak perlu dua varian warna yang inline style memang tidak bisa. --}}
    <span style="font-size: 0.625rem; font-weight: 600; letter-spacing: 0.35em; text-transform: uppercase; color: rgb(107 114 128);">
        Reservation
    </span>
</div>
