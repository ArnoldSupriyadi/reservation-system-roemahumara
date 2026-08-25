<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use App\Support\Jam;
use Database\Seeders\MasterSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data contoh harus mematuhi aturan yang sama dengan data sungguhan.
 *
 * Ia menulis lewat ReservationWriter, jadi pelanggaran akan melempar exception
 * dan seedernya gagal di tengah jalan — meninggalkan sebagian baris jadi dan
 * sebagian belum. Test ini menangkapnya di sini, bukan saat seseorang
 * menyiapkan mesin baru dan seedernya berhenti dengan pesan yang tidak
 * dinantikan siapa pun.
 *
 * Dua baris pernah melanggar dan diperbaiki 2026-08-25: satu mulai 07:00, satu
 * lagi berakhir 23:00 — keduanya lahir sebelum jam operasional jadi aturan.
 */
class ReservationDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(MasterSeeder::class);

        // Seedernya memakai pengguna pertama sebagai PIC dan pembuat; tanpa satu
        // pun pengguna ia berhenti dengan peringatan, bukan membuat data.
        User::factory()->create()->assignRole('admin');
    }

    public function test_it_seeds_without_tripping_any_rule(): void
    {
        $this->seed(ReservationDemoSeeder::class);

        $this->assertSame(10, Reservation::count());
    }

    public function test_every_demo_row_sits_inside_operating_hours(): void
    {
        $this->seed(ReservationDemoSeeder::class);

        foreach (Reservation::all() as $r) {
            $this->assertFalse(
                $r->start_time->diLuarJamOperasional(),
                "{$r->guest_name} mulai {$r->start_time}, di luar jam ".Jam::buka().'–'.Jam::tutup()
            );

            if ($r->end_time !== null) {
                $this->assertFalse(
                    $r->end_time->diLuarJamOperasional(),
                    "{$r->guest_name} selesai {$r->end_time}, di luar jam ".Jam::buka().'–'.Jam::tutup()
                );
            }
        }
    }

    /**
     * Aman dijalankan berulang — idempotency lewat ReservationWriter, bukan
     * lewat pengecekan jumlah baris.
     */
    public function test_running_it_twice_does_not_duplicate(): void
    {
        $this->seed(ReservationDemoSeeder::class);
        $this->seed(ReservationDemoSeeder::class);

        $this->assertSame(10, Reservation::count());
    }
}
