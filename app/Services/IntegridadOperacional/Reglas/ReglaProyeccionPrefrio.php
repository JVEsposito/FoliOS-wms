<?php

namespace App\Services\IntegridadOperacional\Reglas;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\SeveridadHallazgoIntegridadOperacional;
use App\Enums\TipoBulto;
use App\Services\IntegridadOperacional\HallazgoIntegridadDetectado;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReglaProyeccionPrefrio implements ReglaIntegridadOperacional
{
    public const CODIGO = 'prefrio_aprobado_no_proyectado';

    public function codigo(): string
    {
        return self::CODIGO;
    }

    public function nombre(): string
    {
        return 'Prefrío aprobado no proyectado en el folio';
    }

    public function modulo(): string
    {
        return 'prefrio';
    }

    public function evaluar(): iterable
    {
        return DB::table('procesos_prefrio_folios as asignacion')
            ->join('procesos_prefrio as proceso', 'proceso.id', '=', 'asignacion.proceso_prefrio_id')
            ->join('folios as folio', 'folio.id', '=', 'asignacion.folio_id')
            ->where('asignacion.estado', EstadoFolioProcesoPrefrio::Aprobado->value)
            ->where('folio.activo', true)
            ->whereIn('folio.tipo_bulto', [TipoBulto::Pallet->value, TipoBulto::Saldo->value])
            ->whereNotExists(function (Builder $posterior): void {
                $posterior->selectRaw('1')
                    ->from('procesos_prefrio_folios as asignacion_posterior')
                    ->whereColumn('asignacion_posterior.folio_id', 'asignacion.folio_id')
                    ->where(function (Builder $orden): void {
                        $orden->whereColumn('asignacion_posterior.created_at', '>', 'asignacion.created_at')
                            ->orWhere(function (Builder $desempate): void {
                                $desempate
                                    ->whereColumn('asignacion_posterior.created_at', '=', 'asignacion.created_at')
                                    ->whereColumn('asignacion_posterior.id', '>', 'asignacion.id');
                            });
                    });
            })
            ->where(function (Builder $inconsistencia): void {
                $inconsistencia
                    ->whereNull('folio.condicion_termica')
                    ->orWhere(
                        'folio.condicion_termica',
                        '!=',
                        CondicionTermicaFolio::PrefrioAprobado->value,
                    )
                    ->orWhereNull('folio.habilitacion_almacenamiento')
                    ->orWhere(
                        'folio.habilitacion_almacenamiento',
                        '!=',
                        HabilitacionAlmacenamientoFolio::Habilitado->value,
                    );
            })
            ->orderBy('folio.numero_folio')
            ->get([
                'folio.id as folio_id',
                'folio.numero_folio',
                'folio.estado_operacional',
                'folio.condicion_termica',
                'folio.habilitacion_almacenamiento',
                'proceso.id as proceso_id',
                'proceso.codigo as proceso_codigo',
                DB::raw('coalesce(asignacion.finalizado_parcial_at, proceso.finalizado_at) as finalizado_at'),
                'asignacion.id as asignacion_id',
            ])
            ->map(fn (object $fila): HallazgoIntegridadDetectado => new HallazgoIntegridadDetectado(
                reglaCodigo: self::CODIGO,
                severidad: SeveridadHallazgoIntegridadOperacional::Critico,
                modulo: $this->modulo(),
                entidadTipo: 'folio',
                entidadId: $fila->folio_id,
                referencia: $fila->numero_folio,
                titulo: 'Prefrío aprobado sin reflejo en el folio',
                detalle: sprintf(
                    'El folio %s fue aprobado en %s, pero conserva estado %s, condición %s y habilitación %s.',
                    $fila->numero_folio,
                    $fila->proceso_codigo,
                    $fila->estado_operacional ?? 'sin estado',
                    $fila->condicion_termica ?? 'sin condición',
                    $fila->habilitacion_almacenamiento ?? 'sin habilitación',
                ),
                contexto: [
                    'proceso_id' => $fila->proceso_id,
                    'proceso_codigo' => $fila->proceso_codigo,
                    'asignacion_id' => $fila->asignacion_id,
                    'finalizado_at' => $fila->finalizado_at,
                    'estado_operacional' => $fila->estado_operacional,
                    'condicion_termica' => $fila->condicion_termica,
                    'habilitacion_almacenamiento' => $fila->habilitacion_almacenamiento,
                ],
            ));
    }
}
