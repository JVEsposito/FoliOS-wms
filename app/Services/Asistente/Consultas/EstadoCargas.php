<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoCarga;
use App\Models\Carga;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Illuminate\Support\Facades\DB;

/** Cargas que siguen abiertas ahora, con su estado y los folios asignados. */
class EstadoCargas extends ConsultaBase
{
    public function clave(): string
    {
        return 'estado-cargas';
    }

    public function titulo(): string
    {
        return 'Cargas abiertas';
    }

    public function descripcion(): string
    {
        return 'Cargas que todavía no se cierran, con su estado y folios asignados.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasClave(): array
    {
        return ['abiertas', 'abierta', 'activas', 'activa', 'estado', 'estados', 'preparacion', 'separacion', 'pendientes'];
    }

    public function palabrasSecundarias(): array
    {
        return ['carga', 'cargas', 'embarque', 'embarques'];
    }

    public function filtros(): array
    {
        return ['cliente'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarCargas($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        $exportadoras = $this->exportadoras($parametros);
        $terminales = array_map(fn (EstadoCarga $estado) => $estado->value, EstadoCarga::terminales());
        $cargas = $temporada === null ? collect() : Carga::query()
            ->where('temporada_id', $temporada->id)
            ->whereNotIn('estado', $terminales)
            ->when($exportadoras !== null, fn ($q) => $q->whereHas('asignacionesHistoricas.folio', fn ($f) => $f->whereIn(DB::raw('UPPER(TRIM(exportadora))'), $exportadoras)))
            ->withCount('asignacionesActuales')
            ->orderBy('created_at')
            ->get(['id', 'codigo', 'estado', 'publicada_at']);

        if ($cargas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), 'No hay cargas abiertas.', '/oficina/frigorifico/despacho/cargas');
        }

        $porEstado = $cargas->countBy(fn (Carga $c): string => $this->estado($c->estado));

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('Hay %d cargas abiertas: %s.', $cargas->count(), $porEstado->map(fn (int $n, string $e): string => "{$n} {$e}")->implode(', ')),
            [ResultadoConsulta::cifra('Cargas abiertas', $cargas->count()), ResultadoConsulta::cifra('Folios asignados', (int) $cargas->sum('asignaciones_actuales_count'))],
            [
                ['clave' => 'codigo', 'titulo' => 'Carga', 'tipo' => 'codigo'],
                ['clave' => 'estado', 'titulo' => 'Estado'],
                ['clave' => 'folios', 'titulo' => 'Folios', 'tipo' => 'numero'],
                ['clave' => 'publicada', 'titulo' => 'Publicada'],
            ],
            $cargas->map(fn (Carga $c): array => [
                'codigo' => $c->codigo,
                'estado' => $this->estado($c->estado),
                'folios' => (int) $c->asignaciones_actuales_count,
                'publicada' => $this->hora($c->publicada_at),
            ])->all(),
            '/oficina/frigorifico/despacho/cargas',
        );
    }

    private function estado(mixed $estado): string
    {
        $valor = $estado instanceof EstadoCarga ? $estado->value : (string) $estado;

        return str_replace('_', ' ', $valor);
    }
}
