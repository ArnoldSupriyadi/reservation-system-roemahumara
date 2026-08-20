<?php

return [

    /*
     * Durasi yang diasumsikan untuk reservasi yang tidak punya end_time,
     * dipakai HANYA untuk mendeteksi tumpang tindih area.
     * Nilai ini tidak pernah disimpan ke database.
     */
    'default_duration_minutes' => (int) env('RESERVATION_DEFAULT_DURATION', 120),

];
