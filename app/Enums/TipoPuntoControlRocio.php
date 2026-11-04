<?php

namespace App\Enums;

/** Dónde se tomó la medición: sala configurada, túnel de prefrío o cámara. */
enum TipoPuntoControlRocio: string
{
    case Sala = 'sala';
    case Tunel = 'tunel';
    case Camara = 'camara';
}
