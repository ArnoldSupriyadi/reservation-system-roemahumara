<?php

namespace App\Exceptions;

use App\Models\Reservation;
use Exception;

class DuplicateReservationException extends Exception
{
    public function __construct(private readonly ?Reservation $existing = null)
    {
        parent::__construct('Reservasi dengan tanggal, nama, dan jam mulai yang sama sudah ada.');
    }

    public function existing(): ?Reservation
    {
        return $this->existing;
    }
}
