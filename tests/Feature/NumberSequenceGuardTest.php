<?php

namespace Tests\Feature;

use App\Services\NumberSequence;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Spec nomor 28.
 *
 * Kelas ini sengaja TIDAK memakai RefreshDatabase. Trait itu membungkus setiap
 * test dalam transaksi (RefreshDatabase.php:147 memanggil beginTransaction),
 * sehingga DB::transactionLevel() tidak pernah bernilai 0 di dalamnya dan
 * penjaga yang sedang diuji di sini tidak pernah bisa aktif.
 *
 * Testnya aman dijalankan tanpa RefreshDatabase karena penjaga melempar sebelum
 * menyentuh database sama sekali — tidak ada baris yang dibaca maupun ditulis.
 */
class NumberSequenceGuardTest extends TestCase
{
    public function test_calling_outside_a_transaction_is_refused(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Test ini hanya bermakna di luar transaksi.');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('harus dipanggil di dalam transaksi');

        app(NumberSequence::class)->next('reservation');
    }

    public function test_the_guard_lets_a_real_transaction_through(): void
    {
        $sequence = app(NumberSequence::class);

        // Dibatalkan supaya test ini tidak meninggalkan jejak pada database dev.
        DB::beginTransaction();

        try {
            $this->assertIsInt($sequence->next('reservation'));
        } finally {
            DB::rollBack();
        }
    }
}
