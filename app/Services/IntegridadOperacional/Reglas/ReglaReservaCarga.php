<?php

namespace App\Services\IntegridadOperacional\Reglas;

use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\SeveridadHallazgoIntegridadOperacional;
use App\Services\IntegridadOperacional\HallazgoIntegridadDetectado;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReglaReservaCarga implements ReglaIntegridadOperacional
{
    public const CODIGO = 'reserva_carga_inconsistente';

    public function codigo(): string
    {
        return self::CODIGO;
    }

    public function nombre(): string
    {
        return 'Reservas vigentes de cargas y folios operables';
    }

    public function modulo(): string
    {
        return 'cargas';
    }

    public function evaluar(): iterable
    {
        $estadosFolioTerminales = [
            EstadoOperacionalFolio::Anulado->value,
            EstadoOperacionalFolio::RetiradoDefinitivo->value,
            EstadoOperacionalFolio::Despachado->value,
            EstadoOperacionalFolio::Agotado->value,
        ];
        $estadosAsignacionSinReserva = [
            EstadoCargaFolio::Descartado->value,
            EstadoCargaFolio::Reemplazado->value,
        ];
        $estadosCargaTerminales = array_map(
            fn (EstadoCarga $estado): string => $estado->value,
            EstadoCarga::terminales(),
        );

        return DB::table('reservas_carga_folio as reserva')
            ->join('carga_folios as asignacion', 'asignacion.id', '=', 'reserva.carga_folio_id')
            ->join('cargas as carga', 'carga.id', '=', 'asignacion.carga_id')
            ->join('folios as folio', 'folio.id', '=', 'reserva.folio_id')
            ->where(function (Builder $consulta) use (
                $estadosFolioTerminales,
                $estadosAsignacionSinReserva,
                $estadosCargaTerminales,
            ): void {
                $consulta
                    ->where('folio.activo', false)
                    ->orWhereIn('folio.estado_operacional', $estadosFolioTerminales)
                    ->orWhereIn('asignacion.estado', $estadosAsignacionSinReserva)
                    ->orWhereIn('carga.estado', $estadosCargaTerminales);
            })
            ->orderBy('carga.codigo')
            ->orderBy('folio.numero_folio')
            ->get([
                'reserva.folio_id',
                'reserva.carga_folio_id',
                'folio.numero_folio',
                'folio.activo',
                'folio.estado_operacional as folio_estado',
                'asignacion.estado as asignacion_estado',
                'carga.id as carga_id',
                'carga.codigo as carga_codigo',
                'carga.estado as carga_estado',
            ])
            ->map(fn (object $fila): HallazgoIntegridadDetectado => new HallazgoIntegridadDetectado(
                reglaCodigo: self::CODIGO,
                severidad: SeveridadHallazgoIntegridadOperacional::Critico,
                modulo: $this->modulo(),
                entidadTipo: 'reserva_carga',
                entidadId: $fila->carga_folio_id,
                referencia: $fila->numero_folio,
                titulo: 'Reserva de carga incompatible con su estado actual',
                detalle: sprintf(
                    'El folio %s conserva una reserva en %s aunque el folio está %s, la asignación %s y la carga %s.',
                    $fila->numero_folio,
                    $fila->carga_codigo,
                    $fila->folio_estado,
                    $fila->asignacion_estado,
                    $fila->carga_estado,
                ),
                contexto: [
                    'folio_id' => $fila->folio_id,
                    'folio_activo' => (bool) $fila->activo,
                    'folio_estado' => $fila->folio_estado,
                    'carga_id' => $fila->carga_id,
                    'carga_codigo' => $fila->carga_codigo,
                    'carga_estado' => $fila->carga_estado,
                    'asignacion_estado' => $fila->asignacion_estado,
                ],
            ));
    }
}
