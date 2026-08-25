{{--
    Dokumen cetak satu reservasi.

    TATA LETAKNYA MEMAKAI TABEL, BUKAN FLEXBOX ATAU GRID. dompdf tidak mendukung
    keduanya — memakainya menghasilkan PDF yang seluruh isinya menumpuk di satu
    kolom kiri, dan gejalanya baru terlihat setelah PDF dibuka, bukan saat
    Blade-nya dirender.

    Riwayat perubahan sengaja TIDAK disertakan. Dokumen ini dibawa ke lapangan
    dan diserahkan ke dapur; yang dibutuhkan keadaan reservasi sekarang, bukan
    catatan siapa mengubah apa.
--}}
@php
    $venue = config('reservation.venue');
    $status = $reservation->status?->label() ?? 'BELUM DITENTUKAN';
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $reservation->reservation_number }} — {{ $reservation->guest_name }}</title>
    <style>
        /*
           Nunito, disediakan sebagai berkas TTF di public/fonts.

           dompdf hanya membawa keluarga DejaVu; font lain harus didaftarkan
           sendiri lewat @font-face dengan path lokal — bukan URL Google Fonts,
           yang menuntut akses jaringan saat PDF dibuat dan gagal diam-diam di
           server tanpa internet keluar.

           Dipakai versi STATIK, bukan Nunito[wght].ttf yang variabel: dompdf
           tidak mengenali sumbu berat, sehingga font variabel membuat seluruh
           teks tercetak pada satu bobot dan yang tebal tidak pernah tebal.

           Kedua berkas sudah diperiksa memuat em dash dan huruf beraksen —
           tanpa itu "Papardelle Al Ragù" dan tanda — tercetak sebagai kotak
           kosong, dan itu baru ketahuan setelah PDF dibuka.
        */
        @font-face {
            font-family: "Nunito";
            font-weight: 400;
            font-style: normal;
            src: url("{{ public_path('fonts/Nunito-Regular.ttf') }}") format("truetype");
        }

        @font-face {
            font-family: "Nunito";
            font-weight: 700;
            font-style: normal;
            src: url("{{ public_path('fonts/Nunito-Bold.ttf') }}") format("truetype");
        }

        * { font-family: "Nunito", "DejaVu Sans", sans-serif; }

        body { margin: 0; color: #111; font-size: 10pt; line-height: 1.45; }

        table { width: 100%; border-collapse: collapse; }

        .kop { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 14px; }
        .kop td { vertical-align: top; }
        .kop .kanan { text-align: right; font-size: 8pt; width: 45%; line-height: 1.5; vertical-align: middle; }
        h1 { font-size: 12pt; margin: 0 0 2px; letter-spacing: 1px; }
        .nomor { font-size: 16pt; font-weight: bold; }

        .blok { margin-top: 14px; }
        .judul-blok {
            font-size: 8pt; font-weight: bold; letter-spacing: 2px; color: #555;
            border-bottom: 1px solid #bbb; padding-bottom: 3px; margin-bottom: 6px;
        }

        .isi td { padding: 3px 0; vertical-align: top; }
        .isi .label { width: 26%; color: #555; }
        .isi .nilai { font-weight: bold; }

        .menu th {
            font-size: 8pt; text-align: left; border-bottom: 1px solid #111;
            padding: 4px 6px 4px 0; letter-spacing: 0.5px;
        }
        .menu td { padding: 5px 6px 5px 0; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        /* Jarak kanan lebar: tanpa itu judul PORSI dan CATATAN terbaca
           menyambung jadi satu kata. */
        .menu .angka { text-align: right; white-space: nowrap; padding-right: 18px; }
        .catatan { color: #444; font-size: 9pt; }

        /* Aturan #4 CLAUDE.md: catatan tampil penuh. pre-line menjaga baris
           barunya, dan tidak ada batas tinggi yang memotongnya. */
        .remark { white-space: pre-line; border-left: 3px solid #111; padding-left: 8px; }

        .kaki { margin-top: 22px; border-top: 1px solid #bbb; padding-top: 6px; font-size: 7.5pt; color: #666; }
    </style>
</head>
<body>

<table class="kop">
    <tr>
        <td>
            {{-- Logo saja, tanpa teks merek: logonya sendiri sudah memuat tulisan
                 ROEMAH UMARA, jadi teks di bawahnya hanya mengulang. Dinaikkan
                 tingginya karena kini ia satu-satunya penanda identitas di kop. --}}
            @if (file_exists(public_path('img/logo-gold.png')))
                {{-- display:block + margin-bottom, bukan margin saja: img bawaannya
                     inline, dan margin bawah pada elemen inline diabaikan dompdf
                     sehingga logonya tetap menempel ke garis kop. --}}
                <img
                    src="{{ public_path('img/logo-gold.png') }}"
                    alt="Roemah Umara"
                    height="52"
                    style="display: block; margin-bottom: 10px;"
                >
            @endif
        </td>
        <td class="kanan">{{ $venue['address'] }}</td>
    </tr>
</table>

<table>
    <tr>
        <td>
            <h1>DETAIL RESERVASI</h1>
            <div class="nomor">{{ $reservation->reservation_number }}</div>
        </td>
        <td style="text-align: right; vertical-align: bottom;">
            <div style="font-size: 8pt; color: #555;">STATUS</div>
            <div style="font-size: 12pt; font-weight: bold;">{{ $status }}</div>
        </td>
    </tr>
</table>

<div class="blok">
    <div class="judul-blok">TAMU</div>
    <table class="isi">
        <tr><td class="label">Nama tamu</td><td class="nilai">{{ $reservation->guest_name }}</td></tr>
        <tr><td class="label">Company</td><td class="nilai">{{ $reservation->company ?: '—' }}</td></tr>
        <tr><td class="label">HP</td><td class="nilai">{{ $reservation->phone ?: '—' }}</td></tr>
        <tr><td class="label">Email</td><td class="nilai">{{ $reservation->email ?: '—' }}</td></tr>
        <tr><td class="label">PIC / Sales</td><td class="nilai">{{ $reservation->pic?->name ?? '—' }}</td></tr>
    </table>
</div>

<div class="blok">
    <div class="judul-blok">ACARA</div>
    <table class="isi">
        <tr>
            <td class="label">Tanggal</td>
            <td class="nilai">{{ $reservation->reservation_date->translatedFormat('l, d F Y') }}</td>
        </tr>
        <tr>
            <td class="label">Jam</td>
            <td class="nilai">
                @if (blank($reservation->end_time))
                    {{ $reservation->start_time }} (jam tunggal)
                @else
                    {{ $reservation->start_time }}–{{ $reservation->end_time }}
                @endif
            </td>
        </tr>
        <tr><td class="label">Area</td><td class="nilai">{{ $reservation->area?->name ?? '—' }}</td></tr>
        <tr><td class="label">Jenis acara</td><td class="nilai">{{ $reservation->eventType?->name ?? '—' }}</td></tr>
        <tr><td class="label">Pax</td><td class="nilai">{{ $reservation->paxLabel() }} orang</td></tr>
    </table>
</div>

<div class="blok">
    <div class="judul-blok">MENU</div>
    @if ($reservation->menus->isEmpty())
        <div style="color: #777;">Tidak ada menu dipesan.</div>
    @else
        <table class="menu">
            <thead>
                <tr>
                    <th style="width: 5%;">NO</th>
                    <th style="width: 33%;">MENU</th>
                    <th style="width: 22%;">KATEGORI</th>
                    <th style="width: 12%;" class="angka">PORSI</th>
                    <th>CATATAN</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reservation->menus as $menu)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $menu->name }}</strong></td>
                        <td>{{ $menu->category?->name ?? '—' }}</td>
                        <td class="angka">{{ $menu->pivot->pax }}</td>
                        <td class="catatan">{{ filled($menu->pivot->remark) ? $menu->pivot->remark : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="blok">
    <div class="judul-blok">REMARK</div>
    <div class="remark">{{ filled($reservation->remark) ? $reservation->remark : '—' }}</div>
</div>

<div class="kaki">
    Dicetak {{ now()->translatedFormat('d F Y, H:i') }}
    @if ($dicetakOleh)
        oleh {{ $dicetakOleh }}
    @endif
    · Dokumen ini menampilkan keadaan reservasi saat dicetak.
</div>

</body>
</html>
