<?php

namespace App\Services\Romana;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\EventoRecepcionRomana;
use App\Models\PesajeEnvaseRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Notificaciones\ServicioNotificacionesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;

class ServicioRecepcionRomana
{
    public function __construct(
        private readonly ServicioNotificacionesOperacionales $notificaciones,
    ) {}

    /** @param array<string, mixed> $datos */
    public function crear(array $datos, User $usuario): RecepcionRomana
    {
        $payload = $this->datosRecepcion($datos);
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($datos, $usuario, $payload, $hash): RecepcionRomana {
            $existente = RecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($existente) {
                $this->asegurarMismoPayload($existente->payload_hash, $hash);

                return $this->cargar($existente);
            }

            $temporada = $this->temporadaActiva((string) $payload['temporada_id']);
            $cliente = $this->clienteActivo((string) $payload['cliente_id']);
            $this->asegurarGuiaUnica($temporada->id, $cliente->id, (string) $payload['numero_guia_despacho']);
            $ahora = CarbonImmutable::now();
            $ingresoAt = $this->resolverIngresoAt($payload, $ahora);
            $numero = $this->siguienteNumero($ahora);
            $envasePrincipal = $payload['envases'][0];
            $esPesajeEnvases = $payload['tipo_recepcion'] === TipoRecepcionRomana::FrutaPesajeEnvases->value;
            $esSoloEnvases = $payload['tipo_recepcion'] === TipoRecepcionRomana::SoloEnvases->value;
            $recepcion = RecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'numero_recepcion' => $numero,
                'temporada_id' => $temporada->id,
                'temporada_codigo_snapshot' => $temporada->codigo,
                'temporada_nombre_snapshot' => $temporada->nombre,
                'cliente_id' => $cliente->id,
                'cliente_codigo_snapshot' => $cliente->codigo,
                'cliente_nombre_snapshot' => $cliente->nombre,
                'especie_validacion_id' => $payload['especie_validacion_id'],
                'tipo_recepcion' => $payload['tipo_recepcion'],
                'concepto_envases' => $payload['concepto_envases'],
                'tipo_servicio' => $payload['tipo_servicio'],
                'cantidad_envases_declarados' => collect($payload['envases'])->sum('cantidad'),
                'tipo_envase_declarado' => $envasePrincipal['tipo_envase'],
                'numero_guia_despacho' => $payload['numero_guia_despacho'],
                'patente_camion' => $payload['patente_camion'],
                'tipo_camion' => $payload['tipo_camion'],
                'patente_carro' => $payload['patente_carro'],
                'rut_conductor' => $payload['rut_conductor'],
                'nombre_conductor' => $payload['nombre_conductor'],
                'peso_bruto' => $esPesajeEnvases ? 0 : ($esSoloEnvases ? null : $payload['peso_bruto']),
                'peso_tara' => $esPesajeEnvases ? 0 : null,
                'peso_neto' => $esPesajeEnvases ? 0 : null,
                'salida_sin_envases' => false,
                'peso_tara_envases' => null,
                'tipo_envase_calculo_neto' => $esPesajeEnvases
                    ? $payload['tipo_envase_pesaje']
                    : null,
                'tipo_envase_pesaje' => $payload['tipo_envase_pesaje'],
                'tara_unitaria_envase' => $payload['tara_unitaria_envase'],
                'cantidad_envases_pesados' => 0,
                'cantidad_envase_calculo_neto' => $esPesajeEnvases ? 0 : null,
                'estado' => $esPesajeEnvases
                    ? EstadoRecepcionRomana::EnPesajeEnvases
                    : EstadoRecepcionRomana::EnBasculaIngreso,
                'estado_validacion_mp' => EstadoValidacionMp::Pendiente,
                'ingreso_at' => $ingresoAt,
                'ingreso_confirmado_at' => $esPesajeEnvases ? $ahora : null,
                'creado_por_user_id' => $usuario->id,
                'ingreso_confirmado_por_user_id' => $esPesajeEnvases ? $usuario->id : null,
                'observacion' => $payload['observacion'],
            ]);
            $this->sincronizarEnvases($recepcion, $payload['envases']);

            $this->registrarEvento(
                $recepcion,
                (string) $datos['operacion_id'],
                $hash,
                TipoEventoRomana::IngresoRegistrado,
                null,
                $recepcion->estado,
                $usuario,
                $ahora,
                [
                    'peso_bruto' => $esPesajeEnvases || $esSoloEnvases
                        ? null
                        : (float) $recepcion->peso_bruto,
                    'numero_recepcion' => $numero,
                    'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                    'temporada_id' => $recepcion->temporada_id,
                    'fecha_ingreso' => $payload['fecha_ingreso'],
                    'ingreso_at' => $ingresoAt->toAtomString(),
                    'envases' => $payload['envases'],
                    'tipo_envase_pesaje' => $payload['tipo_envase_pesaje'],
                    'tara_unitaria_envase' => $payload['tara_unitaria_envase'],
                ],
            );
            $this->notificaciones->notificarRecepcionRomanaCreada($recepcion);

