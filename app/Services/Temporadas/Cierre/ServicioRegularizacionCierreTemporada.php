<?php

namespace App\Services\Temporadas\Cierre;

use App\Enums\CategoriaPendienteCierre;
use App\Enums\EstadoHidrocoolerMateriaPrima;
use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoSesionEstiba;
use App\Enums\MotivoRegularizacionCierre;
use App\Models\BloqueoCamara;
use App\Models\Camara;
use App\Models\Folio;
use App\Models\LoteMateriaPrima;
use App\Models\ProcesoHidrocoolerMateriaPrima;
use App\Models\ProcesoPrefrio;
use App\Models\RegularizacionCierreTemporada;
use App\Models\ReservaCargaFolio;
use App\Models\SesionEstiba;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Prefrio\ServicioProcesoPrefrio;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Baja administrativa auditada de registros que la temporada dejó abiertos.
 *
 * Cada registro regularizado queda en `regularizaciones_cierre_temporada` con
 * su estado anterior, una fotografía, el motivo y quién lo hizo. El registro
 * original no se borra:
 *
 * - un folio PT pasa a retirado definitivo, deja de estar activo y libera su
 *   posición en la cámara (sube la versión del plano);
 * - una sesión de estiba se cierra forzosamente y libera la cámara;
 * - los procesos de prefrío e hidrocooler se cancelan y liberan sus equipos;
 * - las demás categorías conservan su estado y dejan de contar como pendientes,
 *   siempre que no mantengan recursos operacionales reservados.
 */
final class ServicioRegularizacionCierreTemporada
{
    public const MAXIMO_POR_SOLICITUD = 500;

    public function __construct(
        private readonly ServicioDiagnosticoCierreTemporada $diagnostico,
        private readonly ServicioSesionEstiba $sesiones,
        private readonly ServicioProcesoPrefrio $prefrio,
    ) {}

    /**
     * @param  list<string>  $ids
     * @return Collection<int, RegularizacionCierreTemporada>
     */
    public function regularizar(
        Temporada $temporada,
        CategoriaPendienteCierre $categoria,
        array $ids,
        MotivoRegularizacionCierre $motivoCategoria,
        string $motivo,
        User $usuario,
    ): Collection {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $motivo = Str::of($motivo)->squish()->toString();

        if ($ids === []) {
            throw new DomainException('Selecciona al menos un registro para regularizar.');
        }
        if (count($ids) > self::MAXIMO_POR_SOLICITUD) {
            throw new DomainException('Se pueden regularizar hasta '.self::MAXIMO_POR_SOLICITUD.' registros por vez.');
        }
        if (mb_strlen($motivo) < 10) {
            throw new DomainException('Describe el motivo de la regularización con al menos 10 caracteres.');
        }

        return DB::transaction(function () use ($temporada, $categoria, $ids, $motivoCategoria, $motivo, $usuario): Collection {
            Temporada::query()->lockForUpdate()->findOrFail($temporada->id);
            $bloqueo = $this->diagnostico->bloqueoRegularizacion($temporada, $categoria);
            if ($bloqueo !== null) {
                throw new DomainException($bloqueo);
            }

            $pendientes = $this->diagnostico->pendientes($temporada, $categoria, $ids)->keyBy('id');
            $faltantes = array_values(array_diff($ids, $pendientes->keys()->all()));
            if ($faltantes !== []) {
                throw new DomainException(sprintf(
                    '%d de los registros seleccionados ya no están pendientes en %s o no pertenecen a la temporada %s. Actualiza el diagnóstico.',
                    count($faltantes),
                    $categoria->enFrase(),
                    $temporada->codigo,
                ));
            }

            $this->asegurarSinRecursosOperacionales($categoria, $pendientes->keys()->all());

            $loteId = (string) Str::uuid();
            $ahora = now();
            $camarasAfectadas = [];
            $registros = collect();

            foreach ($pendientes as $pendiente) {
                $snapshot = [
                    'temporada_tipo' => $temporada->tipo->value,
                    'estado' => $pendiente['estado'],
                    'detalle' => $pendiente['detalle'],
                    'responsable' => $pendiente['responsable'],
                    'fecha' => $pendiente['fecha'],
                ];

                if ($categoria === CategoriaPendienteCierre::FoliosPt) {
                    $snapshot += $this->retirarFolio($pendiente['id'], $camarasAfectadas);
                } elseif ($categoria === CategoriaPendienteCierre::SesionesEstiba) {
                    $snapshot += $this->cerrarSesion($pendiente['id'], $usuario, $motivoCategoria, $motivo);
                } elseif ($categoria === CategoriaPendienteCierre::Prefrio) {
                    $snapshot += $this->cancelarPrefrio($pendiente['id'], $usuario, $motivo);
                } elseif ($categoria === CategoriaPendienteCierre::Hidrocooler) {
                    $snapshot += $this->cancelarHidrocooler($pendiente['id'], $usuario);
                }

                $registros->push(RegularizacionCierreTemporada::query()->create([
                    'temporada_id' => $temporada->id,
                    'lote_regularizacion_id' => $loteId,
                    'categoria' => $categoria,
                    'entidad_id' => $pendiente['id'],
                    'referencia' => Str::limit($pendiente['referencia'], 117),
                    'estado_anterior' => $pendiente['estado'],
                    'motivo_categoria' => $motivoCategoria,
                    'motivo' => $motivo,
                    'snapshot' => $snapshot,
                    'regularizado_por_user_id' => $usuario->id,
                    'regularizado_at' => $ahora,
                ]));
            }

            if ($camarasAfectadas !== []) {
                Camara::query()->whereKey(array_keys($camarasAfectadas))->increment('version_plano');
            }

            return $registros;
        });
    }

