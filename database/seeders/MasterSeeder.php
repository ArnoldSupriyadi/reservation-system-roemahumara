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
        // Ballroom ditaruh di belakang, bukan disisipkan di tengah. Urutan larik
        // ini menentukan id, dan id menentukan urutan tampil — menyisipkan di
        // tengah akan membuat urutan di mesin lama berbeda dari pemasangan baru.
        $areas = [
            'VIP 1', 'VIP 2', 'FOYER FnB', 'KORIDOR', 'SOFA REGULAR', 'REGULAR', 'OUTDOOR',
            'BALLROOM 1', 'BALLROOM 2', 'BALLROOM 3', 'BALLROOM 4', 'ALL BALLROOM',
        ];
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

        $this->linkBallrooms();
    }

    /**
     * ALL BALLROOM adalah BALLROOM 1-4 dengan sekat dibuka.
     *
     * Tanpa hubungan ini ConflictChecker memperlakukan kelimanya sebagai ruangan
     * yang tidak berkaitan, sehingga seluruh ballroom bisa dipesan di atas acara
     * yang sudah ada di salah satu bagiannya tanpa peringatan apa pun.
     */
    private function linkBallrooms(): void
    {
        $all = Area::where('name', 'ALL BALLROOM')->first();

        if ($all === null) {
            return;
        }

        Area::whereIn('name', ['BALLROOM 1', 'BALLROOM 2', 'BALLROOM 3', 'BALLROOM 4'])
            ->get()
            ->each(fn (Area $bagian) => $all->overlapWith($bagian));
    }
}
