<?php

namespace Tests\Feature;

use App\Services\NumberSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class NumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    private NumberSequence $sequence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sequence = app(NumberSequence::class);
    }

    private function next(): int
    {
        return DB::transaction(fn () => $this->sequence->next('reservation'));
    }

    public function test_the_first_number_is_one(): void
    {
        $this->assertSame(1, $this->next());
    }

    public function test_numbers_run_in_order(): void
    {
        $this->assertSame(1, $this->next());
        $this->assertSame(2, $this->next());
        $this->assertSame(3, $this->next());
    }

    /**
     * Inilah alasan seluruh tabel penghitung ini ada. AUTO_INCREMENT tidak ikut
     * mundur saat transaksi dibatalkan, sehingga nomor yang diturunkan darinya
     * akan bolong setiap kali sebuah penyimpanan ditolak.
     */
    public function test_a_rolled_back_transaction_does_not_consume_a_number(): void
    {
        $this->assertSame(1, $this->next());

        try {
            DB::transaction(function () {
                $this->sequence->next('reservation');

                throw new RuntimeException('batal');
            });
        } catch (RuntimeException) {
            // diharapkan
        }

        $this->assertSame(2, $this->next(), 'Nomor 2 tidak boleh terbuang.');
    }

    public function test_an_unknown_counter_is_refused(): void
    {
        $this->expectException(LogicException::class);

        DB::transaction(fn () => $this->sequence->next('tidak-ada'));
    }

    public function test_the_stored_value_matches_the_number_handed_out(): void
    {
        $this->next();
        $this->next();

        $this->assertSame(2, (int) DB::table('counters')->where('name', 'reservation')->value('value'));
    }
}