    /**
     * Algunas categorías conservan su estado por razones documentales. No se
     * puede ocultar un pendiente si aún bloquea un andén, folio, posición o
     * tarea que debe quedar disponible para la temporada siguiente.
     *
     * @param  list<string>  $ids
     */
    private function asegurarSinRecursosOperacionales(CategoriaPendienteCierre $categoria, array $ids): void
    {
        if ($categoria === CategoriaPendienteCierre::Cargas) {
            $conFolios = DB::table('carga_folios')->whereIn('carga_id', $ids)->exists();
            $conTareas = DB::table('tareas_carga')->whereIn('carga_id', $ids)->exists();
            $enAnden = DB::table('presencias_carga_anden')->whereIn('carga_id', $ids)->where('estado', 'activa')->exists();
            if ($conFolios || $conTareas || $enAnden) {
                throw new DomainException('La carga conserva folios, tareas o un camión en andén. Cancélala o ciérrala en Cargas antes de regularizarla.');
            }
        }

        if ($categoria === CategoriaPendienteCierre::PlanesOperacionales
            && DB::table('tareas_movimiento')->whereIn('plan_operacional_id', $ids)->exists()) {
            throw new DomainException('El plan conserva tareas de movimiento. Resuélvelas desde Cámaras antes de regularizarlo.');
        }

        if ($categoria === CategoriaPendienteCierre::LotesMp
            && DB::table('lotes_materia_prima')->whereIn('id', $ids)
                ->whereIn('estado', [EstadoLoteMateriaPrima::AsignadoCamara->value, EstadoLoteMateriaPrima::EntregaParcialProceso->value])
                ->exists()) {
            throw new DomainException('El lote MP todavía está asignado a una cámara o parcialmente entregado. Ciérralo en Materia Prima antes de regularizarlo.');
        }
    }

    /** @return array<string, mixed> */
    private function cancelarPrefrio(string $procesoId, User $usuario, string $motivo): array
    {
        $proceso = ProcesoPrefrio::query()->findOrFail($procesoId);
        if ($proceso->folios()->pendienteRetiroParcial()->exists()) {
            throw new DomainException('Confirma primero las salidas parciales pendientes del túnel en Prefrío.');
        }
        $this->prefrio->cancelar($proceso, [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $proceso->version,
            'motivo' => Str::limit('Cierre de temporada: '.$motivo, 100),
            'observacion' => $motivo,
            'ocurrido_at' => now()->toAtomString(),
        ], $usuario, desdeCierreTemporada: true);

        return ['prefrio' => ['tunel_prefrio_id' => $proceso->tunel_prefrio_id, 'version_anterior' => $proceso->version]];
    }

