<x-filament-panels::page>
    @php
        $byDate = $this->reservations->groupBy(fn ($r) => $r->reservation_date->toDateString());
        $selected = $this->selected;
    @endphp

    <div class="flex items-center gap-2">
        <h2 class="text-lg font-bold">{{ $this->monthLabel }}</h2>

        <x-filament::button size="xs" color="gray" wire:click="shiftMonth(-1)">‹</x-filament::button>
        <x-filament::button size="xs" color="gray" wire:click="shiftMonth(1)">›</x-filament::button>

        <span class="ms-auto text-xs text-gray-500">
            {{ $this->reservations->count() }} reservasi bulan ini
        </span>
    </div>

    <div class="mt-3 grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 dark:border-gray-700 dark:bg-gray-700">
        @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $name)
            <div class="bg-white py-1 text-center text-[10px] uppercase tracking-wider text-gray-500 dark:bg-gray-900">
                {{ $name }}
            </div>
        @endforeach

        @foreach ($this->cells as $cell)
            <div class="min-h-[86px] p-1 {{ $cell['day'] === null ? 'bg-gray-50 dark:bg-gray-800' : 'bg-white dark:bg-gray-900' }}">
                @if ($cell['day'] !== null)
                    @php $items = $byDate[$cell['iso']] ?? collect(); @endphp

                    <div class="mb-1 text-[11px] {{ $items->isNotEmpty() ? 'font-bold text-gray-900 dark:text-gray-100' : 'text-gray-400' }}">
                        {{ $cell['day'] }}
                    </div>

                    @foreach ($items as $r)
                        <button
                            type="button"
                            wire:click="select({{ $r->id }})"
                            title="{{ substr($r->start_time, 0, 5) }} {{ $r->guest_name }}"
                            @class([
                                'mb-0.5 block w-full truncate border-s-2 py-0.5 pe-0.5 ps-1 text-start text-[10px] leading-tight',
                                'border-s-green-600' => $r->status?->value === 'confirmed',
                                'border-s-amber-500 border-dashed' => $r->status?->value === 'tentative',
                                'border-s-gray-300' => $r->status === null,
                                'bg-gray-900 font-bold text-white' => $r->id === $this->selectedId,
                                'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' => $r->id !== $this->selectedId,
                            ])
                        >
                            <span class="font-bold">{{ substr($r->start_time, 0, 5) }}</span>
                            {{ $r->guest_name }}
                        </button>
                    @endforeach
                @endif
            </div>
        @endforeach
    </div>

    @if ($selected)
        <x-filament::section class="mt-3">
            <x-slot name="heading">{{ $selected->guest_name }}</x-slot>

            <x-slot name="description">
                {{ $selected->reservation_date->translatedFormat('d F Y') }} ·
                @if (blank($selected->end_time))
                    {{ substr($selected->start_time, 0, 5) }} (jam tunggal)
                @else
                    {{ substr($selected->start_time, 0, 5) }}–{{ substr($selected->end_time, 0, 5) }} (rentang)
                @endif
            </x-slot>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                @foreach ([
                    'Company' => $selected->company,
                    'HP' => $selected->phone,
                    'Email' => $selected->email,
                    'PIC / Sales' => $selected->pic?->name,
                    'Event' => $selected->eventType?->name,
                    'Menu style' => $selected->menuStyle?->name,
                    'Area' => $selected->area?->name,
                    'Pax' => $selected->pax,
                ] as $label => $value)
                    <div>
                        <dt class="text-[9px] font-semibold uppercase tracking-wider text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-3">
                <div class="text-[9px] font-semibold uppercase tracking-wider text-gray-400">Remark</div>
                @if (filled($selected->remark))
                    <p class="mt-0.5 whitespace-pre-line border-s-2 border-amber-500 ps-3 text-sm text-gray-700 dark:text-gray-300">
                        {{ $selected->remark }}
                    </p>
                @else
                    <p class="mt-0.5 text-sm text-gray-400">Tidak ada remark.</p>
                @endif
            </div>

            <div class="mt-4 flex gap-2">
                <x-filament::button
                    tag="a"
                    size="sm"
                    color="gray"
                    href="{{ \App\Filament\Resources\Reservations\ReservationResource::getUrl('view', ['record' => $selected]) }}"
                >
                    Detail &amp; riwayat
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    size="sm"
                    color="gray"
                    href="{{ \App\Filament\Resources\Reservations\ReservationResource::getUrl('edit', ['record' => $selected]) }}"
                >
                    Ubah
                </x-filament::button>
            </div>
        </x-filament::section>
    @else
        <p class="mt-3 rounded-lg border border-gray-200 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700">
            Klik salah satu reservasi di kalender untuk melihat detail lengkapnya.
        </p>
    @endif
</x-filament-panels::page>
