<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Http\Controllers\PublicCalendarController;
use App\Models\Area;
use App\Models\EventType;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class PublicCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $pic;

    private Area $area;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();

        // Nama PIC sengaja dibuat mencolok dan tidak mungkin muncul kebetulan,
        // supaya assertDontSee() di bawah benar-benar bermakna.
        $this->pic = User::factory()->create(['name' => 'RAHASIAPIC']);
        $this->area = Area::create(['name' => 'VIP 1']);
        $this->month = Carbon::now()->format('Y-m');
    }

    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'reservation_date' => Carbon::now()->startOfMonth()->addDays(7),
            'pic_id' => $this->pic->id,
            'created_by' => $this->pic->id,
            'start_time' => '12:00:00',
            'area_id' => $this->area->id,
        ], $overrides));
    }

    /** Spec nomor 30. */
    public function test_the_page_opens_without_logging_in(): void
    {
        $this->reservation();

        $this->assertGuest();
        $this->get('/')->assertOk();
    }

    /**
     * Spec nomor 31, dipersempit berulang kali pada 2026-08-22 atas permintaan
     * eksplisit pemilik sistem. Yang tersisa dijaga di sini hanya dua: phone
     * dan email.
     *
     * Yang DILEPAS dan kini terbit ke publik: pax, menu, guest_name,
     * pic_id, remark, company. Dicatat apa adanya supaya pelonggaran ini tetap
     * terbaca sebagai keputusan sadar, bukan sebagai test yang lupa diperbarui.
     */
    public function test_no_private_data_ever_reaches_the_page(): void
    {
        $r = $this->reservation([
            'guest_name' => 'RAHASIANAMA',
            'company' => 'RAHASIAPERUSAHAAN',
            'phone' => '081999888777',
            'email' => 'rahasia@contoh.test',
            'remark' => 'RAHASIAREMARK sudah DP 50 persen',
            'pax' => 4242,
        ]);

        foreach (['/', "/?bulan={$this->month}", "/?bulan={$this->month}&pilih={$r->id}"] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('081999888777')
                ->assertDontSee('rahasia@contoh.test');
        }
    }

    /**
     * Kolom yang masih tertutup tidak boleh sekadar tidak ditulis di Blade — ia
     * tidak boleh dimuat ke memori sama sekali. Ini yang membuat satu baris
     * ceroboh di Blade suatu hari nanti menghasilkan nilai kosong, bukan kebocoran.
     */
    public function test_the_remaining_private_columns_are_never_even_loaded(): void
    {
        $r = $this->reservation(['end_time' => '15:00:00']);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")->assertOk();

        $loaded = app(PublicCalendarController::class);
        $method = new ReflectionMethod($loaded, 'reservationsIn');
        $rows = $method->invoke($loaded, $this->month);

        foreach (['phone', 'email'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $rows->first()->getAttributes(),
                "Kolom {$forbidden} termuat ke halaman publik."
            );
        }
    }

    public function test_guest_name_company_pic_and_remark_are_shown(): void
    {
        $r = $this->reservation([
            'guest_name' => 'KELUARGA WIJAYA',
            'company' => 'PT SINAR ABADI',
            'remark' => "Baris pertama remark.\nBaris kedua remark.",
        ]);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertSee('KELUARGA WIJAYA')
            ->assertSee('PT SINAR ABADI')
            ->assertSee('RAHASIAPIC')
            ->assertSee('Baris pertama remark.')
            ->assertSee('Baris kedua remark.');
    }

    /** company boleh kosong — panelnya tidak boleh menyisakan baris hampa. */
    public function test_a_reservation_without_a_company_renders_cleanly(): void
    {
        $r = $this->reservation(['guest_name' => 'IBU RATNA', 'company' => null]);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertSee('IBU RATNA');
    }

    /** Aturan #4 CLAUDE.md berlaku juga di halaman publik: remark tidak dipotong. */
    public function test_a_long_remark_is_not_truncated(): void
    {
        $long = str_repeat('Catatan panjang yang tidak boleh dipotong. ', 12);
        $r = $this->reservation(['remark' => $long]);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertSee(trim($long))
            ->assertDontSee('...');
    }

    public function test_pax_and_the_ordered_menu_are_shown(): void
    {
        $nasi = Menu::create(['name' => 'Nasi Umara', 'menu_category_id' => MenuCategory::create(['name' => 'Aneka Nasi'])->id]);
        $teh = Menu::create(['name' => 'Ice Lemon Tea', 'menu_category_id' => MenuCategory::create(['name' => 'Artisan Flavored Tea'])->id]);

        $r = $this->reservation(['pax' => 137]);
        $r->menus()->sync([
            $nasi->id => ['pax' => 30, 'remark' => 'Tidak pedas'],
            $teh->id => ['pax' => 50, 'remark' => null],
        ]);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertSee('137')
            ->assertSee('Nasi Umara')
            ->assertSee('Ice Lemon Tea')
            // Porsinya ikut, bukan hanya nama hidangannya. Justru angka itu yang
            // membedakan daftar pesanan dari daftar menu biasa.
            ->assertSee('30 porsi')
            ->assertSee('50 porsi')
            // Catatan per hidangan ikut terbaca publik, sama seperti remark
            // reservasi yang sudah dilepas sebelumnya.
            ->assertSee('Tidak pedas')
            // Berlabel, bukan teks telanjang. Tanpa label, catatannya menempel
            // di bawah porsi dan terbaca seolah bagian dari nama hidangan.
            ->assertSee('Catatan:');
    }

    /** Menu tanpa catatan tidak boleh memunculkan label "Catatan:" yang kosong. */
    public function test_a_menu_without_a_note_shows_no_note_label(): void
    {
        $nasi = Menu::create([
            'name' => 'Nasi Umara',
            'menu_category_id' => MenuCategory::create(['name' => 'Aneka Nasi'])->id,
        ]);

        $r = $this->reservation();
        $r->menus()->sync([$nasi->id => ['pax' => 30, 'remark' => null]]);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertSee('Nasi Umara')
            ->assertDontSee('Catatan:');
    }

    /** Menu opsional: reservasi tanpa menu tetap merender panelnya. */
    public function test_a_reservation_without_any_menu_still_renders(): void
    {
        $r = $this->reservation(['guest_name' => 'TANPA MENU']);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertSee('TANPA MENU')
            ->assertDontSee('porsi');
    }

    public function test_the_page_shows_what_it_is_supposed_to(): void
    {
        $this->reservation(['end_time' => '15:00:00', 'status' => 'confirmed']);

        $this->get("/?bulan={$this->month}")
            ->assertOk()
            ->assertSee('12:00')
            ->assertSee('VIP 1');
    }

    /** Spec nomor 32. */
    public function test_only_the_requested_month_is_listed(): void
    {
        $this->reservation(['start_time' => '06:30:00']);

        $next = Carbon::now()->startOfMonth()->addMonth();
        $this->reservation([
            'reservation_date' => $next->copy()->addDays(3),
            'start_time' => '19:45:00',
        ]);

        $this->get("/?bulan={$this->month}")
            ->assertOk()
            ->assertSee('06:30')
            ->assertDontSee('19:45');

        $this->get('/?bulan='.$next->format('Y-m'))
            ->assertOk()
            ->assertSee('19:45')
            ->assertDontSee('06:30');
    }

    /** Spec nomor 33. */
    public function test_a_soft_deleted_reservation_disappears(): void
    {
        $r = $this->reservation(['start_time' => '07:15:00']);

        $this->get("/?bulan={$this->month}")->assertSee('07:15');

        $r->delete();

        $this->get("/?bulan={$this->month}")->assertDontSee('07:15');
    }

    /** Spec nomor 34. */
    public function test_confirmed_and_tentative_read_differently(): void
    {
        $this->reservation(['status' => 'confirmed', 'start_time' => '08:00:00']);

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('BOOKED');

        Reservation::query()->forceDelete();
        $this->reservation(['status' => 'tentative', 'start_time' => '08:00:00']);

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('Tentatif');
    }

    public function test_a_reservation_without_a_status_reads_as_tentative(): void
    {
        $this->reservation(['status' => null, 'start_time' => '09:00:00']);

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('Tentatif');
    }

    public function test_internal_status_words_never_appear(): void
    {
        $this->reservation(['status' => 'confirmed']);

        $this->get("/?bulan={$this->month}")
            ->assertOk()
            ->assertDontSee('CONFIRMED')
            ->assertDontSee('TENTATIVE');
    }

    /** Spec nomor 35. */
    public function test_the_detail_panel_shows_area_time_and_event(): void
    {
        $event = EventType::create(['name' => 'MEETING']);
        $r = $this->reservation(['end_time' => '15:00:00', 'event_type_id' => $event->id]);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertSee('12:00')
            ->assertSee('15:00')
            ->assertSee('VIP 1')
            ->assertSee('MEETING');
    }

    /** Spec nomor 36. */
    public function test_a_rubbish_month_falls_back_instead_of_failing(): void
    {
        $this->reservation();

        foreach (['bukan-bulan', '2026-13', '2026-8', '<script>alert(1)</script>', ''] as $rubbish) {
            $this->get('/?bulan='.urlencode($rubbish))->assertOk();
        }
    }

    /** Spec nomor 37. */
    public function test_a_selection_from_another_month_is_ignored(): void
    {
        $next = Carbon::now()->startOfMonth()->addMonth();
        $other = $this->reservation([
            'reservation_date' => $next->copy()->addDays(3),
            'start_time' => '21:45:00',
        ]);

        $this->get("/?bulan={$this->month}&pilih={$other->id}")
            ->assertOk()
            ->assertDontSee('21:45');
    }

    public function test_a_nonexistent_selection_does_not_fail(): void
    {
        $this->reservation();

        $this->get("/?bulan={$this->month}&pilih=999999")->assertOk();
        $this->get("/?bulan={$this->month}&pilih=bukan-angka")->assertOk();
    }

    /**
     * Bukan dari rencana. Kedua test spec nomor 34 di atas bisa lolos tanpa chipnya
     * benar, karena keterangan warna Task 24 selalu mencetak kedua kata itu — halaman
     * berisi satu reservasi tentative pun memuat kata "BOOKED". Menghitung kemunculan,
     * bukan sekadar mencarinya, membuat chip yang kehilangan labelnya ketahuan.
     *
     * Pembedaan status juga tidak boleh bergantung pada warna saja, karena pengunjung
     * yang buta warna tidak bisa membacanya.
     */
    public function test_the_chip_itself_says_the_status_not_only_the_colour(): void
    {
        $this->reservation(['status' => 'tentative', 'start_time' => '10:00:00']);

        $onlyTentative = $this->get("/?bulan={$this->month}")->assertOk()->getContent();

        // 'BOOKED' sekali saja: dari keterangan warna, bukan dari chip.
        $this->assertSame(1, substr_count($onlyTentative, 'BOOKED'));
        $this->assertSame(2, substr_count($onlyTentative, 'Tentatif'));

        Reservation::query()->forceDelete();
        $this->reservation(['status' => 'confirmed', 'start_time' => '10:00:00']);

        $onlyConfirmed = $this->get("/?bulan={$this->month}")->assertOk()->getContent();

        $this->assertSame(2, substr_count($onlyConfirmed, 'BOOKED'));
        $this->assertSame(1, substr_count($onlyConfirmed, 'Tentatif'));
    }

    /**
     * Bukan dari rencana. Spec nomor 36 menuntut halaman ini tidak bisa dijatuhkan
     * lewat query string, dan `?bulan[]=x` membuat query() mengembalikan array,
     * bukan string — sesuatu yang tidak diuji oleh test bulan-sampah di atas.
     */
    public function test_an_array_in_the_query_string_does_not_crash_the_page(): void
    {
        $this->reservation();

        $this->get('/?bulan[]=2026-08')->assertOk();
        $this->get('/?pilih[]=1')->assertOk();
        $this->get('/?bulan[]=a&pilih[]=b')->assertOk();
    }

    public function test_the_page_does_not_link_to_the_staff_panel(): void
    {
        $r = $this->reservation();

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertDontSee('/cms');
    }

    /**
     * Reservasi batal tidak boleh tampil ke umum. Blade halaman publik
     * memperlakukan segala yang bukan CONFIRMED sebagai "Tentatif", jadi
     * kalau yang batal ikut termuat, pengunjung membaca slot yang sudah bebas
     * sebagai slot terpakai.
     */
    public function test_a_cancelled_reservation_is_hidden_from_the_public(): void
    {
        $cancelled = $this->reservation([
            'guest_name' => 'Batal',
            'start_time' => '08:00:00',
            'status' => ReservationStatus::Cancelled,
        ]);

        $response = $this->get("/?bulan={$this->month}");

        $response->assertOk()->assertDontSee('08:00');

        // Membukanya lewat ?pilih pun tidak boleh memunculkan jamnya. Yang
        // diperiksa jamnya, bukan kata "Tentatif" — kata itu juga ada di
        // legenda warna halaman, sehingga tidak membuktikan apa pun.
        $this->get("/?bulan={$this->month}&pilih={$cancelled->id}")
            ->assertOk()
            ->assertDontSee('08:00');
    }

    /**
     * Ikon status benar-benar ter-render, dan ikon yang benar untuk statusnya.
     *
     * Yang dibandingkan bentuk path-nya, bukan nama kelas heroicon, supaya test
     * ini tidak pecah hanya karena versi heroicons naik — tapi tetap gagal kalau
     * ikonnya hilang atau tertukar antar status.
     */
    public function test_each_public_status_carries_its_own_icon(): void
    {
        $cases = [ReservationStatus::Confirmed, ReservationStatus::Tentative];

        foreach ($cases as $status) {
            Reservation::query()->forceDelete();
            $this->reservation(['status' => $status, 'start_time' => '07:00:00']);

            $content = $this->get("/?bulan={$this->month}")->assertOk()->getContent();

            $this->assertStringContainsString(
                $this->iconPath($status),
                $content,
                "Ikon untuk {$status->value} tidak ter-render."
            );

            $this->assertStringNotContainsString(
                $this->iconPath(ReservationStatus::Cancelled),
                $content,
                'Ikon batal tidak boleh muncul di halaman publik.'
            );
        }
    }

    /** Atribut d dari path pertama ikon — cukup untuk membedakan satu ikon dari yang lain. */
    private function iconPath(ReservationStatus $status): string
    {
        preg_match('/d="([^"]+)"/', svg($status->publicIcon())->toHtml(), $m);

        return $m[1];
    }

    public function test_tentative_and_confirmed_are_still_public(): void
    {
        $this->reservation(['guest_name' => 'Tentatif', 'start_time' => '09:00:00']);
        $this->reservation([
            'guest_name' => 'Pasti',
            'start_time' => '16:00:00',
            'status' => ReservationStatus::Confirmed,
        ]);

        $this->get("/?bulan={$this->month}")
            ->assertOk()
            ->assertSee('09:00')
            ->assertSee('16:00');
    }
}
