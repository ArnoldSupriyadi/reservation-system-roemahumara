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

        // Urutan tampilnya mengikuti id, dan id mengikuti urutan penyisipan di
        // sini — jadi urutan larik di atas tetap menentukan urutan di layar,
        // tanpa perlu kolom sort_order.
        foreach ($areas as $name) {
            Area::firstOrCreate(['name' => $name]);
        }

        foreach ($eventTypes as $name) {
            EventType::firstOrCreate(['name' => $name]);
        }

        foreach ($menuStyles as $name) {
            MenuStyle::firstOrCreate(['name' => $name]);
        }
    }
}
