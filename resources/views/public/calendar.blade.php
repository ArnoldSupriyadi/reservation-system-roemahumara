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
                                                'brut-chip-taken' => $r->status?->value === 'confirmed',
                                                'brut-chip-tentative' => $r->status?->value !== 'confirmed',
                                                'brut-chip-selected' => $r->id === $selectedId,
                                            ])
                                        >
                                            {{ substr($r->start_time, 0, 5) }}
                                            @if ($r->area)
                                                <span class="block font-normal">{{ $r->area->name }}</span>
                                            @endif
                                            {{-- Statusnya ditulis, bukan hanya diwarnai: rona saja tidak
                                                 terbaca oleh pengunjung yang buta warna. --}}
                                            <span class="block text-[9px] font-black uppercase tracking-wide">
                                                {{ $r->status?->publicLabel() ?? 'Sedang dijajaki' }}
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
            <span class="inline-block h-4 w-6 border-[2px] border-ink bg-taken"></span> Terisi
        </span>
        <span class="flex items-center gap-2">
            <span class="inline-block h-4 w-6 border-[2px] border-dashed border-ink bg-tentative/40"></span> Sedang dijajaki
        </span>
    </section>

    @if ($selected)
        <section class="brut-box mt-5 p-5">
            <h3 class="text-xl font-black uppercase">
                {{ $selected->reservation_date->translatedFormat('l, d F Y') }}
            </h3>

            <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Jam</dt>
                    <dd class="text-sm font-bold">
                        @if (blank($selected->end_time))
                            {{ substr($selected->start_time, 0, 5) }} (jam tunggal)
                        @else
                            {{ substr($selected->start_time, 0, 5) }}–{{ substr($selected->end_time, 0, 5) }}
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
                    <dd class="text-sm font-bold">{{ $selected->status?->publicLabel() ?? 'Sedang dijajaki' }}</dd>
                </div>
            </dl>
        </section>
    @endif
@endsection
