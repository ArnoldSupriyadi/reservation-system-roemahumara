@php
    $labels = [
        'reservation_date' => 'Tanggal',
        'guest_name' => 'Nama tamu',
        'company' => 'Company',
        'phone' => 'HP',
        'email' => 'Email',
        'pic_id' => 'PIC',
        'event_type_id' => 'Event',
        'menu_style_id' => 'Menu style',
        'area_id' => 'Area',
        'start_time' => 'Jam mulai',
        'end_time' => 'Jam selesai',
        'pax' => 'Pax',
        'status' => 'Status',
        'remark' => 'Remark',
    ];

    $activities = $getRecord()->activities()->with('causer:id,name')->latest('id')->get();
@endphp

<div class="space-y-4">
    @forelse ($activities as $activity)
        <div class="border-s-2 border-gray-200 ps-3 dark:border-gray-700">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ $activity->causer?->name ?? 'Sistem' }}
                </span>
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $activity->event === 'created' ? 'membuat reservasi' : ($activity->event === 'deleted' ? 'menghapus reservasi' : 'mengubah') }}
                </span>
                <span class="ms-auto text-xs text-gray-400">
                    {{ $activity->created_at->translatedFormat('d M Y, H:i') }}
                </span>
            </div>

            @if ($activity->event === 'updated')
                <ul class="mt-1 space-y-1">
                    @foreach (($activity->properties['attributes'] ?? []) as $field => $new)
                        @php $old = $activity->properties['old'][$field] ?? null; @endphp

                        @if ($field === 'remark')
                            <li>
                                <div class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                                    {{ $labels[$field] ?? $field }}
                                </div>
                                <div class="mt-0.5 whitespace-pre-line text-xs text-gray-400 line-through">
                                    {{ filled($old) ? $old : 'kosong' }}
                                </div>
                                <div class="mt-0.5 whitespace-pre-line text-xs text-gray-800 dark:text-gray-200">
                                    {{ filled($new) ? $new : 'kosong' }}
                                </div>
                            </li>
                        @else
                            <li class="text-xs text-gray-700 dark:text-gray-300">
                                {{ $labels[$field] ?? $field }}:
                                <span class="text-gray-400">{{ filled($old) ? $old : 'kosong' }}</span>
                                &rarr;
                                <span>{{ filled($new) ? $new : 'kosong' }}</span>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">Belum ada riwayat perubahan.</p>
    @endforelse
</div>
