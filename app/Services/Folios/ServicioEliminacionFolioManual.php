<?php

namespace App\Services\Folios;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\CargaFolio;
use App\Models\EliminacionFolioManual;
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
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Elimina un pallet o saldo que la tablet del camarero creó sin Validación PT.
 *
 * Solo mientras conserve esa condición: creado a mano en cámara, sin validación y
 * sin haber entrado a una carga, prefrío, repaletizaje, inspección SAG, retención
 * o maniobra. El folio no se borra físicamente: queda anulado e inactivo, libera su
 * posición en el plano y su número (se archiva con un sufijo) para que el pallet
 * real pueda validarse. La eliminación queda registrada con su motivo y snapshot.
 */
class ServicioEliminacionFolioManual
{
    public function __construct(
        private readonly MotorTransicionesOperacionales $motorTransiciones,
        private readonly ServicioPlanesOperacionales $planes,
    ) {}

    /** @param array{operacion_id: string, motivo: string} $datos */
    public function eliminar(Folio $folio, array $datos, User $usuario): EliminacionFolioManual
    {
        $payload = ['folio_id' => $folio->id, 'motivo' => trim($datos['motivo'])];
        $hash = $this->hash($payload);

        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Administracion,
            tipo: 'folio.eliminar_manual',
            usuario: $usuario,
            payload: $payload,
            operacionId: $datos['operacion_id'],
            sujetoTipo: Folio::class,
            sujetoId: (string) $folio->id,
            referencia: $folio->numero_folio,
        );

        return $this->motorTransiciones->ejecutar($comando, function () use ($folio, $datos, $usuario, $payload, $hash): EliminacionFolioManual {
            $existente = EliminacionFolioManual::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if ($existente) {
                if ($existente->folio_id !== $folio->id
                    || $existente->eliminado_por_user_id !== $usuario->id
                    || ! hash_equals($existente->payload_hash, $hash)) {
                    throw new ConflictoOperacion('El UUID de la eliminación ya fue utilizado con datos diferentes.');
                }

                return $this->cargar($existente);
            }

            // Mismo orden de bloqueo que la tablet: cámara, folio y luego su ubicación.
            $camaraId = UbicacionActual::query()->where('folio_id', $folio->id)->value('camara_id');
            $camara = $camaraId ? Camara::query()->lockForUpdate()->find($camaraId) : null;
            $folio = Folio::query()->lockForUpdate()->findOrFail($folio->id);
            $ubicacion = UbicacionActual::query()->where('folio_id', $folio->id)->lockForUpdate()->first();
            if (($ubicacion?->camara_id) !== $camara?->id) {
                throw new ConflictoOperacion('El folio cambió de ubicación mientras se eliminaba. Vuelve a intentarlo.');
            }

            $motivo = $this->motivoNoEliminable($folio);
            if ($motivo !== null) {
                throw new ConflictoOperacion($motivo);
            }

            $ubicacion?->load(['camara:id,codigo', 'posicion:id,etiqueta']);
            $tareasCanceladas = [];
            foreach ($this->tareasActivas($folio) as $tarea) {
                $cancelada = $this->planes->cancelarPorReplanificacion(
                    $tarea,
                    $usuario,
                    "Folio {$folio->numero_folio} eliminado por administración: se creó en cámara sin Validación PT.",
                );
                if (! $cancelada || $cancelada->estado !== EstadoTareaMovimiento::Cancelada) {
                    throw new ConflictoOperacion('Una labor con este folio ya comenzó y no pudo cancelarse. Espera a que termine.');
                }
                $tareasCanceladas[] = $tarea->id;
            }

            $ahora = now();
            $archivado = $this->numeroArchivado($folio->numero_folio);
            $snapshot = [
                'folio' => [
                    'id' => $folio->id,
                    'numero_folio' => $folio->numero_folio,
                    'tipo_bulto' => $folio->tipo_bulto->value,
                    'estado_operacional' => $folio->estado_operacional->value,
                    'condicion_termica' => $folio->condicion_termica?->value,
                    'origen_sistema' => $folio->origen_sistema,
                    'fecha_ingreso' => $folio->fecha_ingreso?->toAtomString(),
                    'variedad' => $folio->variedad,
                    'calibre' => $folio->calibre,
                    'marca' => $folio->marca,
                    'exportadora' => $folio->exportadora,
                    'condicion_sag_id' => $folio->condicion_sag_id,
                    'datos_externos' => $folio->datos_externos,
                ],
                'ubicacion' => $ubicacion ? [
                    'camara_id' => $ubicacion->camara_id,
                    'camara' => $ubicacion->camara?->codigo,
                    'posicion_id' => $ubicacion->posicion_id,
                    'posicion' => $ubicacion->posicion?->etiqueta,
                    'ubicado_at' => $ubicacion->ubicado_at?->toAtomString(),
                ] : null,
                'movimientos' => $folio->movimientos()
                    ->orderBy('recibido_servidor_at')
                    ->get(['id', 'tipo_movimiento', 'user_id', 'dispositivo_id', 'recibido_servidor_at'])
                    ->map(fn ($movimiento): array => [
                        'id' => $movimiento->id,
                        'tipo' => $movimiento->tipo_movimiento->value,
                        'user_id' => $movimiento->user_id,
                        'dispositivo_id' => $movimiento->dispositivo_id,
                        'recibido_servidor_at' => $movimiento->recibido_servidor_at?->toAtomString(),
                    ])->all(),
                'tareas_canceladas' => $tareasCanceladas,
            ];

            if ($ubicacion) {
                $ubicacion->delete();
                $camara->increment('version_plano');
            }

            $eliminacion = EliminacionFolioManual::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'folio_id' => $folio->id,
                'numero_folio' => $folio->numero_folio,
                'numero_folio_archivado' => $archivado,
                'motivo' => $payload['motivo'],
                'eliminado_por_user_id' => $usuario->id,
                'eliminado_at' => $ahora,
                'snapshot' => $snapshot,
            ]);

