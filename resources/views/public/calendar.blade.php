@extends('layouts.public')

@section('title', 'Jadwal '.$monthLabel.' — Roemah Umara')

@section('content')
    <section>
        <h2>{{ $monthLabel }}</h2>

        <a href="{{ route('public.calendar', ['bulan' => $previousMonth]) }}" rel="nofollow">Bulan sebelumnya</a>
        <a href="{{ route('public.calendar', ['bulan' => $nextMonth]) }}" rel="nofollow">Bulan berikutnya</a>

        <p>{{ $total }} jadwal pada bulan ini</p>
    </section>

    <table>
        <thead>
            <tr>
                @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $name)
                    <th scope="col">{{ $name }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach (array_chunk($cells, 7) as $week)
                <tr>
                    @foreach ($week as $cell)
                        <td>
                            @if ($cell['day'] !== null)
                                <span>{{ $cell['day'] }}</span>

                                @foreach (($byDate[$cell['iso']] ?? collect()) as $r)
                                    <a
                                        href="{{ route('public.calendar', ['bulan' => $month, 'pilih' => $r->id]) }}"
                                        @class(['is-selected' => $r->id === $selectedId])
                                    >
                                        <strong>{{ substr($r->start_time, 0, 5) }}</strong>
                                        {{ $r->area?->name }}
                                        <em>{{ $r->status?->publicLabel() ?? 'Sedang dijajaki' }}</em>
                                    </a>
                                @endforeach
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($selected)
        <section>
            <h3>{{ $selected->reservation_date->translatedFormat('l, d F Y') }}</h3>

            <dl>
                <dt>Jam</dt>
                <dd>
                    @if (blank($selected->end_time))
                        {{ substr($selected->start_time, 0, 5) }} (jam tunggal)
                    @else
                        {{ substr($selected->start_time, 0, 5) }}–{{ substr($selected->end_time, 0, 5) }}
                    @endif
                </dd>

                <dt>Area</dt>
                <dd>{{ $selected->area?->name ?? 'Belum ditentukan' }}</dd>

                <dt>Jenis acara</dt>
                <dd>{{ $selected->eventType?->name ?? '—' }}</dd>

                <dt>Status</dt>
                <dd>{{ $selected->status?->publicLabel() ?? 'Sedang dijajaki' }}</dd>
            </dl>
        </section>
    @endif
@endsection
