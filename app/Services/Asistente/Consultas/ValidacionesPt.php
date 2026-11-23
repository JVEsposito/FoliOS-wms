<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoValidacionPallet;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\PeriodoOperativo;
use App\Services\Asistente\ResultadoConsulta;

/**
 * Folios validados en Validación PT en el periodo, por resultado. Usa la jornada de la
 * propia validación (con su regla histórica de turno noche), no un corte de calendario.
 */
class ValidacionesPt extends ConsultaBase
{
    /** Hasta este largo se suma jornada por jornada; un periodo más largo usa el rango horario. */
    private const MAX_JORNADAS = 31;

    public function clave(): string
    {
        return 'validaciones-pt';
    }

    public function titulo(): string
    {
        return 'Validaciones PT';
    }

    public function descripcion(): string
    {
        return 'Folios validados en el periodo, aprobados, observados y rechazados.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasClave(): array
    {
        return ['validaron', 'validados', 'validadas', 'validacion', 'validaciones', 'aprobados', 'rechazados', 'observados'];
    }

    public function palabrasSecundarias(): array
    {
        return ['folios', 'folio', 'pallets', 'pallet', 'saldos'];
    }

    public function filtros(): array
    {
        return ['periodo'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarValidacionesPallet($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$primera, $ultima] = $parametros->jornadas();
        [$desde, $hasta] = $parametros->rango();
        $calendario = app(PeriodoOperativo::class);
        $jornadas = [];
        for ($dia = $primera; $dia <= $ultima && count($jornadas) <= self::MAX_JORNADAS; $dia = $calendario->sumarDias($dia, 1)) {
            $jornadas[] = $dia;
        }
        $porJornada = count($jornadas) <= self::MAX_JORNADAS && $parametros->turno === null;

        $validaciones = $temporada === null ? collect() : ValidacionPallet::query()
            ->where('temporada_id', $temporada->id)
            ->where('estado', EstadoValidacionPallet::Aceptada->value)
            ->when($porJornada, fn ($q) => $q->where(function ($o) use ($jornadas): void {
                foreach ($jornadas as $jornada) {
                    $o->orWhere(fn ($j) => $j->deJornada($jornada));
                }
            }), fn ($q) => $q->where('generado_dispositivo_at', '>=', $desde)->where('generado_dispositivo_at', '<', $hasta))
            ->get(['id', 'tipo_bulto', 'resultado', 'cantidad_cajas']);

        if ($validaciones->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay validaciones PT el {$this->periodo($parametros)}.", '/oficina/validacion');
        }

        $etiquetas = ['aprobado' => 'Aprobados', 'observado' => 'Observados', 'rechazado' => 'Rechazados'];
        $filas = $validaciones
            ->groupBy(fn (ValidacionPallet $v): string => $v->resultado?->value ?? 'sin_resultado')
            ->map(fn ($grupo, string $resultado): array => [
                'resultado' => $etiquetas[$resultado] ?? 'Sin resultado',
                'folios' => $grupo->count(),
                'pallets' => $grupo->filter(fn (ValidacionPallet $v): bool => ($v->tipo_bulto?->value ?? $v->tipo_bulto) === 'pallet')->count(),
                'cajas' => (int) $grupo->sum('cantidad_cajas'),
            ])->sortByDesc('folios')->values();
        $aprobados = (int) ($filas->firstWhere('resultado', 'Aprobados')['folios'] ?? 0);

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se validaron %d folios con %s cajas; %d quedaron aprobados.', $this->periodo($parametros), $validaciones->count(), number_format((int) $filas->sum('cajas'), 0, ',', '.'), $aprobados),
            [
                ResultadoConsulta::cifra('Folios validados', $validaciones->count()),
                ResultadoConsulta::cifra('Aprobados', $aprobados),
                ResultadoConsulta::cifra('Cajas', (int) $filas->sum('cajas')),
            ],
            [
                ['clave' => 'resultado', 'titulo' => 'Resultado'],
                ['clave' => 'folios', 'titulo' => 'Folios', 'tipo' => 'numero'],
                ['clave' => 'pallets', 'titulo' => 'Pallets', 'tipo' => 'numero'],
                ['clave' => 'cajas', 'titulo' => 'Cajas', 'tipo' => 'numero'],
            ],
            $filas->all(),
            '/oficina/validacion',
        );
    }
}
