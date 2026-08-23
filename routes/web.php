<?php

use App\Http\Controllers\PublicCalendarController;
use Illuminate\Support\Facades\Route;

// `/` tidak lagi mengalihkan ke /cms. Staf masuk lewat /cms langsung.
Route::get('/', PublicCalendarController::class)->name('public.calendar');

/*
 * Dokumen cetak reservasi TIDAK didaftarkan di sini.
 *
 * Ia hidup di dalam panel cms lewat ->authenticatedRoutes() di CmsPanelProvider.
 * Didaftarkan di berkas ini dengan middleware 'auth' biasa, tamu yang membuka
 * URL-nya mendapat 500 alih-alih diarahkan ke login: middleware bawaan Laravel
 * mencari route bernama 'login', sedangkan panel ini memakai
 * filament.cms.auth.login.
 */
