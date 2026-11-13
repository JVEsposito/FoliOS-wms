<?php

namespace App\Services\Folios;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoLoteInspeccionSag;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\EstadoValidacionPallet;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ResultadoValidacionPallet;
use App\Exceptions\ConflictoOperacion;
use App\Models\AnulacionValidacionPallet;
use App\Models\Camara;
use App\Models\CambioAtributosPt;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaCargaFolio;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use App\Services\Validacion\ServicioAnulacionValidacionPallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Anulación de una validación PT por administración, también después de prefrío o de
 * ubicarse en cámara, mientras el bulto siga en la planta.
 *
 * Si el pallet aún no tuvo actividad, usa la anulación normal de Validación. Si ya la tuvo,
 * no borra nada: el folio queda anulado e inactivo con todo su historial (prefrío,
 * movimientos), libera su posición en el plano, cancela sus labores pendientes y archiva su
 * número con sufijo para que el pallet correcto pueda validarse con ese número. La anulación
 * queda en anulaciones_validacion_pallet con su motivo y una copia completa.
 *
 * No anula lo que otro proceso todavía necesita: un folio en una carga, en un túnel, en un
 * lote SAG abierto, retenido, en repaletizaje o que un camarero está moviendo.
 */
class ServicioAnulacionAdministrativaValidacion
{
    public function __construct(
        private readonly MotorTransicionesOperacionales $motorTransiciones,
        private readonly ServicioAnulacionValidacionPallet $anulacion,
        private readonly ServicioPlanesOperacionales $planes,
    ) {}

    /** Por qué no se puede anular; null si se puede. */
    public function motivoNoAnulable(Folio $folio, ?ValidacionPallet $validacion): ?string
    {
        if (! $validacion
            || $validacion->estado !== EstadoValidacionPallet::Aceptada
            || $validacion->resultado !== ResultadoValidacionPallet::Aprobado) {
            return 'El folio no tiene una validación aprobada y vigente que anular.';
        }
        if (! $folio->activo || in_array($folio->estado_operacional, ServicioCambioAtributosPt::ESTADOS_CERRADOS, true)) {
            return 'El folio ya no está en la planta y su validación no se puede anular.';
        }
        if ($folio->origen_sistema !== 'validacion' || ($folio->datos_externos['validacion_id'] ?? null) !== $validacion->id) {
            return 'El folio ya no corresponde directamente a esta validación (por ejemplo, salió de un repaletizaje).';
        }
        if ($this->participaEnRepaletizaje($folio)) {
            return 'El pallet participó en un repaletizaje: anularlo dejaría sin respaldo a los folios que se armaron con él.';
        }
        $carga = CargaFolio::query()->where('folio_id', $folio->id)
            ->where(fn ($consulta) => $consulta
                ->whereIn('estado', [EstadoCargaFolio::Pendiente->value, EstadoCargaFolio::ConIncidencia->value, EstadoCargaFolio::EnAnden->value, EstadoCargaFolio::Cargado->value])
                ->orWhereHas('reservaActiva'))
            ->with('carga:id,codigo')->first();
        if ($carga || ReservaCargaFolio::query()->where('folio_id', $folio->id)->exists()) {
            return sprintf('El pallet está en la carga %s. Quítalo de la carga antes de anular su validación.', $carga?->carga?->codigo ?? 'asignada');
        }
        if ($proceso = ProcesoPrefrioFolio::query()->where('folio_id', $folio->id)
            ->where(fn ($asignacion) => $asignacion->where('estado', EstadoFolioProcesoPrefrio::EnProceso)
                ->orWhere(fn ($pendiente) => $pendiente->pendienteRetiroParcial()))
            ->with('proceso:id,codigo')->first()) {
            return sprintf('El pallet está en el proceso de prefrío %s. Retíralo del túnel antes de anular su validación.', $proceso->proceso?->codigo ?? 'activo');
        }
        $loteSag = DB::table('lotes_inspeccion_sag_folios as lf')
            ->join('lotes_inspeccion_sag as l', 'l.id', '=', 'lf.lote_inspeccion_sag_id')
            ->where('lf.folio_id', $folio->id)
            ->whereIn('l.estado', [EstadoLoteInspeccionSag::Preparacion->value, EstadoLoteInspeccionSag::EnInspeccion->value, EstadoLoteInspeccionSag::ResultadoParcial->value])
            ->value('l.codigo');
        if ($loteSag) {
            return "El pallet está en el lote de inspección SAG {$loteSag}. Quítalo del lote antes de anular su validación.";
        }
        if ($folio->habilitacion_almacenamiento === HabilitacionAlmacenamientoFolio::Retenido || $folio->retencionOperacionalActiva()->exists()) {
            return 'El pallet está retenido. Cambia primero su estado de prefrío para liberar la retención.';
        }
        if (DB::table('custodias_temporales_maniobra')->where('bloqueo_folio_id', $folio->id)->exists()
            || $this->tareasPendientes($folio)->contains(fn (TareaMovimiento $tarea): bool => in_array(
                $tarea->estado, [EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::EnProceso], true,
            ))) {
            return 'Un camarero está moviendo este pallet. Espera a que termine la labor.';
        }

        return null;
    }

