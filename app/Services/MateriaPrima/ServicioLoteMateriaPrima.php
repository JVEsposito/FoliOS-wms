<?php

namespace App\Services\MateriaPrima;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoHidrocoolerMateriaPrima;
use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\AsignacionCamaraLoteMateriaPrima;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\EventoLoteMateriaPrima;
use App\Models\LoteMateriaPrima;
use App\Models\ProcesoHidrocoolerMateriaPrima;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Temporadas\GuardiaTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class ServicioLoteMateriaPrima
{
    public function __construct(private readonly GuardiaTemporadaActiva $guardiaTemporada) {}

    /** @param array<string, mixed> $datos */
    public function crear(array $datos, User $usuario): LoteMateriaPrima
    {
        $hash = $this->hash($this->payloadLote($datos));

        return DB::transaction(function () use ($datos, $usuario, $hash): LoteMateriaPrima {
            $existente = LoteMateriaPrima::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();
            if ($existente) {
                if (! hash_equals($existente->payload_hash, $hash)) {
                    throw new ConflictoOperacion(
                        'El identificador de creación ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($existente);
            }

            $preparados = $this->prepararDatos($datos);
            $this->asegurarNumeroUnico(
                $preparados['recepcion']->temporada_id,
                $preparados['recepcion']->cliente_id,
                $datos['numero_lote'],
            );
            $this->asegurarDisponibilidadSegmento(
                $preparados['segmento'],
            );
            $lote = LoteMateriaPrima::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                ...$this->atributosLote($datos, $preparados),
                'estado' => EstadoLoteMateriaPrima::Borrador,
                'version' => 1,
                'creado_por_user_id' => $usuario->id,
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $this->guardarEnvases($lote, $preparados['segmento'], $preparados['recepcion']);
            $this->actualizarEstadoSegmento($preparados['segmento']);
            $this->registrarEvento(
                $lote,
                'lote_creado',
                $usuario,
                $datos['operacion_id'],
                null,
                EstadoLoteMateriaPrima::Borrador,
                ['payload_hash' => $hash],
            );

            return $this->cargar($lote);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(
        LoteMateriaPrima $lote,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        $hash = $this->hash($this->payloadLote($datos));

        return DB::transaction(function () use ($lote, $datos, $usuario, $hash): LoteMateriaPrima {
            $lote = LoteMateriaPrima::query()->lockForUpdate()->findOrFail($lote->id);
            $evento = EventoLoteMateriaPrima::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if ($evento) {
                $this->asegurarEventoIdempotente($evento, $lote, 'lote_actualizado', $hash);

                return $this->cargar($lote);
            }
            if (! $lote->estado->esEditable()) {
                throw new ConflictoOperacion('Solo un lote en borrador puede editarse.');
            }
            if ($lote->version !== (int) $datos['version_conocida']) {
                throw new ConflictoOperacion('El lote cambió desde la última lectura.');
            }

            $preparados = $this->prepararDatos($datos);
            $this->asegurarNumeroUnico(
                $preparados['recepcion']->temporada_id,
                $preparados['recepcion']->cliente_id,
                $datos['numero_lote'],
                $lote->id,
            );
            $this->asegurarDisponibilidadSegmento(
                $preparados['segmento'],
                $lote->id,
            );
            $lote->update([
                ...$this->atributosLote([...$datos, 'lote_id_para_calculo' => $lote->id], $preparados),
                'version' => $lote->version + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $this->guardarEnvases($lote, $preparados['segmento'], $preparados['recepcion']);
            $this->registrarEvento(
                $lote,
                'lote_actualizado',
                $usuario,
                $datos['operacion_id'],
                EstadoLoteMateriaPrima::Borrador,
                EstadoLoteMateriaPrima::Borrador,
                ['payload_hash' => $hash, 'version' => $lote->version],
            );

            return $this->cargar($lote);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function corregirOrigen(
        LoteMateriaPrima $lote,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        $payload = [
            'cuartel' => $datos['cuartel'] ?? null,
            'retirar_calibre' => (bool) $datos['retirar_calibre'],
        ];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($lote, $datos, $usuario, $hash): LoteMateriaPrima {
            $lote = LoteMateriaPrima::query()->lockForUpdate()->findOrFail($lote->id);
            $evento = EventoLoteMateriaPrima::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if ($evento) {
                $this->asegurarEventoIdempotente(
                    $evento,
                    $lote,
                    'origen_corregido',
                    $hash,
                );

                return $this->cargar($lote);
            }
            if ($lote->estado === EstadoLoteMateriaPrima::Anulado) {
                throw new ConflictoOperacion('Un lote anulado no puede corregirse.');
            }
            if ($lote->version !== (int) $datos['version_conocida']) {
                throw new ConflictoOperacion('El lote cambió desde la última lectura.');
            }

            $estado = $lote->estado;
            $anterior = [
                'cuartel' => $lote->cuartel,
                'calibre_validacion_id' => $lote->calibre_validacion_id,
                'calibre' => $lote->calibre_snapshot,
            ];
            $cuartel = filled($datos['cuartel'] ?? null)
                ? trim((string) $datos['cuartel'])
                : null;
            $atributos = [
                'cuartel' => $cuartel,
                'version' => $lote->version + 1,
                'actualizado_por_user_id' => $usuario->id,
            ];
            if ((bool) $datos['retirar_calibre']) {
                $atributos['calibre_validacion_id'] = null;
                $atributos['calibre_snapshot'] = null;
            }
            $lote->update($atributos);
            $this->registrarEvento(
                $lote,
                'origen_corregido',
                $usuario,
                $datos['operacion_id'],
                $estado,
                $estado,
                [
                    'payload_hash' => $hash,
                    'anterior' => $anterior,
                    'nuevo' => [
                        'cuartel' => $lote->cuartel,
                        'calibre_validacion_id' => $lote->calibre_validacion_id,
                        'calibre' => $lote->calibre_snapshot,
                    ],
                    'version' => $lote->version,
                ],
            );

            return $this->cargar($lote);
        }, attempts: 3);
    }

    public function confirmar(
        LoteMateriaPrima $lote,
        string $operacionId,
        int $versionConocida,
        User $usuario,
    ): LoteMateriaPrima {
        return DB::transaction(function () use (
            $lote,
            $operacionId,
            $versionConocida,
            $usuario,
        ): LoteMateriaPrima {
            $lote = LoteMateriaPrima::query()->lockForUpdate()->findOrFail($lote->id);
            $evento = EventoLoteMateriaPrima::query()
                ->where('operacion_id', $operacionId)
                ->first();
            if ($evento) {
                if ($evento->lote_materia_prima_id !== $lote->id
                    || $evento->tipo !== 'lote_confirmado') {
                    throw new ConflictoOperacion(
                        'El identificador de operación ya fue utilizado con otra acción.',
                    );
                }

                return $this->cargar($lote);
            }
            if ($lote->estado !== EstadoLoteMateriaPrima::Borrador) {
                throw new ConflictoOperacion('El lote ya no se encuentra en borrador.');
            }
            if ($lote->version !== $versionConocida) {
                throw new ConflictoOperacion('El lote cambió desde la última lectura.');
            }

            $segmento = SegmentoValidacionMp::query()
                ->with(['envases', 'validacion'])
                ->lockForUpdate()
                ->findOrFail($lote->segmento_validacion_mp_id);
            $recepcion = RecepcionRomana::query()
                ->lockForUpdate()
                ->findOrFail($segmento->validacion->recepcion_romana_id);
            $this->asegurarDisponibilidadSegmento($segmento, $lote->id);
            if ($recepcion->peso_neto === null
                || $lote->kilos_netos_calculados === null
                || $lote->kilos_netos_confirmados === null) {
                throw new ConflictoOperacion('El lote está pendiente de destare. Cierra el destare en Romana antes de confirmarlo.');
            }
            $this->asegurarKilosRecepcion(
                $recepcion,
                (float) $lote->kilos_netos_confirmados,
                $lote->id,
            );

            $nuevoEstado = $lote->requiere_hidrocooler
                ? EstadoLoteMateriaPrima::PendienteHidrocooler
                : EstadoLoteMateriaPrima::PendienteAsignacion;
            $lote->update([
                'estado' => $nuevoEstado,
                'version' => $lote->version + 1,
                'confirmado_por_user_id' => $usuario->id,
                'confirmado_at' => now(),
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $lote,
                'lote_confirmado',
                $usuario,
                $operacionId,
                EstadoLoteMateriaPrima::Borrador,
                $nuevoEstado,
                [
                    'requiere_hidrocooler' => $lote->requiere_hidrocooler,
                    'kilos_netos_calculados' => $lote->kilos_netos_calculados,
                    'kilos_netos_confirmados' => $lote->kilos_netos_confirmados,
                ],
            );
            $this->actualizarEstadoSegmento($segmento);

            return $this->cargar($lote);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function iniciarHidrocooler(
        LoteMateriaPrima $lote,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        $hash = $this->hash($this->payloadHidrocooler($datos));

        return DB::transaction(function () use ($lote, $datos, $usuario, $hash): LoteMateriaPrima {
            $lote = LoteMateriaPrima::query()->lockForUpdate()->findOrFail($lote->id);
            $existente = ProcesoHidrocoolerMateriaPrima::query()
                ->where('operacion_inicio_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();
            if ($existente) {
                if ($existente->lote_materia_prima_id !== $lote->id
                    || ($existente->payload_inicio_hash !== null
                        && ! hash_equals($existente->payload_inicio_hash, $hash))) {
                    throw new ConflictoOperacion(
                        'El identificador de inicio ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($lote);
            }
            if ($lote->estado !== EstadoLoteMateriaPrima::PendienteHidrocooler) {
                throw new ConflictoOperacion('El lote no está pendiente de hidrocooler.');
            }
            if (ProcesoHidrocoolerMateriaPrima::query()
                ->where('lote_materia_prima_id', $lote->id)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictoOperacion('El lote ya posee un ciclo de hidrocooler registrado.');
            }

            if ($datos['control_inicial_conforme'] !== true
                || $datos['condicion_visual_agua'] !== 'conforme'
                || $datos['dosificador_operativo'] !== true) {
                throw ValidationException::withMessages([
                    'control_inicial_conforme' => 'No inicies el ciclo con agua o dosificador no conformes. Corrige y vuelve a medir antes de procesar fruta.',
                ]);
            }

            $equipo = trim($datos['equipo']);
            if (ProcesoHidrocoolerMateriaPrima::query()
                ->where('estado', EstadoHidrocoolerMateriaPrima::EnCurso->value)
                ->whereRaw('LOWER(equipo) = ?', [mb_strtolower($equipo)])
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'equipo' => 'El equipo seleccionado ya posee otro lote en curso.',
                ]);
            }

            $inicio = CarbonImmutable::parse($datos['inicio_at']);
            ProcesoHidrocoolerMateriaPrima::create([
                'lote_materia_prima_id' => $lote->id,
                'operacion_inicio_id' => $datos['operacion_id'],
                'payload_inicio_hash' => $hash,
                'codigo' => 'HC-'.now()->format('Ymd').'-'.strtoupper(
                    str_replace('-', '', $datos['operacion_id']),
                ),
                'estado' => EstadoHidrocoolerMateriaPrima::EnCurso,
                'equipo' => $equipo,
                'turno' => $datos['turno'],
                'cantidad_bombas_funcionando' => $datos['cantidad_bombas_funcionando'],
                'equipo_activo_clave' => hash('sha256', mb_strtolower($equipo)),
                'operador_snapshot' => $usuario->name,
                'cantidad_envases_snapshot' => $lote->cantidad_envases_primarios,
                'kilos_netos_snapshot' => $lote->kilos_netos_confirmados,
                'inicio_at' => $inicio,
                'temperatura_inicial_c' => round((float) $datos['temperatura_inicial_c'], 2),
                'temperatura_objetivo_c' => round((float) $datos['temperatura_objetivo_c'], 2),
                'temperatura_agua_inicial_c' => filled($datos['temperatura_agua_inicial_c'] ?? null)
                    ? round((float) $datos['temperatura_agua_inicial_c'], 2)
                    : null,
                'cloro_libre_ppm' => round((float) $datos['cloro_libre_ppm'], 2),
                'ph_agua' => round((float) $datos['ph_agua'], 2),
                'control_inicial_conforme' => $datos['control_inicial_conforme'],
                'condicion_visual_agua' => $datos['condicion_visual_agua'],
                'dosificador_operativo' => $datos['dosificador_operativo'],
                'manejo_agua' => $datos['manejo_agua'],
                'observacion_inicio' => $datos['observacion_inicio'] ?? null,
                'iniciado_por_user_id' => $usuario->id,
            ]);
            $lote->update([
                'estado' => EstadoLoteMateriaPrima::HidrocoolerEnCurso,
                'version' => $lote->version + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $lote,
                'hidrocooler_iniciado',
                $usuario,
                $datos['operacion_id'],
                EstadoLoteMateriaPrima::PendienteHidrocooler,
                EstadoLoteMateriaPrima::HidrocoolerEnCurso,
                [
                    'equipo' => $equipo,
                    'turno' => $datos['turno'],
                    'cantidad_bombas_funcionando' => $datos['cantidad_bombas_funcionando'],
                    'operador' => $usuario->name,
                    'inicio_at' => $inicio->toAtomString(),
                    'cantidad_envases' => $lote->cantidad_envases_primarios,
                    'kilos_netos' => (float) $lote->kilos_netos_confirmados,
                    'temperatura_inicial_c' => (float) $datos['temperatura_inicial_c'],
                    'temperatura_objetivo_c' => (float) $datos['temperatura_objetivo_c'],
                    'temperatura_agua_inicial_c' => filled($datos['temperatura_agua_inicial_c'] ?? null)
                        ? (float) $datos['temperatura_agua_inicial_c']
                        : null,
                    'cloro_libre_ppm' => (float) $datos['cloro_libre_ppm'],
                    'ph_agua' => (float) $datos['ph_agua'],
                    'control_inicial_conforme' => (bool) $datos['control_inicial_conforme'],
                    'condicion_visual_agua' => $datos['condicion_visual_agua'],
                    'dosificador_operativo' => (bool) $datos['dosificador_operativo'],
                    'manejo_agua' => $datos['manejo_agua'],
                    'payload_hash' => $hash,
                ],
            );

            return $this->cargar($lote);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function completarHidrocooler(
        LoteMateriaPrima $lote,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        $hash = $this->hash($this->payloadHidrocooler($datos));

        return DB::transaction(function () use ($lote, $datos, $usuario, $hash): LoteMateriaPrima {
            $lote = LoteMateriaPrima::query()->lockForUpdate()->findOrFail($lote->id);
            $proceso = ProcesoHidrocoolerMateriaPrima::query()
                ->where('lote_materia_prima_id', $lote->id)
                ->lockForUpdate()
                ->firstOrFail();
            $procesoConOperacion = ProcesoHidrocoolerMateriaPrima::query()
                ->where('operacion_termino_id', $datos['operacion_id'])
                ->first();
            if ($procesoConOperacion
                && $procesoConOperacion->lote_materia_prima_id !== $lote->id) {
                throw new ConflictoOperacion(
                    'El identificador de término ya fue utilizado en otro lote.',
                );
            }
            if ($proceso->operacion_termino_id !== null) {
                if ($proceso->operacion_termino_id !== $datos['operacion_id']
                    || ($proceso->payload_termino_hash !== null
                        && ! hash_equals($proceso->payload_termino_hash, $hash))) {
                    throw new ConflictoOperacion(
                        'El hidrocooler ya fue completado con otra operación o datos.',
                    );
                }

                return $this->cargar($lote);
            }
            if ($lote->estado !== EstadoLoteMateriaPrima::HidrocoolerEnCurso
                || $proceso->estado !== EstadoHidrocoolerMateriaPrima::EnCurso) {
                throw new ConflictoOperacion('El lote no posee un hidrocooler en curso.');
            }

            $termino = CarbonImmutable::parse($datos['termino_at']);
            $inicio = CarbonImmutable::instance($proceso->inicio_at);
            if ($termino->lessThanOrEqualTo($inicio)) {
                throw ValidationException::withMessages([
                    'termino_at' => 'El término debe ser posterior al inicio del hidrocooler.',
                ]);
            }
            $duracion = (int) ceil(($termino->getTimestamp() - $inicio->getTimestamp()) / 60);
            $destino = $datos['destino_salida'];
            if ($destino === 'proceso' && $lote->envase_primario !== TipoEnvaseRomana::Bins) {
                throw ValidationException::withMessages([
                    'destino_salida' => 'La salida directa a proceso solo admite lotes en bins.',
                ]);
            }
            $motivos = [];
            if ($proceso->control_inicial_conforme !== true
                || $proceso->condicion_visual_agua !== 'conforme'
                || $proceso->dosificador_operativo !== true) {
                $motivos[] = 'Control inicial de agua o dosificador con desviación';
            }
            if ($datos['control_final_conforme'] !== true
                || $datos['condicion_visual_agua_final'] !== 'conforme'
                || $datos['dosificador_operativo_final'] !== true) {
                $motivos[] = 'Control final de agua o dosificador con desviación';
            }
            if ((float) $datos['temperatura_c'] > (float) $proceso->temperatura_objetivo_c) {
                $motivos[] = 'Temperatura final de fruta superior al objetivo';
            }
            $estadoNuevo = $motivos !== []
                ? EstadoLoteMateriaPrima::HidrocoolerRetenido
                : ($destino === 'proceso'
                    ? EstadoLoteMateriaPrima::DisponibleProceso
                    : EstadoLoteMateriaPrima::PendienteAsignacion);
            $proceso->update([
                'operacion_termino_id' => $datos['operacion_id'],
                'payload_termino_hash' => $hash,
                'estado' => EstadoHidrocoolerMateriaPrima::Completado,
                'equipo_activo_clave' => null,
                'termino_at' => $termino,
                'duracion_minutos' => $duracion,
                'temperatura_c' => round((float) $datos['temperatura_c'], 2),
                'temperatura_agua_final_c' => filled($datos['temperatura_agua_final_c'] ?? null)
                    ? round((float) $datos['temperatura_agua_final_c'], 2)
                    : null,
                'cloro_libre_final_ppm' => round((float) $datos['cloro_libre_final_ppm'], 2),
                'ph_agua_final' => round((float) $datos['ph_agua_final'], 2),
                'condicion_visual_agua_final' => $datos['condicion_visual_agua_final'],
                'dosificador_operativo_final' => $datos['dosificador_operativo_final'],
                'control_final_conforme' => $datos['control_final_conforme'],
                'motivo_retencion' => $motivos !== [] ? implode('; ', $motivos) : null,
                'destino_salida' => $destino,
                'observacion' => $datos['observacion'] ?? null,
                'accion_correctiva' => $datos['accion_correctiva'] ?? null,
                'completado_por_user_id' => $usuario->id,
            ]);
            $lote->update([
                'estado' => $estadoNuevo,
                'version' => $lote->version + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $lote,
                'hidrocooler_completado',
                $usuario,
                $datos['operacion_id'],
                EstadoLoteMateriaPrima::HidrocoolerEnCurso,
                $estadoNuevo,
                [
                    'equipo' => $proceso->equipo,
                    'inicio_at' => $inicio->toAtomString(),
                    'termino_at' => $termino->toAtomString(),
                    'duracion_minutos' => $duracion,
                    'temperatura_c' => (float) $proceso->temperatura_c,
                    'temperatura_agua_final_c' => $proceso->temperatura_agua_final_c !== null
                        ? (float) $proceso->temperatura_agua_final_c
                        : null,
                    'destino_salida' => $destino,
                    'cloro_libre_final_ppm' => (float) $datos['cloro_libre_final_ppm'],
                    'ph_agua_final' => (float) $datos['ph_agua_final'],
                    'condicion_visual_agua_final' => $datos['condicion_visual_agua_final'],
                    'dosificador_operativo_final' => (bool) $datos['dosificador_operativo_final'],
                    'control_final_conforme' => (bool) $datos['control_final_conforme'],
                    'motivo_retencion' => $proceso->motivo_retencion,
                    'accion_correctiva' => $datos['accion_correctiva'] ?? null,
                    'payload_hash' => $hash,
                ],
            );

            return $this->cargar($lote);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function liberarHidrocooler(
        LoteMateriaPrima $lote,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        $hash = $this->hash($this->payloadHidrocooler($datos));

        return DB::transaction(function () use ($lote, $datos, $usuario, $hash): LoteMateriaPrima {
            $lote = LoteMateriaPrima::query()->lockForUpdate()->findOrFail($lote->id);
            $proceso = ProcesoHidrocoolerMateriaPrima::query()
                ->where('lote_materia_prima_id', $lote->id)
                ->lockForUpdate()->firstOrFail();
            $operacion = ProcesoHidrocoolerMateriaPrima::query()
                ->where('operacion_liberacion_id', $datos['operacion_id'])->first();
            if ($operacion && $operacion->id !== $proceso->id) {
                throw new ConflictoOperacion('El identificador de liberación ya fue utilizado en otro lote.');
            }
            if ($proceso->operacion_liberacion_id !== null) {
                if ($proceso->operacion_liberacion_id !== $datos['operacion_id']
                    || ! hash_equals($proceso->payload_liberacion_hash, $hash)) {
                    throw new ConflictoOperacion('El lote ya fue liberado con otra operación o datos.');
                }

                return $this->cargar($lote);
            }
            if ($lote->estado !== EstadoLoteMateriaPrima::HidrocoolerRetenido
                || $proceso->estado !== EstadoHidrocoolerMateriaPrima::Completado
                || ! $proceso->destino_salida) {
                throw new ConflictoOperacion('El lote no está retenido tras completar Hidrocooler.');
            }
            if ((float) $datos['temperatura_verificacion_c'] > (float) $proceso->temperatura_objetivo_c) {
                throw ValidationException::withMessages([
                    'temperatura_verificacion_c' => 'La fruta sigue sobre la temperatura objetivo; no puede liberarse.',
                ]);
            }

            $estadoNuevo = $proceso->destino_salida === 'proceso'
                ? EstadoLoteMateriaPrima::DisponibleProceso
                : EstadoLoteMateriaPrima::PendienteAsignacion;
            $proceso->update([
                'operacion_liberacion_id' => $datos['operacion_id'],
                'payload_liberacion_hash' => $hash,
                'temperatura_verificacion_c' => round((float) $datos['temperatura_verificacion_c'], 2),
                'cloro_libre_verificacion_ppm' => round((float) $datos['cloro_libre_verificacion_ppm'], 2),
                'ph_agua_verificacion' => round((float) $datos['ph_agua_verificacion'], 2),
                'evaluacion_producto' => $datos['evaluacion_producto'],
                'verificacion_liberacion' => $datos['verificacion_liberacion'],
                'liberado_por_user_id' => $usuario->id,
                'liberado_at' => now(),
            ]);
            $lote->update([
                'estado' => $estadoNuevo,
                'version' => $lote->version + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $lote,
                'hidrocooler_liberado',
                $usuario,
                $datos['operacion_id'],
                EstadoLoteMateriaPrima::HidrocoolerRetenido,
                $estadoNuevo,
                [
                    'motivo_retencion' => $proceso->motivo_retencion,
                    'destino_salida' => $proceso->destino_salida,
                    'temperatura_verificacion_c' => (float) $datos['temperatura_verificacion_c'],
                    'cloro_libre_verificacion_ppm' => (float) $datos['cloro_libre_verificacion_ppm'],
                    'ph_agua_verificacion' => (float) $datos['ph_agua_verificacion'],
                    'evaluacion_producto' => $datos['evaluacion_producto'],
                    'verificacion_liberacion' => $datos['verificacion_liberacion'],
                    'payload_hash' => $hash,
                ],
            );

            return $this->cargar($lote);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function asignarCamara(
        LoteMateriaPrima $lote,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        return DB::transaction(function () use ($lote, $datos, $usuario): LoteMateriaPrima {
            $lote = LoteMateriaPrima::query()->lockForUpdate()->findOrFail($lote->id);
            $existente = AsignacionCamaraLoteMateriaPrima::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if ($existente) {
                if ($existente->lote_materia_prima_id !== $lote->id) {
                    throw new ConflictoOperacion(
                        'El identificador de asignación ya fue utilizado en otro lote.',
                    );
                }

                return $this->cargar($lote);
            }
            if ($lote->estado !== EstadoLoteMateriaPrima::PendienteAsignacion) {
                throw new ConflictoOperacion('El lote no está pendiente de asignación.');
            }
            $camara = Camara::query()
                ->whereKey($datos['camara_id'])
                ->where('contenido', ContenidoCamara::MateriaPrima->value)
                ->where('estado', EstadoCamara::Activa->value)
                ->lockForUpdate()
                ->first();
            if (! $camara) {
                throw ValidationException::withMessages([
                    'camara_id' => 'Selecciona una cámara activa exclusiva de materia prima.',
                ]);
            }

            AsignacionCamaraLoteMateriaPrima::create([
                'operacion_id' => $datos['operacion_id'],
                'lote_materia_prima_id' => $lote->id,
                'camara_id' => $camara->id,
                'asignado_por_user_id' => $usuario->id,
                'asignado_at' => now(),
                'observacion' => $datos['observacion'] ?? null,
            ]);
            $lote->update([
                'estado' => EstadoLoteMateriaPrima::AsignadoCamara,
                'version' => $lote->version + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $lote,
                'camara_asignada',
                $usuario,
                $datos['operacion_id'],
                EstadoLoteMateriaPrima::PendienteAsignacion,
                EstadoLoteMateriaPrima::AsignadoCamara,
                ['camara_id' => $camara->id, 'camara_codigo' => $camara->codigo],
            );

            return $this->cargar($lote);
        }, attempts: 3);
    }

    public function anular(
        LoteMateriaPrima $lote,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): LoteMateriaPrima {
        return DB::transaction(function () use (
            $lote,
            $operacionId,
            $motivo,
            $usuario,
        ): LoteMateriaPrima {
            $lote = LoteMateriaPrima::query()
                ->with(['hidrocooler', 'asignacionCamara'])
                ->lockForUpdate()
                ->findOrFail($lote->id);
            $evento = EventoLoteMateriaPrima::query()
                ->where('operacion_id', $operacionId)
                ->first();
            if ($evento) {
                if ($evento->lote_materia_prima_id !== $lote->id
                    || $evento->tipo !== 'lote_anulado') {
                    throw new ConflictoOperacion(
                        'El identificador de operación ya fue utilizado con otra acción.',
                    );
                }

                return $this->cargar($lote);
            }
            if ($lote->estado === EstadoLoteMateriaPrima::Anulado) {
                throw new ConflictoOperacion('El lote ya se encuentra anulado.');
            }
            if ($lote->hidrocooler || $lote->asignacionCamara) {
                throw new ConflictoOperacion(
                    'El lote ya posee ejecución física y no puede anularse desde Digitación.',
                );
            }

            $estadoAnterior = $lote->estado;
            $segmento = SegmentoValidacionMp::query()
                ->with(['envases', 'validacion.recepcion'])
                ->lockForUpdate()
                ->findOrFail($lote->segmento_validacion_mp_id);
            $lote->update([
                'estado' => EstadoLoteMateriaPrima::Anulado,
                'clave_numero_vigente' => null,
                'version' => $lote->version + 1,
                'actualizado_por_user_id' => $usuario->id,
                'anulado_por_user_id' => $usuario->id,
                'anulado_at' => now(),
                'motivo_anulacion' => trim($motivo),
            ]);
            $this->registrarEvento(
                $lote,
                'lote_anulado',
                $usuario,
                $operacionId,
                $estadoAnterior,
                EstadoLoteMateriaPrima::Anulado,
                ['motivo' => trim($motivo)],
            );
            $this->actualizarEstadoSegmento($segmento);

            return $this->cargar($lote);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function prepararDatos(array $datos): array
    {
        $segmento = SegmentoValidacionMp::query()
            ->with(['envases', 'validacion'])
            ->lockForUpdate()
            ->findOrFail($datos['segmento_validacion_mp_id']);
        $validacion = $segmento->validacion;
        $recepcion = RecepcionRomana::query()
            ->with('detallesEnvases')
            ->lockForUpdate()
            ->findOrFail($validacion->recepcion_romana_id);
        // El lote nace en la temporada de su recepción; esa temporada debe estar activa.
        $this->guardiaTemporada->asegurar($recepcion);
        Cliente::query()->lockForUpdate()->findOrFail($recepcion->cliente_id);
        if ($validacion->estado !== EstadoValidacionMp::Validada) {
            throw new ConflictoOperacion(
                'El segmento todavía no pertenece a una Validación MP confirmada.',
            );
        }
        $tipoContenedor = $recepcion->tipo_envase_calculo_neto
            ?? $recepcion->tipo_envase_declarado?->value;
        if (! $segmento->envases->contains(fn ($envase): bool => $envase->tipo_envase->value === $tipoContenedor && $envase->cantidad > 0)) {
            throw ValidationException::withMessages(['envases' => 'El segmento no contiene envases contenedores para repartir el neto.']);
        }

        $csgId = $segmento->csg_validacion_id ?? ($datos['csg_validacion_id'] ?? null);
        $especieId = $recepcion->especie_validacion_id ?? ($datos['especie_validacion_id'] ?? null);
        $variedadId = $segmento->variedad_validacion_id ?? ($datos['variedad_validacion_id'] ?? null);
        $csg = CsgValidacion::query()
            ->whereKey($csgId)
            ->where('temporada_id', $recepcion->temporada_id)
            ->where('activo', true)
            ->disponibleParaCliente($recepcion->cliente_id)
            ->first();
        $especie = EspecieValidacion::query()
            ->whereKey($especieId)
            ->where('temporada_id', $recepcion->temporada_id)
            ->where('activo', true)
            ->first();
        $variedad = VariedadValidacion::query()
            ->whereKey($variedadId)
            ->where('especie_validacion_id', $especie?->id)
            ->where('activo', true)
            ->first();
        if (! $csg || ! $especie || ! $variedad) {
            throw ValidationException::withMessages([
                'catalogo' => 'CSG, especie y variedad deben pertenecer a la temporada y combinación seleccionadas.',
            ]);
        }
        if ((isset($datos['csg_validacion_id']) && $datos['csg_validacion_id'] !== $csg->id)
            || (isset($datos['especie_validacion_id']) && $datos['especie_validacion_id'] !== $especie->id)
            || (isset($datos['variedad_validacion_id']) && $datos['variedad_validacion_id'] !== $variedad->id)) {
            throw ValidationException::withMessages(['catalogo' => 'El origen del lote debe coincidir con el segmento validado.']);
        }
        if ($segmento->csg_validacion_id
            && $segmento->csg_validacion_id !== $csg->id) {
            throw ValidationException::withMessages([
                'csg_validacion_id' => 'El CSG debe coincidir con la segregación confirmada en Validación MP.',
            ]);
        }
        if ($segmento->variedad_validacion_id
            && $segmento->variedad_validacion_id !== $variedad->id) {
            throw ValidationException::withMessages([
                'variedad_validacion_id' => 'La variedad debe coincidir con la segregación confirmada en Validación MP.',
            ]);
        }
        if (filled($segmento->cuartel)
            && filled($datos['cuartel'] ?? null)
            && mb_strtolower(trim($segmento->cuartel)) !== mb_strtolower(trim($datos['cuartel']))) {
            throw ValidationException::withMessages([
                'cuartel' => 'El cuartel debe coincidir con la segregación confirmada en Validación MP.',
            ]);
        }

        return compact('segmento', 'recepcion', 'csg', 'especie', 'variedad');
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $preparados
     * @return array<string, mixed>
     */
    private function atributosLote(array $datos, array $preparados): array
    {
        /** @var RecepcionRomana $recepcion */
        $recepcion = $preparados['recepcion'];
        $segmento = $preparados['segmento'];
        $contenedor = $recepcion->tipo_envase_calculo_neto
            ?? $recepcion->tipo_envase_declarado?->value;
        $principal = $segmento->envases->first(fn ($envase): bool => $envase->tipo_envase->value === $contenedor);
        $secundario = $segmento->envases->first(fn ($envase): bool => $envase->tipo_envase->value !== $contenedor && $envase->cantidad > 0);
        $destarePendiente = $recepcion->peso_neto === null;
        $calculados = $destarePendiente
            ? null
            : $this->netoEstimado($segmento, $recepcion, $datos['lote_id_para_calculo'] ?? null);
        $taras = $recepcion->detallesEnvases->keyBy(fn ($detalle): string => $detalle->tipo_envase->value);
        $taraTotal = $segmento->envases->sum(fn ($envase): float => (int) $envase->cantidad
            * (float) ($taras->get($envase->tipo_envase->value)?->tara_unitaria_salida ?? 0));
        $brutos = $calculados === null ? null : round($calculados + $taraTotal, 3);

        return [
            'segmento_validacion_mp_id' => $preparados['segmento']->id,
            'recepcion_romana_id' => $recepcion->id,
            'temporada_id' => $recepcion->temporada_id,
            'cliente_id' => $recepcion->cliente_id,
            'numero_lote' => $datos['numero_lote'],
            'clave_numero_vigente' => $this->claveNumeroVigente(
                $recepcion->temporada_id,
                $recepcion->cliente_id,
                $datos['numero_lote'],
            ),
            'csg_validacion_id' => $preparados['csg']->id,
            'csg_snapshot' => $preparados['csg']->codigo,
            'sdp' => $datos['sdp'],
            'ggn' => $datos['ggn'] ?? null,
            'fecha_cosecha' => $datos['fecha_cosecha'],
            'predio' => $datos['predio'],
            'especie_validacion_id' => $preparados['especie']->id,
            'especie_snapshot' => $preparados['especie']->nombre,
            'variedad_validacion_id' => $preparados['variedad']->id,
            'variedad_snapshot' => $preparados['variedad']->nombre,
            'calibre_validacion_id' => null,
            'calibre_snapshot' => null,
            'cuartel' => filled($datos['cuartel'] ?? null)
                ? trim((string) $datos['cuartel'])
                : (filled($preparados['segmento']->cuartel)
                    ? trim((string) $preparados['segmento']->cuartel)
                    : null),
            'tipo_producto' => $datos['tipo_producto'],
            'envase_primario' => $contenedor,
            'envase_secundario' => $secundario?->tipo_envase->value,
            'cantidad_envases_primarios' => (int) $principal->cantidad,
            'cantidad_envases_secundarios' => (int) ($secundario?->cantidad ?? 0),
            'kilos_brutos' => $brutos,
            'kilos_netos_calculados' => $calculados,
            'kilos_netos_confirmados' => $calculados,
            'requiere_hidrocooler' => (bool) $datos['requiere_hidrocooler'],
            'observacion' => $datos['observacion'] ?? null,
        ];
    }

    private function asegurarDisponibilidadSegmento(
        SegmentoValidacionMp $segmento,
        ?string $ignorarLoteId = null,
    ): void {
        if (LoteMateriaPrima::query()
            ->where('segmento_validacion_mp_id', $segmento->id)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
            ->when($ignorarLoteId, fn ($consulta) => $consulta->whereKeyNot($ignorarLoteId))
            ->exists()) {
            throw ValidationException::withMessages(['segmento_validacion_mp_id' => 'El segmento ya tiene un lote activo.']);
        }
    }

    /** El redondeo final se asigna al último segmento según contenedores ocupados, nunca según esponjas o totes. */
    public function netoEstimado(SegmentoValidacionMp $segmento, RecepcionRomana $recepcion, ?string $ignorarLoteId = null): float
    {
        $segmento->loadMissing('envases');
        $tipo = $recepcion->tipo_envase_calculo_neto;
        $cantidad = (int) $segmento->envases->first(fn ($envase): bool => $envase->tipo_envase->value === $tipo)?->cantidad;
        $total = (int) $recepcion->cantidad_envase_calculo_neto;
        if ($cantidad < 1 || $total < 1) {
            throw ValidationException::withMessages(['envases' => 'El segmento necesita al menos un envase contenedor.']);
        }
        $anteriores = LoteMateriaPrima::query()
            ->where('recepcion_romana_id', $recepcion->id)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
            ->when($ignorarLoteId, fn ($consulta) => $consulta->whereKeyNot($ignorarLoteId))
            ->with('envasesDetalle')->get();
        $ocupados = $anteriores->sum(fn (LoteMateriaPrima $lote): int => (int) ($lote->envasesDetalle
            ->first(fn ($envase): bool => $envase->tipo_envase->value === $tipo)?->cantidad
            ?? ($lote->envase_primario->value === $tipo ? $lote->cantidad_envases_primarios
                : ($lote->envase_secundario?->value === $tipo ? $lote->cantidad_envases_secundarios : 0))));
        if ($ocupados + $cantidad > $total) {
            throw ValidationException::withMessages(['envases' => 'Los contenedores de los lotes superan los declarados en Romana.']);
        }
        $miligramos = (int) round((float) $recepcion->peso_neto * 1000);
        $asignados = (int) round($anteriores->sum('kilos_netos_calculados') * 1000);
        $calculados = $ocupados + $cantidad === $total
            ? $miligramos - $asignados
            : (int) round((float) $recepcion->peso_neto_por_envase * 1000) * $cantidad;
        if ($calculados <= 0 || $calculados + $asignados > $miligramos) {
            throw ValidationException::withMessages(['envases' => 'El reparto del neto de Romana no es válido.']);
        }

        return $calculados / 1000;
    }

    /**
     * Distribuye el neto entre borradores de una recepción dentro de la transacción del destare.
     * Los cálculos usan milésimas y el último contenedor completa el remanente exacto.
     */
    public function conciliarDestare(RecepcionRomana $recepcion, User $usuario): void
    {
        if ($recepcion->peso_neto === null || ! $recepcion->tipo_envase_calculo_neto
            || ! $recepcion->cantidad_envase_calculo_neto) {
            throw new ConflictoOperacion('Romana aún no dispone del neto para conciliar los lotes.');
        }
        $recepcion->load('detallesEnvases');
        $tipo = $recepcion->tipo_envase_calculo_neto;
        $total = (int) $recepcion->cantidad_envase_calculo_neto;
        $neto = (int) round((float) $recepcion->peso_neto * 1000);
        $unitario = (int) round((float) $recepcion->peso_neto_por_envase * 1000);
        $lotes = LoteMateriaPrima::query()
            ->where('recepcion_romana_id', $recepcion->id)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
            ->with('envasesDetalle')
            ->orderBy('created_at')->orderBy('id')
            ->lockForUpdate()->get();
        $ocupados = 0;
        $asignados = 0;
        foreach ($lotes as $lote) {
            $cantidad = (int) ($lote->envasesDetalle
                ->first(fn ($envase): bool => $envase->tipo_envase->value === $tipo)?->cantidad ?? 0);
            if ($cantidad < 1 || $ocupados + $cantidad > $total) {
                throw ValidationException::withMessages(['envases' => 'Los contenedores lotizados no coinciden con el destare de Romana.']);
            }
            $ocupados += $cantidad;
            if ($lote->estado !== EstadoLoteMateriaPrima::Borrador) {
                $asignados += (int) round((float) $lote->kilos_netos_calculados * 1000);
                continue;
            }
            $kilos = $ocupados === $total ? $neto - $asignados : $unitario * $cantidad;
            if ($kilos <= 0 || $asignados + $kilos > $neto) {
                throw ValidationException::withMessages(['envases' => 'El neto de los lotes no cabe en el destare de Romana.']);
            }
            $taras = $recepcion->detallesEnvases->keyBy(fn ($detalle): string => $detalle->tipo_envase->value);
            $taraTotal = 0;
            foreach ($lote->envasesDetalle as $envase) {
                $tara = $taras->get($envase->tipo_envase->value)?->tara_unitaria_salida;
                $envase->update(['tara_unitaria' => $tara]);
                $taraTotal += (int) round((float) $tara * 1000) * $envase->cantidad;
            }
            $lote->update([
                'envase_primario' => $tipo,
                'cantidad_envases_primarios' => $cantidad,
                'kilos_netos_calculados' => $kilos / 1000,
                'kilos_netos_confirmados' => $kilos / 1000,
                'kilos_brutos' => ($kilos + $taraTotal) / 1000,
                'version' => $lote->version + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento($lote, 'destare_conciliado', $usuario, null,
                EstadoLoteMateriaPrima::Borrador, EstadoLoteMateriaPrima::Borrador,
                ['kilos_netos_calculados' => $kilos / 1000, 'version' => $lote->version]);
            $asignados += $kilos;
        }
    }

    private function guardarEnvases(LoteMateriaPrima $lote, SegmentoValidacionMp $segmento, RecepcionRomana $recepcion): void
    {
        $taras = $recepcion->detallesEnvases->keyBy(fn ($detalle): string => $detalle->tipo_envase->value);
        foreach ($segmento->envases as $envase) {
            if ($envase->cantidad < 1) {
                continue;
            }
            $lote->envasesDetalle()->updateOrCreate(
                ['tipo_envase' => $envase->tipo_envase->value],
                ['cantidad' => $envase->cantidad,
                    'tara_unitaria' => $taras->get($envase->tipo_envase->value)?->tara_unitaria_salida],
            );
        }
    }

    private function asegurarKilosRecepcion(
        RecepcionRomana $recepcion,
        float $kilos,
        ?string $ignorarLoteId = null,
    ): void {
        $ocupados = (float) LoteMateriaPrima::query()
            ->where('recepcion_romana_id', $recepcion->id)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
            ->when($ignorarLoteId, fn ($consulta) => $consulta->where('id', '!=', $ignorarLoteId))
            ->sum('kilos_netos_confirmados');
        if (($ocupados + $kilos) - (float) $recepcion->peso_neto > 0.001) {
            throw ValidationException::withMessages([
                'kilos_netos_confirmados' => 'La suma de lotes supera el peso neto total de Romana.',
            ]);
        }
    }

    private function asegurarNumeroUnico(
        string $temporadaId,
        string $clienteId,
        string $numero,
        ?string $ignorarLoteId = null,
    ): void {
        $consulta = LoteMateriaPrima::query()
            ->where('temporada_id', $temporadaId)
            ->where('cliente_id', $clienteId)
            ->where('numero_lote', $numero)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value);
        if ($ignorarLoteId) {
            $consulta->where('id', '!=', $ignorarLoteId);
        }
        if ($consulta->exists()) {
            throw ValidationException::withMessages([
                'numero_lote' => 'El número de lote ya existe para esta exportadora y temporada.',
            ]);
        }
    }

    private function actualizarEstadoSegmento(SegmentoValidacionMp $segmento): void
    {
        $activos = LoteMateriaPrima::query()->where('segmento_validacion_mp_id', $segmento->id)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)->count();
        $segmento->update(['estado' => $activos ? 'lotizado' : 'pendiente_lote']);
    }

    private function claveNumeroVigente(
        string $temporadaId,
        string $clienteId,
        string $numero,
    ): string {
        return hash('sha256', implode('|', [
            $temporadaId,
            $clienteId,
            mb_strtoupper(trim($numero)),
        ]));
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function payloadLote(array $datos): array
    {
        return collect($datos)
            ->except(['version_conocida'])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function payloadHidrocooler(array $datos): array
    {
        return collect($datos)->sortKeys()->all();
    }

    /**
     * @param  array<string, mixed>|null  $datos
     */
    private function registrarEvento(
        LoteMateriaPrima $lote,
        string $tipo,
        User $usuario,
        ?string $operacionId,
        ?EstadoLoteMateriaPrima $estadoAnterior,
        ?EstadoLoteMateriaPrima $estadoNuevo,
        ?array $datos = null,
    ): void {
        EventoLoteMateriaPrima::create([
            'lote_materia_prima_id' => $lote->id,
            'operacion_id' => $operacionId,
            'tipo' => $tipo,
            'estado_anterior' => $estadoAnterior?->value,
            'estado_nuevo' => $estadoNuevo?->value,
            'user_id' => $usuario->id,
            'ocurrido_at' => now(),
            'datos' => $datos,
        ]);
    }

    private function asegurarEventoIdempotente(
        EventoLoteMateriaPrima $evento,
        LoteMateriaPrima $lote,
        string $tipo,
        string $hash,
    ): void {
        if ($evento->lote_materia_prima_id !== $lote->id
            || $evento->tipo !== $tipo
            || ! hash_equals((string) data_get($evento->datos, 'payload_hash'), $hash)) {
            throw new ConflictoOperacion(
                'El identificador de operación ya fue utilizado con datos diferentes.',
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash(
                'sha256',
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        } catch (JsonException $exception) {
            throw new ConflictoOperacion(
                'No fue posible validar la operación de materia prima.',
                previous: $exception,
            );
        }
    }

    public function cargar(LoteMateriaPrima $lote): LoteMateriaPrima
    {
        return $lote->refresh()->load([
            'segmento.envases',
            'envasesDetalle',
            'segmento.validacion.recepcion.detallesEnvases',
            'recepcion',
            'temporada',
            'cliente',
            'csg',
            'especie',
            'variedad',
            'calibre',
            'creadoPor',
            'actualizadoPor',
            'confirmadoPor',
            'anuladoPor',
            'hidrocooler.iniciadoPor',
            'hidrocooler.completadoPor',
            'hidrocooler.liberadoPor',
            'asignacionCamara.camara',
            'asignacionCamara.asignadoPor',
            'eventos.usuario',
        ]);
    }
}
