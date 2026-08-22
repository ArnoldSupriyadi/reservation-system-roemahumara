<?php

namespace Tests\Unit;

use App\Support\MonthGrid;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthGridTest extends TestCase
{
    /**
     * Spec nomor 38. Tujuh bulan yang tanggal 1-nya jatuh pada tujuh hari berbeda.
     */
    public function test_the_week_starts_on_monday_whatever_day_the_month_begins(): void
    {
        foreach (['2026-06', '2026-09', '2026-04', '2026-01', '2026-05', '2026-08', '2026-02'] as $month) {
            $cells = MonthGrid::cells($month);
            $first = Carbon::createFromFormat('Y-m-d', $month.'-01');
            $expectedLead = ($first->dayOfWeek + 6) % 7;

            $lead = 0;

            foreach ($cells as $cell) {
                if ($cell['day'] !== null) {
                    break;
                }

                $lead++;
            }

            $this->assertSame($expectedLead, $lead, "Sel kosong salah untuk {$month} ({$first->format('l')}).");
            $this->assertSame($expectedLead + $first->daysInMonth, count($cells), "Jumlah sel salah untuk {$month}.");
        }
    }

    public function test_january_first_2026_lands_in_the_thursday_column(): void
    {
        $cells = MonthGrid::cells('2026-01');

        $this->assertSame('Thursday', Carbon::parse('2026-01-01')->format('l'));
        $this->assertSame(3, array_search(1, array_column($cells, 'day'), true));
    }

    public function test_each_day_carries_its_iso_date(): void
    {
        $cells = MonthGrid::cells('2026-08');
        $days = array_values(array_filter($cells, fn ($cell) => $cell['day'] !== null));

        $this->assertSame('2026-08-01', $days[0]['iso']);
        $this->assertSame('2026-08-09', $days[8]['iso']);
        $this->assertSame('2026-08-31', $days[30]['iso']);
    }

    public function test_the_label_is_written_in_indonesian(): void
    {
        $this->assertSame('Agustus 2026', MonthGrid::label('2026-08'));
    }

    public function test_a_valid_month_passes_through_normalize(): void
    {
        $this->assertSame('2026-08', MonthGrid::normalize('2026-08'));
    }

    public function test_rubbish_falls_back_to_the_current_month(): void
    {
        $now = Carbon::now()->format('Y-m');

        $this->assertSame($now, MonthGrid::normalize(null));
        $this->assertSame($now, MonthGrid::normalize(''));
        $this->assertSame($now, MonthGrid::normalize('bukan-bulan'));
        $this->assertSame($now, MonthGrid::normalize('2026-13'));
        $this->assertSame($now, MonthGrid::normalize('2026-8'));
        $this->assertSame($now, MonthGrid::normalize('<script>alert(1)</script>'));
    }

    public function test_shift_moves_by_whole_months(): void
    {
        $this->assertSame('2026-09', MonthGrid::shift('2026-08', 1));
        $this->assertSame('2026-07', MonthGrid::shift('2026-08', -1));
        $this->assertSame('2027-01', MonthGrid::shift('2026-12', 1));
        $this->assertSame('2025-12', MonthGrid::shift('2026-01', -1));
    }
}
