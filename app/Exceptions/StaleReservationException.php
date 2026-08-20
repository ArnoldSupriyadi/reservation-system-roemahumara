<?php

namespace App\Exceptions;

use Exception;

class StaleReservationException extends Exception
{
    public function __construct()
    {
        parent::__construct('Reservasi ini baru saja diubah orang lain.');
    }
}
