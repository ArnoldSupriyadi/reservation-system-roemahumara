{{--
    Sama dengan filament-widgets::table-widget bawaan, ditambah satu pita
    peringatan di atas tabelnya.

    Ada karena rentang Minggu ini dan Bulan ini memuat SATU PERIODE PENUH,
    termasuk tanggal yang sudah lewat. Tanpa pemberitahuan, daftar yang memuat
    acara kemarin di tempat yang berjudul "reservasi terdekat" akan terbaca
    sebagai acara yang masih akan datang — dan itu jenis salah baca yang baru
    ketahuan setelah ada yang menyiapkan ruangan untuk acara yang sudah selesai.
--}}
<x-filament-widgets::widget class="fi-wi-table">
    @if ($this->memuatTanggalLampau())
        <div class="ru-alert" role="status">
            <x-filament::icon
                icon="heroicon-o-information-circle"
                class="ru-alert-icon"
            />

            <p>
                Daftar ini memuat <strong>seluruh {{ $this->labelRentang() }}</strong> —
                termasuk reservasi yang tanggalnya sudah lewat, ditandai
                <em>sudah lewat</em> di kolom Tanggal.
                Pilih <strong>Terdekat</strong> untuk melihat yang akan datang saja.
            </p>
        </div>
    @endif

    {{ $this->table }}

    {{--
        CSS-nya di sini, bukan kelas Tailwind: Filament membangun CSS-nya sendiri
        di public/css/filament, terpisah dari app.css, dan hanya memuat kelas yang
        dipakai view bawaannya. Alasan yang sama dicatat di
        resources/views/filament/tabs-full-width.blade.php.
    --}}
    <style>
        .ru-alert {
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
            margin-bottom: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            /* Garis tepi, bukan warna latar saja: pita yang hanya dibedakan
               warna akan hilang bagi mata yang sulit membedakannya, dan ikonnya
               sendiri terlalu kecil untuk jadi satu-satunya penanda. */
            border: 1px solid var(--info-300);
            background-color: var(--info-50);
            color: var(--info-700);
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .ru-alert-icon {
            flex-shrink: 0;
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.125rem;
        }

        .fi-dark .ru-alert {
            border-color: var(--info-700);
            /* Latar info-50 di atas layar gelap menyilaukan; yang dipakai
               lapisan tipis warnanya sendiri. */
            background-color: rgb(255 255 255 / 0.05);
            color: var(--info-300);
        }
    </style>
</x-filament-widgets::widget>