    /** @return array<string, mixed> */
    private function cancelarHidrocooler(string $procesoId, User $usuario): array
    {
        $proceso = ProcesoHidrocoolerMateriaPrima::query()->lockForUpdate()->findOrFail($procesoId);
        $lote = LoteMateriaPrima::query()->lockForUpdate()->findOrFail($proceso->lote_materia_prima_id);
        if ($proceso->estado !== EstadoHidrocoolerMateriaPrima::EnCurso
            || $lote->estado !== EstadoLoteMateriaPrima::HidrocoolerEnCurso) {
            throw new DomainException('El ciclo de hidrocooler o su lote cambiaron de estado. Actualiza el diagnóstico.');
        }

        $equipo = $proceso->equipo;
        $proceso->update([
            'estado' => EstadoHidrocoolerMateriaPrima::Cancelado,
            'equipo_activo_clave' => null,
            'termino_at' => now(),
        ]);
        $lote->update([
            'estado' => EstadoLoteMateriaPrima::PendienteHidrocooler,
            'version' => $lote->version + 1,
            'actualizado_por_user_id' => $usuario->id,
        ]);

        return ['hidrocooler' => ['equipo' => $equipo, 'lote_id' => $lote->id, 'estado_lote_anterior' => EstadoLoteMateriaPrima::HidrocoolerEnCurso->value]];
    }

    /**
     * @param  array<string, true>  $camarasAfectadas
     * @return array<string, mixed>
     */
    private function retirarFolio(string $folioId, array &$camarasAfectadas): array
    {
        $folio = Folio::query()->lockForUpdate()->findOrFail($folioId);
        $ubicacion = UbicacionActual::query()->where('folio_id', $folio->id)->lockForUpdate()->first();
        $reserva = ReservaCargaFolio::query()->where('folio_id', $folio->id)->lockForUpdate()->first();
        $fotografia = [
            'folio' => [
                'numero_folio' => $folio->numero_folio,
                'tipo_bulto' => $folio->tipo_bulto->value,
                'estado_operacional' => $folio->estado_operacional->value,
                'activo' => (bool) $folio->activo,
            ],
            'ubicacion' => null,
            'reserva_carga_folio_id' => $reserva?->carga_folio_id,
        ];

        if ($ubicacion) {
            $posicion = $ubicacion->posicion_id
                ? DB::table('posiciones')->where('id', $ubicacion->posicion_id)->first(['etiqueta'])
                : null;
            $fotografia['ubicacion'] = [
                'camara_id' => $ubicacion->camara_id,
                'camara' => Camara::query()->whereKey($ubicacion->camara_id)->value('codigo'),
                'posicion_id' => $ubicacion->posicion_id,
                'posicion' => $posicion?->etiqueta,
                'ubicado_at' => $ubicacion->ubicado_at?->toAtomString(),
            ];
            $camarasAfectadas[$ubicacion->camara_id] = true;
            $ubicacion->delete();
        }

        // Las cargas de la temporada ya están cerradas o regularizadas; una
        // reserva que sigue viva es un resto que no debe retener el folio.
        $reserva?->delete();

        if (! in_array($folio->estado_operacional, [
            EstadoOperacionalFolio::Anulado,
            EstadoOperacionalFolio::RetiradoDefinitivo,
            EstadoOperacionalFolio::Despachado,
            EstadoOperacionalFolio::Agotado,
        ], true)) {
            $folio->update([
                'estado_operacional' => EstadoOperacionalFolio::RetiradoDefinitivo,
                'activo' => false,
            ]);
        }

        return $fotografia;
    }

    /** @return array<string, mixed> */
    private function cerrarSesion(
        string $sesionId,
        User $usuario,
        MotivoRegularizacionCierre $motivoCategoria,
        string $motivo,
    ): array {
        $sesion = SesionEstiba::query()->findOrFail($sesionId);
        $texto = Str::limit("Cierre de temporada ({$motivoCategoria->etiqueta()}): {$motivo}", 250);
        $conBloqueo = BloqueoCamara::query()
            ->where('camara_id', $sesion->camara_id)
            ->where('sesion_estiba_id', $sesion->id)
            ->exists();

        if ($conBloqueo) {
            $this->sesiones->cerrarForzosamente($sesion, $usuario, $texto, desdeAdministracion: true);
        } else {
            // Sesión abierta que ya perdió el bloqueo de la cámara.
            SesionEstiba::query()->whereKey($sesion->id)->lockForUpdate()->first()?->update([
                'estado' => EstadoSesionEstiba::CierreForzado,
                'cerrada_at' => now(),
                'cierre_forzado_por_user_id' => $usuario->id,
                'motivo_cierre' => $texto,
            ]);
        }

        return [
            'sesion' => [
                'camara_id' => $sesion->camara_id,
                'user_id' => $sesion->user_id,
                'dispositivo_id' => $sesion->dispositivo_id,
                'tenia_bloqueo' => $conBloqueo,
            ],
        ];
    }
}