    /**
     * @param  array{operacion_id: string, motivo_categoria: string, motivo: string}  $datos
     * @return array{anulacion: AnulacionValidacionPallet, numero_archivado: ?string}
     */
    public function anular(Folio $folio, ValidacionPallet $validacion, array $datos, User $usuario): array
    {
        $existente = AnulacionValidacionPallet::query()->where('operacion_id', $datos['operacion_id'])->first();
        if ($existente) {
            return ['anulacion' => $existente, 'numero_archivado' => $existente->snapshot['numero_archivado'] ?? null];
        }
        // Sin actividad posterior: la anulación normal, que deja el número listo para revalidar.
        if ($this->anulacion->puedeAnular($validacion)) {
            $anulacion = $this->anulacion->anular($validacion, $datos, $usuario);
            $this->registrarCambio($folio->refresh(), $anulacion, $datos, $usuario, null);

            return ['anulacion' => $anulacion, 'numero_archivado' => null];
        }

        $payload = ['folio_id' => $folio->id, 'motivo_categoria' => trim($datos['motivo_categoria']), 'motivo' => trim($datos['motivo'])];
        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Administracion,
            tipo: 'validacion.anular_administrativa',
            usuario: $usuario,
            payload: $payload,
            operacionId: $datos['operacion_id'],
            sujetoTipo: ValidacionPallet::class,
            sujetoId: (string) $validacion->id,
            referencia: $folio->numero_folio,
        );

