<?php

namespace App\Finanzas\Captura;

use RuntimeException;

/** Otra lectura de Finanzas está corriendo; la siguiente la retomará donde quede. */
final class CapturaEnCurso extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ya hay una captura de hechos en curso. Espere a que termine.');
    }
}
