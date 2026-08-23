{{--
    Daftar menu yang dipesan berikut porsi dan catatannya.

    Dibuat sebagai view sendiri, bukan TextEntry biasa, karena tiap baris membawa
    tiga hal sekaligus — nama, porsi, dan catatan — dan catatan wajib tampil
    penuh (aturan #4 CLAUDE.md). TextEntry yang merangkai ketiganya jadi satu
    baris teks akan memaksa catatan panjang terpotong di ujung kolom.
--}}
@php $menus = $getRecord()->menus; @endphp

@if ($menus->isEmpty())
    <p class="fi-in-placeholder text-sm text-gray-400 dark:text-gray-500">Tidak ada menu dipesan.</p>
@else
    <ul style="display: flex; flex-direction: column; gap: 0.5rem;">
        @foreach ($menus as $menu)
            <li style="border-bottom: 1px dashed rgb(209 213 219); padding-bottom: 0.5rem;">
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <span style="font-weight: 600;">{{ $menu->name }}</span>
                    <span style="white-space: nowrap; font-variant-numeric: tabular-nums;">
                        {{ $menu->pivot->pax }} porsi
                    </span>
                </div>

                <div style="font-size: 0.75rem; color: rgb(107 114 128);">{{ $menu->category?->name }}</div>

                {{-- Selalu tampil, sama seperti halaman publik: letak barisnya tetap,
                     jadi tidak perlu memastikan sendiri apakah hidangan ini punya
                     catatan. Berlabel supaya isinya tidak terbaca menyambung dengan
                     nama dan porsi di atasnya.

                     whitespace-pre-line: catatan berbaris banyak tetap utuh. --}}
                <p style="margin-top: 0.25rem; white-space: pre-line; border-left: 2px solid rgb(245 158 11); padding-left: 0.5rem; font-size: 0.875rem;">
                    <span style="font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;">Catatan:</span>
                    {{ filled($menu->pivot->remark) ? $menu->pivot->remark : '—' }}
                </p>
            </li>
        @endforeach
    </ul>
@endif
