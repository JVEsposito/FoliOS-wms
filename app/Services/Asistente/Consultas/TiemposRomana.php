<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;

/**
 * Tiempo en planta de cada camión (ingreso a salida) y espera de destare de los que
 * siguen en báscula de salida.
 */
class TiemposRomana extends ConsultaBase
{
    public function clave(): string
    {
        return 'tiempos-romana';
    }

    public function titulo(): string
    {
        return 'Tiempos de Romana';
    }

    public function descripcion(): string
    {
        return 'Tiempo en planta por camión, espera de destare y los más lentos.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function palabrasClave(): array
    {
        return ['tiempo', 'tiempos', 'demora', 'demoro', 'planta', 'destare', 'espera', 'esperando', 'romana', 'camion', 'camiones', 'minutos', 'horas'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRomana($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $recepciones = $temporada === null ? collect() : RecepcionRomana::query()
            ->where('temporada_id', $temporada->id)
            ->whereNull('eliminada_at')
            ->where('estado', '!=', EstadoRecepcionRomana::Anulada->value)
            ->where('ingreso_at', '>=', $desde)->where('ingreso_at', '<', $hasta)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('cliente_id', $id))
            ->get(['id', 'numero_recepcion', 'cliente_nombre_snapshot', 'patente_camion', 'estado', 'ingreso_at', 'ingreso_confirmado_at', 'salida_at']);

        if ($recepciones->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay recepciones de Romana el {$this->periodo($parametros)}.", '/oficina/romana');
        }

        $ahora = CarbonImmutable::now();
        $filas = $recepciones->map(function (RecepcionRomana $recepcion) use ($ahora): ?array {
            $esperando = $recepcion->estado === EstadoRecepcionRomana::EnBasculaSalida;
            $fin = $recepcion->salida_at ?? ($esperando ? $ahora : null);
            if ($fin === null) {
                return null;
            }
            $inicio = $esperando ? ($recepcion->ingreso_confirmado_at ?? $recepcion->ingreso_at) : $recepcion->ingreso_at;

            return [
                'codigo' => $recepcion->numero_recepcion,
                'cliente' => $recepcion->cliente_nombre_snapshot,
                'patente' => $recepcion->patente_camion,
                'minutos' => self::minutos($inicio->diffInMinutes($fin, true)),
                'estado' => $esperando ? 'Esperando destare' : 'Cerrada',
                'esperando' => $esperando,
            ];
        })->filter()->values();

        $cerradas = $filas->where('esperando', false);
        $esperandoDestare = $filas->where('esperando', true);
        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "Las recepciones del {$this->periodo($parametros)} todavía no tienen salida registrada.", '/oficina/romana');
        }

        $cifras = [ResultadoConsulta::cifra('Recepciones', $recepciones->count())];
        if ($cerradas->isNotEmpty()) {
            $cifras[] = ResultadoConsulta::cifra('Tiempo en planta promedio', self::minutos($cerradas->avg('minutos')), 'min');
            $cifras[] = ResultadoConsulta::cifra('Tiempo en planta máximo', (int) $cerradas->max('minutos'), 'min');
        }
        $cifras[] = ResultadoConsulta::cifra('Esperando destare', $esperandoDestare->count());

        $resumen = $cerradas->isNotEmpty()
            ? sprintf('El %s, %d camiones cerraron con un tiempo en planta promedio de %d min.', $this->periodo($parametros), $cerradas->count(), self::minutos($cerradas->avg('minutos')))
            : sprintf('El %s no hay camiones cerrados todavía.', $this->periodo($parametros));
        if ($esperandoDestare->isNotEmpty()) {
            $resumen .= sprintf(' %d esperan destare.', $esperandoDestare->count());
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            $resumen,
            $cifras,
            [
                ['clave' => 'codigo', 'titulo' => 'Recepción', 'tipo' => 'codigo'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'patente', 'titulo' => 'Patente', 'tipo' => 'patente'],
                ['clave' => 'minutos', 'titulo' => 'Minutos', 'tipo' => 'numero'],
                ['clave' => 'estado', 'titulo' => 'Estado', 'tipo' => 'estado'],
            ],
            $filas->sortByDesc('minutos')->take(10)->map(fn (array $fila): array => array_diff_key($fila, ['esperando' => true]))->values()->all(),
            '/oficina/romana',
        );
    }
}
