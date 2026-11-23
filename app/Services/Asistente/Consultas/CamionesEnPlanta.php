<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Camiones con una recepción de Romana abierta ahora: en báscula de ingreso, de salida o pesando envases. */
class CamionesEnPlanta extends ConsultaBase
{
    private const ESTADOS = [
        EstadoRecepcionRomana::EnBasculaIngreso,
        EstadoRecepcionRomana::EnPesajeEnvases,
        EstadoRecepcionRomana::EnBasculaSalida,
    ];

    public function clave(): string
    {
        return 'camiones-en-planta';
    }

    public function titulo(): string
    {
        return 'Camiones en planta';
    }

    public function descripcion(): string
    {
        return 'Camiones con su recepción abierta en Romana, con su etapa y el tiempo que llevan.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function palabrasClave(): array
    {
        return ['patio', 'planta', 'adentro', 'dentro', 'abiertas', 'abierta', 'bascula'];
    }

    public function palabrasSecundarias(): array
    {
        return ['camion', 'camiones', 'recepcion', 'recepciones'];
    }

    public function filtros(): array
    {
        return ['cliente'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRomana($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        $abiertas = $temporada === null ? collect() : RecepcionRomana::query()
            ->where('temporada_id', $temporada->id)
            ->whereNull('eliminada_at')
            ->whereIn('estado', array_map(fn ($estado) => $estado->value, self::ESTADOS))
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('cliente_id', $id))
            ->orderBy('ingreso_at')
            ->get(['numero_recepcion', 'patente_camion', 'cliente_nombre_snapshot', 'estado', 'ingreso_at']);

        if ($abiertas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), 'No hay camiones con recepción abierta en Romana.', '/oficina/romana');
        }

        $ahora = now();
        $etapas = [
            EstadoRecepcionRomana::EnBasculaIngreso->value => 'Báscula de ingreso',
            EstadoRecepcionRomana::EnPesajeEnvases->value => 'Pesaje de envases',
            EstadoRecepcionRomana::EnBasculaSalida->value => 'Esperando destare',
        ];
        $filas = $abiertas->map(fn (RecepcionRomana $r): array => [
            'recepcion' => $r->numero_recepcion,
            'patente' => $r->patente_camion,
            'cliente' => $r->cliente_nombre_snapshot,
            'etapa' => $etapas[$r->estado->value] ?? $r->estado->value,
            'minutos' => $r->ingreso_at ? self::minutos($r->ingreso_at->diffInMinutes($ahora, true)) : null,
        ]);
        $destare = $filas->where('etapa', 'Esperando destare')->count();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('Ahora hay %d camiones con recepción abierta; %d esperan destare.', $filas->count(), $destare),
            [
                ResultadoConsulta::cifra('Camiones en planta', $filas->count()),
                ResultadoConsulta::cifra('Esperando destare', $destare),
                ResultadoConsulta::cifra('Más tiempo en planta', (int) $filas->max('minutos'), 'min'),
            ],
            [
                ['clave' => 'recepcion', 'titulo' => 'Recepción', 'tipo' => 'codigo'],
                ['clave' => 'patente', 'titulo' => 'Patente', 'tipo' => 'codigo'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'etapa', 'titulo' => 'Etapa'],
                ['clave' => 'minutos', 'titulo' => 'Minutos', 'tipo' => 'numero'],
            ],
            $filas->all(),
            '/oficina/romana',
        );
    }
}
