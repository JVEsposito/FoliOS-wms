<?php

namespace App\Enums;

enum EstadoRecepcionRomana: string
{
    case EnBasculaIngreso = 'en_bascula_ingreso';
    case EnBasculaSalida = 'en_bascula_salida';
    case EnPesajeEnvases = 'en_pesaje_envases';
    case Cerrado = 'cerrado';
    case Anulada = 'anulada';

    public function esEditable(): bool
    {
        return in_array($this, [
            self::EnBasculaIngreso,
            self::EnPesajeEnvases,
        ], true);
    }
}
