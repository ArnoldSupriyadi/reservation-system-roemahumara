<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);
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
     * Spec nomor 31. Test terpenting di berkas ini: ia menjaga satu-satunya
     * keputusan yang bisa merugikan tamu bila salah.
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
                ->assertDontSee('RAHASIANAMA')
                ->assertDontSee('RAHASIAPERUSAHAAN')
                ->assertDontSee('081999888777')
                ->assertDontSee('rahasia@contoh.test')
                ->assertDontSee('RAHASIAREMARK')
                ->assertDontSee('4242')
                ->assertDontSee('RAHASIAPIC');
        }
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

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('Terisi');

        Reservation::query()->forceDelete();
        $this->reservation(['status' => 'tentative', 'start_time' => '08:00:00']);

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('Sedang dijajaki');
    }

    public function test_a_reservation_without_a_status_reads_as_tentative(): void
    {
        $this->reservation(['status' => null, 'start_time' => '09:00:00']);

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('Sedang dijajaki');
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
        $event = EventType::create(['name' => 'MEETING', 'sort_order' => 1]);
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
     * berisi satu reservasi tentative pun memuat kata "Terisi". Menghitung kemunculan,
     * bukan sekadar mencarinya, membuat chip yang kehilangan labelnya ketahuan.
     *
     * Pembedaan status juga tidak boleh bergantung pada warna saja, karena pengunjung
     * yang buta warna tidak bisa membacanya.
     */
    public function test_the_chip_itself_says_the_status_not_only_the_colour(): void
    {
        $this->reservation(['status' => 'tentative', 'start_time' => '10:00:00']);

        $onlyTentative = $this->get("/?bulan={$this->month}")->assertOk()->getContent();

        // 'Terisi' sekali saja: dari keterangan warna, bukan dari chip.
        $this->assertSame(1, substr_count($onlyTentative, 'Terisi'));
        $this->assertSame(2, substr_count($onlyTentative, 'Sedang dijajaki'));

        Reservation::query()->forceDelete();
        $this->reservation(['status' => 'confirmed', 'start_time' => '10:00:00']);

        $onlyConfirmed = $this->get("/?bulan={$this->month}")->assertOk()->getContent();

        $this->assertSame(2, substr_count($onlyConfirmed, 'Terisi'));
        $this->assertSame(1, substr_count($onlyConfirmed, 'Sedang dijajaki'));
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
}
