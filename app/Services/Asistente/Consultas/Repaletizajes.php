<?php

namespace App\Services\Asistente\Consultas;

use App\Models\Repaletizaje;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Illuminate\Support\Facades\DB;

/** Repaletizajes confirmados en el periodo, con su folio resultante. */
class Repaletizajes extends ConsultaBase
{
    public function clave(): string
    {
        return 'repaletizajes';
    }

    public function titulo(): string
    {
        return 'Repaletizajes';
    }

    public function descripcion(): string
    {
        return 'Repas confirmados en el periodo, con su folio resultante y cajas.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasClave(): array
    {
        return ['repa', 'repas', 'repaletizaje', 'repaletizajes', 'repaletizado', 'rearmado'];
    }

    public function filtros(): array
    {
        return ['periodo', 'cliente'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRepaletizajes($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        // fecha_operacional es la jornada; un turno se acota además por la hora de confirmación.
        [$primera, $ultima] = $parametros->jornadas();
        [$desde, $hasta] = $parametros->rango();
        $exportadoras = $this->exportadoras($parametros);
        $repas = Repaletizaje::query()
            ->whereNotNull('confirmado_at')->whereNull('anulado_at')
            ->whereBetween('fecha_operacional', [$primera, $ultima])
            ->when($parametros->turno, fn ($q) => $q->where('confirmado_at', '>=', $desde)->where('confirmado_at', '<', $hasta))
            ->when($exportadoras !== null, fn ($q) => $q->whereHas('folioResultante', fn ($f) => $f->whereIn(DB::raw('UPPER(TRIM(exportadora))'), $exportadoras)))
            ->with('folioResultante:id,numero_folio')
            ->orderByDesc('confirmado_at')
            ->get(['id', 'folio_resultante_id', 'cantidad_resultante', 'fecha_operacional', 'confirmado_at']);

        if ($repas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay repaletizajes confirmados el {$this->periodo($parametros)}.", '/oficina/validacion/repaletizajes');
        }

        $cajas = (int) $repas->sum('cantidad_resultante');

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se confirmaron %d repaletizajes con %s cajas resultantes.', $this->periodo($parametros), $repas->count(), number_format($cajas, 0, ',', '.')),
            [ResultadoConsulta::cifra('Repaletizajes', $repas->count()), ResultadoConsulta::cifra('Cajas resultantes', $cajas)],
            [
                ['clave' => 'folio', 'titulo' => 'Folio resultante', 'tipo' => 'folio'],
                ['clave' => 'cajas', 'titulo' => 'Cajas', 'tipo' => 'numero'],
                ['clave' => 'confirmado', 'titulo' => 'Confirmado'],
            ],
            $repas->map(fn (Repaletizaje $repa): array => [
                'folio' => $repa->folioResultante?->numero_folio ?? '—',
                'cajas' => (int) $repa->cantidad_resultante,
                'confirmado' => $this->hora($repa->confirmado_at),
            ])->all(),
            '/oficina/validacion/repaletizajes',
        );
    }
}
