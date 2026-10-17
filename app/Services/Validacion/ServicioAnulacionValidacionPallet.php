<?php

namespace App\Services\Validacion;

use App\Enums\CondicionTermicaFolio;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ResultadoValidacionPallet;
use App\Exceptions\ConflictoOperacion;
use App\Models\AnulacionValidacionPallet;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaCargaFolio;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

class ServicioAnulacionValidacionPallet
{
    public function __construct(
        private readonly MotorTransicionesOperacionales $motorTransiciones,
    ) {}

    /** @param array<string, mixed> $datos */
    public function anular(
        ValidacionPallet $validacion,
        array $datos,
        User $usuario,
    ): AnulacionValidacionPallet {
        $payload = $this->normalizar($datos);
        $hash = $this->hash($payload);

        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Administracion,
            tipo: 'validacion.anular',
            usuario: $usuario,
            payload: $payload,
            operacionId: $datos['operacion_id'],
            sujetoTipo: ValidacionPallet::class,
            sujetoId: (string) $validacion->id,
            referencia: $validacion->numero_folio,
        );
        $accion = function () use (
            $validacion,
            $datos,
            $usuario,
            $payload,
            $hash,
        ): AnulacionValidacionPallet {
            $existenteOperacion = AnulacionValidacionPallet::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();

            if ($existenteOperacion) {
                if ($existenteOperacion->validacion_pallet_id !== $validacion->id
                    || $existenteOperacion->anulado_por_user_id !== $usuario->id
                    || ! hash_equals($existenteOperacion->payload_hash, $hash)) {
                    throw new ConflictoOperacion(
                        'El UUID de anulación ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($existenteOperacion);
            }

            $validacionBloqueada = ValidacionPallet::query()
                ->whereKey($validacion->id)
                ->lockForUpdate()
                ->firstOrFail();
            $folio = $validacionBloqueada->folio_id
                ? Folio::query()
                    ->whereKey($validacionBloqueada->folio_id)
                    ->lockForUpdate()
                    ->first()
                : null;

            $anulacionPrevia = AnulacionValidacionPallet::query()
                ->where('validacion_pallet_id', $validacionBloqueada->id)
                ->lockForUpdate()
                ->first();

            if ($anulacionPrevia) {
                throw new ConflictoOperacion('La validación ya fue anulada anteriormente.');
            }

            $motivoNoAnulable = $this->motivoNoAnulable($validacionBloqueada, $folio);
            if ($motivoNoAnulable !== null) {
                throw new ConflictoOperacion($motivoNoAnulable);
            }

            /** @var Folio $folio */
            $snapshot = [
                'validacion' => [
                    'id' => $validacionBloqueada->id,
                    'numero_folio' => $validacionBloqueada->numero_folio,
                    'numero_intento' => $validacionBloqueada->numero_intento,
                    'resultado' => $validacionBloqueada->resultado->value,
                    'estado' => $validacionBloqueada->estado->value,
                    'tipo_bulto' => $validacionBloqueada->tipo_bulto,
                    'cantidad_cajas' => $validacionBloqueada->cantidad_cajas,
                    'linea_proceso' => $validacionBloqueada->linea_proceso,
                    'turno' => $validacionBloqueada->turno,
                    'user_id' => $validacionBloqueada->user_id,
                    'dispositivo_id' => $validacionBloqueada->dispositivo_id,
                    'generado_dispositivo_at' => $validacionBloqueada->generado_dispositivo_at?->toAtomString(),
                    'recibido_servidor_at' => $validacionBloqueada->recibido_servidor_at?->toAtomString(),
                    'snapshot' => $validacionBloqueada->snapshot,
                ],
                'folio' => [
                    'id' => $folio->id,
                    'numero_folio' => $folio->numero_folio,
                    'tipo_bulto' => $folio->tipo_bulto?->value,
                    'estado_operacional' => $folio->estado_operacional?->value,
                    'condicion_termica' => $folio->condicion_termica?->value,
                    'habilitacion_almacenamiento' => $folio->habilitacion_almacenamiento?->value,
                    'activo' => $folio->activo,
                    'variedad' => $folio->variedad,
                    'calibre' => $folio->calibre,
                    'marca' => $folio->marca,
                    'exportadora' => $folio->exportadora,
                    'origen_sistema' => $folio->origen_sistema,
                    'identificador_externo' => $folio->identificador_externo,
                    'datos_externos' => $folio->datos_externos,
                ],
            ];

            $anulacion = AnulacionValidacionPallet::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'validacion_pallet_id' => $validacionBloqueada->id,
                'folio_id' => $folio->id,
                'numero_folio' => $folio->numero_folio,
                'motivo_categoria' => $payload['motivo_categoria'],
                'motivo' => $payload['motivo'],
                'anulado_por_user_id' => $usuario->id,
                'anulado_at' => now(),
                'snapshot' => $snapshot,
            ]);

            $snapshotValidacion = $validacionBloqueada->snapshot ?? [];
            $snapshotValidacion['anulacion'] = [
                'id' => $anulacion->id,
                'operacion_id' => $anulacion->operacion_id,
                'motivo_categoria' => $anulacion->motivo_categoria,
                'motivo' => $anulacion->motivo,
                'anulado_por_user_id' => $usuario->id,
                'anulado_at' => $anulacion->anulado_at?->toAtomString(),
            ];
            $validacionBloqueada->forceFill([
                'estado' => EstadoValidacionPallet::Anulada,
                'snapshot' => $snapshotValidacion,
            ])->save();

            $datosExternos = $folio->datos_externos ?? [];
            $datosExternos['anulacion_validacion_id'] = $anulacion->id;
            $datosExternos['anulacion_validacion_operacion_id'] = $anulacion->operacion_id;
            $datosExternos['anulacion_validacion_categoria'] = $anulacion->motivo_categoria;
            $datosExternos['anulado_por_user_id'] = $usuario->id;
            $datosExternos['anulado_at'] = $anulacion->anulado_at?->toAtomString();

            $folio->forceFill([
                'activo' => false,
                'estado_operacional' => EstadoOperacionalFolio::Anulado,
                'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
                'fuente_habilitacion_almacenamiento' => null,
                'habilitado_almacenamiento_at' => null,
                'habilitado_almacenamiento_por_user_id' => null,
                'retencion_termica_motivo' => null,
                'datos_externos' => $datosExternos,
            ])->save();

            return $this->cargar($anulacion->refresh());
        };

