<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

class NumberSequence
{
    /**
     * Ambil nomor berikutnya dan naikkan penghitungnya.
     *
     * Wajib dipanggil di dalam transaksi. Kenaikan penghitung ikut mundur bila
     * transaksinya dibatalkan — inilah yang membuat nomor tidak pernah bolong,
     * dan yang membedakannya dari AUTO_INCREMENT.
     */
    public function next(string $name): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                "NumberSequence::next('{$name}') harus dipanggil di dalam transaksi. "
                .'Di luar transaksi, FOR UPDATE tidak menahan apa pun, sehingga dua '
                .'permintaan bersamaan bisa menerima nomor yang sama.'
            );
        }

        $current = DB::table('counters')
            ->where('name', $name)
            ->lockForUpdate()
            ->value('value');

        if ($current === null) {
            throw new LogicException("Penghitung '{$name}' tidak ada di tabel counters.");
        }

        $next = ((int) $current) + 1;

        DB::table('counters')
            ->where('name', $name)
            ->update([
                'value' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }
}
