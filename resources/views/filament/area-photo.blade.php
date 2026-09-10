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
    termasuk bagi yang memakai keyboard saja. Alpine hanya dipakai dua baris,
    dan ia sudah ada di panel ini (lihat widgets/today.blade.php).

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

            dialog.ru-area-photo-dialog {
                border: 0;
                padding: 0;
                background: transparent;
                max-width: 92vw;
                max-height: 92vh;
            }

            dialog.ru-area-photo-dialog::backdrop {
                background: rgb(0 0 0 / 0.75);
            }

            dialog.ru-area-photo-dialog img {
                display: block;
                max-width: 92vw;
                max-height: 92vh;
                border-radius: 0.5rem;
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
            menargetkan elemen dialog itu sendiri, sedangkan klik pada fotonya
            menargetkan <img> — jadi perbandingan ini menutup saat latar diklik
            dan membiarkannya terbuka saat fotonya yang diklik.
        --}}
        <dialog
            class="ru-area-photo-dialog"
            x-ref="besar"
            x-on:click="$event.target === $el && $el.close()"
        >
            <img src="{{ $url }}" alt="Foto panduan area">
        </dialog>
    </div>
@else
    <img src="{{ $url }}" alt="Area ini belum difoto" style="height: 180px;">
@endif
