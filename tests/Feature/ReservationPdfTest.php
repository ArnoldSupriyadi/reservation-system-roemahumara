<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\EventType;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->staff = User::factory()->create(['name' => 'IRA']);
        $this->staff->assignRole('staff');

        $this->reservation = Reservation::factory()->create([
            'guest_name' => 'Ibu Marluce',
            'company' => 'PT Sinar Abadi',
            'phone' => '08123456789',
            'email' => 'marluce@contoh.test',
            'pic_id' => $this->staff->id,
            'created_by' => $this->staff->id,
            'area_id' => Area::create(['name' => 'VIP 1'])->id,
            'event_type_id' => EventType::create(['name' => 'GATHERING'])->id,
            'pax' => 27,
            'remark' => "Sekat dibuka jam 11.30.\nTagihan ke perusahaan.",
        ]);

        $menu = Menu::create([
            'name' => 'Tape Roll',
            'menu_category_id' => MenuCategory::create(['name' => 'Aneka Jajanan Ringan'])->id,
        ]);

        $this->reservation->menus()->sync([
            $menu->id => ['pax' => 10, 'remark' => 'sajikan pas tamu datang saja'],
        ]);
    }

    private function url(): string
    {
        return "/cms/reservations/{$this->reservation->id}/pdf";
    }

    /** HTML sumber dokumennya, jauh lebih bisa diperiksa daripada byte PDF. */
    private function html(): string
    {
        return view('pdf.reservation', [
            'reservation' => $this->reservation->load(['pic', 'area', 'eventType', 'menus.category']),
            'dicetakOleh' => 'IRA',
        ])->render();
    }

    public function test_it_returns_a_real_pdf(): void
    {
        $res = $this->actingAs($this->staff)->get($this->url());

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_the_file_is_named_after_the_reservation_number(): void
    {
        $res = $this->actingAs($this->staff)->get($this->url());

        $this->assertStringContainsString(
            $this->reservation->reservation_number.'.pdf',
            (string) $res->headers->get('content-disposition'),
        );
    }

    /**
     * Tanpa masuk, id yang ditebak di URL akan membuka nomor HP, email, dan
     * catatan internal tamu kepada siapa saja.
     */
    public function test_a_guest_cannot_download_it(): void
    {
        $this->assertGuest();

        $this->get($this->url())->assertRedirect();
    }

    /** Pengguna nonaktif ditolak, sama seperti di panel. */
    public function test_an_inactive_user_cannot_download_it(): void
    {
        $mati = User::factory()->inactive()->create();
        $mati->assignRole('staff');

        $this->actingAs($mati)->get($this->url())->assertForbidden();
    }

    /**
     * Kop kiri berisi logo saja — teks "Roemah Umara RESERVATION" dihapus
     * 2026-08-23 karena logonya sendiri sudah memuat tulisan itu. Yang diperiksa
     * gambarnya benar-benar dirujuk, bukan sekadar ada teks apa pun: logo yang
     * gagal dimuat meninggalkan kop kosong tanpa satu pun tanda kesalahan.
     */
    public function test_the_header_shows_the_logo_and_the_address(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('img/logo-gold.png', $html, 'Logo tidak dirujuk di kop.');
        $this->assertFileExists(public_path('img/logo-gold.png'));

        // Teks merek TIDAK boleh kembali; logonya sudah memuatnya.
        $this->assertStringNotContainsString('<div class="merek">', $html);

        $this->assertStringContainsString('Jl. RC. Veteran Raya No.Lot 51', $html);
        $this->assertStringContainsString('Jakarta Selatan, 12330', $html);
    }

    public function test_it_shows_everything_in_the_reservation(): void
    {
        $html = $this->html();

        foreach ([
            $this->reservation->reservation_number,
            'Ibu Marluce', 'PT Sinar Abadi', '08123456789', 'marluce@contoh.test',
            'IRA', 'VIP 1', 'GATHERING', '27',
            'Tape Roll', 'Aneka Jajanan Ringan', '10', 'sajikan pas tamu datang saja',
            'Sekat dibuka jam 11.30.', 'Tagihan ke perusahaan.',
        ] as $harusAda) {
            $this->assertStringContainsString($harusAda, $html, "'{$harusAda}' tidak ada di dokumen.");
        }
    }

    /**
     * Riwayat perubahan sengaja di luar dokumen. Diperiksa dengan membuat
     * riwayat yang benar-benar ada lebih dulu — memeriksa ketiadaan kata pada
     * reservasi yang memang belum pernah diubah tidak membuktikan apa pun.
     */
    public function test_it_leaves_out_the_change_history(): void
    {
        $this->reservation->pax = 40;
        $this->reservation->save();

        $this->assertGreaterThan(1, $this->reservation->activities()->count(), 'Riwayatnya harus ada dulu.');

        $html = $this->html();

        $this->assertStringNotContainsString('Riwayat', $html);
        $this->assertStringNotContainsString('mengubah', $html);
        $this->assertStringNotContainsString('membuat reservasi', $html);
    }

    public function test_a_reservation_without_menus_still_prints(): void
    {
        $this->reservation->menus()->detach();

        $this->actingAs($this->staff)->get($this->url())->assertOk();

        $this->assertStringContainsString('Tidak ada menu dipesan.', $this->html());
    }

    /** Aturan #4 CLAUDE.md: remark tampil penuh, juga di kertas. */
    public function test_a_long_remark_is_not_truncated(): void
    {
        $panjang = str_repeat('Catatan panjang yang tidak boleh dipotong. ', 12);
        $this->reservation->remark = $panjang;
        $this->reservation->save();

        $this->assertStringContainsString(trim($panjang), $this->html());
    }
}
