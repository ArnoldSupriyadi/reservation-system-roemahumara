@extends('layouts.public')

@section('title', 'Jadwal '.$monthLabel.' — Roemah Umara')

@section('content')
    <section class="mb-5 flex flex-wrap items-center gap-3">
        <h2 class="text-2xl font-black uppercase">{{ $monthLabel }}</h2>

        <a href="{{ route('public.calendar', ['bulan' => $previousMonth]) }}" rel="nofollow" class="brut-btn">‹ Sebelumnya</a>
        <a href="{{ route('public.calendar', ['bulan' => $nextMonth]) }}" rel="nofollow" class="brut-btn">Berikutnya ›</a>

        <p class="brut-count ms-auto">{{ $total }} Booking</p>
    </section>

    <div class="brut-box overflow-x-auto">
        <table class="w-full table-fixed border-collapse">
            <thead>
                <tr>
                    @php
                        $days = [
                            ['Sen', 'Senin'], ['Sel', 'Selasa'], ['Rab', 'Rabu'], ['Kam', 'Kamis'],
                            ['Jum', 'Jumat'], ['Sab', 'Sabtu'], ['Min', 'Minggu'],
                        ];
                    @endphp

                    @foreach ($days as [$short, $full])
                        <th
                            scope="col"
                            @class([
                                'border-[2px] border-ink bg-ink py-2 text-[11px] font-black uppercase tracking-widest',
                                'text-white' => ! $loop->last,
                                'text-sunday-ink' => $loop->last,
                            ])
                        >
                            {{-- Tujuh kolom dibagi rata: di ponsel selebar 360px tiap kolom hanya
                                 dapat sekitar 50px, dan "MINGGU" berhuruf kapital tidak muat.
                                 display:none membuang yang tersembunyi dari pohon aksesibilitas,
                                 jadi pembaca layar hanya menerima satu nama, bukan dua. --}}
                            <span class="sm:hidden">{{ $short }}</span>
                            <span class="hidden sm:inline">{{ $full }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach (array_chunk($cells, 7) as $week)
                    <tr>
                        @foreach ($week as $cell)
                            <td class="h-24 border-[2px] border-ink p-1 align-top {{ $cell['day'] === null ? 'bg-black/5' : '' }}">
                                @if ($cell['day'] !== null)
                                    {{-- Kolom ke-6 selalu Minggu karena grid dimulai Senin. Memakai
                                         $loop->last akan salah: baris terakhir bisa pendek bila bulannya
                                         tidak berakhir pada hari Minggu. --}}
                                    <span @class([
                                        'mb-1 block text-xs font-black',
                                        'text-sunday' => $loop->index === 6,
                                    ])>{{ $cell['day'] }}</span>

                                    @foreach (($byDate[$cell['iso']] ?? collect()) as $r)
                                        <a
                                            href="{{ route('public.calendar', ['bulan' => $month, 'pilih' => $r->id]) }}"
                                            @class([
                                                'brut-chip',
                                                'brut-chip-booked' => $r->status?->value === 'confirmed',
                                                'brut-chip-tentative' => $r->status?->value !== 'confirmed',
                                                'brut-chip-selected' => $r->id === $selectedId,
                                            ])
                                        >
                                            @php $status = \App\Enums\ReservationStatus::publicOrDefault($r->status); @endphp

                                            {{ $r->start_time }}
                                            <span class="block truncate font-black">{{ $r->guest_name }}</span>
                                            @if ($r->area)
                                                <span class="block font-normal">{{ $r->area->name }}</span>
                                            @endif
                                            {{-- Statusnya ditulis, bukan hanya diwarnai: rona saja tidak
                                                 terbaca oleh pengunjung yang buta warna. Ikonnya isyarat
                                                 ketiga, bukan pengganti labelnya. --}}
                                            <span class="flex items-center gap-1 text-[9px] font-black uppercase tracking-wide">
                                                @svg($status->publicIcon(), 'h-3 w-3 shrink-0')
                                                {{ $status->publicLabel() }}
                                            </span>
                                        </a>
                                    @endforeach
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <section class="mt-5 flex flex-wrap gap-4 text-xs font-bold uppercase">
        <span class="flex items-center gap-2">
            <span class="inline-block h-4 w-6 border-[2px] border-ink bg-booked"></span>
            @svg(\App\Enums\ReservationStatus::Confirmed->publicIcon(), 'h-4 w-4')
            {{ \App\Enums\ReservationStatus::Confirmed->publicLabel() }}
        </span>
        <span class="flex items-center gap-2">
            <span class="inline-block h-4 w-6 border-[2px] border-dashed border-ink bg-tentative/40"></span>
            @svg(\App\Enums\ReservationStatus::Tentative->publicIcon(), 'h-4 w-4')
            {{ \App\Enums\ReservationStatus::Tentative->publicLabel() }}
        </span>
    </section>

    @if ($selected)
        <section class="brut-box mt-5 p-5">
            <h3 class="text-xl font-black uppercase">
                {{ $selected->guest_name }}
            </h3>
            @if (filled($selected->company))
                <p class="text-sm font-bold uppercase">{{ $selected->company }}</p>
            @endif
            <p class="text-sm font-bold uppercase">
                {{ $selected->reservation_date->translatedFormat('l, d F Y') }}
            </p>

            <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Jam</dt>
                    <dd class="text-sm font-bold">
                        @if (blank($selected->end_time))
                            {{ $selected->start_time }} (jam tunggal)
                        @else
                            {{ $selected->start_time }}–{{ $selected->end_time }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Area</dt>
                    <dd class="text-sm font-bold">{{ $selected->area?->name ?? 'Belum ditentukan' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Jenis acara</dt>
                    <dd class="text-sm font-bold">{{ $selected->eventType?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Status</dt>
                    @php $selectedStatus = \App\Enums\ReservationStatus::publicOrDefault($selected->status); @endphp
                    <dd class="flex items-center gap-1.5 text-sm font-bold">
                        @svg($selectedStatus->publicIcon(), 'h-4 w-4 shrink-0')
                        {{ $selectedStatus->publicLabel() }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Pax</dt>
                    <dd class="text-sm font-bold">{{ $selected->pax }} orang</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Menu</dt>
                    <dd class="text-sm font-bold">{{ $selected->menus->count() ?: '—' }} item</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">PIC / Sales</dt>
                    <dd class="text-sm font-bold">{{ $selected->pic?->name ?? '—' }}</dd>
                </div>
            </dl>

            @if ($selected->menus->isNotEmpty())
                <div class="mt-4 border-t-[3px] border-ink pt-4">
                    <dt class="text-[10px] font-black uppercase tracking-widest">Menu dipesan</dt>
                    <ul class="mt-1 grid gap-x-4 gap-y-1 text-sm font-bold sm:grid-cols-2">
                        @foreach ($selected->menus as $menu)
                            <li class="border-b border-dashed border-ink/30 py-1">
                                <div class="flex justify-between gap-3">
                                    <span>{{ $menu->name }}</span>
                                    <span class="shrink-0 tabular-nums">{{ $menu->pivot->pax }} porsi</span>
                                </div>
                                {{-- Barisnya SELALU ada, meski catatannya kosong.
                                     Letaknya jadi tetap, sehingga mata tidak perlu
                                     mencari apakah hidangan ini punya catatan atau
                                     tidak — em dash sudah menjawabnya.

                                     Berlabel, bukan teks telanjang: tanpa label isinya
                                     menempel persis di bawah nama dan porsi sehingga
                                     terbaca seolah bagian dari nama hidangan — "Tape
                                     Roll 10 porsi sajikan pas tamu datang saja".

                                     Tampil penuh, aturan #4 CLAUDE.md. --}}
                                <p class="mt-0.5 whitespace-pre-line border-s-2 border-amber-500 ps-2 text-xs font-normal">
                                    <span class="font-black uppercase tracking-wide">Catatan:</span>
                                    {{ filled($menu->pivot->remark) ? $menu->pivot->remark : '—' }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Aturan #4 CLAUDE.md berlaku juga di sini: remark tampil penuh.
                 JANGAN memakai Str::limit(), words(), atau menyembunyikannya di
                 balik tombol. --}}
            <div class="mt-4 border-t-[3px] border-ink pt-4">
                <dt class="text-[10px] font-black uppercase tracking-widest">Remark</dt>
                <dd class="mt-1 whitespace-pre-line text-sm font-bold">
                    {{ filled($selected->remark) ? $selected->remark : '—' }}
                </dd>
            </div>
        </section>
    @endif
@endsection
