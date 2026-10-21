<?php

namespace App\Finanzas\Dotacion;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Models\VinculoDotacion;
use App\Finanzas\Tarifario\GestorCargos;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CargosDotacion
{
    public const MEDIDAS = ['horas_programadas', 'horas_asistidas', 'jornadas_programadas', 'jornadas_asistidas'];

    public function __construct(private EsquemaFinanzas $esquema, private LectorRrhh $lector, private GestorCargos $cargos) {}

    public function crear(array $datos, User $actor): CargoAcordado
    {
        $candado = 'dotacion-'.substr(hash('sha256', $datos['asignacion_id'].'|'.$datos['servicio']), 0, 32);
        if (! $this->esquema->tomarCandado($candado, 3)) {
            throw ValidationException::withMessages(['asignacion_id' => 'La asignación se está llevando a Finanzas. Reintente.']);
        }
        try {
            return $this->esquema->transaccion(function () use ($datos, $actor): CargoAcordado {
                $a = $this->lector->buscar($datos['asignacion_id']);
                $real = in_array($datos['medida'], ['horas_asistidas', 'jornadas_asistidas'], true);
                if ($a['cliente_id'] === null) {
                    throw ValidationException::withMessages(['asignacion_id' => 'La asignación debe tener un cliente antes de llevarla a Finanzas.']);
                }
                if ($real && (! $a['asistencia_valida'] || ! $a['turno_finalizado'])) {
                    throw ValidationException::withMessages(['medida' => 'La asistencia requiere un turno terminado y marcajes completos sin incidencias. Puede usar programación bajo un acuerdo.']);
                }
                if (! $real && $datos['base_cobro'] === 'ejecucion') {
                    throw ValidationException::withMessages(['base_cobro' => 'Las cantidades programadas se respaldan con acuerdo, compromiso o disponibilidad.']);
                }
                $cantidad = (string) $a[$datos['medida']];
                if (! BigDecimal::of($cantidad)->isGreaterThan(0)) {
                    throw ValidationException::withMessages(['medida' => 'La cantidad elegida debe ser mayor que cero.']);
                }
                $huella = $a[$real ? 'huella_asistencia' : 'huella_programacion'];
                $vinculo = VinculoDotacion::query()->where('asignacion_id', $a['id'])->where('servicio', $datos['servicio'])->lockForUpdate()->first();
                $anterior = $vinculo ? CargoAcordado::query()->lockForUpdate()->findOrFail($vinculo->cargo_id) : null;
                if ($anterior !== null && $anterior->anulado_at === null && $vinculo->huella !== $huella) {
                    throw ValidationException::withMessages(['asignacion_id' => 'La fuente cambió y ya tiene un cargo. Revíselo; para reemplazarlo debe anular ese cargo expresamente.']);
                }
                $reemplaza = $datos['reemplaza_cargo_id'] ?? null;
                if ($anterior?->anulado_at !== null && $reemplaza !== $anterior->id) {
                    throw ValidationException::withMessages(['reemplaza_cargo_id' => 'Confirme expresamente el cargo anulado que reemplazará.']);
                }
                if ($anterior === null && $reemplaza !== null) {
                    throw ValidationException::withMessages(['reemplaza_cargo_id' => 'Esta asignación todavía no tiene un cargo que reemplazar.']);
                }
                $nuevo = $anterior === null || $anterior->anulado_at !== null;
                $revision = $nuevo ? ($vinculo?->revision ?? 0) + 1 : $vinculo->revision;
                $cargo = $this->cargos->crear(['operacion_id' => $nuevo ? (string) Str::uuid() : $anterior->operacion_id,
                    'servicio' => $datos['servicio'], 'cliente_id' => $a['cliente_id'], 'fecha' => $datos['fecha'], 'cantidad' => $cantidad,
                    'base_cobro' => $datos['base_cobro'], 'estado_ejecucion' => $real ? 'realizado' : match ($a['estado']) {
                        'completada' => 'realizado', 'cancelada' => 'cancelado', default => 'programado'
                    },
                    'respaldo' => $datos['respaldo'], 'contexto' => ['rrhh' => ['asignacion_id' => $a['id'], 'revision' => $revision, 'version_asignacion' => (int) $a['version'],
                        'medida' => $datos['medida'], 'unidad_confirmada' => true, 'cantidad' => $cantidad, 'huella' => $huella,
                        'cargo' => $a['cargo'], 'area' => $a['area'], 'ceco' => $a['ceco'], 'desde_at' => $a['desde_at'], 'hasta_at' => $a['hasta_at'],
                        'estado_asignacion' => $a['estado'], 'reemplaza_cargo_id' => $reemplaza]]], $actor);
                if ($nuevo) {
                    VinculoDotacion::query()->updateOrCreate(['asignacion_id' => $a['id'], 'servicio' => $datos['servicio']], ['cargo_id' => $cargo->id, 'revision' => $revision, 'huella' => $huella]);
                }

                return $cargo;
            });
        } finally {
            $this->esquema->liberarCandado($candado);
        }
    }
}