            return $this->cargar($recepcion);
        });
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(RecepcionRomana $recepcion, array $datos, User $usuario): RecepcionRomana
    {
        $payload = $this->datosRecepcion($datos);
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                $this->asegurarEventoIdempotente($evento, $recepcion, $hash, TipoEventoRomana::IngresoActualizado);

                return $this->cargar($recepcion);
            }

            if ($recepcion->estado_validacion_mp !== EstadoValidacionMp::Pendiente) {
                throw new ConflictoOperacion('La recepción ya fue tomada por Validación MP y sus antecedentes no pueden editarse.');
            }
            if (! $recepcion->estado->esEditable()) {
                throw new ConflictoOperacion('La recepción ya confirmó su ingreso y sus antecedentes no pueden editarse.');
            }
            $this->asegurarTipoPesajeInmutable($recepcion, $payload);
            if ($recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                && $recepcion->pesajesEnvases()->whereNull('anulado_at')->exists()) {
                throw new ConflictoOperacion(
                    'La recepción ya posee lecturas. Anula los pesajes antes de modificar sus antecedentes.',
                );
            }

            $temporada = $this->temporadaActiva((string) $payload['temporada_id']);
            $this->asegurarMismaTemporada($recepcion, $temporada);
            $cliente = $this->clienteActivo((string) $payload['cliente_id']);
            $this->asegurarGuiaUnica(
                $temporada->id,
                $cliente->id,
                (string) $payload['numero_guia_despacho'],
                $recepcion->id,
            );
            $envasePrincipal = $payload['envases'][0];
            $ahora = CarbonImmutable::now();
            $ingresoAt = $this->resolverIngresoAt($payload, $ahora, $recepcion);
            $recepcion->update([
                'temporada_id' => $temporada->id,
                'temporada_codigo_snapshot' => $temporada->codigo,
                'temporada_nombre_snapshot' => $temporada->nombre,
                'cliente_id' => $cliente->id,
                'cliente_codigo_snapshot' => $cliente->codigo,
                'cliente_nombre_snapshot' => $cliente->nombre,
                'especie_validacion_id' => $payload['especie_validacion_id'],
                'tipo_recepcion' => $payload['tipo_recepcion'],
                'concepto_envases' => $payload['concepto_envases'],
                'tipo_servicio' => $payload['tipo_servicio'],
                'cantidad_envases_declarados' => collect($payload['envases'])->sum('cantidad'),
                'tipo_envase_declarado' => $envasePrincipal['tipo_envase'],
                'numero_guia_despacho' => $payload['numero_guia_despacho'],
                'patente_camion' => $payload['patente_camion'],
                'tipo_camion' => $payload['tipo_camion'],
                'patente_carro' => $payload['patente_carro'],
                'rut_conductor' => $payload['rut_conductor'],
                'nombre_conductor' => $payload['nombre_conductor'],
                'ingreso_at' => $ingresoAt,
                'peso_bruto' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? 0
                    : ($payload['tipo_recepcion'] === TipoRecepcionRomana::SoloEnvases->value
                        ? null
                        : $payload['peso_bruto']),
                'peso_tara' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? 0
                    : $recepcion->peso_tara,
                'peso_neto' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? 0
                    : $recepcion->peso_neto,
                'tipo_envase_calculo_neto' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? $payload['tipo_envase_pesaje']
                    : $recepcion->tipo_envase_calculo_neto,
                'tipo_envase_pesaje' => $payload['tipo_envase_pesaje'],
                'tara_unitaria_envase' => $payload['tara_unitaria_envase'],
                'cantidad_envases_pesados' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? 0
                    : $recepcion->cantidad_envases_pesados,
                'cantidad_envase_calculo_neto' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? 0
                    : $recepcion->cantidad_envase_calculo_neto,
                'peso_neto_por_envase' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? null
                    : $recepcion->peso_neto_por_envase,
                'observacion' => $payload['observacion'],
                'version' => $recepcion->version + 1,
            ]);
            $this->sincronizarEnvases($recepcion, $payload['envases']);
            $this->registrarEvento(
                $recepcion,
                (string) $datos['operacion_id'],
                $hash,
                TipoEventoRomana::IngresoActualizado,
                $recepcion->estado,
                $recepcion->estado,
                $usuario,
                $ahora,
                [
                    'version' => $recepcion->version,
                    'fecha_ingreso' => $payload['fecha_ingreso'],
                    'ingreso_at' => $ingresoAt->toAtomString(),
                ],
            );

            return $this->cargar($recepcion);
        });
    }

    /** @param array<string, mixed> $datos */
    public function corregirAdministrativamente(
        RecepcionRomana $recepcion,
        array $datos,
        User $usuario,
    ): RecepcionRomana {
        $payload = [
            ...$this->datosRecepcion($datos),
            'version_conocida' => (int) $datos['version_conocida'],
            'motivo_correccion' => $datos['motivo_correccion'],
            'peso_tara' => isset($datos['peso_tara'])
                ? round((float) $datos['peso_tara'], 2)
                : null,
            'tipo_envase_calculo_neto' => $datos['tipo_envase_calculo_neto'] ?? null,
        ];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()
                ->with('detallesEnvases')
                ->lockForUpdate()
                ->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();

            if ($evento) {
                $this->asegurarEventoIdempotente(
                    $evento,
                    $recepcion,
                    $hash,
                    TipoEventoRomana::CorreccionAdministrativa,
                );

                return $this->cargar($recepcion);
            }

            if ($recepcion->estado_validacion_mp !== EstadoValidacionMp::Pendiente) {
                throw new ConflictoOperacion(
                    'La recepción ya fue tomada por Validación MP y no admite correcciones administrativas.',
                );
            }
            if ($recepcion->version !== $payload['version_conocida']) {
                throw new ConflictoOperacion(
                    'La recepción cambió desde que abriste el expediente. Actualiza antes de corregir.',
                );
            }
            $this->asegurarTipoPesajeInmutable($recepcion, $payload);
            if ($recepcion->pesajesEnvases()->whereNull('anulado_at')->exists()) {
                throw new ConflictoOperacion(
                    'Las recepciones con lecturas deben corregirse anulando el pesaje equivocado antes del cierre.',
                );
            }

            $temporada = $this->temporadaActiva((string) $payload['temporada_id']);
            $this->asegurarMismaTemporada($recepcion, $temporada);
            $cliente = $this->clienteActivo((string) $payload['cliente_id']);
            $this->asegurarGuiaUnica(
                $temporada->id,
                $cliente->id,
                (string) $payload['numero_guia_despacho'],
                $recepcion->id,
            );

            $estado = $recepcion->estado;
            $anterior = $this->snapshotCorreccion($recepcion);
            $envasePrincipal = $payload['envases'][0];
            $ahora = CarbonImmutable::now();
            $ingresoAt = $this->resolverIngresoAt($payload, $ahora, $recepcion);
            $actualizacion = [
                'temporada_id' => $temporada->id,
                'temporada_codigo_snapshot' => $temporada->codigo,
                'temporada_nombre_snapshot' => $temporada->nombre,
                'cliente_id' => $cliente->id,
                'cliente_codigo_snapshot' => $cliente->codigo,
                'cliente_nombre_snapshot' => $cliente->nombre,
                'especie_validacion_id' => $payload['especie_validacion_id'],
                'tipo_recepcion' => $payload['tipo_recepcion'],
                'concepto_envases' => $payload['concepto_envases'],
                'tipo_servicio' => $payload['tipo_servicio'],
                'cantidad_envases_declarados' => collect($payload['envases'])->sum('cantidad'),
                'tipo_envase_declarado' => $envasePrincipal['tipo_envase'],
                'numero_guia_despacho' => $payload['numero_guia_despacho'],
                'patente_camion' => $payload['patente_camion'],
                'tipo_camion' => $payload['tipo_camion'],
                'patente_carro' => $payload['patente_carro'],
                'rut_conductor' => $payload['rut_conductor'],
                'nombre_conductor' => $payload['nombre_conductor'],
                'ingreso_at' => $ingresoAt,
                'peso_bruto' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? 0
                    : ($payload['tipo_recepcion'] === TipoRecepcionRomana::SoloEnvases->value
                        ? null
                        : $payload['peso_bruto']),
                'tipo_envase_calculo_neto' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? $payload['tipo_envase_pesaje']
                    : $recepcion->tipo_envase_calculo_neto,
                'tipo_envase_pesaje' => $payload['tipo_envase_pesaje'],
                'tara_unitaria_envase' => $payload['tara_unitaria_envase'],
                'observacion' => $payload['observacion'],
                'version' => $recepcion->version + 1,
            ];

            if ($estado === EstadoRecepcionRomana::Cerrado
                && $payload['tipo_recepcion'] === TipoRecepcionRomana::SoloEnvases->value) {
                $actualizacion = [
                    ...$actualizacion,
                    'peso_bruto' => null,
                    'peso_tara' => null,
                    'peso_neto' => null,
                    'salida_sin_envases' => false,
                    'peso_tara_envases' => null,
                    'tipo_envase_calculo_neto' => null,
                    'cantidad_envase_calculo_neto' => null,
                    'peso_neto_por_envase' => null,
                ];
            } elseif ($estado === EstadoRecepcionRomana::Cerrado) {
                $actualizacion = [
                    ...$actualizacion,
                    ...$this->recalcularCierreCorregido($recepcion, $payload),
                ];
            }

            $recepcion->update($actualizacion);
            $this->sincronizarEnvases($recepcion, $payload['envases']);
            if ($payload['tipo_recepcion'] === TipoRecepcionRomana::SoloEnvases->value) {
                $recepcion->detallesEnvases()->update(['tara_unitaria_salida' => null]);
            }
            $recepcion->refresh()->load('detallesEnvases');
            $posterior = $this->snapshotCorreccion($recepcion);

            $this->registrarEvento(
                $recepcion,
                (string) $datos['operacion_id'],
                $hash,
                TipoEventoRomana::CorreccionAdministrativa,
                $estado,
                $estado,
                $usuario,
                $ahora,
                [
                    'motivo' => $payload['motivo_correccion'],
                    'version' => $recepcion->version,
                    'anterior' => $anterior,
                    'posterior' => $posterior,
                ],
            );

            return $this->cargar($recepcion);
        });
    }

    public function confirmarIngreso(RecepcionRomana $recepcion, string $operacionId, User $usuario): RecepcionRomana
    {
        $hash = $this->hash(['accion' => 'confirmar_ingreso', 'recepcion_id' => $recepcion->id]);

        return DB::transaction(function () use ($recepcion, $operacionId, $usuario, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $operacionId)->first();
            if ($evento) {
                $this->asegurarEventoIdempotente($evento, $recepcion, $hash, TipoEventoRomana::IngresoConfirmado);

                return $this->cargar($recepcion);
            }

            if ($recepcion->estado !== EstadoRecepcionRomana::EnBasculaIngreso) {
                throw new ConflictoOperacion('La recepción no está disponible para confirmar el pesaje de ingreso.');
            }

            $ahora = CarbonImmutable::now();
            $recepcion->update([
                'estado' => EstadoRecepcionRomana::EnBasculaSalida,
                'ingreso_confirmado_at' => $ahora,
                'ingreso_confirmado_por_user_id' => $usuario->id,
                'version' => $recepcion->version + 1,
            ]);
            $this->registrarEvento(
                $recepcion,
                $operacionId,
                $hash,
                TipoEventoRomana::IngresoConfirmado,
                EstadoRecepcionRomana::EnBasculaIngreso,
                EstadoRecepcionRomana::EnBasculaSalida,
                $usuario,
                $ahora,
                ['peso_bruto' => $recepcion->peso_bruto !== null
                    ? (float) $recepcion->peso_bruto
                    : null],
            );

            return $this->cargar($recepcion);
        });
    }

    /** @param array<string, mixed> $datos */
    public function registrarPesajeEnvases(
        RecepcionRomana $recepcion,
        array $datos,
        User $usuario,
    ): RecepcionRomana {
        $payload = [
            'recepcion_id' => $recepcion->id,
            'cantidad_envases' => (int) $datos['cantidad_envases'],
            'peso_bruto' => round((float) $datos['peso_bruto'], 3),
            'observacion' => $datos['observacion'] ?? null,
        ];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()
                ->with('detallesEnvases')
                ->lockForUpdate()
                ->findOrFail($recepcion->id);
            $existente = PesajeEnvaseRecepcionRomana::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if ($existente) {
                if ($existente->recepcion_romana_id !== $recepcion->id) {
                    throw new ConflictoOperacion(
                        'El identificador de operación ya fue utilizado en otra recepción.',
                    );
                }
                $this->asegurarMismoPayload($existente->payload_hash, $hash);
                $evento = EventoRecepcionRomana::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->first();
                if (! $evento) {
                    throw new ConflictoOperacion(
                        'La lectura existente no posee su evento de trazabilidad asociado.',
                    );
                }
                $this->asegurarEventoIdempotente(
                    $evento,
                    $recepcion,
                    $hash,
                    TipoEventoRomana::PesajeEnvasesRegistrado,
                );

                return $this->cargar($recepcion);
            }
            if (EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->exists()) {
                throw new ConflictoOperacion(
                    'El identificador de operación ya fue utilizado en otra acción de Romana.',
                );
            }

            $this->asegurarRecepcionEnPesajeEnvases($recepcion);
            $cantidadDeclarada = $this->cantidadDeclaradaParaPesaje($recepcion);
            $cantidadPesada = (int) $recepcion->pesajesEnvases()
                ->whereNull('anulado_at')
                ->sum('cantidad_envases');
            $cantidad = $payload['cantidad_envases'];
            if ($cantidadPesada + $cantidad > $cantidadDeclarada) {
                throw new ConflictoOperacion(sprintf(
                    'La lectura supera los %d envases pendientes de pesaje.',
                    max(0, $cantidadDeclarada - $cantidadPesada),
                ));
            }

            $taraUnitaria = (float) $recepcion->tara_unitaria_envase;
            $pesoTara = round($taraUnitaria * $cantidad, 3);
            if ($payload['peso_bruto'] <= $pesoTara) {
                throw new ConflictoOperacion(
                    'El peso bruto del grupo debe ser mayor que la tara total de sus envases.',
                );
            }
            $secuencia = ((int) $recepcion->pesajesEnvases()->max('secuencia')) + 1;
            $ahora = CarbonImmutable::now();
            $pesaje = PesajeEnvaseRecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'recepcion_romana_id' => $recepcion->id,
                'secuencia' => $secuencia,
                'tipo_envase' => $recepcion->tipo_envase_pesaje,
                'cantidad_envases' => $cantidad,
                'peso_bruto' => $payload['peso_bruto'],
                'tara_unitaria_envase' => $taraUnitaria,
                'peso_tara' => $pesoTara,
                'peso_neto' => round($payload['peso_bruto'] - $pesoTara, 3),
                'observacion' => $payload['observacion'],
                'registrado_por_user_id' => $usuario->id,
                'pesado_at' => $ahora,
            ]);
            $resumen = $this->resumenPesajesEnvases($recepcion);
            $recepcion->update([
                ...$resumen,
                'version' => $recepcion->version + 1,
            ]);
            $this->registrarEvento(
                $recepcion,
                (string) $datos['operacion_id'],
                $hash,
                TipoEventoRomana::PesajeEnvasesRegistrado,
                EstadoRecepcionRomana::EnPesajeEnvases,
                EstadoRecepcionRomana::EnPesajeEnvases,
                $usuario,
                $ahora,
                [
                    'pesaje_id' => $pesaje->id,
                    'secuencia' => $secuencia,
                    'cantidad_envases' => $cantidad,
                    'peso_bruto' => (float) $pesaje->peso_bruto,
                    'peso_tara' => (float) $pesaje->peso_tara,
                    'peso_neto' => (float) $pesaje->peso_neto,
                    'cantidad_envases_pesados' => $resumen['cantidad_envases_pesados'],
                    'cantidad_envases_declarados' => $cantidadDeclarada,
                    'peso_neto_por_envase' => $resumen['peso_neto_por_envase'],
                ],
            );

            return $this->cargar($recepcion);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function anularPesajeEnvases(
        RecepcionRomana $recepcion,
        PesajeEnvaseRecepcionRomana $pesaje,
        array $datos,
        User $usuario,
    ): RecepcionRomana {
        $payload = [
            'recepcion_id' => $recepcion->id,
            'pesaje_id' => $pesaje->id,
            'motivo' => $datos['motivo'],
        ];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $pesaje, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()
                ->with('detallesEnvases')
                ->lockForUpdate()
                ->findOrFail($recepcion->id);
            $pesaje = PesajeEnvaseRecepcionRomana::query()
                ->lockForUpdate()
                ->findOrFail($pesaje->id);
            $existente = PesajeEnvaseRecepcionRomana::query()
                ->where('operacion_anulacion_id', $datos['operacion_id'])
                ->first();
            if ($existente) {
                if ($existente->id !== $pesaje->id) {
                    throw new ConflictoOperacion(
                        'El identificador de operación ya fue utilizado en otro pesaje.',
                    );
                }
                $this->asegurarMismoPayload((string) $existente->payload_anulacion_hash, $hash);
                $evento = EventoRecepcionRomana::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->first();
                if (! $evento) {
                    throw new ConflictoOperacion(
                        'La anulación existente no posee su evento de trazabilidad asociado.',
                    );
                }
                $this->asegurarEventoIdempotente(
                    $evento,
                    $recepcion,
                    $hash,
                    TipoEventoRomana::PesajeEnvasesAnulado,
                );

                return $this->cargar($recepcion);
            }
            if (EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->exists()) {
                throw new ConflictoOperacion(
                    'El identificador de operación ya fue utilizado en otra acción de Romana.',
                );
            }

            $this->asegurarRecepcionEnPesajeEnvases($recepcion);
            if ($pesaje->recepcion_romana_id !== $recepcion->id) {
                throw new ConflictoOperacion('La lectura no pertenece a esta recepción.');
            }
            if ($pesaje->anulado_at !== null) {
                throw new ConflictoOperacion('La lectura ya se encuentra anulada.');
            }

            $ahora = CarbonImmutable::now();
            $pesaje->update([
                'operacion_anulacion_id' => $datos['operacion_id'],
                'payload_anulacion_hash' => $hash,
                'anulado_at' => $ahora,
                'anulado_por_user_id' => $usuario->id,
                'motivo_anulacion' => $payload['motivo'],
            ]);
            $resumen = $this->resumenPesajesEnvases($recepcion);
            $recepcion->update([
                ...$resumen,
                'version' => $recepcion->version + 1,
            ]);
            $this->registrarEvento(
                $recepcion,
                (string) $datos['operacion_id'],
                $hash,
                TipoEventoRomana::PesajeEnvasesAnulado,
                EstadoRecepcionRomana::EnPesajeEnvases,
                EstadoRecepcionRomana::EnPesajeEnvases,
                $usuario,
                $ahora,
                [
                    'pesaje_id' => $pesaje->id,
                    'secuencia' => $pesaje->secuencia,
                    'motivo' => $payload['motivo'],
                    'cantidad_envases_pesados' => $resumen['cantidad_envases_pesados'],
                    'peso_neto_por_envase' => $resumen['peso_neto_por_envase'],
                ],
            );

            return $this->cargar($recepcion);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function cerrar(RecepcionRomana $recepcion, array $datos, User $usuario): RecepcionRomana
    {
        if ($recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases) {
            return $this->cerrarPesajeEnvases($recepcion, $datos, $usuario);
        }
        if ($recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases) {
            return $this->cerrarSoloEnvases($recepcion, $datos, $usuario);
        }

        $payload = [
            'accion' => 'cerrar',
            'recepcion_id' => $recepcion->id,
            'peso_tara' => round((float) $datos['peso_tara'], 2),
            'tipo_envase_calculo_neto' => $datos['tipo_envase_calculo_neto'] ?? null,
            'salida_sin_envases' => (bool) ($datos['salida_sin_envases'] ?? false),
            'taras_envases' => collect($datos['taras_envases'] ?? [])
                ->map(fn (array $envase): array => [
                    'tipo_envase' => $envase['tipo_envase'],
                    'tara_unitaria' => round((float) $envase['tara_unitaria'], 3),
                ])
                ->sortBy('tipo_envase')
                ->values()
                ->all(),
            'observacion' => $datos['observacion'] ?? null,
        ];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()
                ->with('detallesEnvases')
                ->lockForUpdate()
                ->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                $this->asegurarEventoIdempotente($evento, $recepcion, $hash, TipoEventoRomana::RecepcionCerrada);

                return $this->cargar($recepcion);
            }

            if ($recepcion->estado !== EstadoRecepcionRomana::EnBasculaSalida) {
                throw new ConflictoOperacion('La recepción debe confirmar primero el pesaje de ingreso.');
            }

            $tara = (float) $payload['peso_tara'];
            $bruto = (float) $recepcion->peso_bruto;
            $tarasEnvases = $this->calcularTaraEnvasesSalida($recepcion, $payload);
            $pesoTaraEnvases = $tarasEnvases['peso_tara_envases'];
            $pesoTaraTotal = round($tara + $pesoTaraEnvases, 3);
            if ($pesoTaraTotal >= $bruto) {
                throw new ConflictoOperacion(
                    $payload['salida_sin_envases']
                        ? 'La tara del camión más la tara calculada de envases debe ser menor que el peso bruto registrado.'
                        : 'La tara debe ser menor que el peso bruto registrado.',
                );
            }

            $tipoCalculo = $payload['tipo_envase_calculo_neto']
                ?? $recepcion->tipo_envase_declarado?->value
                ?? $recepcion->detallesEnvases->first()?->tipo_envase?->value;
            $detalleCalculo = $recepcion->detallesEnvases
                ->first(fn ($detalle): bool => $detalle->tipo_envase->value === $tipoCalculo);
            if (! $detalleCalculo || $detalleCalculo->cantidad_declarada < 1) {
                throw new ConflictoOperacion(
                    'Selecciona un envase declarado para calcular el peso neto individual.',
                );
            }

            $ahora = CarbonImmutable::now();
            $pesoNeto = round($bruto - $pesoTaraTotal, 3);
            $pesoNetoPorEnvase = round($pesoNeto / $detalleCalculo->cantidad_declarada, 3);
            $recepcion->update([
                'peso_tara' => $tara,
                'peso_neto' => $pesoNeto,
                'salida_sin_envases' => $payload['salida_sin_envases'],
                'peso_tara_envases' => $pesoTaraEnvases,
                'tipo_envase_calculo_neto' => $tipoCalculo,
                'cantidad_envase_calculo_neto' => $detalleCalculo->cantidad_declarada,
                'peso_neto_por_envase' => $pesoNetoPorEnvase,
                'estado' => EstadoRecepcionRomana::Cerrado,
                'salida_at' => $ahora,
                'cerrado_por_user_id' => $usuario->id,
                'observacion_cierre' => $payload['observacion'],
                'version' => $recepcion->version + 1,
            ]);
            foreach ($recepcion->detallesEnvases as $detalle) {
                $detalle->update([
                    'tara_unitaria_salida' => $tarasEnvases['taras_unitarias'][$detalle->tipo_envase->value]
                        ?? null,
                ]);
            }
            $this->registrarEvento(
                $recepcion,
                (string) $datos['operacion_id'],
                $hash,
                TipoEventoRomana::RecepcionCerrada,
                EstadoRecepcionRomana::EnBasculaSalida,
                EstadoRecepcionRomana::Cerrado,
                $usuario,
                $ahora,
                [
                    'numero_recepcion' => $recepcion->numero_recepcion,
                    'peso_bruto' => $bruto,
                    'peso_tara' => $tara,
                    'salida_sin_envases' => $payload['salida_sin_envases'],
                    'peso_tara_envases' => $pesoTaraEnvases,
                    'peso_tara_total' => $pesoTaraTotal,
                    'peso_neto' => (float) $recepcion->peso_neto,
                    'tipo_envase_calculo_neto' => $tipoCalculo,
                    'cantidad_envase_calculo_neto' => $detalleCalculo->cantidad_declarada,
                    'peso_neto_por_envase' => $pesoNetoPorEnvase,
                    'observacion_cierre' => $payload['observacion'],
                ],
            );

            return $this->cargar($recepcion);
        });
    }

    /** @param array<string, mixed> $datos */
    private function cerrarSoloEnvases(
        RecepcionRomana $recepcion,
        array $datos,
        User $usuario,
    ): RecepcionRomana {
        $payload = [
            'accion' => 'cerrar_solo_envases',
            'recepcion_id' => $recepcion->id,
            'observacion' => $datos['observacion'] ?? null,
        ];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if ($evento) {
                $this->asegurarEventoIdempotente(
                    $evento,
                    $recepcion,
                    $hash,
                    TipoEventoRomana::RecepcionCerrada,
                );

                return $this->cargar($recepcion);
            }

            if ($recepcion->tipo_recepcion !== TipoRecepcionRomana::SoloEnvases
                || $recepcion->estado !== EstadoRecepcionRomana::EnBasculaSalida) {
                throw new ConflictoOperacion(
                    'La recepción documental de envases debe confirmar primero su ingreso.',
                );
            }

            $ahora = CarbonImmutable::now();
            $recepcion->update([
                'peso_bruto' => null,
                'peso_tara' => null,
                'peso_neto' => null,
                'salida_sin_envases' => false,
                'peso_tara_envases' => null,
                'tipo_envase_calculo_neto' => null,
                'cantidad_envase_calculo_neto' => null,
                'peso_neto_por_envase' => null,
                'estado' => EstadoRecepcionRomana::Cerrado,
                'salida_at' => $ahora,
                'cerrado_por_user_id' => $usuario->id,
                'observacion_cierre' => $payload['observacion'],
                'version' => $recepcion->version + 1,
            ]);
            $this->registrarEvento(
                $recepcion,
                (string) $datos['operacion_id'],
                $hash,
                TipoEventoRomana::RecepcionCerrada,
                EstadoRecepcionRomana::EnBasculaSalida,
                EstadoRecepcionRomana::Cerrado,
                $usuario,
                $ahora,
                [
                    'numero_recepcion' => $recepcion->numero_recepcion,
                    'recepcion_sin_pesaje' => true,
                    'observacion_cierre' => $payload['observacion'],
                ],
            );

            return $this->cargar($recepcion);
        });
    }

    /** @param array<string, mixed> $datos */
    private function cerrarPesajeEnvases(
        RecepcionRomana $recepcion,
        array $datos,
        User $usuario,
    ): RecepcionRomana {
        $payload = [
            'accion' => 'cerrar_pesaje_envases',
            'recepcion_id' => $recepcion->id,
            'observacion' => $datos['observacion'] ?? null,
        ];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()
                ->with('detallesEnvases')
                ->lockForUpdate()
                ->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if ($evento) {
                $this->asegurarEventoIdempotente(
                    $evento,
                    $recepcion,
                    $hash,
                    TipoEventoRomana::RecepcionCerrada,
                );

                return $this->cargar($recepcion);
            }

            $this->asegurarRecepcionEnPesajeEnvases($recepcion);
            $cantidadDeclarada = $this->cantidadDeclaradaParaPesaje($recepcion);
            $resumen = $this->resumenPesajesEnvases($recepcion);
            if ($resumen['cantidad_envases_pesados'] !== $cantidadDeclarada) {
                throw new ConflictoOperacion(sprintf(
                    'Faltan %d envases por pesar antes de cerrar la recepción.',
                    max(0, $cantidadDeclarada - $resumen['cantidad_envases_pesados']),
                ));
            }

            $ahora = CarbonImmutable::now();
            $recepcion->update([
                ...$resumen,
                'estado' => EstadoRecepcionRomana::Cerrado,
                'salida_at' => $ahora,
                'cerrado_por_user_id' => $usuario->id,
                'observacion_cierre' => $payload['observacion'],
                'version' => $recepcion->version + 1,
            ]);
            $this->registrarEvento(
                $recepcion,
                (string) $datos['operacion_id'],
                $hash,
                TipoEventoRomana::RecepcionCerrada,
                EstadoRecepcionRomana::EnPesajeEnvases,
                EstadoRecepcionRomana::Cerrado,
                $usuario,
                $ahora,
                [
                    'numero_recepcion' => $recepcion->numero_recepcion,
                    'tipo_envase_pesaje' => $recepcion->tipo_envase_pesaje->value,
                    'tara_unitaria_envase' => (float) $recepcion->tara_unitaria_envase,
                    'cantidad_envases_pesados' => $resumen['cantidad_envases_pesados'],
                    'peso_bruto' => $resumen['peso_bruto'],
                    'peso_tara' => $resumen['peso_tara'],
                    'peso_neto' => $resumen['peso_neto'],
                    'peso_neto_por_envase' => $resumen['peso_neto_por_envase'],
                    'observacion_cierre' => $payload['observacion'],
                ],
            );

            return $this->cargar($recepcion);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datosRecepcion(array $datos): array
    {
        $envases = collect($datos['envases'])
            ->map(fn (array $envase): array => [
                'tipo_envase' => $envase['tipo_envase'],
                'cantidad' => (int) $envase['cantidad'],
            ])
            ->sortBy('tipo_envase')
            ->values()
            ->all();

        return [
            'temporada_id' => $datos['temporada_id'],
            'cliente_id' => $datos['cliente_id'],
            'especie_validacion_id' => $datos['especie_validacion_id'] ?? null,
            'tipo_recepcion' => $datos['tipo_recepcion'],
            'fecha_ingreso' => $datos['fecha_ingreso'] ?? null,
            'concepto_envases' => $datos['concepto_envases'] ?? null,
            'tipo_servicio' => $datos['tipo_servicio'] ?? 'almacenaje',
            'envases' => $envases,
            'numero_guia_despacho' => $datos['numero_guia_despacho'],
            'patente_camion' => $datos['patente_camion'],
            'tipo_camion' => $datos['tipo_camion'],
            'patente_carro' => $datos['patente_carro'] ?? null,
            'rut_conductor' => $datos['rut_conductor'],
            'nombre_conductor' => $datos['nombre_conductor'],
            'peso_bruto' => isset($datos['peso_bruto'])
                ? round((float) $datos['peso_bruto'], 3)
                : null,
            'tipo_envase_pesaje' => $datos['tipo_envase_pesaje'] ?? null,
            'tara_unitaria_envase' => isset($datos['tara_unitaria_envase'])
                ? round((float) $datos['tara_unitaria_envase'], 3)
                : null,
            'observacion' => $datos['observacion'] ?? null,
        ];
    }

    private function asegurarRecepcionEnPesajeEnvases(RecepcionRomana $recepcion): void
    {
        if ($recepcion->tipo_recepcion !== TipoRecepcionRomana::FrutaPesajeEnvases
            || $recepcion->estado !== EstadoRecepcionRomana::EnPesajeEnvases) {
            throw new ConflictoOperacion(
                'La recepción no se encuentra disponible para registrar pesajes de envases.',
            );
        }
        if ($recepcion->tipo_envase_pesaje === null
            || (float) $recepcion->tara_unitaria_envase <= 0) {
            throw new ConflictoOperacion(
                'La recepción no posee un envase y una tara unitaria configurados.',
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function asegurarTipoPesajeInmutable(
        RecepcionRomana $recepcion,
        array $payload,
    ): void {
        $tipoNuevo = TipoRecepcionRomana::from((string) $payload['tipo_recepcion']);
        if ($recepcion->tipo_recepcion !== $tipoNuevo
            && ($recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases
                || $tipoNuevo === TipoRecepcionRomana::FrutaPesajeEnvases)) {
            throw new ConflictoOperacion(
                'El flujo de pesaje acumulativo debe definirse al crear la recepción y no puede convertirse posteriormente.',
            );
        }
    }

    private function cantidadDeclaradaParaPesaje(RecepcionRomana $recepcion): int
    {
        $tipo = $recepcion->tipo_envase_pesaje?->value;
        $detalle = $recepcion->detallesEnvases->first(
            fn ($linea): bool => $linea->tipo_envase->value === $tipo,
        );
        if (! $detalle || $detalle->cantidad_declarada < 1) {
            throw new ConflictoOperacion(
                'El envase configurado para pesaje no está declarado en la recepción.',
            );
        }

        return (int) $detalle->cantidad_declarada;
    }

    /** @return array<string, float|int|string|null> */
    private function resumenPesajesEnvases(RecepcionRomana $recepcion): array
    {
        $totales = $recepcion->pesajesEnvases()
            ->whereNull('anulado_at')
            ->selectRaw('COALESCE(SUM(cantidad_envases), 0) as cantidad_envases')
            ->selectRaw('COALESCE(SUM(peso_bruto), 0) as peso_bruto')
            ->selectRaw('COALESCE(SUM(peso_tara), 0) as peso_tara')
            ->selectRaw('COALESCE(SUM(peso_neto), 0) as peso_neto')
            ->first();
        $cantidad = (int) ($totales?->cantidad_envases ?? 0);
        $pesoBruto = round((float) ($totales?->peso_bruto ?? 0), 3);
        $pesoTara = round((float) ($totales?->peso_tara ?? 0), 3);
        $pesoNeto = round((float) ($totales?->peso_neto ?? 0), 3);

        return [
            'cantidad_envases_pesados' => $cantidad,
            'peso_bruto' => $pesoBruto,
            'peso_tara' => $pesoTara,
            'peso_neto' => $pesoNeto,
            'tipo_envase_calculo_neto' => $recepcion->tipo_envase_pesaje?->value,
            'cantidad_envase_calculo_neto' => $cantidad,
            'peso_neto_por_envase' => $cantidad > 0
                ? round($pesoNeto / $cantidad, 3)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, float|int|string>
     */
    private function recalcularCierreCorregido(
        RecepcionRomana $recepcion,
        array $payload,
    ): array {
        $tara = $payload['peso_tara'] !== null
            ? (float) $payload['peso_tara']
            : (float) $recepcion->peso_tara;
        $bruto = (float) $payload['peso_bruto'];
        if ($tara <= 0) {
            throw new ConflictoOperacion(
                'El peso bruto corregido debe ser mayor que la tara registrada.',
            );
        }

        $pesoTaraEnvases = 0.0;
        if ($recepcion->salida_sin_envases) {
            foreach ($payload['envases'] as $envase) {
                $detalle = $recepcion->detallesEnvases->first(
                    fn ($actual): bool => $actual->tipo_envase->value === $envase['tipo_envase'],
                );
                $taraUnitaria = (float) ($detalle?->tara_unitaria_salida ?? 0);
                if ($taraUnitaria <= 0) {
                    throw new ConflictoOperacion(
                        'Configura la tara del nuevo tipo de envase antes de recalcular esta recepción.',
                    );
                }
                $pesoTaraEnvases += $taraUnitaria * (int) $envase['cantidad'];
            }
            $pesoTaraEnvases = round($pesoTaraEnvases, 3);
        }
        $pesoTaraTotal = round($tara + $pesoTaraEnvases, 3);
        if ($pesoTaraTotal >= $bruto) {
            throw new ConflictoOperacion(
                'El peso bruto corregido debe ser mayor que la tara total registrada.',
            );
        }

        $tipoCalculo = (string) (
            $payload['tipo_envase_calculo_neto']
            ?? $recepcion->tipo_envase_calculo_neto
        );
        $detalleCalculo = collect($payload['envases'])->first(
            fn (array $envase): bool => $envase['tipo_envase'] === $tipoCalculo,
        );
        if (! $detalleCalculo) {
            throw new ConflictoOperacion(
                'No puedes retirar el envase utilizado para calcular el neto individual.',
            );
        }

        $cantidad = (int) $detalleCalculo['cantidad'];
        $pesoNeto = round($bruto - $pesoTaraTotal, 3);

        return [
            'peso_tara' => $tara,
            'peso_neto' => $pesoNeto,
            'peso_tara_envases' => $pesoTaraEnvases,
            'tipo_envase_calculo_neto' => $tipoCalculo,
            'cantidad_envase_calculo_neto' => $cantidad,
            'peso_neto_por_envase' => round($pesoNeto / $cantidad, 3),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotCorreccion(RecepcionRomana $recepcion): array
    {
        return [
            'temporada_id' => $recepcion->temporada_id,
            'cliente_id' => $recepcion->cliente_id,
            'especie_validacion_id' => $recepcion->especie_validacion_id,
            'tipo_recepcion' => $recepcion->tipo_recepcion->value,
            'ingreso_at' => $recepcion->ingreso_at?->toAtomString(),
            'concepto_envases' => $recepcion->concepto_envases?->value,
            'tipo_servicio' => $recepcion->tipo_servicio->value,
            'envases' => $recepcion->detallesEnvases
                ->sortBy(fn ($detalle): string => $detalle->tipo_envase->value)
                ->map(fn ($detalle): array => [
                    'tipo_envase' => $detalle->tipo_envase->value,
                    'cantidad' => $detalle->cantidad_declarada,
                    'tara_unitaria_salida' => $detalle->tara_unitaria_salida !== null
                        ? (float) $detalle->tara_unitaria_salida
                        : null,
                ])
                ->values()
                ->all(),
            'numero_guia_despacho' => $recepcion->numero_guia_despacho,
            'patente_camion' => $recepcion->patente_camion,
            'tipo_camion' => $recepcion->tipo_camion?->value,
            'patente_carro' => $recepcion->patente_carro,
            'rut_conductor' => $recepcion->rut_conductor,
            'nombre_conductor' => $recepcion->nombre_conductor,
            'peso_bruto' => $recepcion->peso_bruto !== null ? (float) $recepcion->peso_bruto : null,
            'peso_tara' => $recepcion->peso_tara !== null ? (float) $recepcion->peso_tara : null,
            'peso_neto' => $recepcion->peso_neto !== null ? (float) $recepcion->peso_neto : null,
            'salida_sin_envases' => $recepcion->salida_sin_envases,
            'peso_tara_envases' => $recepcion->peso_tara_envases !== null
                ? (float) $recepcion->peso_tara_envases
                : null,
            'tipo_envase_calculo_neto' => $recepcion->tipo_envase_calculo_neto,
            'cantidad_envase_calculo_neto' => $recepcion->cantidad_envase_calculo_neto,
            'peso_neto_por_envase' => $recepcion->peso_neto_por_envase !== null
                ? (float) $recepcion->peso_neto_por_envase
                : null,
            'tipo_envase_pesaje' => $recepcion->tipo_envase_pesaje?->value,
            'tara_unitaria_envase' => $recepcion->tara_unitaria_envase !== null
                ? (float) $recepcion->tara_unitaria_envase
                : null,
            'cantidad_envases_pesados' => $recepcion->cantidad_envases_pesados,
            'observacion' => $recepcion->observacion,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function resolverIngresoAt(
        array $payload,
        CarbonImmutable $ahora,
        ?RecepcionRomana $recepcion = null,
    ): CarbonImmutable {
        $esSoloEnvases = $payload['tipo_recepcion'] === TipoRecepcionRomana::SoloEnvases->value;
        if (! $esSoloEnvases) {
            if ($recepcion?->tipo_recepcion === TipoRecepcionRomana::SoloEnvases) {
                return CarbonImmutable::instance($recepcion->created_at);
            }

            return $recepcion?->ingreso_at !== null
                ? CarbonImmutable::instance($recepcion->ingreso_at)
                : $ahora;
        }

        $zona = (string) config('app.operational_timezone');
        $horaBase = $recepcion?->ingreso_at !== null
            ? CarbonImmutable::instance($recepcion->ingreso_at)->setTimezone($zona)
            : $ahora->setTimezone($zona);
        $fechaSeleccionada = CarbonImmutable::parse((string) $payload['fecha_ingreso'], $zona)
            ->startOfDay()
            ->setTime(
                $horaBase->hour,
                $horaBase->minute,
                $horaBase->second,
                $horaBase->micro,
            );

        return $fechaSeleccionada->utc();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{peso_tara_envases: float, taras_unitarias: array<string, float>}
     */
    private function calcularTaraEnvasesSalida(
        RecepcionRomana $recepcion,
        array $payload,
    ): array {
        $taras = collect($payload['taras_envases'])->keyBy('tipo_envase');
        $tarasUnitarias = [];
        $pesoTaraEnvases = 0.0;
        foreach ($recepcion->detallesEnvases as $detalle) {
            $tipo = $detalle->tipo_envase->value;
            $taraUnitaria = (float) ($taras->get($tipo)['tara_unitaria'] ?? 0);
            if ($taraUnitaria <= 0) {
                throw new ConflictoOperacion(
                    "Configura la tara unitaria de {$tipo} antes de cerrar la recepción.",
                );
            }
            $tarasUnitarias[$tipo] = $taraUnitaria;
            $pesoTaraEnvases += $taraUnitaria * $detalle->cantidad_declarada;
        }

        if (count($tarasUnitarias) !== $taras->count()) {
            throw new ConflictoOperacion(
                'Las taras configuradas no coinciden con los tipos de envase declarados.',
            );
        }

        return [
            'peso_tara_envases' => $payload['salida_sin_envases'] ? round($pesoTaraEnvases, 3) : 0.0,
            'taras_unitarias' => $tarasUnitarias,
        ];
    }

    /** @param array<int, array{tipo_envase: string, cantidad: int}> $envases */
    private function sincronizarEnvases(RecepcionRomana $recepcion, array $envases): void
    {
        $tipos = collect($envases)->pluck('tipo_envase')->all();
        $recepcion->detallesEnvases()->whereNotIn('tipo_envase', $tipos)->delete();

        foreach ($envases as $envase) {
            $recepcion->detallesEnvases()->updateOrCreate(
                ['tipo_envase' => $envase['tipo_envase']],
                [
                    'cantidad_declarada' => $envase['cantidad'],
                    ...($recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases
                        ? ['tara_unitaria_salida' => $recepcion->tara_unitaria_envase]
                        : []),
                ],
            );
        }
    }

    /**
     * Editar o corregir una recepción nunca la cambia de temporada: antes, el
     * formulario enviaba la temporada activa y la recepción quedaba trasladada.
     */
    private function asegurarMismaTemporada(RecepcionRomana $recepcion, Temporada $temporada): void
    {
        if ($recepcion->temporada_id !== null && $recepcion->temporada_id !== $temporada->id) {
            throw new ConflictoOperacion(
                "La recepción {$recepcion->numero_recepcion} pertenece a otra temporada y no puede trasladarse a {$temporada->codigo}.",
            );
        }
    }

    private function temporadaActiva(string $temporadaId): Temporada
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();

        if (! $temporada || $temporada->id !== $temporadaId) {
            throw new ConflictoOperacion('La temporada global no está activa para nuevas recepciones.');
        }

        return $temporada;
    }

    private function clienteActivo(string $clienteId): Cliente
    {
        $cliente = Cliente::query()
            ->whereKey($clienteId)
            ->where('activo', true)
            ->first();

        if (! $cliente) {
            throw new ConflictoOperacion('El cliente operacional no está activo para nuevas recepciones.');
        }

        return $cliente;
    }

    private function asegurarGuiaUnica(
        string $temporadaId,
        string $clienteId,
        string $guia,
        ?string $ignorarId = null,
    ): void {
        $consulta = RecepcionRomana::query()
            ->where('temporada_id', $temporadaId)
            ->where('cliente_id', $clienteId)
            ->where('numero_guia_despacho', $guia);
        if ($ignorarId) {
            $consulta->where('id', '!=', $ignorarId);
        }

        if ($consulta->exists()) {
            throw new ConflictoOperacion('La guía de despacho ya fue registrada para este cliente.');
        }
    }

    private function siguienteNumero(CarbonImmutable $fecha): string
    {
        $periodo = $fecha->format('ym');
        DB::table('correlativos_recepcion_romana')->insertOrIgnore([
            'periodo' => $periodo,
            'ultimo_numero' => 0,
            'created_at' => $fecha,
            'updated_at' => $fecha,
        ]);
        $correlativo = DB::table('correlativos_recepcion_romana')
            ->where('periodo', $periodo)
            ->lockForUpdate()
            ->first();
        $siguiente = ((int) $correlativo->ultimo_numero) + 1;
        DB::table('correlativos_recepcion_romana')
            ->where('periodo', $periodo)
            ->update(['ultimo_numero' => $siguiente, 'updated_at' => $fecha]);

        return sprintf('REC-%s-%04d', $periodo, $siguiente);
    }

    /** @param array<string, mixed> $datos */
    private function registrarEvento(
        RecepcionRomana $recepcion,
        string $operacionId,
        string $hash,
        TipoEventoRomana $tipo,
        ?EstadoRecepcionRomana $estadoAnterior,
        EstadoRecepcionRomana $estadoNuevo,
        User $usuario,
        CarbonImmutable $ocurridoAt,
        array $datos,
    ): void {
        EventoRecepcionRomana::create([
            'operacion_id' => $operacionId,
            'payload_hash' => $hash,
            'recepcion_romana_id' => $recepcion->id,
            'tipo' => $tipo,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'user_id' => $usuario->id,
            'ocurrido_at' => $ocurridoAt,
            'datos' => $datos,
        ]);
    }

    private function asegurarEventoIdempotente(
        EventoRecepcionRomana $evento,
        RecepcionRomana $recepcion,
        string $hash,
        TipoEventoRomana $tipo,
    ): void {
        if ($evento->recepcion_romana_id !== $recepcion->id
            || $evento->payload_hash !== $hash
            || $evento->tipo !== $tipo) {
            throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
        }
    }

    private function asegurarMismoPayload(string $existente, string $recibido): void
    {
        if (! hash_equals($existente, $recibido)) {
            throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        ksort($payload);

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new ConflictoOperacion('No fue posible validar la operación de romana.', previous: $exception);
        }
    }

    private function cargar(RecepcionRomana $recepcion): RecepcionRomana
    {
        return $recepcion->refresh()->load([
            'temporada',
            'cliente',
            'creadoPor',
            'ingresoConfirmadoPor',
            'cerradoPor',
            'validacionTomadaPor',
            'detallesEnvases',
            'pesajesEnvases' => fn ($consulta) => $consulta
                ->with(['registradoPor', 'anuladoPor'])
                ->orderBy('secuencia'),
            'eventos' => fn ($consulta) => $consulta->with('usuario')->orderBy('ocurrido_at'),
        ]);
    }
}
