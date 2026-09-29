<?php

namespace App\Services\MateriaPrima;

use App\Enums\EstadoSubloteRetornoPacking;
use App\Exceptions\ConflictoOperacion;
use App\Models\BinRetornoPacking;
use App\Models\BinRetornoPackingOrigen;
use App\Models\EntregaFrutaProceso;
use App\Models\LoteMateriaPrima;
use App\Models\ModificacionBinRetornoPacking;
use App\Models\PersonalAccessToken;
use App\Models\RegularizacionRetornoPackingLegacy;
use App\Models\RetornoPacking;
use App\Models\TipoResultadoPacking;
use App\Models\User;
use App\Services\Secuencias\ServicioSecuenciaDocumento;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class ServicioBinRetornoPacking
{
    public function __construct(
        private readonly ServicioSecuenciaDocumento $secuencias,
    ) {}

    /** @param array<string, mixed> $datos */
    public function registrar(array $datos, User $usuario): BinRetornoPacking
    {
        $payload = $this->payloadBin($datos);
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($datos, $usuario, $payload, $hash): BinRetornoPacking {
            $existente = BinRetornoPacking::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();

            if ($existente) {
                if ($existente->payload_hash !== $hash) {
                    throw new ConflictoOperacion(
                        'El identificador de operación ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($existente);
            }

            $temporadaActivaId = $this->temporadaActivaId();
            $origenes = $this->validarOrigenes($payload['origenes']);
            $this->validarCuadratura($payload['kilos_totales'], $origenes);

            $token = $usuario->currentAccessToken();
            $folioProvisional = sprintf(
                'PR-%06d',
                $this->secuencias->reservarSiguiente('bins_retorno_packing'),
            );

            $bin = BinRetornoPacking::create([
                'temporada_id' => $temporadaActivaId,
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'folio_provisional' => $folioProvisional,
                'kilos_totales' => $payload['kilos_totales'],
                'estado' => 'pendiente_regularizacion',
                'registrado_por_user_id' => $usuario->id,
                'dispositivo_id' => $token instanceof PersonalAccessToken
                    ? $token->dispositivo_id
                    : null,
                'registrado_at' => now(),
                'observacion' => $payload['observacion'],
            ]);

            $this->crearOrigenes($bin, $origenes);

            return $this->cargar($bin);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function modificar(
        BinRetornoPacking $bin,
        array $datos,
        User $usuario,
    ): BinRetornoPacking {
        return DB::transaction(function () use ($bin, $datos, $usuario): BinRetornoPacking {
            $bin = BinRetornoPacking::query()->lockForUpdate()->findOrFail($bin->id);
            $origenesPersistidos = $bin->origenes()
                ->lockForUpdate()
                ->get();
            $payload = $this->payloadModificacion($bin, $datos, $origenesPersistidos);
            $hash = $this->hash($payload);

            $modificacionExistente = ModificacionBinRetornoPacking::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();
            if ($modificacionExistente) {
                if ($modificacionExistente->bin_retorno_packing_id !== $bin->id
                    || ! hash_equals($modificacionExistente->payload_hash, $hash)) {
                    throw new ConflictoOperacion(
                        'El identificador de modificación ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($bin);
            }

            if ($bin->anulado_at !== null) {
                throw new ConflictoOperacion('El bin está anulado y no puede modificarse.');
            }

            if ($bin->despacho_comercial_id !== null) {
                throw new ConflictoOperacion('El bin está reservado o despachado comercialmente y no puede modificarse.');
            }

            if ($bin->temporada_id !== $this->temporadaActivaId()) {
                throw new ConflictoOperacion(
                    'El bin no pertenece a la temporada activa y no puede modificarse.',
                );
            }

            $origenesRecibidos = $this->validarOrigenesModificacion(
                $payload['origenes'],
                $origenesPersistidos,
            );

            $this->validarCuadratura(
                $payload['kilos_totales'],
                $origenesRecibidos,
            );

            $regularizado = $bin->regularizado_at !== null;
            $tipo = null;
            if ($regularizado) {
                $folioOcupado = BinRetornoPacking::query()
                    ->where('folio_definitivo_vigente', $payload['folio_definitivo'])
                    ->whereKeyNot($bin->id)
                    ->exists();
                if ($folioOcupado) {
                    throw ValidationException::withMessages([
                        'folio_definitivo' => 'El folio definitivo ya está asociado a otro bin retornado.',
                    ]);
                }

                $tipo = TipoResultadoPacking::query()
                    ->findOrFail($payload['tipo_resultado_packing_id']);
                if (! $tipo->activo && $tipo->id !== $bin->tipo_resultado_packing_id) {
                    throw ValidationException::withMessages([
                        'tipo_resultado_packing_id' => 'La clasificación seleccionada ya no está activa.',
                    ]);
                }

                $this->validarCuadratura(
                    $payload['kilos_totales_definitivos'],
                    $origenesRecibidos->map(
                        fn (array $origen): array => [
                            'kilos_aportados' => $origen['kilos_aportados_definitivos'],
                        ],
                    ),
                );
            }

            $datosAnteriores = $this->snapshotBin($bin, $origenesPersistidos);

            $origenesActualesPorClave = $origenesPersistidos->keyBy('clave_proceso');
            $origenesNuevosPorClave = $origenesRecibidos->keyBy('clave_proceso');
            foreach ($origenesPersistidos as $origen) {
                if (! $origenesNuevosPorClave->has($origen->clave_proceso)) {
                    $origen->delete();
                }
            }
            foreach ($origenesRecibidos as $origenRecibido) {
                $datosOrigen = [
                    'lote_materia_prima_id' => $origenRecibido['lote_materia_prima_id'],
                    'numero_lote' => $origenRecibido['numero_lote'],
                    'numero_orden' => $origenRecibido['numero_orden'],
                    'linea_proceso' => $origenRecibido['linea_proceso'],
                    'turno' => $origenRecibido['turno'],
                    'clave_proceso' => $origenRecibido['clave_proceso'],
                    'kilos_aportados' => $origenRecibido['kilos_aportados'],
                ];
                if ($regularizado) {
                    $datosOrigen['kilos_aportados_definitivos'] =
                        $origenRecibido['kilos_aportados_definitivos'];
                }

                $origenExistente = $origenesActualesPorClave->get(
                    $origenRecibido['clave_proceso'],
                );
                if ($origenExistente) {
                    $origenExistente->update($datosOrigen);
                } else {
                    BinRetornoPackingOrigen::create([
                        'bin_retorno_packing_id' => $bin->id,
                        ...$datosOrigen,
                    ]);
                }
            }

            $datosBin = [
                'kilos_totales' => $payload['kilos_totales'],
                'observacion' => $payload['observacion'],
            ];
            if ($regularizado && $tipo) {
                $datosBin = [
                    ...$datosBin,
                    'folio_definitivo' => $payload['folio_definitivo'],
                    'folio_definitivo_vigente' => $payload['folio_definitivo'],
                    'tipo_resultado_packing_id' => $tipo->id,
                    'nombre_resultado' => $payload['nombre_resultado'] ?? $tipo->nombre,
                    'kilos_totales_definitivos' => $payload['kilos_totales_definitivos'],
                ];
            }
            $bin->update($datosBin);

            $bin->refresh();
            $origenesActualizados = $bin->origenes()->get();
            ModificacionBinRetornoPacking::create([
                'bin_retorno_packing_id' => $bin->id,
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'datos_anteriores' => $datosAnteriores,
                'datos_nuevos' => $this->snapshotBin($bin, $origenesActualizados),
                'motivo' => $payload['motivo'],
                'modificado_por_user_id' => $usuario->id,
                'modificado_at' => now(),
            ]);

            return $this->cargar($bin->fresh());
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function regularizar(
        BinRetornoPacking $bin,
        array $datos,
        User $usuario,
    ): BinRetornoPacking {
        $payload = $this->payloadRegularizacion($datos);
        $hash = $this->hash($payload);

        return DB::transaction(function () use (
            $bin,
            $datos,
            $usuario,
            $payload,
            $hash,
        ): BinRetornoPacking {
            $bin = BinRetornoPacking::query()->lockForUpdate()->findOrFail($bin->id);

            if ($bin->anulado_at !== null) {
                throw new ConflictoOperacion('El bin está anulado y no puede regularizarse.');
            }

            if ($bin->regularizado_at !== null) {
                if ($bin->operacion_regularizacion_id !== $datos['operacion_id']
                    || ! is_string($bin->payload_regularizacion_hash)
                    || ! hash_equals($bin->payload_regularizacion_hash, $hash)) {
                    throw new ConflictoOperacion('El bin ya fue regularizado con otra operación o datos diferentes.');
                }

                return $this->cargar($bin);
            }

            if ($bin->temporada_id !== $this->temporadaActivaId()) {
                throw new ConflictoOperacion(
                    'El bin no pertenece a la temporada activa y no puede regularizarse.',
                );
            }

            $operacionOcupada = BinRetornoPacking::query()
                ->where('operacion_regularizacion_id', $datos['operacion_id'])
                ->whereKeyNot($bin->id)
                ->exists();
            if ($operacionOcupada) {
                throw new ConflictoOperacion(
                    'El identificador de regularización ya fue utilizado en otro bin.',
                );
            }

            $ocupado = BinRetornoPacking::query()
                ->where('folio_definitivo_vigente', $payload['folio_definitivo'])
                ->whereKeyNot($bin->id)
                ->exists();
            if ($ocupado) {
                throw ValidationException::withMessages([
                    'folio_definitivo' => 'El folio definitivo ya está asociado a otro bin retornado.',
                ]);
            }

            $tipo = TipoResultadoPacking::query()
                ->where('activo', true)
                ->findOrFail($payload['tipo_resultado_packing_id']);

            $origenesPersistidos = $bin->origenes()
                ->lockForUpdate()
                ->get();
            $origenesDefinitivos = collect($payload['origenes'])
                ->keyBy('origen_id');
            if ($origenesPersistidos->count() !== $origenesDefinitivos->count()
                || $origenesPersistidos->contains(
                    fn (BinRetornoPackingOrigen $origen): bool => ! $origenesDefinitivos->has($origen->id),
                )) {
                throw ValidationException::withMessages([
                    'origenes' => 'Debes confirmar los kilos definitivos de todos los procesos originales del bin.',
                ]);
            }

            $cuadraturaDefinitiva = $origenesPersistidos->map(
                fn (BinRetornoPackingOrigen $origen): array => [
                    'kilos_aportados' => $origenesDefinitivos[$origen->id]['kilos_aportados_definitivos'],
                ],
            );
            $this->validarCuadratura(
                $payload['kilos_totales_definitivos'],
                $cuadraturaDefinitiva,
            );

            foreach ($origenesPersistidos as $origen) {
                $origen->update([
                    'kilos_aportados_definitivos' => $origenesDefinitivos[$origen->id]['kilos_aportados_definitivos'],
                ]);
            }

            $bin->update([
                'folio_definitivo' => $payload['folio_definitivo'],
                'folio_definitivo_vigente' => $payload['folio_definitivo'],
                'kilos_totales_definitivos' => $payload['kilos_totales_definitivos'],
                'tipo_resultado_packing_id' => $tipo->id,
                'nombre_resultado' => $payload['nombre_resultado'] ?? $tipo->nombre,
                'estado' => 'regularizado',
                'operacion_regularizacion_id' => $datos['operacion_id'],
                'payload_regularizacion_hash' => $hash,
                'regularizado_por_user_id' => $usuario->id,
                'regularizado_at' => now(),
            ]);

            return $this->cargar($bin);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function anular(
        BinRetornoPacking $bin,
        array $datos,
        User $usuario,
    ): BinRetornoPacking {
        $payload = ['motivo' => trim((string) $datos['motivo'])];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($bin, $datos, $usuario, $payload, $hash): BinRetornoPacking {
            $bin = BinRetornoPacking::query()->lockForUpdate()->findOrFail($bin->id);

            if ($bin->anulado_at !== null) {
                if ($bin->operacion_anulacion_id !== $datos['operacion_id']
                    || ! is_string($bin->payload_anulacion_hash)
                    || ! hash_equals($bin->payload_anulacion_hash, $hash)) {
                    throw new ConflictoOperacion(
                        'El bin ya fue anulado con otra operación o un motivo diferente.',
                    );
                }

                return $this->cargar($bin);
            }

            if ($bin->temporada_id !== $this->temporadaActivaId()) {
                throw new ConflictoOperacion(
                    'El bin no pertenece a la temporada activa y no puede anularse.',
                );
            }

            if ($bin->despacho_comercial_id !== null) {
                throw new ConflictoOperacion('El bin está reservado o despachado comercialmente y no puede anularse.');
            }

            $operacionOcupada = BinRetornoPacking::query()
                ->where('operacion_anulacion_id', $datos['operacion_id'])
                ->whereKeyNot($bin->id)
                ->exists();
            if ($operacionOcupada) {
                throw new ConflictoOperacion(
                    'El identificador de anulación ya fue utilizado en otro bin.',
                );
            }

            $bin->update([
                'estado' => 'anulado',
                'folio_definitivo_vigente' => null,
                'operacion_anulacion_id' => $datos['operacion_id'],
                'payload_anulacion_hash' => $hash,
                'anulado_por_user_id' => $usuario->id,
                'anulado_at' => now(),
                'motivo_anulacion' => $payload['motivo'],
            ]);

            return $this->cargar($bin);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function migrarLegacy(
        RetornoPacking $retorno,
        array $datos,
        User $usuario,
    ): BinRetornoPacking {
        $payload = $this->payloadMigracion($datos);
        $hash = $this->hash($payload);

        return DB::transaction(function () use (
            $retorno,
            $datos,
            $usuario,
            $payload,
            $hash,
        ): BinRetornoPacking {
            $retorno = RetornoPacking::query()
                ->with(['entregas.lote', 'resultados'])
                ->lockForUpdate()
                ->findOrFail($retorno->id);

            $regularizacion = RegularizacionRetornoPackingLegacy::query()
                ->where('retorno_packing_id', $retorno->id)
                ->lockForUpdate()
                ->first();
            if ($regularizacion) {
                if ($regularizacion->operacion_id !== $datos['operacion_id']
                    || $regularizacion->payload_hash !== $hash
                    || $regularizacion->accion !== 'migrado'
                    || ! $regularizacion->bin_retorno_packing_id) {
                    throw new ConflictoOperacion('El retorno anterior ya fue regularizado de otra forma.');
                }

                return $this->cargar(
                    BinRetornoPacking::query()->findOrFail($regularizacion->bin_retorno_packing_id),
                );
            }

            if ($retorno->anulado_at !== null) {
                throw new ConflictoOperacion('No se puede migrar un retorno anterior anulado.');
            }
            if ($retorno->resultados->count() !== 1
                || (int) $retorno->resultados->first()->cantidad_bins !== 1) {
                throw ValidationException::withMessages([
                    'retorno' => 'Solo se migra automáticamente un retorno anterior que represente exactamente un bin. Descártalo y vuelve a ingresar sus bins individualmente.',
                ]);
            }

            $origenes = $this->validarOrigenesLegacy($retorno, $payload['origenes']);
            $this->validarCuadratura($payload['kilos_totales'], $origenes);

            $resultado = $retorno->resultados->first();
            $temporadaActivaId = $this->temporadaActivaId();
            $token = $usuario->currentAccessToken();
            $folioProvisional = sprintf(
                'PR-%06d',
                $this->secuencias->reservarSiguiente('bins_retorno_packing'),
            );
            $bin = BinRetornoPacking::create([
                'temporada_id' => $temporadaActivaId,
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'folio_provisional' => $folioProvisional,
                'kilos_totales' => $payload['kilos_totales'],
                'estado' => 'pendiente_regularizacion',
                'retorno_packing_legacy_id' => $retorno->id,
                'registrado_por_user_id' => $usuario->id,
                'dispositivo_id' => $token instanceof PersonalAccessToken
                    ? $token->dispositivo_id
                    : null,
                'registrado_at' => now(),
                'observacion' => sprintf(
                    'Migrado desde %s%s',
                    $retorno->numero,
                    $resultado->numero_sublote ? ' / '.$resultado->numero_sublote : '',
                ),
            ]);
            $this->crearOrigenes($bin, $origenes);

            RegularizacionRetornoPackingLegacy::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'retorno_packing_id' => $retorno->id,
                'bin_retorno_packing_id' => $bin->id,
                'accion' => 'migrado',
                'motivo' => $payload['motivo'],
                'registrado_por_user_id' => $usuario->id,
                'registrado_at' => now(),
            ]);

            return $this->cargar($bin);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function descartarLegacy(
        RetornoPacking $retorno,
        array $datos,
        User $usuario,
    ): void {
        $payload = [
            'accion' => 'descartado',
            'motivo' => trim((string) $datos['motivo']),
        ];
        $hash = $this->hash($payload);

        DB::transaction(function () use ($retorno, $datos, $usuario, $payload, $hash): void {
            $retorno = RetornoPacking::query()
                ->with('resultados')
                ->lockForUpdate()
                ->findOrFail($retorno->id);

            $regularizacion = RegularizacionRetornoPackingLegacy::query()
                ->where('retorno_packing_id', $retorno->id)
                ->lockForUpdate()
                ->first();
            if ($regularizacion) {
                if ($regularizacion->operacion_id === $datos['operacion_id']
                    && $regularizacion->payload_hash === $hash
                    && $regularizacion->accion === 'descartado') {
                    return;
                }

                throw new ConflictoOperacion('El retorno anterior ya fue regularizado.');
            }

            RegularizacionRetornoPackingLegacy::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'retorno_packing_id' => $retorno->id,
                'accion' => 'descartado',
                'motivo' => $payload['motivo'],
                'registrado_por_user_id' => $usuario->id,
                'registrado_at' => now(),
            ]);

            if ($retorno->anulado_at === null) {
                $retorno->update([
                    'operacion_anulacion_id' => $datos['operacion_id'],
                    'anulado_por_user_id' => $usuario->id,
                    'anulado_at' => now(),
                    'motivo_anulacion' => 'Regularización de modelo: '.$payload['motivo'],
                ]);
                $retorno->resultados()->update([
                    'estado' => EstadoSubloteRetornoPacking::Anulado->value,
                    'updated_at' => now(),
                ]);
            }
        }, attempts: 3);
    }

    public function cargar(BinRetornoPacking $bin): BinRetornoPacking
    {
        return $bin->load([
            'origenes.lote:id,numero_lote',
            'tipoResultado:id,codigo,nombre',
            'registradoPor:id,name',
            'regularizadoPor:id,name',
            'anuladoPor:id,name',
            'retornoLegacy:id,numero',
            'ultimaModificacion.modificadoPor:id,name',
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function payloadBin(array $datos): array
    {
        return [
            'kilos_totales' => $this->decimal($datos['kilos_totales']),
            'observacion' => filled($datos['observacion'] ?? null)
                ? trim((string) $datos['observacion'])
                : null,
            'origenes' => $this->normalizarOrigenes($datos['origenes']),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  Collection<int, BinRetornoPackingOrigen>  $origenesPersistidos
     * @return array<string, mixed>
     */
    private function payloadModificacion(
        BinRetornoPacking $bin,
        array $datos,
        Collection $origenesPersistidos,
    ): array {
        $regularizado = $bin->regularizado_at !== null;
        $persistidosPorId = $origenesPersistidos->keyBy('id');
        $origenes = collect($datos['origenes'])
            ->map(function (array $origen) use ($persistidosPorId, $regularizado): array {
                $identidadCompleta = filled($origen['lote_materia_prima_id'] ?? null)
                    && filled($origen['numero_orden'] ?? null)
                    && filled($origen['linea_proceso'] ?? null)
                    && filled($origen['turno'] ?? null);
                $persistido = filled($origen['origen_id'] ?? null)
                    ? $persistidosPorId->get((string) $origen['origen_id'])
                    : null;
                if (! $identidadCompleta && ! $persistido) {
                    throw ValidationException::withMessages([
                        'origenes' => 'Selecciona un proceso válido para cada origen del retorno.',
                    ]);
                }

                $normalizado = [
                    'lote_materia_prima_id' => $identidadCompleta
                        ? (string) $origen['lote_materia_prima_id']
                        : $persistido->lote_materia_prima_id,
                    'numero_orden' => trim((string) (
                        $identidadCompleta ? $origen['numero_orden'] : $persistido->numero_orden
                    )),
                    'linea_proceso' => trim((string) (
                        $identidadCompleta ? $origen['linea_proceso'] : $persistido->linea_proceso
                    )),
                    'turno' => mb_strtoupper(trim((string) (
                        $identidadCompleta ? $origen['turno'] : $persistido->turno
                    ))),
                    'kilos_aportados' => $this->decimal($origen['kilos_aportados']),
                ];
                if ($regularizado) {
                    $normalizado['kilos_aportados_definitivos'] = $this->decimal(
                        $origen['kilos_aportados_definitivos'],
                    );
                }

                return $normalizado;
            })
            ->sortBy(fn (array $origen): string => $this->claveProceso($origen))
            ->values()
            ->all();

        $payload = [
            'motivo' => trim((string) $datos['motivo']),
            'kilos_totales' => $this->decimal($datos['kilos_totales']),
            'observacion' => filled($datos['observacion'] ?? null)
                ? trim((string) $datos['observacion'])
                : null,
            'origenes' => $origenes,
        ];
        if ($regularizado) {
            $payload = [
                ...$payload,
                'folio_definitivo' => mb_strtoupper(trim((string) $datos['folio_definitivo'])),
                'tipo_resultado_packing_id' => (string) $datos['tipo_resultado_packing_id'],
                'nombre_resultado' => filled($datos['nombre_resultado'] ?? null)
                    ? trim((string) $datos['nombre_resultado'])
                    : null,
                'kilos_totales_definitivos' => $this->decimal(
                    $datos['kilos_totales_definitivos'],
                ),
            ];
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $origenes
     * @param  Collection<int, BinRetornoPackingOrigen>  $origenesPersistidos
     * @return Collection<int, array<string, mixed>>
     */
    private function validarOrigenesModificacion(
        array $origenes,
        Collection $origenesPersistidos,
    ): Collection {
        $persistidosPorClave = $origenesPersistidos->keyBy('clave_proceso');
        $vistos = [];
        $nuevos = [];

        foreach ($origenes as $origen) {
            $clave = $this->claveProceso($origen);
            if (isset($vistos[$clave])) {
                throw ValidationException::withMessages([
                    'origenes' => 'No repitas el mismo proceso dentro de un bin.',
                ]);
            }
            $vistos[$clave] = true;
            if (! $persistidosPorClave->has($clave)) {
                $nuevos[] = $origen;
            }
        }

        $nuevosValidados = $this->validarOrigenes($nuevos)->keyBy('clave_proceso');

        return collect($origenes)->map(function (array $origen) use (
            $persistidosPorClave,
            $nuevosValidados,
        ): array {
            $clave = $this->claveProceso($origen);
            $persistido = $persistidosPorClave->get($clave);
            if ($persistido) {
                return [
                    ...$origen,
                    'lote_materia_prima_id' => $persistido->lote_materia_prima_id,
                    'numero_lote' => $persistido->numero_lote,
                    'numero_orden' => $persistido->numero_orden,
                    'linea_proceso' => $persistido->linea_proceso,
                    'turno' => $persistido->turno,
                    'clave_proceso' => $clave,
                ];
            }

            return $nuevosValidados->get($clave);
        });
    }

    /**
     * @param  Collection<int, BinRetornoPackingOrigen>  $origenes
     * @return array<string, mixed>
     */
    private function snapshotBin(BinRetornoPacking $bin, Collection $origenes): array
    {
        return [
            'folio_provisional' => $bin->folio_provisional,
            'folio_definitivo' => $bin->folio_definitivo,
            'kilos_totales' => $this->decimal($bin->kilos_totales),
            'kilos_totales_definitivos' => $bin->kilos_totales_definitivos !== null
                ? $this->decimal($bin->kilos_totales_definitivos)
                : null,
            'tipo_resultado_packing_id' => $bin->tipo_resultado_packing_id,
            'nombre_resultado' => $bin->nombre_resultado,
            'estado' => $bin->estado,
            'observacion' => $bin->observacion,
            'origenes' => $origenes
                ->sortBy('id')
                ->map(fn (BinRetornoPackingOrigen $origen): array => [
                    'origen_id' => $origen->id,
                    'lote_materia_prima_id' => $origen->lote_materia_prima_id,
                    'numero_lote' => $origen->numero_lote,
                    'numero_orden' => $origen->numero_orden,
                    'linea_proceso' => $origen->linea_proceso,
                    'turno' => $origen->turno,
                    'kilos_aportados' => $this->decimal($origen->kilos_aportados),
                    'kilos_aportados_definitivos' => $origen->kilos_aportados_definitivos !== null
                            ? $this->decimal($origen->kilos_aportados_definitivos)
                            : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $datos */
    private function payloadRegularizacion(array $datos): array
    {
        return [
            'folio_definitivo' => mb_strtoupper(trim((string) $datos['folio_definitivo'])),
            'tipo_resultado_packing_id' => (string) $datos['tipo_resultado_packing_id'],
            'nombre_resultado' => filled($datos['nombre_resultado'] ?? null)
                ? trim((string) $datos['nombre_resultado'])
                : null,
            'kilos_totales_definitivos' => $this->decimal($datos['kilos_totales_definitivos']),
            'origenes' => collect($datos['origenes'])
                ->map(fn (array $origen): array => [
                    'origen_id' => (string) $origen['origen_id'],
                    'kilos_aportados_definitivos' => $this->decimal(
                        $origen['kilos_aportados_definitivos'],
                    ),
                ])
                ->sortBy('origen_id')
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $datos */
    private function payloadMigracion(array $datos): array
    {
        return [
            'kilos_totales' => $this->decimal($datos['kilos_totales']),
            'motivo' => filled($datos['motivo'] ?? null)
                ? trim((string) $datos['motivo'])
                : null,
            'origenes' => $this->normalizarOrigenes($datos['origenes']),
        ];
    }

    /** @param array<int, array<string, mixed>> $origenes */
    private function normalizarOrigenes(array $origenes): array
    {
        return collect($origenes)
            ->map(fn (array $origen): array => [
                'lote_materia_prima_id' => (string) $origen['lote_materia_prima_id'],
                'numero_orden' => trim((string) $origen['numero_orden']),
                'linea_proceso' => trim((string) $origen['linea_proceso']),
                'turno' => mb_strtoupper(trim((string) $origen['turno'])),
                'kilos_aportados' => $this->decimal($origen['kilos_aportados']),
            ])
            ->sortBy(fn (array $origen): string => $this->claveProceso($origen))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $origenes
     * @return Collection<int, array<string, mixed>>
     */
    private function validarOrigenes(array $origenes): Collection
    {
        $vistos = [];

        return collect($origenes)->map(function (array $origen) use (&$vistos): array {
            $clave = $this->claveProceso($origen);
            if (isset($vistos[$clave])) {
                throw ValidationException::withMessages([
                    'origenes' => 'No repitas el mismo proceso dentro de un bin.',
                ]);
            }
            $vistos[$clave] = true;

            $lote = LoteMateriaPrima::query()
                ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                ->find($origen['lote_materia_prima_id']);
            if (! $lote) {
                throw ValidationException::withMessages([
                    'origenes' => 'Uno de los lotes de origen no pertenece a la temporada activa.',
                ]);
            }

            $existeProceso = EntregaFrutaProceso::query()
                ->whereNull('anulado_at')
                ->where('lote_materia_prima_id', $lote->id)
                ->where('numero_orden', $origen['numero_orden'])
                ->where('linea_proceso', $origen['linea_proceso'])
                ->where('turno', $origen['turno'])
                ->exists();
            if (! $existeProceso) {
                throw ValidationException::withMessages([
                    'origenes' => sprintf(
                        'No existe una entrega vigente del lote %s para el proceso indicado.',
                        $lote->numero_lote,
                    ),
                ]);
            }

            return [
                ...$origen,
                'numero_lote' => $lote->numero_lote,
                'clave_proceso' => $clave,
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $origenes
     * @return Collection<int, array<string, mixed>>
     */
    private function validarOrigenesLegacy(RetornoPacking $retorno, array $origenes): Collection
    {
        $permitidos = $retorno->entregas
            ->map(fn (EntregaFrutaProceso $entrega): string => $this->claveProceso([
                'lote_materia_prima_id' => $entrega->lote_materia_prima_id,
                'numero_orden' => $entrega->numero_orden,
                'linea_proceso' => $entrega->linea_proceso,
                'turno' => $entrega->turno,
            ]))
            ->flip();

        $validados = $this->validarOrigenes($origenes);
        foreach ($validados as $origen) {
            if (! $permitidos->has($origen['clave_proceso'])) {
                throw ValidationException::withMessages([
                    'origenes' => 'La migración solo puede utilizar procesos que estaban asociados al retorno anterior.',
                ]);
            }
        }

        return $validados;
    }

    /** @param Collection<int, array<string, mixed>> $origenes */
    private function validarCuadratura(string $kilosTotales, Collection $origenes): void
    {
        $total = $this->milesimas($kilosTotales);
        $distribuido = $origenes->sum(
            fn (array $origen): int => $this->milesimas($origen['kilos_aportados']),
        );

        if ($total !== $distribuido) {
            throw ValidationException::withMessages([
                'origenes' => sprintf(
                    'Los kilos distribuidos por proceso (%s kg) deben coincidir exactamente con el peso total del bin (%s kg).',
                    number_format($distribuido / 1000, 3, ',', '.'),
                    number_format($total / 1000, 3, ',', '.'),
                ),
            ]);
        }
    }

    /** @param Collection<int, array<string, mixed>> $origenes */
    private function crearOrigenes(BinRetornoPacking $bin, Collection $origenes): void
    {
        foreach ($origenes as $origen) {
            BinRetornoPackingOrigen::create([
                'bin_retorno_packing_id' => $bin->id,
                'lote_materia_prima_id' => $origen['lote_materia_prima_id'],
                'numero_lote' => $origen['numero_lote'],
                'numero_orden' => $origen['numero_orden'],
                'linea_proceso' => $origen['linea_proceso'],
                'turno' => $origen['turno'],
                'clave_proceso' => $origen['clave_proceso'],
                'kilos_aportados' => $origen['kilos_aportados'],
            ]);
        }
    }

    /** @param array<string, mixed> $origen */
    private function claveProceso(array $origen): string
    {
        return hash('sha256', implode('|', [
            $origen['lote_materia_prima_id'],
            mb_strtoupper(trim((string) $origen['numero_orden'])),
            mb_strtoupper(trim((string) $origen['linea_proceso'])),
            mb_strtoupper(trim((string) $origen['turno'])),
        ]));
    }

    private function temporadaActivaId(): string
    {
        $temporadaId = app(ServicioTemporadaActiva::class)
            ->buscarConBloqueoCompartido()?->id;
        if (! is_string($temporadaId) || $temporadaId === '') {
            throw new ConflictoOperacion(
                'No existe una temporada activa para registrar retornos de Packing.',
            );
        }

        return $temporadaId;
    }

    private function decimal(mixed $valor): string
    {
        return number_format((float) $valor, 3, '.', '');
    }

    private function milesimas(mixed $valor): int
    {
        return (int) round((float) $valor * 1000);
    }

    /** @throws JsonException */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