            $folio->forceFill([
                'numero_folio' => $archivado,
                'activo' => false,
                'estado_operacional' => EstadoOperacionalFolio::Anulado,
                'datos_externos' => [
                    ...($folio->datos_externos ?? []),
                    'eliminacion_manual' => [
                        'id' => $eliminacion->id,
                        'numero_folio' => $eliminacion->numero_folio,
                        'motivo' => $eliminacion->motivo,
                        'eliminado_por_user_id' => $usuario->id,
                        'eliminado_at' => $ahora->toAtomString(),
                    ],
                ],
            ])->save();

            return $this->cargar($eliminacion);
        });
    }

    /** Por qué el folio ya no puede eliminarse; null si conserva la condición. */
    public function motivoNoEliminable(Folio $folio): ?string
    {
        if ($folio->tipo_bulto === TipoBulto::Material) {
            return 'Solo se eliminan pallets o saldos creados en cámara sin validación.';
        }
        if ($folio->origen_sistema !== 'manual') {
            return 'Solo se eliminan folios creados a mano en cámara, sin Validación PT.';
        }
        if (! $folio->activo || $folio->estado_operacional === EstadoOperacionalFolio::Anulado) {
            return 'El folio ya está inactivo.';
        }
        if (ValidacionPallet::query()->where('folio_id', $folio->id)->exists()
            || filled($folio->datos_externos['validacion_id'] ?? null)) {
            return 'El folio tiene una validación registrada y ya no puede eliminarse.';
        }
        if (CargaFolio::query()->where('folio_id', $folio->id)->exists()
            || ReservaCargaFolio::query()->where('folio_id', $folio->id)->exists()) {
            return 'El folio fue asignado a una carga y ya no puede eliminarse.';
        }
        if (ProcesoPrefrioFolio::query()->where('folio_id', $folio->id)->exists()) {
            return 'El folio pasó por prefrío y ya no puede eliminarse.';
        }
        if (DB::table('repaletizaje_detalles')->where('folio_origen_id', $folio->id)->exists()
            || DB::table('repaletizaje_resultados')->where('folio_id', $folio->id)->exists()
            || DB::table('repaletizajes')->where(fn ($consulta) => $consulta
                ->where('folio_resultante_id', $folio->id)
                ->orWhere('folio_conservado_id', $folio->id))->exists()) {
            return 'El folio participó en un repaletizaje y ya no puede eliminarse.';
        }
        if (DB::table('lotes_inspeccion_sag_folios')->where('folio_id', $folio->id)->exists()
            || DB::table('autorizaciones_sag_folio')->where('folio_id', $folio->id)->exists()) {
            return 'El folio tiene registros de inspección SAG y ya no puede eliminarse.';
        }
        if (DB::table('retenciones_operacionales_folios')->where('folio_id', $folio->id)->exists()) {
            return 'El folio tiene una retención registrada y ya no puede eliminarse.';
        }
        if (DB::table('custodias_temporales_maniobra')->where('bloqueo_folio_id', $folio->id)->exists()
            || $this->tareasActivas($folio)->contains(fn (TareaMovimiento $tarea): bool => in_array(
                $tarea->estado,
                [EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::EnProceso],
                true,
            ))) {
            return 'Un camarero está moviendo este folio. Espera a que termine la labor.';
        }

        return null;
    }

    /** @return Collection<int, TareaMovimiento> */
    private function tareasActivas(Folio $folio)
    {
        return TareaMovimiento::query()
            ->where('folio_id', $folio->id)
            ->whereNotIn('estado', [EstadoTareaMovimiento::Completada->value, EstadoTareaMovimiento::Cancelada->value])
            ->orderBy('id')
            ->get();
    }

    /** El número queda libre para el pallet real; el folio eliminado conserva uno archivado. */
    private function numeroArchivado(string $numero): string
    {
        for ($intento = 1; ; $intento++) {
            $sufijo = "-ELIM-{$intento}";
            $candidato = mb_substr($numero, 0, 50 - mb_strlen($sufijo)).$sufijo;
            if (! Folio::query()->where('numero_folio', $candidato)->exists()) {
                return $candidato;
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new DomainException('No fue posible preparar la eliminación del folio.', previous: $exception);
        }
    }

    private function cargar(EliminacionFolioManual $eliminacion): EliminacionFolioManual
    {
        return $eliminacion->refresh()->load(['eliminadoPor:id,name', 'folio:id,numero_folio,estado_operacional,activo']);
    }
}
