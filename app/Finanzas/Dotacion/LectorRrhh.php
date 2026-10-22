<?php

namespace App\Finanzas\Dotacion;

use App\Finanzas\Periodo;
use App\Rrhh\Asistencia\MedidasDotacion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Finanzas solo lee RRHH; no consulta fichas, documentos, PIN ni credenciales. */
final class LectorRrhh
{
    public function __construct(private MedidasDotacion $medidas) {}

    private function asignaciones(): Builder
    {
        return DB::connection(config('finanzas.conexion_rrhh'))->table('rh_asignaciones')->select([
            'id', 'cliente_id', 'cliente_nombre', 'cargo', 'area', 'ceco', 'desde_at', 'hasta_at',
            'descanso_programado_minutos', 'estado', 'version', 'fuente_marcaje', 'persona_id',
        ]);
    }

    /** Proyección comercial sin persona, documentos, marcajes individuales ni costo interno. */
    public function hechosProgramacion(): Builder
    {
        return DB::connection(config('finanzas.conexion_rrhh'))->table('rh_asignaciones', 'a')
            ->select(['a.id', 'a.created_at', 'a.desde_at as ocurrido_at', 'a.cliente_id', 'a.cliente_nombre', 'a.cargo', 'a.area', 'a.ceco', 'a.desde_at', 'a.hasta_at', 'a.descanso_programado_minutos', 'a.estado']);
    }

    public function mes(Periodo $periodo): LengthAwarePaginator
    {
        return DB::connection(config('finanzas.conexion_rrhh'))->transaction(fn () => $this->asignaciones()->where('desde_at', '<', $periodo->hastaUtc())->where('hasta_at', '>', $periodo->desdeUtc())
            ->orderBy('desde_at')->orderBy('id')->paginate(25)->through(fn ($a) => $this->detalle((array) $a)));
    }

    public function buscar(string $id): array
    {
        return DB::connection(config('finanzas.conexion_rrhh'))->transaction(fn () => $this->detalle((array) $this->asignaciones()->where('id', $id)->firstOrFail()));
    }

    private function detalle(array $a): array
    {
        $marcas = DB::connection(config('finanzas.conexion_rrhh'))->table('rh_marcajes')
            ->where('persona_id', $a['persona_id'])->where('fuente_codigo', $a['fuente_marcaje'])
            ->whereBetween('instante_at', [CarbonImmutable::parse($a['desde_at'], 'UTC')->subDay(), CarbonImmutable::parse($a['hasta_at'], 'UTC')->addDay()])
            ->orderBy('instante_at')->orderBy('id')->get(['id', 'tipo', 'instante_at', 'anulado_at', 'corrige_id'])->map(fn ($m) => (array) $m)->all();
        unset($a['persona_id']);
        $medidas = $this->medidas->calcular($a, $marcas);
        $programacion = hash('sha256', json_encode($a, JSON_THROW_ON_ERROR));
        $asistencia = hash('sha256', json_encode([$a, $marcas], JSON_THROW_ON_ERROR));

        return [...$a, ...$medidas, 'turno_finalizado' => CarbonImmutable::parse($a['hasta_at'], 'UTC')->lessThanOrEqualTo(CarbonImmutable::now('UTC')),
            'huella_programacion' => $programacion, 'huella_asistencia' => $asistencia];
    }
}
