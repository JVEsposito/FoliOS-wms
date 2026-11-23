<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Guías de despacho de envases que siguen en borrador: armadas pero sin confirmar la salida. */
class DespachosEnvasesPendientes extends ConsultaBase
{
    public function clave(): string
    {
        return 'despachos-envases-pendientes';
    }

    public function titulo(): string
    {
        return 'Despachos de envases sin confirmar';
    }

    public function descripcion(): string
    {
        return 'Guías de despacho de envases en borrador, con su cliente y patente.';
    }

    public function dominio(): string
    {
        return 'envases';
    }

    public function palabrasClave(): array
    {
        return ['borrador', 'borradores', 'gde', 'confirmar', 'confirmadas', 'pendientes', 'pendiente'];
    }

    public function palabrasSecundarias(): array
    {
        return ['despacho', 'despachos', 'envases', 'envase', 'guia', 'guias'];
    }

    public function filtros(): array
    {
        return ['cliente'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarCuentaEnvases($usuario) || $this->alcance->puedeGestionarDespachoEnvases($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $guias = GuiaDespachoEnvase::query()
            ->where('estado', EstadoGuiaDespachoEnvase::Borrador->value)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('cliente_id', $id))
            ->orderBy('created_at')
            ->get(['numero', 'numero_guia_despacho', 'cliente_nombre_snapshot', 'patente_camion', 'created_at']);

        if ($guias->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), 'No hay despachos de envases en borrador.', '/oficina/envases/despachos');
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('Hay %d despachos de envases en borrador, sin salida confirmada.', $guias->count()),
            [ResultadoConsulta::cifra('En borrador', $guias->count())],
            [
                ['clave' => 'numero', 'titulo' => 'Despacho', 'tipo' => 'codigo'],
                ['clave' => 'guia', 'titulo' => 'Guía'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'patente', 'titulo' => 'Patente', 'tipo' => 'codigo'],
                ['clave' => 'creado', 'titulo' => 'Creado'],
            ],
            $guias->map(fn (GuiaDespachoEnvase $g): array => [
                'numero' => $g->numero,
                'guia' => $g->numero_guia_despacho,
                'cliente' => $g->cliente_nombre_snapshot,
                'patente' => $g->patente_camion,
                'creado' => $this->hora($g->created_at),
            ])->all(),
            '/oficina/envases/despachos',
        );
    }
}
