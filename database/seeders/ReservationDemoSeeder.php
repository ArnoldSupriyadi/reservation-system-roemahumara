<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Reservasi contoh untuk melihat halaman publik dan kalender staf berisi data.
 *
 * Sengaja TIDAK dipanggil dari DatabaseSeeder: `db:seed` polos harus menghasilkan
 * sistem kosong yang siap dipakai sungguhan, bukan sistem berisi tamu palsu.
 * Jalankan sendiri saat memang ingin melihat tampilan berisi:
 *
 *     php artisan db:seed --class=ReservationDemoSeeder
 *
 * Menulis lewat ReservationWriter, bukan factory, supaya nomor RU-R-nya berurutan
 * sungguhan dan idempotency-nya ikut teruji — menjalankan seeder ini dua kali tidak
 * menghasilkan data kembar.
 */
class ReservationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->orderBy('id')->first();

        if ($actor === null) {
            $this->command?->warn('Belum ada pengguna. Jalankan `php artisan db:seed` dulu.');

            return;
        }

        $areas = Area::query()->orderBy('id')->get();
        $events = EventType::query()->orderBy('id')->get();

        if ($areas->isEmpty() || $events->isEmpty()) {
            $this->command?->warn('Master area atau jenis acara kosong. Jalankan `php artisan db:seed` dulu.');

            return;
        }

        $writer = app(ReservationWriter::class);
        $month = Carbon::now()->startOfMonth();

        // [tanggal ke-, status, jam mulai, jam selesai, nama tamu]
        $rows = [
            [3, 'confirmed', '09:00', '12:00', 'Keluarga Wijaya'],
            [3, 'tentative', '19:00', null, 'Bapak Hartono'],
            [8, 'confirmed', '11:30', '14:00', 'PT Sinar Abadi'],
            [12, null, '18:00', '21:00', 'Ibu Ratna'],
            [17, 'confirmed', '07:00', null, 'Komunitas Sepeda Pagi'],
            [17, 'tentative', '13:00', '16:00', 'Bapak Surya'],
            [17, 'confirmed', '20:00', '23:00', 'Keluarga Tanuwijaya'],
            [22, 'tentative', '10:15', '12:45', 'Ibu Melati'],
            [26, 'confirmed', '16:00', '19:30', 'PT Bangun Karya'],
            // Contoh yang batal: tampil dicoret di kalender staf, dan sama
            // sekali tidak muncul di halaman publik.
            [26, 'cancelled', '09:00', '11:00', 'Bapak Nugroho'],
        ];

        foreach ($rows as $i => [$day, $status, $start, $end, $guest]) {
            $writer->create([
                'reservation_date' => $month->copy()->addDays($day - 1)->toDateString(),
                'guest_name' => $guest,
                'phone' => '08120000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'pic_id' => $actor->id,
                'area_id' => $areas[$i % $areas->count()]->id,
                'event_type_id' => $events[$i % $events->count()]->id,
                'start_time' => $start.':00',
                'end_time' => $end === null ? null : $end.':00',
                'pax' => 10 + $i * 5,
                'status' => $status,
                'remark' => 'Data contoh. Catatan internal ini tidak boleh tampil di halaman publik.',
            ], 'demo-'.$month->format('Y-m').'-'.$i, $actor);
        }

        $this->command?->info('Reservasi contoh: '.Reservation::count().' baris.');
    }
}
