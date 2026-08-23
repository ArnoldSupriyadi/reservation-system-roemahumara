<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori menu jadi tabel master, bukan lagi konstanta di kode.
 *
 * Sebelumnya daftarnya ada di Menu::CATEGORIES. Itu mencegah salah ketik, tapi
 * menuntut menyunting berkas PHP setiap kali kategori baru dibutuhkan — beban
 * yang tidak masuk akal untuk sistem yang dikelola sendiri oleh penggunanya.
 * Sebagai tabel master, kategori bisa ditambah lewat /cms/menu-categories dan
 * pencegahan salah ketiknya pindah ke relasi.
 *
 * Urutannya dipertahankan persis seperti urutan lama supaya daftar menu di layar
 * tidak berubah susunannya: id mengikuti urutan penyisipan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /*
         * Yang disisipkan HANYA kategori yang benar-benar sudah dipakai baris
         * menus. Migrasi ini memindahkan data, bukan menyemai — di database
         * kosong ia tidak membuat apa-apa, dan MenuSeeder yang mengisinya.
         * Menyemai dari migrasi membuat pemasangan baru punya kategori yang
         * tidak pernah diminta siapa pun, dan urutannya bertabrakan dengan
         * urutan yang dibuat seeder.
         *
         * Daftar di bawah dipakai hanya untuk MENGURUTKAN, supaya susunan lama
         * di layar tidak berubah. Sengaja disalin ke sini, bukan diambil dari
         * Menu::CATEGORIES: konstanta itu dihapus pada perubahan yang sama, dan
         * migrasi yang memanggil kode aplikasi akan pecah begitu kodenya
         * bergerak.
         */
        $urutanBaku = [
            'Hidangan Pembuka', 'Aneka Jajanan Ringan', 'Selada', 'Sop',
            'Hidangan Nasi & Mie', 'Pasta', 'Lauk Unggas', 'Lauk Daging',
            'Boga Bahari', 'Aneka Hidangan Sayuran', 'Spesial Menu Anak',
            'Aneka Nasi', 'Aneka Sambal', 'Hidangan Penutup',
            'Signature Rempah Umara', 'Signature Healthy Drink', 'Smoothies',
            'Fresh Juice', 'Tea Selection', 'Artisan Flavored Tea',
            'Classic Coffee & Chocolate Selection',
            'Manual Brew & Cold Brew Selection', 'Soft Drink', 'Mineral Water',
            'Gaya Sajian',
        ];

        $terpakai = DB::table('menus')->distinct()->pluck('category')->filter()->unique();

        $urutan = $terpakai
            ->sortBy(function (string $nama) use ($urutanBaku) {
                $posisi = array_search($nama, $urutanBaku, true);

                // Kategori tak dikenal ditaruh di belakang, bukan dibuang.
                return $posisi === false ? PHP_INT_MAX : $posisi;
            })
            ->values();

        foreach ($urutan as $nama) {
            DB::table('menu_categories')->insert([
                'name' => $nama,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('menus', function (Blueprint $table) {
            // Sementara nullable supaya bisa diisi bertahap; dijadikan wajib
            // setelah semua baris terpetakan.
            $table->foreignId('menu_category_id')->nullable()->after('name')
                ->constrained('menu_categories')->restrictOnDelete();
        });

        foreach (DB::table('menu_categories')->get() as $kategori) {
            DB::table('menus')
                ->where('category', $kategori->name)
                ->update(['menu_category_id' => $kategori->id]);
        }

        $yatim = DB::table('menus')->whereNull('menu_category_id')->count();

        if ($yatim > 0) {
            throw new RuntimeException("Ada {$yatim} menu yang kategorinya tidak terpetakan.");
        }

        DB::statement('ALTER TABLE menus MODIFY COLUMN menu_category_id BIGINT UNSIGNED NOT NULL');

        Schema::table('menus', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('category', 80)->nullable()->after('name')->index();
        });

        foreach (DB::table('menu_categories')->get() as $kategori) {
            DB::table('menus')
                ->where('menu_category_id', $kategori->id)
                ->update(['category' => $kategori->name]);
        }

        Schema::table('menus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_category_id');
        });

        Schema::dropIfExists('menu_categories');
    }
};
