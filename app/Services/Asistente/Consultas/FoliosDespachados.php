<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoCargaFolio;
use App\Models\CargaFolio;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Pallets y saldos que salieron de planta en el periodo, por carga, según la salida confirmada. */
class FoliosDespachados extends ConsultaBase
{
    public function clave(): string
    {
        return 'folios-despachados';
    }

    public function titulo(): string
    {
        return 'Folios despachados';
    }

    public function descripcion(): string
    {
        return 'Pallets y saldos que salieron de planta en el periodo, por carga.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasClave(): array
    {
        return ['despachados', 'despachado', 'despachamos', 'despacharon', 'salieron', 'salio', 'embarcados', 'embarcamos'];
    }

    public function palabrasSecundarias(): array
    {
        return ['pallets', 'pallet', 'folios', 'folio', 'saldos'];
    }

    public function filtros(): array
    {
        return ['periodo', 'cliente'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarCargas($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $exportadoras = $this->exportadoras($parametros);
        // La salida queda en finalizado_at; el despacho directo desde cámara la deja en asignado_at.
        $salida = DB::raw('COALESCE(carga_folios.finalizado_at, carga_folios.asignado_at)');
        $filas = $temporada === null ? collect() : CargaFolio::query()
            ->join('cargas', 'cargas.id', '=', 'carga_folios.carga_id')
            ->join('folios', 'folios.id', '=', 'carga_folios.folio_id')
            ->where('cargas.temporada_id', $temporada->id)
            ->where('carga_folios.estado', EstadoCargaFolio::Despachado->value)
            ->where($salida, '>=', $desde)->where($salida, '<', $hasta)
            ->when($exportadoras !== null, fn ($q) => $q->whereIn(DB::raw('UPPER(TRIM(folios.exportadora))'), $exportadoras))
            ->groupBy('cargas.codigo')
            ->selectRaw("cargas.codigo as carga, count(*) as folios, sum(case when folios.tipo_bulto = 'pallet' then 1 else 0 end) as pallets, max(COALESCE(carga_folios.finalizado_at, carga_folios.asignado_at)) as salida")
            ->orderBy('salida')
            ->get();

        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No salieron folios el {$this->periodo($parametros)}.", '/oficina/frigorifico/despacho/cargas');
        }

        $folios = (int) $filas->sum('folios');
        $pallets = (int) $filas->sum('pallets');

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s salieron %d folios (%d pallets y %d saldos) en %d cargas.', $this->periodo($parametros), $folios, $pallets, $folios - $pallets, $filas->count()),
            [
                ResultadoConsulta::cifra('Folios despachados', $folios),
                ResultadoConsulta::cifra('Pallets', $pallets),
                ResultadoConsulta::cifra('Cargas', $filas->count()),
            ],
            [
                ['clave' => 'carga', 'titulo' => 'Carga', 'tipo' => 'codigo'],
                ['clave' => 'folios', 'titulo' => 'Folios', 'tipo' => 'numero'],
                ['clave' => 'pallets', 'titulo' => 'Pallets', 'tipo' => 'numero'],
                ['clave' => 'salida', 'titulo' => 'Última salida'],
            ],
            $filas->map(fn ($fila): array => [
                'carga' => $fila->carga,
                'folios' => (int) $fila->folios,
                'pallets' => (int) $fila->pallets,
                'salida' => $fila->salida ? $this->hora(CarbonImmutable::parse($fila->salida, 'UTC')) : null,
            ])->all(),
            '/oficina/frigorifico/despacho/cargas',
        );
    }
}
