<?php

namespace Tests\Feature\Asistente;

use App\Models\User;
use App\Services\Asistente\Consultas\ConsultaPorTema;
use App\Services\Asistente\Consultas\VistaConsulta;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/**
 * Tema de prueba con dos vistas y sin vista por defecto: «Bodegas de prueba» con las
 * vistas «ocupacion» (por periodo) y «clientes» (por cliente). Responde con el nombre
 * de la vista para que la prueba vea cuál se ejecutó.
 */
class TemaDePrueba extends ConsultaPorTema
{
    public function clave(): string
    {
        return 'tema-de-prueba';
    }

    public function titulo(): string
    {
        return 'Bodegas de prueba';
    }

    public function descripcion(): string
    {
        return 'Tema de prueba con dos vistas.';
    }

    public function dominio(): string
    {
        return 'consultas';
    }

    public function vistaPorDefecto(): ?string
    {
        return null;
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return true;
    }

    protected function palabrasDelTema(): array
    {
        return ['bodegon', 'bodegones'];
    }

    protected function vistasDelTema(): array
    {
        return [
            new VistaConsulta('ocupacion', 'Ocupación', 'Ocupación de las bodegas.', ['ocupacion', 'ocupadas'], ['periodo']),
            new VistaConsulta('clientes', 'Por cliente', 'Bodegas por cliente.', ['cliente', 'clientes', 'por cliente'], ['cliente']),
        ];
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        return ResultadoConsulta::sinDatos($this->titulo(), "Vista {$vista->clave}.");
    }
}
