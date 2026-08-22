<?php

use App\Http\Controllers\PublicCalendarController;
use Illuminate\Support\Facades\Route;

// `/` tidak lagi mengalihkan ke /cms. Staf masuk lewat /cms langsung.
Route::get('/', PublicCalendarController::class)->name('public.calendar');
