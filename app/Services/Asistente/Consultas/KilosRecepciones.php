<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\PeriodoOperativo;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kilos brutos, tara y netos de las recepciones cerradas, por cliente.
 */
class KilosRecepciones extends ConsultaBase
{
    public function clave(): string
    {
        return 'kilos-recepciones';
    }

    public function titulo(): string
    {
        return 'Kilos recibidos';
    }

    public function descripcion(): string
    {
        return 'Kilos brutos, tara y netos de las recepciones cerradas, por cliente.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function palabrasClave(): array
    {
        return ['kilos', 'kg', 'kilo', 'neto', 'netos', 'bruto', 'brutos', 'recepcion', 'recepciones', 'recibido', 'recibidos', 'ingreso', 'peso', 'entrada', 'entro', 'entraron', 'llegada', 'llego', 'llegaron'];
    }

    public function palabrasSecundarias(): array
    {
        return ['fruta'];
    }

    public function filtros(): array
    {
        return ['periodo', 'cliente', 'especie', 'por_dia'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRomana($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        if ($parametros->agrupar === 'dia') {
            return $this->porJornada($parametros);
        }
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $filas = $temporada === null ? collect() : $this->recepciones($temporada->id, $desde, $hasta, $parametros)
            ->groupBy('cliente_id', 'cliente_nombre_snapshot')
            ->selectRaw('cliente_nombre_snapshot as cliente, count(*) as recepciones, sum(peso_bruto) as bruto, sum(peso_tara) as tara, sum(peso_neto) as neto')
            ->orderByDesc('neto')
            ->get();

        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay recepciones cerradas el {$this->periodo($parametros)}.", '/oficina/romana');
        }

        $redondeo = fn ($valor): float => round((float) $valor, 1);
        $neto = $redondeo($filas->sum('neto'));

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se recibieron %s kg netos en %d recepciones.', $this->periodo($parametros), number_format($neto, 1, ',', '.'), (int) $filas->sum('recepciones')),
            [
                ResultadoConsulta::cifra('Kilos netos', $neto, 'kg'),
                ResultadoConsulta::cifra('Kilos brutos', $redondeo($filas->sum('bruto')), 'kg'),
                ResultadoConsulta::cifra('Recepciones', (int) $filas->sum('recepciones')),
            ],
            [
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'recepciones', 'titulo' => 'Recepciones', 'tipo' => 'numero'],
                ['clave' => 'bruto', 'titulo' => 'Bruto (kg)', 'tipo' => 'numero'],
                ['clave' => 'tara', 'titulo' => 'Tara (kg)', 'tipo' => 'numero'],
                ['clave' => 'neto', 'titulo' => 'Neto (kg)', 'tipo' => 'numero'],
            ],
            $filas->map(fn ($fila): array => [
                'cliente' => $fila->cliente,
                'recepciones' => (int) $fila->recepciones,
                'bruto' => $redondeo($fila->bruto),
                'tara' => $redondeo($fila->tara),
                'neto' => $redondeo($fila->neto),
            ])->all(),
            '/oficina/romana',
        );
    }

    /** Una fila por jornada operativa (07:00 a 06:59), de la más antigua a la más reciente. */
    private function porJornada(ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $recepciones = $temporada === null ? collect() : $this->recepciones($temporada->id, $desde, $hasta, $parametros)
            ->get(['ingreso_at', 'peso_bruto', 'peso_neto']);

        if ($recepciones->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay recepciones cerradas el {$this->periodo($parametros)}.", '/oficina/romana');
        }

        $periodo = app(PeriodoOperativo::class);
        $filas = $recepciones
            ->groupBy(fn (RecepcionRomana $recepcion): string => $periodo->jornada($recepcion->ingreso_at))
            ->sortKeys()
            ->map(fn ($grupo, string $jornada): array => [
                'jornada' => PeriodoOperativo::fechaLegible($jornada),
                'recepciones' => $grupo->count(),
                'bruto' => round((float) $grupo->sum('peso_bruto'), 1),
                'neto' => round((float) $grupo->sum('peso_neto'), 1),
            ])->values();
        $mayor = $filas->sortByDesc('neto')->first();
        $neto = round((float) $filas->sum('neto'), 1);

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se recibieron %s kg netos en %d jornadas con recepciones. La jornada con más kilos fue el %s, con %s kg.',
                $this->periodo($parametros), number_format($neto, 1, ',', '.'), $filas->count(), $mayor['jornada'], number_format($mayor['neto'], 1, ',', '.')),
            [
                ResultadoConsulta::cifra('Kilos netos', $neto, 'kg'),
                ResultadoConsulta::cifra('Jornadas', $filas->count()),
                ResultadoConsulta::cifra('Promedio por jornada', round($neto / max(1, $filas->count()), 1), 'kg'),
            ],
            [
                ['clave' => 'jornada', 'titulo' => 'Jornada'],
                ['clave' => 'recepciones', 'titulo' => 'Recepciones', 'tipo' => 'numero'],
                ['clave' => 'bruto', 'titulo' => 'Bruto (kg)', 'tipo' => 'numero'],
                ['clave' => 'neto', 'titulo' => 'Neto (kg)', 'tipo' => 'numero'],
            ],
            $filas->all(),
            '/oficina/romana',
        );
    }

    private function recepciones(string $temporadaId, CarbonImmutable $desde, CarbonImmutable $hasta, ParametrosConsulta $parametros): Builder
    {
        return RecepcionRomana::query()
            ->where('temporada_id', $temporadaId)
            ->whereNull('eliminada_at')
            ->where('estado', EstadoRecepcionRomana::Cerrado->value)
            ->where('ingreso_at', '>=', $desde)->where('ingreso_at', '<', $hasta)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('cliente_id', $id))
            ->when($parametros->especieId, fn ($q, $id) => $q->where('especie_validacion_id', $id));
    }
}
