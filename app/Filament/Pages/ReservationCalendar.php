<?php

namespace App\Filament\Pages;

use App\Models\Reservation;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

class ReservationCalendar extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-calendar';

    protected static string | UnitEnum | null $navigationGroup = 'Reservasi';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Kalender';

    protected static ?string $navigationLabel = 'Kalender';

    protected string $view = 'filament.pages.reservation-calendar';

    /** Bulan aktif dalam format Y-m. */
    public string $month;

    public ?int $selectedId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Reservation::class) ?? false;
    }

    public function mount(): void
    {
        $this->month = Carbon::now()->format('Y-m');
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->month.'-01')
            ->addMonths($delta)
            ->format('Y-m');

        $this->selectedId = null;
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    /** @return Collection<int, Reservation> */
    public function getReservationsProperty(): Collection
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();

        return Reservation::query()
            ->with(['pic:id,name', 'area:id,name', 'eventType:id,name', 'menuStyle:id,name'])
            ->whereYear('reservation_date', $start->year)
            ->whereMonth('reservation_date', $start->month)
            ->orderBy('reservation_date')
            ->orderBy('start_time')
            ->get();
    }

    public function getSelectedProperty(): ?Reservation
    {
        return $this->reservations->firstWhere('id', $this->selectedId);
    }

    /**
     * Sel kalender dengan minggu dimulai hari Senin.
     * Sel kosong di awal bulan bernilai null.
     *
     * @return array<int, array{day: ?int, iso: ?string}>
     */
    public function getCellsProperty(): array
    {
        $first = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();

        // dayOfWeek: 0 = Minggu. Geser agar Senin menjadi 0.
        $lead = ($first->dayOfWeek + 6) % 7;

        $cells = array_fill(0, $lead, ['day' => null, 'iso' => null]);

        for ($day = 1; $day <= $first->daysInMonth; $day++) {
            $cells[] = [
                'day' => $day,
                'iso' => sprintf('%s-%02d', $this->month, $day),
            ];
        }

        return $cells;
    }

    public function getMonthLabelProperty(): string
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->translatedFormat('F Y');
    }
}
