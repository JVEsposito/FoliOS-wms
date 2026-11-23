<?php

namespace App\Services\Asistente\Haiku;

use RuntimeException;

/** Haiku no respondió o respondió algo inutilizable. El asistente sigue sin IA. */
class FallaHaiku extends RuntimeException
{
    public const SIN_RED = 'sin_red';

    public const API = 'api';

    public const RESPUESTA = 'respuesta';

    public function __construct(public readonly string $motivo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
