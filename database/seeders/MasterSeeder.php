<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
use Illuminate\Database\Seeder;

class MasterSeeder extends Seeder
{
    public function run(): void
    {
        $areas = ['VIP 1', 'VIP 2', 'FOYER FnB', 'KORIDOR', 'SOFA REGULAR', 'REGULAR', 'OUTDOOR'];
        $eventTypes = ['TEST FOOD', 'PRIVATE', 'MEETING', 'LUNCH', 'DINNER', 'GATHERING'];
        $menuStyles = ['BUFFET', 'AL CARTE'];

        foreach ($areas as $i => $name) {
            Area::firstOrCreate(['name' => $name], ['sort_order' => $i + 1]);
        }

        foreach ($eventTypes as $i => $name) {
            EventType::firstOrCreate(['name' => $name], ['sort_order' => $i + 1]);
        }

        foreach ($menuStyles as $i => $name) {
            MenuStyle::firstOrCreate(['name' => $name], ['sort_order' => $i + 1]);
        }
    }
}