        return $this->motorTransiciones->ejecutar($comando, function () use ($folio, $validacion, $datos, $payload, $usuario): array {
            // Mismo orden de bloqueo que la tablet: cámara, folio y su ubicación.
            $camaraId = UbicacionActual::query()->where('folio_id', $folio->id)->value('camara_id');
            $camara = $camaraId ? Camara::query()->lockForUpdate()->find($camaraId) : null;
            $folio = Folio::query()->lockForUpdate()->findOrFail($folio->id);
            $validacion = ValidacionPallet::query()->lockForUpdate()->findOrFail($validacion->id);
            $ubicacion = UbicacionActual::query()->where('folio_id', $folio->id)->lockForUpdate()->first();
            if (($ubicacion?->camara_id) !== $camara?->id) {
                throw new ConflictoOperacion('El pallet cambió de ubicación mientras se anulaba. Vuelve a intentarlo.');
            }
            if ($motivo = $this->motivoNoAnulable($folio, $validacion)) {
                throw new ConflictoOperacion($motivo);
            }

            $tareasCanceladas = [];
            foreach ($this->tareasPendientes($folio) as $tarea) {
                $cancelada = $this->planes->cancelarPorReplanificacion($tarea, $usuario, "Validación del folio {$folio->numero_folio} anulada por administración.");
                if (! $cancelada || $cancelada->estado !== EstadoTareaMovimiento::Cancelada) {
                    throw new ConflictoOperacion('Una labor con este pallet ya comenzó y no pudo cancelarse. Espera a que termine.');
                }
                $tareasCanceladas[] = $tarea->id;
            }

            $ubicacion?->load(['camara:id,codigo,nombre', 'posicion:id,etiqueta']);
            $archivado = $this->numeroArchivado($folio->numero_folio);
            $ahora = now();
            $snapshot = [
                'tipo' => 'administrativa',
                'numero_archivado' => $archivado,
                'validacion' => [
                    'id' => $validacion->id,
                    'numero_folio' => $validacion->numero_folio,
                    'numero_intento' => $validacion->numero_intento,
                    'resultado' => $validacion->resultado->value,
                    'estado' => $validacion->estado->value,
                    'tipo_bulto' => $validacion->tipo_bulto,
                    'cantidad_cajas' => $validacion->cantidad_cajas,
                    'user_id' => $validacion->user_id,
                    'recibido_servidor_at' => $validacion->recibido_servidor_at?->toAtomString(),
                    'snapshot' => $validacion->snapshot,
                ],
                'folio' => [
                    'id' => $folio->id,
                    'numero_folio' => $folio->numero_folio,
                    'tipo_bulto' => $folio->tipo_bulto?->value,
                    'estado_operacional' => $folio->estado_operacional?->value,
                    'condicion_termica' => $folio->condicion_termica?->value,
                    'habilitacion_almacenamiento' => $folio->habilitacion_almacenamiento?->value,
                    'fecha_ingreso' => $folio->fecha_ingreso?->toAtomString(),
                    'variedad' => $folio->variedad,
                    'calibre' => $folio->calibre,
                    'marca' => $folio->marca,
                    'exportadora' => $folio->exportadora,
                    'datos_externos' => $folio->datos_externos,
                ],
                'ubicacion' => $ubicacion ? [
                    'camara_id' => $ubicacion->camara_id,
                    'camara' => $ubicacion->camara?->nombre ?: $ubicacion->camara?->codigo,
                    'posicion_id' => $ubicacion->posicion_id,
                    'posicion' => $ubicacion->posicion?->etiqueta,
                    'ubicado_at' => $ubicacion->ubicado_at?->toAtomString(),
                ] : null,
                'procesos_prefrio' => ProcesoPrefrioFolio::query()->where('folio_id', $folio->id)->with('proceso:id,codigo')->get()
                    ->map(fn (ProcesoPrefrioFolio $registro): array => [
                        'proceso' => $registro->proceso?->codigo,
                        'estado' => $registro->estado instanceof \BackedEnum ? $registro->estado->value : $registro->estado,
                    ])->all(),
                'movimientos' => $folio->movimientos()->count(),
                'tareas_canceladas' => $tareasCanceladas,
            ];

            if ($ubicacion) {
                $ubicacion->delete();
                $camara?->increment('version_plano');
            }

            $anulacion = AnulacionValidacionPallet::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'validacion_pallet_id' => $validacion->id,
                'folio_id' => $folio->id,
                'numero_folio' => $folio->numero_folio,
                'motivo_categoria' => $payload['motivo_categoria'],
                'motivo' => $payload['motivo'],
                'anulado_por_user_id' => $usuario->id,
                'anulado_at' => $ahora,
                'snapshot' => $snapshot,
            ]);
            $validacion->forceFill([
                'estado' => EstadoValidacionPallet::Anulada,
                'snapshot' => [...($validacion->snapshot ?? []), 'anulacion' => [
                    'id' => $anulacion->id,
                    'operacion_id' => $anulacion->operacion_id,
                    'tipo' => 'administrativa',
                    'motivo_categoria' => $anulacion->motivo_categoria,
                    'motivo' => $anulacion->motivo,
                    'anulado_por_user_id' => $usuario->id,
                    'anulado_at' => $ahora->toAtomString(),
                ]],
            ])->save();

