<?php

namespace App\Services\Temporadas;

use App\Enums\ContenidoCamara;
use App\Models\ReinicioOperacional;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Planificador\ServicioRecalculosPendientesPlanificador;
use App\Services\Revisiones\RevisionBandejasOperacionales;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioReinicioOperacional
{
    private const TABLAS_CATALOGO = [
        'temporadas',
        'clientes',
        'aliases_clientes',
        'clientes_validacion',
        'especies_validacion',
        'variedades_validacion',
        'calibres_validacion',
        'envases_validacion',
        'categorias_validacion',
        'csg_validacion',
        'csg_variedades_validacion',
        'marcas_validacion',
        'articulos_validacion',
        'origenes_validacion',
        'combinaciones_validacion',
        'condiciones_sag',
        'camaras',
        'posiciones',
        'tuneles_prefrio',
        'posiciones_tunel_prefrio',
        'andenes',
        'productores_csg',
        'clientes_productores_csg',
        'users',
        'dispositivos',
        'perfiles_acceso',
        'perfiles_impresion_etiquetas',
        'tipos_resultado_packing',
    ];

    private const TABLAS_BODEGA = [
        'temporadas_materiales',
        'clientes_materiales',
        'proveedores_materiales',
        'clientes_proveedores_materiales',
        'items_materiales',
        'destinos_materiales',
        'importaciones_catalogo_materiales',
        'recepciones_materiales',
        'detalles_recepciones_materiales',
        'bultos_recepciones_materiales',
        'eventos_recepciones_materiales',
        'eliminaciones_recepciones_materiales',
        'folios_materiales',
        'folios_materiales_liberados',
        'correcciones_items_folios_materiales',
        'despachos_materiales',
        'asignaciones_despachos_materiales',
        'detalles_despacho_materiales',
        'reservas_materiales',
        'operaciones_retiro_materiales',
        'retiros_materiales',
        'movimientos_inventario_materiales',
        'conexiones_existencias',
        'eventos_bloqueos_materiales',
        'correlativos_materiales_clientes',
        'recetas_materiales',
        'versiones_recetas_materiales',
        'detalles_versiones_recetas_materiales',
        'ordenes_transformacion_materiales',
        'eventos_transformacion_materiales',
        'lotes_transformacion_materiales',
        'reservas_transformacion_materiales',
        'consumos_transformacion_materiales',
        'salidas_transformacion_materiales',
        'trabajos_impresion_materiales',
        'folios_trabajos_impresion_materiales',
    ];

    public function __construct(
        private readonly ServicioPanelGerencial $panelGerencial,
        private readonly RevisionBandejasOperacionales $revisionesBandejas,
    ) {}

    public function fraseConfirmacion(Temporada $temporada): string
    {
        return sprintf('REINICIAR %s', $temporada->codigo);
    }

    /** @return array<string, mixed> */
    public function previsualizar(Temporada $temporada): array
    {
        $this->asegurarTemporadaActiva($temporada);

        return [
            'temporada' => [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
            ],
            'frase_confirmacion' => $this->fraseConfirmacion($temporada),
            'alcances' => ['frigorifico', 'materia_prima'],
            'resumen' => $this->resumen($temporada),
            'se_conserva' => [
                'temporada activa',
                'catálogos y maestros',
                'usuarios y permisos',
                'cámaras, posiciones, túneles y andenes',
                'todos los catálogos y datos operacionales de Bodega',
            ],
        ];
    }

    /**
     * @return array{reinicio: ReinicioOperacional, reutilizado: bool}
     */
    public function ejecutar(
        Temporada $temporada,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): array {
        $this->asegurarTemporadaActiva($temporada);
        $existente = ReinicioOperacional::query()
            ->where('operacion_id', $operacionId)
            ->first();
        if ($existente) {
            $this->asegurarMismaTemporada($existente, $temporada);

            return ['reinicio' => $existente, 'reutilizado' => true];
        }

        $resultado = DB::transaction(function () use (
            $temporada,
            $operacionId,
            $motivo,
            $usuario,
        ): array {
            $temporadaBloqueada = Temporada::query()
                ->lockForUpdate()
                ->findOrFail($temporada->id);
            $this->asegurarTemporadaActiva($temporadaBloqueada);

            $existente = ReinicioOperacional::query()
                ->where('operacion_id', $operacionId)
                ->first();
            if ($existente) {
                $this->asegurarMismaTemporada($existente, $temporadaBloqueada);

                return ['reinicio' => $existente, 'reutilizado' => true];
            }

            $this->asegurarSeparacionBodega($temporadaBloqueada);
            $preservadoAntes = $this->huellaPreservada();
            $resumenAntes = $this->resumen($temporadaBloqueada);
            $resumenEliminado = $this->eliminarOperacion($temporadaBloqueada);
            $resumenDespues = $this->resumen($temporadaBloqueada);

            if ($this->totalResumen($resumenDespues) !== 0) {
                throw new DomainException(
                    'El reinicio no pudo dejar Frigorífico y Materia Prima en cero. No se aplicó ningún cambio.',
                );
            }

            if ($preservadoAntes !== $this->huellaPreservada()) {
                throw new DomainException(
                    'La verificación detectó cambios fuera de Frigorífico y Materia Prima. El reinicio fue revertido.',
                );
            }

            $reinicio = ReinicioOperacional::query()->create([
                'operacion_id' => $operacionId,
                'temporada_id' => $temporadaBloqueada->id,
                'alcances' => ['frigorifico', 'materia_prima'],
                'motivo' => $motivo,
                'resumen_antes' => $resumenAntes,
                'resumen_eliminado' => $resumenEliminado,
                'resumen_despues' => $resumenDespues,
                'ejecutado_por_user_id' => $usuario->id,
            ]);

            return ['reinicio' => $reinicio, 'reutilizado' => false];
        }, 3);

        $this->panelGerencial->invalidar();
        $this->revisionesBandejas->invalidar(
            RevisionBandejasOperacionales::PREFRIO,
            RevisionBandejasOperacionales::CARGAS,
        );

        return $resultado;
    }

    /** @return array<string, array<string, int>> */
    private function resumen(Temporada $temporada): array
    {
        $folios = $this->foliosFrigorifico($temporada);
        $cargas = $this->cargas($temporada);
        $procesos = $this->procesosPrefrio($temporada);
        $recepciones = $this->recepcionesRomana($temporada);
        $validacionesMp = $this->validacionesMp($temporada);
        $segmentos = $this->segmentosMp($temporada);
        $lotes = $this->lotesMp($temporada);
        $entregasProceso = DB::table('entregas_fruta_proceso')
            ->select('id')
            ->whereIn('lote_materia_prima_id', clone $lotes);
        $retornosPacking = DB::table('retornos_packing')
            ->select('id')
            ->whereIn('entrega_fruta_proceso_id', clone $entregasProceso);
        $movimientosEnvases = $this->movimientosEnvases($temporada);
        $guias = $this->guiasEnvases($temporada);

        return [
            'frigorifico' => [
                'folios' => (clone $folios)->count(),
                'validaciones_pallet' => $this->validacionesPallet($temporada)->count(),
                'cargas' => (clone $cargas)->count(),
                'embarques' => DB::table('embarques')
                    ->where('temporada_id', $temporada->id)
                    ->count(),
                'asignaciones_carga' => DB::table('carga_folios')
                    ->whereIn('carga_id', clone $cargas)
                    ->count(),
                'procesos_prefrio' => (clone $procesos)->count(),
                'folios_prefrio' => DB::table('procesos_prefrio_folios')
                    ->whereIn('proceso_prefrio_id', clone $procesos)
                    ->count(),
                'retenciones_operacionales' => DB::table('retenciones_operacionales_folios')
                    ->whereIn('folio_id', clone $folios)
                    ->count(),
                'movimientos_camara' => DB::table('movimientos')
                    ->whereIn('folio_id', clone $folios)
                    ->count(),
                'planes_operacionales' => DB::table('planes_operacionales')
                    ->where('temporada_id', $temporada->id)
                    ->count(),
                'tareas_movimiento' => DB::table('tareas_movimiento')
                    ->whereIn(
                        'plan_operacional_id',
                        DB::table('planes_operacionales')
                            ->select('id')
                            ->where('temporada_id', $temporada->id),
                    )
                    ->count(),
                'ubicaciones_camara' => DB::table('ubicaciones_actuales')
                    ->whereIn('folio_id', clone $folios)
                    ->count(),
                'sesiones_abiertas' => DB::table('sesiones_estiba as se')
                    ->join('camaras as c', 'c.id', '=', 'se.camara_id')
                    ->where('c.contenido', ContenidoCamara::Productos->value)
                    ->where('se.estado', 'abierta')
                    ->count(),
            ],
            'materia_prima' => [
                'recepciones_romana' => (clone $recepciones)->count(),
                'pesajes_envases_romana' => DB::table('pesajes_envases_recepcion_romana')
                    ->whereIn('recepcion_romana_id', clone $recepciones)
                    ->count(),
                'validaciones_mp' => (clone $validacionesMp)->count(),
                'segmentos' => (clone $segmentos)->count(),
                'lotes' => (clone $lotes)->count(),
                'entregas_fruta_proceso' => DB::table('entregas_fruta_proceso')
                    ->whereIn('lote_materia_prima_id', clone $lotes)
                    ->count(),
                'retornos_packing' => (clone $retornosPacking)->count(),
                'sublotes_retorno_packing' => DB::table('sublotes_retorno_packing')
                    ->whereIn('retorno_packing_id', clone $retornosPacking)
                    ->count(),
                'procesos_hidrocooler' => DB::table('procesos_hidrocooler_materia_prima')
                    ->whereIn('lote_materia_prima_id', clone $lotes)
                    ->count(),
                'asignaciones_camara' => DB::table('asignaciones_camara_lote_materia_prima')
                    ->whereIn('lote_materia_prima_id', clone $lotes)
                    ->count(),
                'movimientos_envases' => (clone $movimientosEnvases)->count(),
                'guias_envases' => (clone $guias)->count(),
            ],
        ];
    }

    /** @return array<string, int> */
    private function eliminarOperacion(Temporada $temporada): array
    {
        $eliminados = [];
        $folios = $this->foliosFrigorifico($temporada);
        $cargas = $this->cargas($temporada);
        $asignacionesCarga = DB::table('carga_folios')
            ->select('id')
            ->whereIn('carga_id', clone $cargas);
        $incidencias = DB::table('incidencias_carga_folio')
            ->select('id')
            ->whereIn('carga_folio_id', clone $asignacionesCarga);
        $procesos = $this->procesosPrefrio($temporada);
        $recepciones = $this->recepcionesRomana($temporada);
        $validacionesMp = $this->validacionesMp($temporada);
        $segmentos = $this->segmentosMp($temporada);
        $lotes = $this->lotesMp($temporada);
        $entregasProceso = DB::table('entregas_fruta_proceso')
            ->select('id')
            ->whereIn('lote_materia_prima_id', clone $lotes);
        $retornosPacking = DB::table('retornos_packing')
            ->select('id')
            ->whereIn('entrega_fruta_proceso_id', clone $entregasProceso);
        $movimientosEnvases = $this->movimientosEnvases($temporada);
        $guias = $this->guiasEnvases($temporada);
        $sesiones = $this->sesionesRelacionadas($folios, $asignacionesCarga);
        $movimientoIds = DB::table('movimientos')
            ->select('id')
            ->whereIn('folio_id', clone $folios);

        $eliminados['recalculos_planificador'] = DB::table('recalculos_pendientes_planificador')
            ->where(function (Builder $consulta) use (
                $temporada,
                $cargas,
                $movimientoIds,
            ): void {
                $consulta
                    ->where('objetivo_id', $temporada->id)
                    ->orWhere(function (Builder $porCarga) use ($cargas): void {
                        $porCarga
                            ->where('tipo', ServicioRecalculosPendientesPlanificador::CARGA)
                            ->whereIn('fuente_id', clone $cargas);
                    })
                    ->orWhere(function (Builder $porMovimiento) use ($movimientoIds): void {
                        $porMovimiento
                            ->whereIn('tipo', [
                                ServicioRecalculosPendientesPlanificador::UBICACION,
                                ServicioRecalculosPendientesPlanificador::SEGREGACION,
                                ServicioRecalculosPendientesPlanificador::REORDENAMIENTO,
                                ServicioRecalculosPendientesPlanificador::DESOCUPACION,
                            ])
                            ->whereIn('fuente_id', clone $movimientoIds);
                    });
            })
            ->delete();

        $notificaciones = DB::table('notificaciones_operacionales')
            ->select('id')
            ->where(function (Builder $consulta) use (
                $folios,
                $cargas,
                $incidencias,
                $recepciones,
            ): void {
                $consulta
                    ->whereIn('folio_id', clone $folios)
                    ->orWhereIn('carga_id', clone $cargas)
                    ->orWhereIn('incidencia_carga_folio_id', clone $incidencias)
                    ->orWhereIn('recepcion_romana_id', clone $recepciones);
            });
        $notificacionesIds = $notificaciones->pluck('id');
        $eliminados['lecturas_notificaciones'] = DB::table('lecturas_notificaciones_operacionales')
            ->whereIn('notificacion_operacional_id', $notificacionesIds)
            ->delete();
        $eliminados['notificaciones'] = DB::table('notificaciones_operacionales')
            ->whereIn('id', $notificacionesIds)
            ->delete();

        $eliminados['eventos_prefrio'] = DB::table('eventos_prefrio')
            ->whereIn('proceso_prefrio_id', clone $procesos)
            ->delete();
        $eliminados['historial_habilitaciones'] = DB::table('historial_habilitaciones_almacenamiento')
            ->whereIn('folio_id', clone $folios)
            ->delete();
        $eliminados['folios_prefrio'] = DB::table('procesos_prefrio_folios')
            ->whereIn('proceso_prefrio_id', clone $procesos)
            ->delete();
        $eliminados['procesos_prefrio'] = DB::table('procesos_prefrio')
            ->where('temporada_id', $temporada->id)
            ->delete();
        $eliminados['retenciones_operacionales'] = DB::table('retenciones_operacionales_folios')
            ->whereIn('folio_id', clone $folios)
            ->delete();

        DB::table('carga_folios')
            ->whereIn('carga_id', clone $cargas)
            ->update(['reemplaza_a_carga_folio_id' => null]);
        DB::table('incidencias_carga_folio')
            ->whereIn('carga_folio_id', clone $asignacionesCarga)
            ->update(['carga_folio_reemplazo_id' => null]);
        $eliminados['incidencias_carga'] = DB::table('incidencias_carga_folio')
            ->whereIn('carga_folio_id', clone $asignacionesCarga)
            ->delete();
        $eliminados['reservas_carga'] = DB::table('reservas_carga_folio')
            ->whereIn('carga_folio_id', clone $asignacionesCarga)
            ->delete();
        $embarques = DB::table('embarques')
            ->select('id')
            ->where('temporada_id', $temporada->id);
        $eliminados['eventos_embarque'] = DB::table('eventos_embarque')
            ->whereIn('embarque_id', clone $embarques)
            ->delete();
        $eliminados['instructivos_embarque'] = DB::table('instructivos_embarque')
            ->whereIn('embarque_id', clone $embarques)
            ->delete();
        $eliminados['embarques'] = DB::table('embarques')
            ->where('temporada_id', $temporada->id)
            ->delete();
        $eliminados['eventos_carga'] = DB::table('eventos_carga')
            ->whereIn('carga_id', clone $cargas)
            ->delete();
        $eliminados['tareas_carga'] = DB::table('tareas_carga')
            ->whereIn('carga_id', clone $cargas)
            ->delete();
        $eliminados['asignaciones_carga'] = DB::table('carga_folios')
            ->whereIn('carga_id', clone $cargas)
            ->delete();
        $eliminados['cargas'] = DB::table('cargas')
            ->where('temporada_id', $temporada->id)
            ->delete();

        $movimientos = DB::table('movimientos')
            ->select('id')
            ->whereIn('folio_id', clone $folios);
        $operaciones = DB::table('movimientos')
            ->whereIn('folio_id', clone $folios)
            ->pluck('operacion_id');
        $eliminados['ubicaciones_camara'] = DB::table('ubicaciones_actuales')
            ->where(function (Builder $consulta) use ($folios, $movimientos): void {
                $consulta
                    ->whereIn('folio_id', clone $folios)
                    ->orWhereIn('movimiento_id', clone $movimientos);
            })
            ->delete();
        $eliminados['movimientos_camara'] = DB::table('movimientos')
            ->whereIn('folio_id', clone $folios)
            ->delete();
        $planesOperacionales = DB::table('planes_operacionales')
            ->select('id')
            ->where('temporada_id', $temporada->id);
        $eliminados['tareas_movimiento'] = DB::table('tareas_movimiento')
            ->whereIn('plan_operacional_id', clone $planesOperacionales)
            ->delete();
        $eliminados['planes_operacionales'] = DB::table('planes_operacionales')
            ->where('temporada_id', $temporada->id)
            ->delete();
        $eliminados['operaciones_sincronizacion'] = $this->eliminarPorIds(
            'operaciones_sincronizacion',
            $operaciones,
        );

        // Solo se descarta la trazabilidad de los folios que este reinicio elimina. La de otras
        // temporadas nunca se modifica: asegurarSeparacionBodega bloquea el reinicio si depende
        // de estos lotes.
        $eliminados['trazabilidad_origenes'] = DB::table('trazabilidad_folio_origenes')
            ->whereIn('folio_id', clone $folios)
            ->delete();

        DB::table('validaciones_pallet')
            ->where('temporada_id', $temporada->id)
            ->update(['validacion_conflicto_id' => null]);
        $eliminados['validaciones_pallet'] = DB::table('validaciones_pallet')
            ->where('temporada_id', $temporada->id)
            ->delete();
        $eliminados['folios'] = DB::table('folios')
            ->where('temporada_id', $temporada->id)
            ->whereIn('tipo_bulto', ['pallet', 'saldo'])
            ->delete();

        $sesionesEliminables = DB::table('sesiones_estiba')
            ->whereIn('id', $sesiones)
            ->whereNotExists(function (Builder $consulta): void {
                $consulta->selectRaw('1')
                    ->from('movimientos')
                    ->whereColumn('movimientos.sesion_origen_id', 'sesiones_estiba.id')
                    ->orWhereColumn('movimientos.sesion_destino_id', 'sesiones_estiba.id');
            })
            ->whereNotExists(function (Builder $consulta): void {
                $consulta->selectRaw('1')
                    ->from('incidencias_carga_folio')
                    ->whereColumn('incidencias_carga_folio.sesion_estiba_id', 'sesiones_estiba.id');
            })
            ->pluck('id');
        $eliminados['bloqueos_camara'] = DB::table('bloqueos_camara')
            ->whereIn('sesion_estiba_id', $sesionesEliminables)
            ->delete();
        $eliminados['sesiones_estiba'] = $this->eliminarPorIds(
            'sesiones_estiba',
            $sesionesEliminables,
        );
        DB::table('camaras')
            ->where('contenido', ContenidoCamara::Productos->value)
            ->increment('version_plano');

        $eliminados['eventos_guias_envases'] = DB::table('eventos_guias_despacho_envases')
            ->whereIn('guia_despacho_envase_id', clone $guias)
            ->delete();
        $eliminados['detalles_guias_envases'] = DB::table('detalles_guias_despacho_envases')
            ->whereIn('guia_despacho_envase_id', clone $guias)
            ->delete();
        $eliminados['guias_envases'] = DB::table('guias_despacho_envases')
            ->where('temporada_id', $temporada->id)
            ->delete();

        $eliminados['sublotes_retorno_packing'] = DB::table('sublotes_retorno_packing')
            ->whereIn('retorno_packing_id', clone $retornosPacking)
            ->delete();
        $eliminados['retornos_packing'] = DB::table('retornos_packing')
            ->whereIn('entrega_fruta_proceso_id', clone $entregasProceso)
            ->delete();
        $eliminados['entregas_fruta_proceso'] = DB::table('entregas_fruta_proceso')
            ->whereIn('lote_materia_prima_id', clone $lotes)
            ->delete();
        $eliminados['eventos_lotes'] = DB::table('eventos_lote_materia_prima')
            ->whereIn('lote_materia_prima_id', clone $lotes)
            ->delete();
        $eliminados['asignaciones_lotes'] = DB::table('asignaciones_camara_lote_materia_prima')
            ->whereIn('lote_materia_prima_id', clone $lotes)
            ->delete();
        $eliminados['hidrocooler'] = DB::table('procesos_hidrocooler_materia_prima')
            ->whereIn('lote_materia_prima_id', clone $lotes)
            ->delete();
        $eliminados['envases_lotes'] = DB::table('lotes_materia_prima_envases')
            ->whereIn('lote_materia_prima_id', clone $lotes)
            ->delete();
        $eliminados['lotes'] = DB::table('lotes_materia_prima')
            ->where('temporada_id', $temporada->id)
            ->delete();

        $eliminados['envases_segmentos'] = DB::table('segmentos_envases_validacion_mp')
            ->whereIn('segmento_validacion_mp_id', clone $segmentos)
            ->delete();
        $eliminados['segmentos_mp'] = DB::table('segmentos_validacion_mp')
            ->whereIn('validacion_mp_id', clone $validacionesMp)
            ->delete();
        $eliminados['validaciones_mp'] = DB::table('validaciones_mp')
            ->where('temporada_id', $temporada->id)
            ->delete();

        $eliminados['revisiones_envases'] = DB::table('revisiones_movimientos_envases')
            ->whereIn('movimiento_envase_id', clone $movimientosEnvases)
            ->delete();
        DB::table('movimientos_envases')
            ->where('temporada_id', $temporada->id)
            ->update(['movimiento_origen_id' => null]);
        $eliminados['movimientos_envases'] = DB::table('movimientos_envases')
            ->where('temporada_id', $temporada->id)
            ->delete();

        $eliminados['pesajes_envases_romana'] = DB::table('pesajes_envases_recepcion_romana')
            ->whereIn('recepcion_romana_id', clone $recepciones)
            ->delete();
        $eliminados['detalles_envases_romana'] = DB::table('detalles_envases_recepcion_romana')
            ->whereIn('recepcion_romana_id', clone $recepciones)
            ->delete();
        $eliminados['eventos_romana'] = DB::table('eventos_recepcion_romana')
            ->whereIn('recepcion_romana_id', clone $recepciones)
            ->delete();
        $eliminados['recepciones_romana'] = DB::table('recepciones_romana')
            ->where('temporada_id', $temporada->id)
            ->delete();

        return $eliminados;
    }

    private function asegurarTemporadaActiva(Temporada $temporada): void
    {
        if (! $temporada->activa) {
            throw new DomainException(
                'El reinicio solo está disponible para la temporada global activa.',
            );
        }
    }

    private function asegurarMismaTemporada(
        ReinicioOperacional $reinicio,
        Temporada $temporada,
    ): void {
        if ($reinicio->temporada_id !== $temporada->id) {
            throw new DomainException(
                'El identificador de operación ya fue utilizado para otra temporada.',
            );
        }
    }

    private function asegurarSeparacionBodega(Temporada $temporada): void
    {
        $folios = $this->foliosFrigorifico($temporada);
        $cargas = $this->cargas($temporada);
        $procesos = $this->procesosPrefrio($temporada);
        $recepciones = $this->recepcionesRomana($temporada);
        $movimientosEnvases = $this->movimientosEnvases($temporada);
        $guias = $this->guiasEnvases($temporada);

        foreach ([
            'folios_materiales' => 'folio_id',
            'folios_trabajos_impresion_materiales' => 'folio_id',
            'migraciones_temporadas_folios' => 'folio_id',
            'correcciones_items_folios_materiales' => 'folio_id',
            'eventos_bloqueos_materiales' => 'folio_id',
        ] as $tabla => $columna) {
            if (DB::table($tabla)->whereIn($columna, clone $folios)->exists()) {
                throw new DomainException(
                    'Se detectó un folio compartido con Bodega. El reinicio fue bloqueado sin borrar datos.',
                );
            }
        }

        if (DB::table('trazabilidad_folio_origenes')
            ->whereNotIn('folio_id', clone $folios)
            ->whereIn('lote_materia_prima_id', DB::table('lotes_materia_prima')
                ->select('id')
                ->where('temporada_id', $temporada->id))
            ->exists()) {
            throw new DomainException(
                'Hay pallets fuera de este reinicio cuya trazabilidad apunta a lotes de la temporada. El reinicio fue bloqueado sin borrar datos.',
            );
        }

        if (DB::table('carga_folios as cf')
            ->join('folios as f', 'f.id', '=', 'cf.folio_id')
            ->whereIn('cf.carga_id', clone $cargas)
            ->where(function (Builder $consulta) use ($temporada): void {
                $consulta->where('f.temporada_id', '!=', $temporada->id)
                    ->orWhereNotIn('f.tipo_bulto', ['pallet', 'saldo']);
            })
            ->exists()) {
            throw new DomainException(
                'Una carga de Frigorífico contiene folios de otro alcance. Corrígela antes de reiniciar.',
            );
        }

        if (DB::table('procesos_prefrio_folios as ppf')
            ->join('folios as f', 'f.id', '=', 'ppf.folio_id')
            ->whereIn('ppf.proceso_prefrio_id', clone $procesos)
            ->where(function (Builder $consulta) use ($temporada): void {
                $consulta->where('f.temporada_id', '!=', $temporada->id)
                    ->orWhereNotIn('f.tipo_bulto', ['pallet', 'saldo']);
            })
            ->exists()) {
            throw new DomainException(
                'Un proceso de Prefrío contiene folios de otro alcance. Corrígelo antes de reiniciar.',
            );
        }

        if (DB::table('validaciones_pallet')
            ->whereIn('folio_id', clone $folios)
            ->where('temporada_id', '!=', $temporada->id)
            ->exists()) {
            throw new DomainException(
                'Se detectó una validación cruzada entre temporadas. El reinicio fue bloqueado.',
            );
        }

        if (DB::table('validaciones_pallet as vp')
            ->join('folios as f', 'f.id', '=', 'vp.folio_id')
            ->where('vp.temporada_id', $temporada->id)
            ->where(function (Builder $consulta) use ($temporada): void {
                $consulta->where('f.temporada_id', '!=', $temporada->id)
                    ->orWhereNotIn('f.tipo_bulto', ['pallet', 'saldo']);
            })
            ->exists()) {
            throw new DomainException(
                'Una validación PT está asociada a un folio fuera de Frigorífico. El reinicio fue bloqueado.',
            );
        }

        $cargasCruzadas = DB::table('carga_folios')
            ->whereIn('folio_id', clone $folios)
            ->whereNotIn('carga_id', clone $cargas)
            ->exists()
            || DB::table('procesos_prefrio_folios')
                ->whereIn('folio_id', clone $folios)
                ->whereNotIn('proceso_prefrio_id', clone $procesos)
                ->exists()
            || DB::table('eventos_carga')
                ->whereIn('folio_id', clone $folios)
                ->whereNotIn('carga_id', clone $cargas)
                ->exists()
            || DB::table('reservas_carga_folio')
                ->whereIn('folio_id', clone $folios)
                ->whereNotIn(
                    'carga_folio_id',
                    DB::table('carga_folios')
                        ->select('id')
                        ->whereIn('carga_id', clone $cargas),
                )
                ->exists();
        if ($cargasCruzadas) {
            throw new DomainException(
                'Se detectaron asignaciones cruzadas entre temporadas de Frigorífico. El reinicio fue bloqueado.',
            );
        }

        $asignacionesCarga = DB::table('carga_folios')
            ->select('id')
            ->whereIn('carga_id', clone $cargas);
        if (DB::table('carga_folios')
            ->whereIn('reemplaza_a_carga_folio_id', clone $asignacionesCarga)
            ->whereNotIn('id', clone $asignacionesCarga)
            ->exists()
            || DB::table('incidencias_carga_folio')
                ->whereIn('carga_folio_reemplazo_id', clone $asignacionesCarga)
                ->whereNotIn('carga_folio_id', clone $asignacionesCarga)
                ->exists()
            || DB::table('validaciones_pallet')
                ->whereIn(
                    'validacion_conflicto_id',
                    $this->validacionesPallet($temporada),
                )
                ->where('temporada_id', '!=', $temporada->id)
                ->exists()) {
            throw new DomainException(
                'Se detectaron referencias históricas cruzadas. El reinicio fue bloqueado sin borrar datos.',
            );
        }

        if (DB::table('movimientos as m')
            ->leftJoin('camaras as co', 'co.id', '=', 'm.camara_origen_id')
            ->leftJoin('camaras as cd', 'cd.id', '=', 'm.camara_destino_id')
            ->whereIn('m.folio_id', clone $folios)
            ->where(function (Builder $consulta): void {
                $consulta
                    ->where('co.contenido', ContenidoCamara::Materiales->value)
                    ->orWhere('cd.contenido', ContenidoCamara::Materiales->value);
            })
            ->exists()) {
            throw new DomainException(
                'Un folio de Frigorífico aparece en una cámara de Bodega. El reinicio fue bloqueado.',
            );
        }

        $operaciones = DB::table('movimientos')
            ->whereIn('folio_id', clone $folios)
            ->whereNotNull('operacion_id')
            ->pluck('operacion_id');
        if ($operaciones->isNotEmpty()
            && DB::table('movimientos')
                ->whereIn('operacion_id', $operaciones)
                ->whereNotIn('folio_id', clone $folios)
                ->exists()) {
            throw new DomainException(
                'Una operación de cámara contiene movimientos fuera de Frigorífico. El reinicio fue bloqueado.',
            );
        }

        if (DB::table('movimientos_envases')
            ->whereIn('movimiento_origen_id', clone $movimientosEnvases)
            ->where('temporada_id', '!=', $temporada->id)
            ->exists()) {
            throw new DomainException(
                'Un movimiento de envases está encadenado con otra temporada. El reinicio fue bloqueado.',
            );
        }

        if (DB::table('detalles_guias_despacho_envases')
            ->whereIn('movimiento_origen_id', clone $movimientosEnvases)
            ->whereNotIn('guia_despacho_envase_id', clone $guias)
            ->exists()) {
            throw new DomainException(
                'Una guía de otra temporada referencia envases del reinicio. No se borraron datos.',
            );
        }

        if (DB::table('movimientos_envases')
            ->whereIn('recepcion_romana_id', clone $recepciones)
            ->where('temporada_id', '!=', $temporada->id)
            ->exists()) {
            throw new DomainException(
                'Una recepción de Romana tiene movimientos en otra temporada. El reinicio fue bloqueado.',
            );
        }

        if (DB::table('validaciones_mp as vm')
            ->join('recepciones_romana as rr', 'rr.id', '=', 'vm.recepcion_romana_id')
            ->where('vm.temporada_id', $temporada->id)
            ->where('rr.temporada_id', '!=', $temporada->id)
            ->exists()
            || DB::table('lotes_materia_prima as lmp')
                ->join('recepciones_romana as rr', 'rr.id', '=', 'lmp.recepcion_romana_id')
                ->join('segmentos_validacion_mp as svm', 'svm.id', '=', 'lmp.segmento_validacion_mp_id')
                ->join('validaciones_mp as vm', 'vm.id', '=', 'svm.validacion_mp_id')
                ->where('lmp.temporada_id', $temporada->id)
                ->where(function (Builder $consulta) use ($temporada): void {
                    $consulta->where('rr.temporada_id', '!=', $temporada->id)
                        ->orWhere('vm.temporada_id', '!=', $temporada->id);
                })
                ->exists()) {
            throw new DomainException(
                'Se detectaron recepciones, validaciones o lotes MP cruzados entre temporadas. El reinicio fue bloqueado.',
            );
        }

        $incidencias = DB::table('incidencias_carga_folio')
            ->select('id')
            ->whereIn(
                'carga_folio_id',
                DB::table('carga_folios')
                    ->select('id')
                    ->whereIn('carga_id', clone $cargas),
            );
        $notificacionesCruzadas = DB::table('notificaciones_operacionales')
            ->whereNotNull('despacho_material_id')
            ->where(function (Builder $consulta) use (
                $folios,
                $cargas,
                $recepciones,
                $incidencias,
            ): void {
                $consulta
                    ->whereIn('folio_id', clone $folios)
                    ->orWhereIn('carga_id', clone $cargas)
                    ->orWhereIn('incidencia_carga_folio_id', clone $incidencias)
                    ->orWhereIn('recepcion_romana_id', clone $recepciones);
            })
            ->exists();
        if ($notificacionesCruzadas) {
            throw new DomainException(
                'Una notificación del reinicio está asociada a Bodega. No se borraron datos.',
            );
        }
    }

    /** @return array<string, array<string, int>> */
    private function huellaPreservada(): array
    {
        $catalogos = [];
        foreach (self::TABLAS_CATALOGO as $tabla) {
            $catalogos[$tabla] = DB::table($tabla)->count();
        }

        $bodega = [];
        foreach (self::TABLAS_BODEGA as $tabla) {
            $bodega[$tabla] = DB::table($tabla)->count();
        }
        $bodega['folios_tipo_material'] = DB::table('folios')
            ->where('tipo_bulto', 'material')
            ->count();
        $bodega['ubicaciones_materiales'] = DB::table('ubicaciones_actuales as ua')
            ->join('folios as f', 'f.id', '=', 'ua.folio_id')
            ->where('f.tipo_bulto', 'material')
            ->count();
        $bodega['movimientos_materiales'] = DB::table('movimientos as m')
            ->join('folios as f', 'f.id', '=', 'm.folio_id')
            ->where('f.tipo_bulto', 'material')
            ->count();
        $bodega['sesiones_camaras_materiales'] = DB::table('sesiones_estiba as se')
            ->join('camaras as c', 'c.id', '=', 'se.camara_id')
            ->where('c.contenido', ContenidoCamara::Materiales->value)
            ->count();
        $bodega['bloqueos_camaras_materiales'] = DB::table('bloqueos_camara as bc')
            ->join('camaras as c', 'c.id', '=', 'bc.camara_id')
            ->where('c.contenido', ContenidoCamara::Materiales->value)
            ->count();
        $bodega['notificaciones_despachos_materiales'] = DB::table('notificaciones_operacionales')
            ->whereNotNull('despacho_material_id')
            ->count();

        return compact('catalogos', 'bodega');
    }

    private function totalResumen(array $resumen): int
    {
        return collect($resumen)
            ->flatMap(fn (array $alcance): array => $alcance)
            ->sum();
    }

    private function eliminarPorIds(string $tabla, Collection $ids): int
    {
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table($tabla)->whereIn('id', $ids)->delete();
    }

    private function sesionesRelacionadas(
        Builder $folios,
        Builder $asignacionesCarga,
    ): Collection {
        $sesiones = DB::table('movimientos')
            ->whereIn('folio_id', clone $folios)
            ->get(['sesion_origen_id', 'sesion_destino_id'])
            ->flatMap(fn (object $movimiento): array => [
                $movimiento->sesion_origen_id,
                $movimiento->sesion_destino_id,
            ])
            ->filter();
        $sesiones = $sesiones->merge(
            DB::table('incidencias_carga_folio')
                ->whereIn('carga_folio_id', clone $asignacionesCarga)
                ->pluck('sesion_estiba_id'),
        );
        $sesiones = $sesiones->merge(
            DB::table('sesiones_estiba as se')
                ->join('camaras as c', 'c.id', '=', 'se.camara_id')
                ->where('c.contenido', ContenidoCamara::Productos->value)
                ->where('se.estado', 'abierta')
                ->pluck('se.id'),
        );

        return $sesiones->unique()->values();
    }

    private function foliosFrigorifico(Temporada $temporada): Builder
    {
        return DB::table('folios')
            ->select('id')
            ->where('temporada_id', $temporada->id)
            ->whereIn('tipo_bulto', ['pallet', 'saldo']);
    }

    private function validacionesPallet(Temporada $temporada): Builder
    {
        return DB::table('validaciones_pallet')
            ->select('id')
            ->where('temporada_id', $temporada->id);
    }

    private function cargas(Temporada $temporada): Builder
    {
        return DB::table('cargas')
            ->select('id')
            ->where('temporada_id', $temporada->id);
    }

    private function procesosPrefrio(Temporada $temporada): Builder
    {
        return DB::table('procesos_prefrio')
            ->select('id')
            ->where('temporada_id', $temporada->id);
    }

    private function recepcionesRomana(Temporada $temporada): Builder
    {
        return DB::table('recepciones_romana')
            ->select('id')
            ->where('temporada_id', $temporada->id);
    }

    private function validacionesMp(Temporada $temporada): Builder
    {
        return DB::table('validaciones_mp')
            ->select('id')
            ->where('temporada_id', $temporada->id);
    }

    private function segmentosMp(Temporada $temporada): Builder
    {
        return DB::table('segmentos_validacion_mp')
            ->select('id')
            ->whereIn('validacion_mp_id', $this->validacionesMp($temporada));
    }

    private function lotesMp(Temporada $temporada): Builder
    {
        return DB::table('lotes_materia_prima')
            ->select('id')
            ->where('temporada_id', $temporada->id);
    }

    private function movimientosEnvases(Temporada $temporada): Builder
    {
        return DB::table('movimientos_envases')
            ->select('id')
            ->where('temporada_id', $temporada->id);
    }

    private function guiasEnvases(Temporada $temporada): Builder
    {
        return DB::table('guias_despacho_envases')
            ->select('id')
            ->where('temporada_id', $temporada->id);
    }
}