        return $this->motorTransiciones->ejecutar($comando, $accion);
    }

    public function puedeAnular(ValidacionPallet $validacion): bool
    {
        $folio = $validacion->relationLoaded('folio')
            ? $validacion->folio
            : ($validacion->folio_id ? Folio::query()->find($validacion->folio_id) : null);

        return $this->motivoNoAnulable($validacion, $folio) === null;
    }

    /**
     * Las anulables entre muchas validaciones (con su folio cargado), con una consulta por
     * tipo de actividad posterior en vez de siete por pallet. Mismas reglas que
     * motivoNoAnulable().
     *
     * @param  Collection<int, ValidacionPallet>  $validaciones
     * @return Collection<int, ValidacionPallet>
     */
    public function anulables(Collection $validaciones): Collection
    {
        $porEstado = $validaciones
            ->filter(fn (ValidacionPallet $validacion): bool => $this->motivoPorEstado($validacion, $validacion->folio) === null)
            ->values();
        $folioIds = $porEstado->pluck('folio_id')->unique()->values()->all();
        if ($folioIds === []) {
            return collect();
        }
        $conActividad = collect([
            UbicacionActual::query()->whereIn('folio_id', $folioIds)->pluck('folio_id'),
            CargaFolio::query()->whereIn('folio_id', $folioIds)->pluck('folio_id'),
            ReservaCargaFolio::query()->whereIn('folio_id', $folioIds)->pluck('folio_id'),
            Movimiento::query()->whereIn('folio_id', $folioIds)->distinct()->pluck('folio_id'),
            ProcesoPrefrioFolio::query()->whereIn('folio_id', $folioIds)->distinct()->pluck('folio_id'),
            DB::table('repaletizaje_detalles')->whereIn('folio_origen_id', $folioIds)->pluck('folio_origen_id'),
            DB::table('repaletizajes')->whereIn('folio_resultante_id', $folioIds)->pluck('folio_resultante_id'),
            DB::table('repaletizajes')->whereIn('folio_conservado_id', $folioIds)->pluck('folio_conservado_id'),
        ])->flatten()->filter()->flip();

        return $porEstado
            ->reject(fn (ValidacionPallet $validacion): bool => $conActividad->has($validacion->folio_id))
            ->values();
    }

    public function motivoNoAnulable(
        ValidacionPallet $validacion,
        ?Folio $folio = null,
    ): ?string {
        return $this->motivoPorEstado($validacion, $folio) ?? $this->motivoPorActividad($folio);
    }

    /** Lo que se decide con la validación y el folio, sin consultar su actividad. */
    private function motivoPorEstado(ValidacionPallet $validacion, ?Folio $folio): ?string
    {
        if ($validacion->estado !== EstadoValidacionPallet::Aceptada
            || $validacion->resultado !== ResultadoValidacionPallet::Aprobado) {
            return 'Solo se pueden anular validaciones aprobadas y vigentes.';
        }

        if (! $folio) {
            return 'La validación no conserva el folio asociado.';
        }

        if (! $folio->activo) {
            return 'El folio ya se encuentra inactivo.';
        }

        if ($folio->origen_sistema !== 'validacion'
            || ($folio->datos_externos['validacion_id'] ?? null) !== $validacion->id) {
            return 'El folio ya no corresponde directamente a esta validación.';
        }

        if ($folio->estado_operacional !== EstadoOperacionalFolio::PendientePrefrio
            || $folio->condicion_termica !== CondicionTermicaFolio::PendientePrefrio
            || $folio->habilitacion_almacenamiento !== HabilitacionAlmacenamientoFolio::NoHabilitado) {
            return 'El pallet ya avanzó desde el estado pendiente de prefrío y no puede anularse.';
        }

        return null;
    }

    /** Actividad posterior a la validación: ubicación, carga, movimientos, prefrío o repaletizaje. */
    private function motivoPorActividad(Folio $folio): ?string
    {
        if ($folio->ubicacionActual()->exists()) {
            return 'El pallet ya posee o poseyó una ubicación incompatible con la anulación.';
        }

        if ($folio->asignacionesCarga()->exists() || $folio->reservaCargaActual()->exists()) {
            return 'El pallet ya fue incorporado o reservado para una carga.';
        }

        if ($folio->movimientos()->exists()) {
            return 'El pallet ya posee movimientos de cámara y no puede anularse.';
        }

        if ($folio->procesosPrefrio()->exists()) {
            return 'El pallet ya ingresó a un proceso de prefrío y no puede anularse.';
        }

        $participaRepa = DB::table('repaletizaje_detalles')
            ->where('folio_origen_id', $folio->id)
            ->exists()
            || DB::table('repaletizajes')
                ->where(function ($consulta) use ($folio): void {
                    $consulta
                        ->where('folio_resultante_id', $folio->id)
                        ->orWhere('folio_conservado_id', $folio->id);
                })
                ->exists();

        if ($participaRepa) {
            return 'El pallet ya participó en un repaletizaje y no puede anularse.';
        }

        return null;
    }

    /** @param array<string, mixed> $datos */
    private function normalizar(array $datos): array
    {
        return [
            'motivo_categoria' => trim((string) $datos['motivo_categoria']),
            'motivo' => trim((string) $datos['motivo']),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new DomainException(
                'No fue posible preparar la operación de anulación.',
                previous: $exception,
            );
        }
    }

    private function cargar(AnulacionValidacionPallet $anulacion): AnulacionValidacionPallet
    {
        return $anulacion->load([
            'validacion.usuario:id,name',
            'folio:id,numero_folio,estado_operacional,condicion_termica,activo',
            'anuladoPor:id,name',
        ]);
    }
}
