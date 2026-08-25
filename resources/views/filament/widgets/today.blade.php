{{--
    Jam berjalan, tapi jam VENUE — bukan jam komputer yang membukanya.

    Titik awalnya diambil dari server (epoch milidetik saat halaman dirender),
    lalu ditambah selisih waktu yang berjalan di peramban. Memakai Date.now()
    telanjang akan menampilkan jam laptop masing-masing orang; satu laptop yang
    zona waktunya keliru akan membuat stafnya mencatat jam yang salah tanpa
    merasa ada yang aneh. Intl dengan timeZone Asia/Jakarta menjaga tampilannya
    tetap jam Jakarta meski laptopnya disetel ke zona lain.

    Tanggalnya dirender di server dengan translatedFormat, jadi nama hari dan
    bulannya mengikuti APP_LOCALE tanpa perlu daftar nama bulan di JavaScript.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <div
            class="ru-today"
            x-data="{
                mulaiServer: {{ now()->getTimestampMs() }},
                mulaiPeramban: Date.now(),
                jam: '',
                perbarui() {
                    /* Locale en-GB, bukan id-ID: keduanya sama-sama 24 jam,
                       tapi id-ID memisahkan jam dengan titik (10.11.05) dan
                       yang terbaca staf sehari-hari adalah titik dua. Locale
                       ini hanya menentukan bentuk angkanya — tidak ada nama
                       hari atau bulan di sini. */
                    this.jam = new Intl.DateTimeFormat('en-GB', {
                        timeZone: 'Asia/Jakarta',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false,
                    }).format(new Date(this.mulaiServer + (Date.now() - this.mulaiPeramban)))
                },
            }"
            x-init="perbarui(); setInterval(() => perbarui(), 1000)"
        >
            <p class="ru-today-label">Hari ini</p>

            {{-- x-cloak tidak dipakai: nilai awalnya diisi server, jadi jamnya
                 sudah benar sebelum Alpine sempat berjalan. Tanpa itu, kotaknya
                 berkedip kosong setiap kali halaman dimuat. --}}
            <p class="ru-today-clock" x-text="jam">{{ now()->format('H:i:s') }}</p>

            <p class="ru-today-date">{{ now()->translatedFormat('l, j F Y') }}</p>
        </div>
    </x-filament::section>

    {{--
        CSS-nya di sini, bukan kelas Tailwind: Filament membangun CSS-nya sendiri
        di public/css/filament, terpisah dari app.css, dan hanya memuat kelas yang
        dipakai view bawaannya. Alasan yang sama dicatat di
        resources/views/filament/tabs-full-width.blade.php.

        Warnanya memakai variabel milik Filament, sehingga ikut berubah sendiri
        saat panel dipakai dalam mode gelap.
    --}}
    <style>
        .ru-today {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .ru-today-label {
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--gray-400);
        }

        .ru-today-clock {
            /* Tabular-nums menjaga lebar angka tetap sama, supaya jamnya tidak
               bergoyang setiap detik saat 1 berganti jadi 8. */
            font-variant-numeric: tabular-nums;
            font-size: 2.25rem;
            line-height: 1.1;
            font-weight: 700;
            color: var(--gray-950);
        }

        .fi-dark .ru-today-clock {
            color: var(--gray-50);
        }

        .ru-today-date {
            font-size: 0.875rem;
            color: var(--gray-500);
        }
    </style>
</x-filament-widgets::widget>
