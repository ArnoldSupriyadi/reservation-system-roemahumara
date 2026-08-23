<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;

/**
 * Dokumen cetak satu reservasi.
 *
 * Ditampilkan inline, bukan dipaksa unduh. Peramban membuka penampil PDF-nya
 * sendiri yang sudah menyediakan tombol cetak dan simpan sekaligus — memaksa
 * unduh justru menutup jalan mencetak tanpa menyimpan berkas lebih dulu.
 */
class ReservationPdfController extends Controller
{
    // Controller bawaan Laravel 12 tidak lagi membawa trait ini. Tanpa
    // menambahkannya sendiri, authorize() di bawah melempar "Call to undefined
    // method" — bukan menolak akses, melainkan galat 500 yang justru terlihat
    // seperti masalah lain.
    use AuthorizesRequests;

    public function __invoke(Reservation $reservation): Response
    {
        // Kunci yang sama dengan halaman detail. Tanpa ini, siapa pun yang
        // sudah masuk bisa mengambil reservasi mana pun lewat menebak id di URL
        // — termasuk nomor HP dan catatan pembayaran yang ada di dalamnya.
        $this->authorize('view', $reservation);

        $reservation->load(['pic:id,name', 'area:id,name', 'eventType:id,name', 'menus.category:id,name']);

        $pdf = Pdf::loadView('pdf.reservation', [
            'reservation' => $reservation,
            'dicetakOleh' => auth()->user()?->name,
        ])->setPaper('a4');

        return $pdf->stream($reservation->reservation_number.'.pdf');
    }
}