            $numeroOriginal = $folio->numero_folio;
            $folio->forceFill([
                // El número queda libre para el pallet correcto; el anulado conserva uno archivado.
                'numero_folio' => $archivado,
                'activo' => false,
                'estado_operacional' => EstadoOperacionalFolio::Anulado,
                'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
                'fuente_habilitacion_almacenamiento' => null,
                'habilitado_almacenamiento_at' => null,
                'habilitado_almacenamiento_por_user_id' => null,
                'retencion_termica_motivo' => null,
                'datos_externos' => [
                    ...($folio->datos_externos ?? []),
                    'anulacion_validacion_id' => $anulacion->id,
                    'anulacion_validacion_operacion_id' => $anulacion->operacion_id,
                    'anulacion_validacion_categoria' => $anulacion->motivo_categoria,
                    'anulacion_administrativa' => true,
                    'numero_folio_original' => $numeroOriginal,
                    'anulado_por_user_id' => $usuario->id,
                    'anulado_at' => $ahora->toAtomString(),
                ],
            ])->save();

            $this->registrarCambio($folio, $anulacion, $datos, $usuario, $snapshot['folio'] + ['ubicacion' => $snapshot['ubicacion']]);

            return ['anulacion' => $anulacion, 'numero_archivado' => $archivado];
        });
    }

    /** Deja la anulación también en la bitácora del módulo de administración. */
    private function registrarCambio(Folio $folio, AnulacionValidacionPallet $anulacion, array $datos, User $usuario, ?array $antes): void
    {
        $antes ??= $anulacion->snapshot['folio'] ?? [];
        CambioAtributosPt::query()->firstOrCreate(['operacion_id' => $datos['operacion_id']], [
            'temporada_id' => $folio->temporada_id,
            'folio_id' => $folio->id,
            'numero_folio' => $anulacion->numero_folio,
            'payload_hash' => $anulacion->payload_hash,
            'tipo' => CambioAtributosPt::ANULACION,
            'antes' => collect($antes)->only(['numero_folio', 'estado_operacional', 'condicion_termica', 'tipo_bulto', 'exportadora', 'variedad', 'calibre', 'ubicacion'])->all(),
            'despues' => ['numero_folio' => $folio->numero_folio, 'estado_operacional' => $folio->estado_operacional->value, 'activo' => false],
            'motivo' => $anulacion->motivo,
            'user_id' => $usuario->id,
        ]);
    }

    private function participaEnRepaletizaje(Folio $folio): bool
    {
        return DB::table('repaletizaje_detalles')->where('folio_origen_id', $folio->id)->exists()
            || DB::table('repaletizaje_resultados')->where('folio_id', $folio->id)->exists()
            || DB::table('repaletizajes')->where(fn ($consulta) => $consulta
                ->where('folio_resultante_id', $folio->id)
                ->orWhere('folio_conservado_id', $folio->id))->exists();
    }

    /** @return Collection<int, TareaMovimiento> */
    private function tareasPendientes(Folio $folio): Collection
    {
        return TareaMovimiento::query()
            ->where('folio_id', $folio->id)
            ->whereNotIn('estado', [EstadoTareaMovimiento::Completada->value, EstadoTareaMovimiento::Cancelada->value])
            ->orderBy('id')
            ->get();
    }

    private function numeroArchivado(string $numero): string
    {
        for ($intento = 1; ; $intento++) {
            $sufijo = "-ANUL-{$intento}";
            $candidato = mb_substr($numero, 0, 50 - mb_strlen($sufijo)).$sufijo;
            if (! Folio::query()->where('numero_folio', $candidato)->exists()) {
                return $candidato;
            }
        }
    }
}
