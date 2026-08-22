<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_fills_all_three_masters(): void
    {
        $this->seed(MasterSeeder::class);

        $this->assertSame(7, Area::count());
        $this->assertSame(6, EventType::count());
        $this->assertSame(2, MenuStyle::count());
    }

    /**
     * Urutannya kini mengikuti id, bukan sort_order yang sudah dihapus. Yang
     * dijaga tetap sama: urutan larik di MasterSeeder menentukan urutan tampil,
     * karena id mengikuti urutan penyisipan.
     */
    public function test_areas_are_ordered_as_in_the_spreadsheet(): void
    {
        $this->seed(MasterSeeder::class);

        $this->assertSame(
            ['VIP 1', 'VIP 2', 'FOYER FnB', 'KORIDOR', 'SOFA REGULAR', 'REGULAR', 'OUTDOOR'],
            Area::orderBy('id')->pluck('name')->all()
        );
    }

    public function test_active_scope_filters_inactive_rows(): void
    {
        Area::create(['name' => 'VIP 3', 'is_active' => true]);
        Area::create(['name' => 'GUDANG', 'is_active' => false]);

        $this->assertSame(['VIP 3'], Area::query()->active()->pluck('name')->all());
    }
}
