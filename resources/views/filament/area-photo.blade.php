{{--
    Pratinjau foto panduan area di form reservasi CMS.

    Dua nilai datang dari viewData() di ReservationForm: $url (foto areanya,
    atau placeholder) dan $adaFoto (penanda mana di antara keduanya).

    CSS ditulis di sini sebagai <style>, BUKAN kelas Tailwind. Filament
    membangun CSS-nya sendiri di public/css/filament, terpisah dari app.css, dan
    hanya memuat kelas yang dipakai view bawaannya — alasan yang sama sudah
    dicatat di tabs-full-width.blade.php dan di CLAUDE.md.

    Pembesarnya memakai <dialog> bawaan peramban, bukan overlay buatan sendiri:
    latar gelap, Esc untuk menutup, dan jebakan fokus sudah benar sejak awal,
    termasuk bagi yang memakai keyboard saja.

    Placeholder sengaja TIDAK ikut cabang ini: tidak ada yang bisa diperbesar
    dari tulisan "No image", dan kursor zoom-in di atasnya menjanjikan sesuatu
    yang tidak ada. Karena itu seluruh markup pembesar — termasuk aturan CSS-nya
    — hanya dirender kalau fotonya benar-benar ada.
--}}
@if ($adaFoto)
    <div class="ru-area-foto" x-data>
        <style>
            .ru-area-foto__thumb {
                height: 180px;
                border-radius: 0.5rem;
                cursor: zoom-in;
            }

            /*
             * Dialog bawaan memusatkan dirinya sendiri lewat margin auto. Yang
             * ditambahkan di sini hanya jarak amannya: tanpa itu foto lanskap
             * yang lebar menempel ke tepi layar, dan tombol tutup di pojoknya
             * ikut tertekan keluar pandangan.
             */
            dialog.ru-area-photo-dialog {
                border: 0;
                padding: 0;
                background: transparent;
                overflow: visible;
                max-width: none;
                max-height: none;
                margin: auto;
            }

            dialog.ru-area-photo-dialog::backdrop {
                background: rgb(0 0 0 / 0.75);
            }

            /*
             * Pembungkusnya yang dibatasi, bukan gambarnya — tombol tutup
             * berpaut ke sudut pembungkus ini, jadi ia mengikuti tepi foto
             * seberapa pun ukuran fotonya. Batas 88vw/88vh menyisakan jarak ke
             * tepi layar di keempat sisi.
             */
            .ru-area-photo-frame {
                position: relative;
                display: inline-block;
                max-width: 88vw;
                max-height: 88vh;
            }

            .ru-area-photo-frame img {
                display: block;
                max-width: 88vw;
                max-height: 88vh;
                border-radius: 0.5rem;
            }

            /*
             * Sengaja mencolok. Tombol tutup yang menyatu dengan gambar di
             * belakangnya sama saja dengan tidak ada — dan foto area ini
             * berlatar apa saja, terang maupun gelap. Lingkaran putih pekat
             * dengan garis gelap terbaca di atas keduanya.
             *
             * Letaknya menggantung sedikit di luar sudut foto supaya ia tidak
             * pernah menutupi bagian gambar yang justru ingin dilihat.
             */
            .ru-area-photo-close {
                position: absolute;
                top: -0.75rem;
                right: -0.75rem;
                width: 2.5rem;
                height: 2.5rem;
                border-radius: 9999px;
                border: 2px solid #111827;
                background: #ffffff;
                color: #111827;
                font-size: 1.25rem;
                line-height: 1;
                font-weight: 700;
                cursor: pointer;
                box-shadow: 0 2px 10px rgb(0 0 0 / 0.5);
            }

            .ru-area-photo-close:hover,
            .ru-area-photo-close:focus-visible {
                background: #111827;
                color: #ffffff;
                outline: 3px solid #ffffff;
            }
        </style>

        <img
            class="ru-area-foto__thumb"
            src="{{ $url }}"
            alt="Foto panduan area, klik untuk memperbesar"
            x-on:click="$refs.besar.showModal()"
        >

        {{--
            Klik pada latar menutup: pada <dialog>, klik di area gelap
            menargetkan elemen dialog itu sendiri, sedangkan klik pada isinya
            menargetkan gambar atau tombol — jadi perbandingan ini menutup saat
            latar diklik dan membiarkannya terbuka saat fotonya yang diklik.
        --}}
        <dialog
            class="ru-area-photo-dialog"
            x-ref="besar"
            x-on:click="$event.target === $el && $el.close()"
        >
            <div class="ru-area-photo-frame">
                <img src="{{ $url }}" alt="Foto panduan area">

                {{--
                    type="button", dan itu WAJIB, bukan gaya penulisan.

                    Skema ini dirender di dalam <form wire:submit="create">
                    milik Filament. Versi pertama tombol ini memakai
                    <form method="dialog"> — cara bawaan peramban menutup dialog
                    tanpa JavaScript — dan itu bug: <form> di dalam <form> adalah
                    HTML tidak sah, parser peramban membuang tag bagian dalam
                    diam-diam, dan tombolnya jatuh jadi milik form Filament.
                    Dengan type="submit", mengkliknya mencoba MENYIMPAN
                    RESERVASI alih-alih menutup foto.

                    Karena pembuangan itu terjadi di parser peramban sedangkan
                    HTML dari server tampak baik-baik saja, test yang hanya
                    memeriksa keberadaan tombolnya hijau selama bug itu hidup.
                    AreaPhotoTest::test_the_close_button_never_nests_a_form
                    memeriksa markupnya, bukan keberadaannya.

                    Autofocus supaya yang memakai keyboard langsung berdiri di
                    tombol tutup begitu fotonya terbuka.
                --}}
                <button type="button" class="ru-area-photo-close" aria-label="Tutup"
                        x-on:click="$el.closest('dialog').close()" autofocus>&times;</button>
            </div>
        </dialog>
    </div>
@else
    <img src="{{ $url }}" alt="Area ini belum difoto" style="height: 180px;">
@endif
