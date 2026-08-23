<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use Database\Seeders\MenuSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationMenuTest extends TestCase
{
    use RefreshDatabase;

    private ReservationWriter $writer;

    private User $actor;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ReservationWriter::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        $this->area = Area::create(['name' => 'VIP 1']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'reservation_date' => '2026-09-07',
            'guest_name' => 'Ibu Marluce',
            'phone' => '08112233445',
            'pic_id' => $this->actor->id,
            'area_id' => $this->area->id,
            'start_time' => '12:00',
            'pax' => 27,
            'remark' => 'Uji menu.',
        ], $overrides);
    }

    private function menu(string $nama, string $kategori = 'Aneka Nasi'): Menu
    {
        return Menu::create([
            'name' => $nama,
            'menu_category_id' => MenuCategory::firstOrCreate(['name' => $kategori])->id,
        ]);
    }

    public function test_a_reservation_can_order_several_menus_with_their_own_portions(): void
    {
        $nasi = $this->menu('Nasi Umara');
        $sate = $this->menu('Sate Ayam Roemah Umara', 'Lauk Unggas');
        $teh = $this->menu('Ice Lemon Tea', 'Artisan Flavored Tea');

        $r = $this->writer->create($this->payload([
            'menu_items' => [
                ['menu_id' => $nasi->id, 'pax' => 30],
                ['menu_id' => $sate->id, 'pax' => 20],
                ['menu_id' => $teh->id, 'pax' => 50],
            ],
        ]), (string) Str::uuid(), $this->actor);

        $this->assertSame(3, $r->menus()->count());
        $this->assertSame(30, $r->menus->firstWhere('id', $nasi->id)->pivot->pax);
        $this->assertSame(20, $r->menus->firstWhere('id', $sate->id)->pivot->pax);
        $this->assertSame(50, $r->menus->firstWhere('id', $teh->id)->pivot->pax);
    }

    /**
     * Porsi tiap item berdiri sendiri, tidak diturunkan dari pax reservasi.
     * Kalau ia ikut pax, minuman yang dipesan lebih banyak daripada jumlah tamu
     * jadi mustahil dicatat — padahal itu kejadian biasa.
     */
    public function test_portions_are_independent_of_the_guest_count(): void
    {
        $teh = $this->menu('Ice Lemon Tea', 'Artisan Flavored Tea');

        $r = $this->writer->create($this->payload([
            'pax' => 27,
            'menu_items' => [['menu_id' => $teh->id, 'pax' => 50]],
        ]), (string) Str::uuid(), $this->actor);

        $this->assertSame(27, $r->pax);
        $this->assertSame(50, $r->menus->first()->pivot->pax);
    }

    public function test_menu_is_optional(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);

        $this->assertSame(0, $r->menus()->count());
    }

    public function test_editing_replaces_the_whole_selection(): void
    {
        $nasi = $this->menu('Nasi Umara');
        $sate = $this->menu('Sate Ayam Roemah Umara', 'Lauk Unggas');

        $r = $this->writer->create($this->payload([
            'menu_items' => [['menu_id' => $nasi->id, 'pax' => 30]],
        ]), (string) Str::uuid(), $this->actor);

        $this->writer->update($r, $this->payload([
            'menu_items' => [['menu_id' => $sate->id, 'pax' => 12]],
        ]), 1, $this->actor);

        $segar = $r->fresh()->load('menus');

        $this->assertSame(1, $segar->menus->count());
        $this->assertSame('Sate Ayam Roemah Umara', $segar->menus->first()->name);
        $this->assertSame(12, $segar->menus->first()->pivot->pax);
    }

    /**
     * update() menerima array parsial. Mengubah pax saja tidak boleh diam-diam
     * menghapus menu yang sudah dipesan — pembedaannya: kunci menu_items tidak
     * dikirim sama sekali, versus dikirim sebagai larik kosong.
     */
    public function test_a_partial_update_leaves_the_menu_alone(): void
    {
        $nasi = $this->menu('Nasi Umara');

        $r = $this->writer->create($this->payload([
            'menu_items' => [['menu_id' => $nasi->id, 'pax' => 30]],
        ]), (string) Str::uuid(), $this->actor);

        $this->writer->update($r, ['pax' => 40], 1, $this->actor);

        $this->assertSame(1, $r->fresh()->menus()->count(), 'Menu tidak boleh ikut terhapus.');
    }

    public function test_sending_an_empty_list_clears_the_menu(): void
    {
        $nasi = $this->menu('Nasi Umara');

        $r = $this->writer->create($this->payload([
            'menu_items' => [['menu_id' => $nasi->id, 'pax' => 30]],
        ]), (string) Str::uuid(), $this->actor);

        $this->writer->update($r, ['menu_items' => []], 1, $this->actor);

        $this->assertSame(0, $r->fresh()->menus()->count());
    }

    /**
     * Menu yang pernah dipesan tidak boleh lenyap dari catatan hanya karena
     * dihapus dari master — pivotnya memakai restrictOnDelete. Untuk menu yang
     * tidak lagi disajikan, ada kolom is_active.
     */
    public function test_a_menu_in_use_cannot_be_deleted(): void
    {
        $nasi = $this->menu('Nasi Umara');

        $this->writer->create($this->payload([
            'menu_items' => [['menu_id' => $nasi->id, 'pax' => 30]],
        ]), (string) Str::uuid(), $this->actor);

        $this->expectException(QueryException::class);

        $nasi->delete();
    }

    public function test_deleting_a_reservation_removes_its_menu_rows(): void
    {
        $nasi = $this->menu('Nasi Umara');

        $r = $this->writer->create($this->payload([
            'menu_items' => [['menu_id' => $nasi->id, 'pax' => 30]],
        ]), (string) Str::uuid(), $this->actor);

        $r->forceDelete();

        $this->assertSame(0, \DB::table('menu_reservation')->count());
    }

    /** Setiap menu harus punya kategori; kolomnya NOT NULL. */
    public function test_every_seeded_menu_has_a_category(): void
    {
        $this->seed(MenuSeeder::class);

        $this->assertSame(0, Menu::whereNull('menu_category_id')->count());
        $this->assertSame(24, MenuCategory::count());
    }

    /**
     * Kategori kini baris master, bukan konstanta — bisa ditambah lewat CMS
     * tanpa menyunting kode.
     */
    public function test_a_new_category_can_be_added_without_touching_code(): void
    {
        $this->seed(MenuSeeder::class);

        $baru = MenuCategory::create(['name' => 'Paket Hemat']);
        $menu = $this->menu('Paket Nasi Ayam', 'Paket Hemat');

        $this->assertSame($baru->id, $menu->menu_category_id);
        $this->assertSame('Paket Hemat', $menu->fresh()->category->name);
        $this->assertSame(25, MenuCategory::count(), '24 dari seeder plus satu yang baru dibuat.');
    }

    /** Kategori yang masih dipakai tidak boleh terhapus dan menyeret menunya. */
    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $menu = $this->menu('Nasi Umara');

        $this->expectException(QueryException::class);

        $menu->category->delete();
    }

    public function test_the_seeder_loads_every_dish(): void
    {
        $this->seed(MenuSeeder::class);

        $this->assertSame(137, Menu::count());
        $this->assertSame(24, MenuCategory::count());
        $this->assertTrue(Menu::where('name', 'Papardelle Al Ragù')->exists(), 'Huruf beraksen harus utuh.');
    }

    public function test_the_seeder_is_safe_to_run_twice(): void
    {
        $this->seed(MenuSeeder::class);
        $this->seed(MenuSeeder::class);

        $this->assertSame(137, Menu::count());
    }

    /** Reservasi yang sama tidak boleh memesan satu menu dua kali. */
    public function test_the_same_menu_cannot_be_ordered_twice(): void
    {
        $nasi = $this->menu('Nasi Umara');

        $r = $this->writer->create($this->payload([
            'menu_items' => [
                ['menu_id' => $nasi->id, 'pax' => 30],
                ['menu_id' => $nasi->id, 'pax' => 10],
            ],
        ]), (string) Str::uuid(), $this->actor);

        $this->assertSame(1, $r->menus()->count());
        $this->assertSame(10, $r->menus->first()->pivot->pax, 'Yang terakhir menang, bukan gagal dengan galat SQL.');
    }

    public function test_menus_are_listed_in_menu_order_not_alphabetically(): void
    {
        $this->seed(MenuSeeder::class);

        $urutan = Menu::query()->with('category')->inMenuOrder()->get()
            ->pluck('category.name')->unique()->values()->all();

        $this->assertSame('Hidangan Pembuka', $urutan[0], 'Pembuka harus lebih dulu.');
        $this->assertSame('Mineral Water', end($urutan), 'Minuman terakhir.');
    }

    public function test_each_ordered_menu_can_carry_its_own_note(): void
    {
        $nasi = $this->menu('Nasi Umara');
        $sate = $this->menu('Sate Ayam Roemah Umara', 'Lauk Unggas');

        $r = $this->writer->create($this->payload([
            'menu_items' => [
                ['menu_id' => $nasi->id, 'pax' => 30, 'remark' => 'Tidak pedas'],
                ['menu_id' => $sate->id, 'pax' => 20, 'remark' => "Tanpa kacang.\nSaus dipisah."],
            ],
        ]), (string) Str::uuid(), $this->actor);

        $this->assertSame('Tidak pedas', $r->menus->firstWhere('id', $nasi->id)->pivot->remark);
        $this->assertSame(
            "Tanpa kacang.\nSaus dipisah.",
            $r->menus->firstWhere('id', $sate->id)->pivot->remark,
            'Catatan berbaris banyak harus utuh.'
        );
    }

    /** Catatan opsional, dan spasi doang tidak dihitung sebagai catatan. */
    public function test_a_blank_note_is_stored_as_nothing(): void
    {
        $nasi = $this->menu('Nasi Umara');

        $r = $this->writer->create($this->payload([
            'menu_items' => [['menu_id' => $nasi->id, 'pax' => 30, 'remark' => '   ']],
        ]), (string) Str::uuid(), $this->actor);

        $this->assertNull($r->menus->first()->pivot->remark);
    }

    public function test_reservation_factory_still_works_without_menus(): void
    {
        $r = Reservation::factory()->create();

        $this->assertSame(0, $r->menus()->count());
    }
}
