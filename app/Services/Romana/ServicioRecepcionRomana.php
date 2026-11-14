<?php

namespace App\Services\Romana;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\PesajeEnvaseRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Documentos\ServicioFormatosRegistro;
use App\Services\Envases\DocumentoSalidaEnvases;
use App\Services\Envases\ServicioDevolucionEnvases;
use App\Services\Envases\ServicioPrestamoInternoEnvases;
use App\Services\MateriaPrima\ServicioLoteMateriaPrima;
use App\Services\Notificaciones\ServicioNotificacionesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use JsonException;
use Ramsey\Uuid\Uuid;

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
            $devolucion = $this->datosDevolucion($payload, $cliente);
            $ahora = CarbonImmutable::now();
            $ingresoAt = $this->resolverIngresoAt($payload, $ahora);
            $this->asegurarCronologiaDevolucion($devolucion, $cliente, $temporada, $ingresoAt);
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
                ...$devolucion,
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
            app(ServicioGuiasRecepcionRomana::class)->guardar($recepcion, $payload);

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
                    'guias' => app(ServicioGuiasRecepcionRomana::class)->datos($recepcion),
                    'temporada_id' => $recepcion->temporada_id,
                    'fecha_ingreso' => $payload['fecha_ingreso'],
                    'ingreso_at' => $ingresoAt->toAtomString(),
                    'envases' => $payload['envases'],
                    'tipo_envase_pesaje' => $payload['tipo_envase_pesaje'],
                    'tara_unitaria_envase' => $payload['tara_unitaria_envase'],
                    ...($devolucion['devolucion_productor_csg_id'] !== null ? ['devolucion' => $recepcion->datosDevolucion()] : []),
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

            if ($recepcion->estado === EstadoRecepcionRomana::Anulada || $recepcion->estado_validacion_mp !== EstadoValidacionMp::Pendiente) {
                throw new ConflictoOperacion('La recepción ya fue tomada por Validación MP y sus antecedentes no pueden editarse.');
            }
            if (! $recepcion->estado->esEditable()) {
                throw new ConflictoOperacion('La recepción ya confirmó su ingreso y sus antecedentes no pueden editarse.');
            }
            if ($recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaConEnvases
                && (int) round((float) $recepcion->peso_bruto * 1000) !== (int) round((float) $payload['peso_bruto'] * 1000)) {
                Gate::forUser($usuario)->authorize('corregir-peso-bruto-romana');
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
            $devolucion = $this->datosDevolucion($payload, $cliente);
            $envasePrincipal = $payload['envases'][0];
            $ahora = CarbonImmutable::now();
            $ingresoAt = $this->resolverIngresoAt($payload, $ahora, $recepcion);
            $this->asegurarCronologiaDevolucion($devolucion, $cliente, $temporada, $ingresoAt);
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
                ...$devolucion,
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
                'reparto_neto_envases' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? null
                    : $recepcion->reparto_neto_envases,
                'observacion' => $payload['observacion'],
                'version' => $recepcion->version + 1,
            ]);
            $this->sincronizarEnvases($recepcion, $payload['envases']);
            app(ServicioGuiasRecepcionRomana::class)->guardar($recepcion, $payload);
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
                    'envases' => $payload['envases'],
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

            if ($recepcion->estado === EstadoRecepcionRomana::Anulada || $recepcion->estado_validacion_mp !== EstadoValidacionMp::Pendiente) {
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

            $devolucion = $this->datosDevolucion($payload, $cliente);
            $estado = $recepcion->estado;
            $anterior = $this->snapshotCorreccion($recepcion);
            $envasePrincipal = $payload['envases'][0];
            $ahora = CarbonImmutable::now();
            $ingresoAt = $this->resolverIngresoAt($payload, $ahora, $recepcion);
            $this->asegurarCronologiaDevolucion($devolucion, $cliente, $temporada, $ingresoAt);
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
                ...$devolucion,
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
                ...($recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases
                    ? ['reparto_neto_envases' => null]
                    : []),
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
            app(ServicioGuiasRecepcionRomana::class)->guardar($recepcion, $payload);
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

    /** Corrige solo la lectura de bruto; no vuelve a registrar ingresos ni salidas de envases. */
    public function corregirPesoBruto(RecepcionRomana $recepcion, array $datos, User $usuario): RecepcionRomana
    {
        Gate::forUser($usuario)->authorize('corregir-peso-bruto-romana');
        $payload = ['accion' => 'corregir_peso_bruto', 'recepcion_id' => $recepcion->id,
            'version_conocida' => (int) $datos['version_conocida'], 'peso_bruto' => round((float) $datos['peso_bruto'], 3),
            'pesaje_id' => $datos['pesaje_id'] ?? null, 'motivo_correccion' => $datos['motivo_correccion']];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->with('detallesEnvases')->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                $this->asegurarEventoIdempotente($evento, $recepcion, $hash, TipoEventoRomana::PesoBrutoCorregido);

                return $this->cargar($recepcion);
            }
            if ($recepcion->estado === EstadoRecepcionRomana::Anulada || $recepcion->eliminada_at !== null
                || $recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases) {
                throw new ConflictoOperacion('Solo puedes corregir el bruto de una recepción de fruta vigente.');
            }
            $this->temporadaActiva($recepcion->temporada_id);
            if ($recepcion->version !== $payload['version_conocida']) {
                throw new ConflictoOperacion('La recepción cambió. Actualiza el expediente antes de corregir el peso bruto.');
            }
            $anterior = $this->snapshotPesos($recepcion);
            $pesajeAnterior = null;
            $pesajePosterior = null;
            if ($recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases) {
                if ($payload['pesaje_id'] === null) {
                    throw ValidationException::withMessages(['pesaje_id' => 'Selecciona la tanda cuyo peso bruto deseas corregir.']);
                }
                $pesaje = $recepcion->pesajesEnvases()->whereKey($payload['pesaje_id'])->whereNull('anulado_at')->lockForUpdate()->firstOrFail();
                $pesajeAnterior = ['id' => $pesaje->id, 'secuencia' => $pesaje->secuencia, 'peso_bruto' => (float) $pesaje->peso_bruto,
                    'peso_tara' => (float) $pesaje->peso_tara, 'peso_neto' => (float) $pesaje->peso_neto, 'envases' => $pesaje->lineas()];
                $this->asegurarCambioBruto((float) $pesaje->peso_bruto, $payload['peso_bruto']);
                $netoGramos = (int) round($payload['peso_bruto'] * 1000) - (int) round((float) $pesaje->peso_tara * 1000);
                if ($netoGramos <= 0) {
                    throw ValidationException::withMessages(['peso_bruto' => 'El bruto corregido debe superar la tara de la tanda.']);
                }
                // Conserva las proporciones medidas y las taras originales de la tanda,
                // aunque las referencias de la especie hayan cambiado desde su registro.
                // Los bins que contienen totes no tienen neto propio y siguen en cero.
                $lineas = app(ServicioRepartoEnvases::class)->lineasConFruta($recepcion->especie_validacion_id, $pesaje->lineas());
                $base = array_sum(array_map(fn ($linea): int => (int) round($linea['peso_neto'] * 1000), $lineas));
                if ($base <= 0) {
                    throw new ConflictoOperacion('La tanda no tiene un reparto de neto válido para corregirla.');
                }
                $conNeto = array_keys(array_filter($lineas, fn ($linea): bool => (int) round($linea['peso_neto'] * 1000) > 0));
                $asignados = 0;
                foreach ($lineas as $i => &$linea) {
                    if (! in_array($i, $conNeto, true)) {
                        $linea['peso_neto'] = 0.0;

                        continue;
                    }
                    $gramos = $i === end($conNeto) ? $netoGramos - $asignados
                        : intdiv($netoGramos * (int) round($linea['peso_neto'] * 1000), $base);
                    if ($gramos <= 0) {
                        throw ValidationException::withMessages(['peso_bruto' => 'El bruto es insuficiente para mantener netos positivos en todos los envases de la tanda.']);
                    }
                    $linea['peso_neto'] = $gramos / 1000;
                    $asignados += $gramos;
                }
                unset($linea);
                $pesaje->update(['peso_bruto' => $payload['peso_bruto'], 'peso_neto' => $netoGramos / 1000, 'envases' => $lineas]);
                $pesajePosterior = [...$pesajeAnterior, 'peso_bruto' => (float) $pesaje->peso_bruto,
                    'peso_neto' => (float) $pesaje->peso_neto, 'envases' => $lineas];
                $actualizacion = $this->resumenPesajesEnvases($recepcion);
            } else {
                if ($payload['pesaje_id'] !== null) {
                    throw ValidationException::withMessages(['pesaje_id' => 'Esta recepción registra el bruto del camión, sin tandas.']);
                }
                $this->asegurarCambioBruto((float) $recepcion->peso_bruto, $payload['peso_bruto']);
                $actualizacion = ['peso_bruto' => $payload['peso_bruto']];
                if ($recepcion->estado === EstadoRecepcionRomana::Cerrado) {
                    if ($recepcion->peso_tara === null) {
                        throw new ConflictoOperacion('La recepción cerrada no tiene una tara registrada. Revisa el expediente.');
                    }
                    $neto = ((int) round($payload['peso_bruto'] * 1000) - (int) round((float) $recepcion->peso_tara * 1000)
                        - (int) round((float) $recepcion->peso_tara_envases * 1000)) / 1000;
                    if ($neto <= 0) {
                        throw ValidationException::withMessages(['peso_bruto' => 'El bruto corregido debe superar la tara total registrada.']);
                    }
                    $actualizacion['peso_neto'] = $neto;
                    if ($recepcion->reparto_neto_envases) {
                        $congelado = $recepcion->reparto_neto_envases;
                        $reparto = app(ServicioRepartoEnvases::class)->calcular($neto,
                            array_column($congelado, 'cantidad', 'tipo_envase'), array_column($congelado, 'tipo_envase'),
                            array_column($congelado, 'peso_referencia', 'tipo_envase'));
                        $actualizacion['reparto_neto_envases'] = $reparto;
                        $actualizacion['peso_neto_por_envase'] = round($reparto[0]['peso_neto_unitario'], 3);
                    } elseif ($recepcion->cantidad_envase_calculo_neto > 0) {
                        $actualizacion['peso_neto_por_envase'] = round($neto / $recepcion->cantidad_envase_calculo_neto, 3);
                    } else {
                        throw new ConflictoOperacion('La recepción cerrada no tiene cantidades válidas para recalcular el neto por envase.');
                    }
                }
            }
            $recepcion->update([...$actualizacion, 'version' => $recepcion->version + 1]);
            if ($recepcion->estado === EstadoRecepcionRomana::Cerrado) {
                app(ServicioLoteMateriaPrima::class)->conciliarDestare($recepcion, $usuario,
                    correccionPeso: ['operacion_romana_id' => (string) $datos['operacion_id'], 'motivo' => $payload['motivo_correccion']]);
            }
            $this->registrarEvento($recepcion, (string) $datos['operacion_id'], $hash, TipoEventoRomana::PesoBrutoCorregido,
                $recepcion->estado, $recepcion->estado, $usuario, CarbonImmutable::now(),
                ['motivo' => $payload['motivo_correccion'], 'version' => $recepcion->version,
                    'anterior' => $anterior, 'posterior' => $this->snapshotPesos($recepcion),
                    'pesaje_anterior' => $pesajeAnterior, 'pesaje_posterior' => $pesajePosterior]);

            return $this->cargar($recepcion);
        }, attempts: 3);
    }

    private function asegurarCambioBruto(float $anterior, float $nuevo): void
    {
        if ((int) round($anterior * 1000) === (int) round($nuevo * 1000)) {
            throw ValidationException::withMessages(['peso_bruto' => 'El peso bruto corregido es igual al registrado.']);
        }
    }

    private function snapshotPesos(RecepcionRomana $recepcion): array
    {
        return ['peso_bruto' => $recepcion->peso_bruto !== null ? (float) $recepcion->peso_bruto : null,
            'peso_tara' => $recepcion->peso_tara !== null ? (float) $recepcion->peso_tara : null,
            'peso_tara_envases' => $recepcion->peso_tara_envases !== null ? (float) $recepcion->peso_tara_envases : null,
            'peso_neto' => $recepcion->peso_neto !== null ? (float) $recepcion->peso_neto : null,
            'peso_neto_por_envase' => $recepcion->peso_neto_por_envase !== null ? (float) $recepcion->peso_neto_por_envase : null,
            'reparto_neto_envases' => $recepcion->reparto_neto_envases];
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
            // La cantidad total (un solo tipo) conserva el contrato y hash anteriores.
            ...(isset($datos['envases'])
                ? ['envases' => collect($datos['envases'])
                    ->map(fn (array $linea): array => [
                        'tipo_envase' => (string) $linea['tipo_envase'],
                        'cantidad' => (int) $linea['cantidad'],
                    ])
                    ->filter(fn (array $linea): bool => $linea['cantidad'] > 0)
                    ->sortBy('tipo_envase')
                    ->values()
                    ->all()]
                : ['cantidad_envases' => (int) $datos['cantidad_envases']]),
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
            $declaradas = $this->cantidadesDeclaradasParaPesaje($recepcion);
            $lineas = $this->lineasSolicitadasTanda($payload, $declaradas);
            $pesadas = $this->pesadoPorTipo($recepcion);
            foreach ($lineas as $linea) {
                $pendientes = max(0, $declaradas[$linea['tipo_envase']] - ($pesadas[$linea['tipo_envase']]['cantidad'] ?? 0));
                if ($linea['cantidad'] > $pendientes) {
                    throw new ConflictoOperacion(count($declaradas) === 1
                        ? sprintf('La lectura supera los %d envases pendientes de pesaje.', $pendientes)
                        : sprintf(
                            'La lectura supera los envases pendientes de %s: quedan %d.',
                            TipoEnvaseRomana::from($linea['tipo_envase'])->etiqueta(),
                            $pendientes,
                        ));
                }
            }

            // Se calcula en gramos para que tara y neto de la tanda cuadren sin redondeos.
            $taras = $this->tarasPesaje($recepcion);
            $tarasGramos = array_map(
                fn (array $linea): int => (int) round($taras[$linea['tipo_envase']] * 1000) * $linea['cantidad'],
                $lineas,
            );
            $pesoTaraGramos = array_sum($tarasGramos);
            $pesoBrutoGramos = (int) round($payload['peso_bruto'] * 1000);
            if ($pesoBrutoGramos <= $pesoTaraGramos) {
                throw new ConflictoOperacion(
                    'El peso bruto del grupo debe ser mayor que la tara total de sus envases.',
                );
            }
            $netosGramos = $this->repartirNetoTanda($recepcion, $lineas, $pesoBrutoGramos - $pesoTaraGramos);
            $detalle = array_map(fn (array $linea, int $tara, int $neto): array => [
                'tipo_envase' => $linea['tipo_envase'],
                'cantidad' => $linea['cantidad'],
                'tara_unitaria' => round($taras[$linea['tipo_envase']], 3),
                'peso_tara' => $tara / 1000,
                'peso_neto' => $neto / 1000,
            ], $lineas, $tarasGramos, $netosGramos);
            $cantidad = array_sum(array_column($lineas, 'cantidad'));
            $secuencia = ((int) $recepcion->pesajesEnvases()->max('secuencia')) + 1;
            $ahora = CarbonImmutable::now();
            $pesaje = PesajeEnvaseRecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'recepcion_romana_id' => $recepcion->id,
                'secuencia' => $secuencia,
                // Con varios tipos, la lectura conserva el primero y la tara promedio; el detalle va por tipo.
                'tipo_envase' => $lineas[0]['tipo_envase'],
                'cantidad_envases' => $cantidad,
                'envases' => $detalle,
                'peso_bruto' => $payload['peso_bruto'],
                'tara_unitaria_envase' => round($pesoTaraGramos / $cantidad / 1000, 3),
                'peso_tara' => $pesoTaraGramos / 1000,
                'peso_neto' => ($pesoBrutoGramos - $pesoTaraGramos) / 1000,
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
                    'envases' => $detalle,
                    'peso_bruto' => (float) $pesaje->peso_bruto,
                    'peso_tara' => (float) $pesaje->peso_tara,
                    'peso_neto' => (float) $pesaje->peso_neto,
                    'cantidad_envases_pesados' => $resumen['cantidad_envases_pesados'],
                    'cantidad_envases_declarados' => array_sum($declaradas),
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

        $payload = $this->payloadCierre($recepcion, $datos);
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $documentoSalida = app(DocumentoSalidaEnvases::class);
            $documentoSalida->bloquear($recepcion->temporada_id, $recepcion->cliente_id, $payload['modo_salida_envases'] !== 'vacio' ? $payload['numero_guia_salida'] : null, 'numero_guia_salida');
            $clienteConocido = $recepcion->cliente_id;
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

            if ($recepcion->estado_validacion_mp !== EstadoValidacionMp::Validada
                || $recepcion->detallesEnvases->contains(fn ($detalle): bool => $detalle->cantidad_validada === null)) {
                throw new ConflictoOperacion('Completa la Validación MP en la PDA antes de registrar el destare.');
            }
            $this->temporadaActiva($recepcion->temporada_id);
            $tara = (float) $payload['peso_tara'];
            $bruto = (float) $recepcion->peso_bruto;
            if ($recepcion->cliente_id !== $clienteConocido) {
                throw new ConflictoOperacion('La recepción cambió de cliente. Actualiza antes de registrar su salida.');
            }
            if ($payload['modo_salida_envases'] !== 'vacio') {
                $documentoSalida->asegurarSinDespacho($recepcion->temporada_id, $recepcion->cliente_id, $payload['numero_guia_salida'], $recepcion->id);
            }
            $tarasEnvases = $this->calcularTaraEnvasesSalida($recepcion, $payload);
            // Camión con varios CSG: Romana indica a qué productor se entregan los envases que salen.
            $productorSalida = app(ProductoresRecepcion::class)->elegirSalida($recepcion, $payload['salida_productor_csg'],
                array_sum($tarasEnvases['salidas']) > 0);
            if ($payload['modo_salida_envases'] !== 'vacio') {
                app(ServicioInspeccionEnvases::class)->guardar($recepcion, 'despacho', $tarasEnvases['salidas'], $payload['inspeccion_envases'], $usuario, $datos['operacion_id']);
            }
            $pesoTaraEnvases = $tarasEnvases['peso_tara_envases'];
            $pesoNeto = round($bruto - $tara - $pesoTaraEnvases, 3);
            if ($pesoNeto <= 0) {
                throw new ConflictoOperacion('El peso neto de fruta debe ser mayor que cero.');
            }

            $reparto = app(ServicioRepartoEnvases::class)->preparar($recepcion, $pesoNeto,
                $payload['envases_reparto'] ?? ($payload['tipo_envase_calculo_neto'] ? [$payload['tipo_envase_calculo_neto']] : null));
            $tipoCalculo = $reparto[0]['tipo_envase'];
            $detalleCalculo = $recepcion->detallesEnvases->first(fn ($d): bool => $d->tipo_envase->value === $tipoCalculo);
            $ahora = CarbonImmutable::now();
            $pesoNetoPorEnvase = round($reparto[0]['peso_neto_unitario'], 3);
            $recepcion->update([
                'peso_tara' => $tara,
                'peso_neto' => $pesoNeto,
                'salida_sin_envases' => false,
                'modo_salida_envases' => $payload['modo_salida_envases'],
                'numero_guia_salida' => $payload['numero_guia_salida'],
                'salida_productor_snapshot' => $productorSalida,
                'peso_tara_envases' => $pesoTaraEnvases,
                'tipo_envase_calculo_neto' => $tipoCalculo,
                'cantidad_envase_calculo_neto' => $detalleCalculo->cantidadConFruta(),
                'reparto_neto_envases' => $reparto,
                'peso_neto_por_envase' => $pesoNetoPorEnvase,
                'estado' => EstadoRecepcionRomana::Cerrado,
                'salida_at' => $ahora,
                'cerrado_por_user_id' => $usuario->id,
                ...app(ServicioFormatosRegistro::class)->snapshotRomana(),
                'observacion_cierre' => $payload['observacion'],
                'version' => $recepcion->version + 1,
            ]);
            foreach ($recepcion->detallesEnvases as $detalle) {
                $detalle->update([
                    'tara_unitaria_salida' => $tarasEnvases['taras_unitarias'][$detalle->tipo_envase->value]
                        ?? null,
                ]);
            }
            // Los bins de planta prestados en Validación MP no salen con el camión: se devuelven
            // internamente antes de registrar los envases que sí salen.
            app(ServicioPrestamoInternoEnvases::class)->devolver($recepcion, $usuario, $ahora, $payload['numero_guia_salida'] ?? null);
            foreach ($tarasEnvases['salidas'] as $tipo => $cantidad) {
                if ($cantidad === 0) {
                    continue;
                }
                $movimiento = $this->registrarSalidaEnvase($recepcion, $tipo, $cantidad, $payload['numero_guia_salida'], $usuario, $ahora);
                $recepcion->salidasEnvases()->create([
                    'tipo_envase' => $tipo, 'cantidad' => $cantidad,
                    'tara_unitaria' => $tarasEnvases['taras_unitarias'][$tipo],
                    'movimiento_envase_id' => $movimiento->id,
                ]);
            }
            app(ServicioLoteMateriaPrima::class)->conciliarDestare($recepcion, $usuario);
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
                    'modo_salida_envases' => $payload['modo_salida_envases'],
                    'numero_guia_salida' => $payload['numero_guia_salida'],
                    'salida_productor' => $productorSalida,
                    'salidas_envases' => $tarasEnvases['salidas'],
                    'peso_tara_envases' => $pesoTaraEnvases,
                    'peso_tara_total' => round($tara + $pesoTaraEnvases, 3),
                    'peso_neto' => (float) $recepcion->peso_neto,
                    'tipo_envase_calculo_neto' => $tipoCalculo,
                    'cantidad_envase_calculo_neto' => $detalleCalculo->cantidadConFruta(),
                    'peso_neto_por_envase' => $pesoNetoPorEnvase,
                    'observacion_cierre' => $payload['observacion'],
                ],
            );

            return $this->cargar($recepcion);
        });
    }

    /** @param array<string, mixed> $datos */
    public function corregirSalidaEnvases(RecepcionRomana $recepcion, array $datos, User $usuario): RecepcionRomana
    {
        $payload = [
            ...$this->payloadCierre($recepcion, $datos),
            'accion' => 'corregir_salida_envases',
            'version_conocida' => (int) $datos['version_conocida'],
            'motivo_correccion' => $datos['motivo_correccion'],
        ];
        $hash = $this->hash($payload);

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $documentoSalida = app(DocumentoSalidaEnvases::class);
            $documentoSalida->bloquear($recepcion->temporada_id, $recepcion->cliente_id, $payload['modo_salida_envases'] !== 'vacio' ? $payload['numero_guia_salida'] : null, 'numero_guia_salida');
            $clienteConocido = $recepcion->cliente_id;
            $recepcion = RecepcionRomana::query()->with(['detallesEnvases', 'guiasRecepcion', 'salidasEnvases'])
                ->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                $this->asegurarEventoIdempotente($evento, $recepcion, $hash, TipoEventoRomana::SalidaEnvasesCorregida);

                return $this->cargar($recepcion);
            }
            if ($recepcion->estado !== EstadoRecepcionRomana::Cerrado
                || $recepcion->tipo_recepcion !== TipoRecepcionRomana::FrutaConEnvases
                || $recepcion->modo_salida_envases === null) {
                throw new ConflictoOperacion('Solo puedes corregir salidas de recepciones cerradas con el nuevo registro por tipo.');
            }
            $this->temporadaActiva($recepcion->temporada_id);
            if ($recepcion->version !== $payload['version_conocida']) {
                throw new ConflictoOperacion('La recepción cambió. Actualiza el expediente antes de corregir la salida.');
            }
            if (MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', TipoMovimientoEnvase::ConciliacionSalida->value)->exists()) {
                throw new ConflictoOperacion('La salida está conciliada con un despacho. Sus cantidades y guía requieren revisión conjunta antes de corregirse.');
            }
            if ($recepcion->lotesMateriaPrima()->exists()) {
                throw new ConflictoOperacion('El lote ya fue creado. La corrección de pesos y saldos requiere conciliación supervisada.');
            }
            if ($payload['envases_reparto'] !== null && $payload['envases_reparto'] !== collect(app(ServicioRepartoEnvases::class)->tipos($recepcion))->sort()->values()->all()) {
                throw new ConflictoOperacion('La corrección de salida conserva los envases de reparto del destare.');
            }
            if (round((float) $recepcion->peso_tara, 2) !== $payload['peso_tara']
                || ($payload['tipo_envase_calculo_neto'] !== null
                    && $payload['tipo_envase_calculo_neto'] !== $recepcion->tipo_envase_calculo_neto)) {
                throw new ConflictoOperacion('La corrección de salida no permite cambiar el pesaje ni el envase contenedor.');
            }

            if ($recepcion->cliente_id !== $clienteConocido) {
                throw new ConflictoOperacion('La recepción cambió de cliente. Actualiza antes de registrar su salida.');
            }
            if ($payload['modo_salida_envases'] !== 'vacio') {
                $documentoSalida->asegurarSinDespacho($recepcion->temporada_id, $recepcion->cliente_id, $payload['numero_guia_salida'], $recepcion->id);
            }
            $resultado = $this->calcularTaraEnvasesSalida($recepcion, $payload);
            $nuevoNeto = round((float) $recepcion->peso_bruto - (float) $recepcion->peso_tara
                - $resultado['peso_tara_envases'], 3);
            if ($nuevoNeto <= 0) {
                throw new ConflictoOperacion('El peso neto de fruta debe ser mayor que cero.');
            }
            $anteriores = $recepcion->salidasEnvases->keyBy(fn ($salida): string => $salida->tipo_envase->value);
            $tipos = collect(array_keys($resultado['salidas']))->merge($anteriores->keys())->unique();
            $productorSalida = app(ProductoresRecepcion::class)->elegirSalida($recepcion, $payload['salida_productor_csg'],
                array_sum($resultado['salidas']) > 0);
            $cambio = ($recepcion->salida_productor_snapshot['codigo'] ?? null) !== ($productorSalida['codigo'] ?? null)
                || $recepcion->modo_salida_envases !== $payload['modo_salida_envases']
                || $recepcion->numero_guia_salida !== $payload['numero_guia_salida']
                || abs((float) $recepcion->peso_neto - $nuevoNeto) > 0.0005
                || $tipos->contains(fn (string $tipo): bool => (int) ($anteriores->get($tipo)?->cantidad ?? 0)
                    !== (int) ($resultado['salidas'][$tipo] ?? 0));
            if (! $cambio) {
                throw new ConflictoOperacion('La salida corregida no presenta cambios.');
            }
            if ($payload['modo_salida_envases'] !== 'vacio') {
                app(ServicioInspeccionEnvases::class)->guardar($recepcion, 'despacho', $resultado['salidas'], $payload['inspeccion_envases'], $usuario, $datos['operacion_id'], $datos['motivo_correccion']);
            }
            $ahora = CarbonImmutable::now();
            $salidasAnteriores = $anteriores->mapWithKeys(
                fn ($salida): array => [$salida->tipo_envase->value => $salida->cantidad],
            )->all();
            foreach ($tipos as $tipo) {
                $anterior = $anteriores->get($tipo);
                $cantidadNueva = (int) ($resultado['salidas'][$tipo] ?? 0);
                if ($anterior && $anterior->cantidad > 0) {
                    $movimiento = MovimientoEnvase::query()->lockForUpdate()->findOrFail($anterior->movimiento_envase_id);
                    if (DetalleGuiaDespachoEnvase::query()->where('movimiento_origen_id', $movimiento->id)->exists()
                        || MovimientoEnvase::query()->where('movimiento_origen_id', $movimiento->id)->exists()) {
                        throw new ConflictoOperacion('La salida ya tiene movimientos vinculados; requiere conciliación supervisada.');
                    }
                    MovimientoEnvase::create([
                        'operacion_id' => (string) Uuid::uuid4(),
                        'temporada_id' => $recepcion->temporada_id,
                        'cliente_id' => $recepcion->cliente_id,
                        'recepcion_romana_id' => $recepcion->id,
                        'documento_tipo' => 'recepcion_romana',
                        'documento_id' => $recepcion->id,
                        'numero_documento' => $movimiento->numero_documento,
                        'tipo_movimiento' => TipoMovimientoEnvase::ReversionSalidaRecepcionFruta,
                        'tipo_envase' => $tipo,
                        'cantidad' => $anterior->cantidad,
                        'signo_cuenta' => 1,
                        'signo_existencia' => 1,
                        'propiedad' => $movimiento->propiedad,
                        'movimiento_origen_id' => $movimiento->id,
                        'ocurrido_at' => $ahora,
                        'salida_at' => $ahora,
                        'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
                        'creado_por_user_id' => $usuario->id,
                        'datos' => ['motivo_correccion' => $payload['motivo_correccion']],
                    ]);
                }
                $nuevo = $cantidadNueva > 0 ? MovimientoEnvase::create([
                    'operacion_id' => (string) Uuid::uuid4(),
                    'temporada_id' => $recepcion->temporada_id,
                    'cliente_id' => $recepcion->cliente_id,
                    'recepcion_romana_id' => $recepcion->id,
                    'documento_tipo' => 'recepcion_romana',
                    'documento_id' => $recepcion->id,
                    'numero_documento' => $payload['numero_guia_salida'],
                    'tipo_movimiento' => TipoMovimientoEnvase::SalidaRecepcionFruta,
                    'tipo_envase' => $tipo,
                    'cantidad' => $cantidadNueva,
                    'signo_cuenta' => -1,
                    'signo_existencia' => -1,
                    'propiedad' => $recepcion->propiedadEnvase($tipo),
                    'ocurrido_at' => $ahora,
                    'salida_at' => $ahora,
                    'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
                    'creado_por_user_id' => $usuario->id,
                    'datos' => ['motivo_correccion' => $payload['motivo_correccion']],
                ]) : null;
                if ($anterior) {
                    $anterior->update([
                        'cantidad' => $cantidadNueva,
                        'tara_unitaria' => $resultado['taras_unitarias'][$tipo] ?? $anterior->tara_unitaria,
                        'movimiento_envase_id' => $nuevo?->id,
                    ]);
                } elseif ($nuevo) {
                    $recepcion->salidasEnvases()->create([
                        'tipo_envase' => $tipo,
                        'cantidad' => $cantidadNueva,
                        'tara_unitaria' => $resultado['taras_unitarias'][$tipo],
                        'movimiento_envase_id' => $nuevo->id,
                    ]);
                }
            }
            foreach ($recepcion->detallesEnvases as $detalle) {
                $detalle->update(['tara_unitaria_salida' => $resultado['taras_unitarias'][$detalle->tipo_envase->value]]);
            }
            $netoAnterior = (float) $recepcion->peso_neto;
            $recepcion->update([
                'modo_salida_envases' => $payload['modo_salida_envases'],
                'salida_sin_envases' => $payload['modo_salida_envases'] === 'vacio',
                'numero_guia_salida' => $payload['numero_guia_salida'],
                'salida_productor_snapshot' => $productorSalida,
                'peso_tara_envases' => $resultado['peso_tara_envases'],
                'peso_neto' => $nuevoNeto,
                'peso_neto_por_envase' => $recepcion->reparto_neto_envases
                    ? round($recepcion->reparto_neto_envases[0]['peso_neto_unitario'] * $nuevoNeto / $netoAnterior, 3)
                    : round($nuevoNeto / $recepcion->cantidad_envase_calculo_neto, 3),
                'reparto_neto_envases' => $recepcion->reparto_neto_envases ? array_map(fn ($fila): array => [...$fila,
                    'peso_neto_unitario' => $fila['peso_neto_unitario'] * $nuevoNeto / $netoAnterior], $recepcion->reparto_neto_envases) : null,
                'version' => $recepcion->version + 1,
            ]);
            $this->registrarEvento(
                $recepcion, (string) $datos['operacion_id'], $hash,
                TipoEventoRomana::SalidaEnvasesCorregida,
                EstadoRecepcionRomana::Cerrado, EstadoRecepcionRomana::Cerrado,
                $usuario, $ahora, [
                    'motivo' => $payload['motivo_correccion'],
                    'salidas_anteriores' => $salidasAnteriores,
                    'salidas_nuevas' => $resultado['salidas'],
                    'neto_anterior' => $netoAnterior,
                    'neto_nuevo' => $nuevoNeto,
                    'salida_productor' => $productorSalida,
                ],
            );

            return $this->cargar($recepcion);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    private function payloadCierre(RecepcionRomana $recepcion, array $datos): array
    {
        return [
            'accion' => 'cerrar',
            'inspeccion_envases' => $datos['inspeccion_envases'] ?? null,
            'recepcion_id' => $recepcion->id,
            'peso_tara' => round((float) $datos['peso_tara'], 2),
            'envases_reparto' => isset($datos['envases_reparto']) ? collect($datos['envases_reparto'])->sort()->values()->all() : null,
            'tipo_envase_calculo_neto' => $datos['tipo_envase_calculo_neto'] ?? null,
            'modo_salida_envases' => $datos['modo_salida_envases'],
            'numero_guia_salida' => $datos['numero_guia_salida'] ?? null,
            'salida_productor_csg' => filled($datos['salida_productor_csg'] ?? null) ? trim((string) $datos['salida_productor_csg']) : null,
            'salida_envases' => collect($datos['salida_envases'] ?? [])
                ->map(fn (array $fila): array => [
                    'tipo_envase' => $fila['tipo_envase'], 'cantidad' => (int) $fila['cantidad'],
                ])->sortBy('tipo_envase')->values()->all(),
            'taras_envases' => collect($datos['taras_envases'] ?? [])
                ->map(fn (array $envase): array => [
                    'tipo_envase' => $envase['tipo_envase'],
                    'tara_unitaria' => round((float) $envase['tara_unitaria'], 3),
                ])->sortBy('tipo_envase')->values()->all(),
            'observacion' => $datos['observacion'] ?? null,
        ];
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
                ...app(ServicioFormatosRegistro::class)->snapshotRomana(),
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
            $declaradas = $this->cantidadesDeclaradasParaPesaje($recepcion);
            $pesadas = $this->pesadoPorTipo($recepcion);
            $faltantes = [];
            foreach ($declaradas as $tipo => $cantidad) {
                $falta = $cantidad - ($pesadas[$tipo]['cantidad'] ?? 0);
                if ($falta > 0) {
                    $faltantes[$tipo] = $falta;
                }
            }
            if ($faltantes !== []) {
                throw new ConflictoOperacion(count($declaradas) === 1
                    ? sprintf('Faltan %d envases por pesar antes de cerrar la recepción.', array_sum($faltantes))
                    : 'Faltan envases por pesar antes de cerrar la recepción: '.collect($faltantes)
                        ->map(fn (int $falta, string $tipo): string => $falta.' '.TipoEnvaseRomana::from($tipo)->etiqueta())
                        ->implode(', ').'.');
            }
            $resumen = $this->resumenPesajesEnvases($recepcion);

            $ahora = CarbonImmutable::now();
            $recepcion->update([
                ...$resumen,
                'estado' => EstadoRecepcionRomana::Cerrado,
                'salida_at' => $ahora,
                'cerrado_por_user_id' => $usuario->id,
                ...app(ServicioFormatosRegistro::class)->snapshotRomana(),
                'observacion_cierre' => $payload['observacion'],
                'version' => $recepcion->version + 1,
            ]);
            app(ServicioLoteMateriaPrima::class)->conciliarDestare($recepcion, $usuario);
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
                    'envases' => array_map(fn (string $tipo): array => [
                        'tipo_envase' => $tipo,
                        'cantidad' => $pesadas[$tipo]['cantidad'],
                        'peso_neto' => $pesadas[$tipo]['peso_neto_gramos'] / 1000,
                    ], array_keys($declaradas)),
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
        $guias = app(ServicioGuiasRecepcionRomana::class)->preparar($datos);
        $esPesajeEnvases = $datos['tipo_recepcion'] === TipoRecepcionRomana::FrutaPesajeEnvases->value;
        $esDevolucion = $datos['tipo_recepcion'] === TipoRecepcionRomana::SoloEnvases->value
            && ($datos['concepto_envases'] ?? null) === ConceptoEnvasesRomana::Devolucion->value;
        $envases = collect($datos['envases'])
            ->map(fn (array $envase): array => [
                'tipo_envase' => $envase['tipo_envase'],
                'cantidad' => (int) $envase['cantidad'],
                // Omitirla conserva el contrato y hash de las solicitudes anteriores.
                ...(($datos['tipo_recepcion'] !== TipoRecepcionRomana::SoloEnvases->value || $esDevolucion) && isset($envase['propiedad'])
                    ? ['propiedad' => $envase['propiedad']] : []),
                ...($esPesajeEnvases && isset($envase['tara_unitaria'])
                    ? ['tara_unitaria' => round((float) $envase['tara_unitaria'], 3)] : []),
            ])
            ->sortBy('tipo_envase')
            ->values()
            ->all();
        // El envase de referencia del pesaje: el indicado por el contrato anterior o
        // el primero declarado; su tara es la de esa línea.
        $tipoPesaje = $esPesajeEnvases ? ($datos['tipo_envase_pesaje'] ?? $envases[0]['tipo_envase'] ?? null) : null;
        $lineaPesaje = collect($envases)->firstWhere('tipo_envase', $tipoPesaje);
        $taraPesaje = $lineaPesaje['tara_unitaria'] ?? (isset($datos['tara_unitaria_envase'])
            ? round((float) $datos['tara_unitaria_envase'], 3)
            : null);

        return [
            'temporada_id' => $datos['temporada_id'],
            'cliente_id' => $datos['cliente_id'],
            'especie_validacion_id' => $datos['especie_validacion_id'] ?? null,
            'tipo_recepcion' => $datos['tipo_recepcion'],
            'fecha_ingreso' => $datos['fecha_ingreso'] ?? null,
            'concepto_envases' => $datos['concepto_envases'] ?? null,
            'tipo_servicio' => $datos['tipo_servicio'] ?? 'almacenaje',
            'envases' => $envases,
            'numero_guia_despacho' => $guias === null ? $datos['numero_guia_despacho'] : ServicioGuiasRecepcionRomana::resumen($guias),
            ...($guias === null ? [] : ['guias' => $guias]),
            'patente_camion' => $datos['patente_camion'],
            'tipo_camion' => $datos['tipo_camion'],
            'patente_carro' => $datos['patente_carro'] ?? null,
            'rut_conductor' => $datos['rut_conductor'],
            'nombre_conductor' => $datos['nombre_conductor'],
            'peso_bruto' => isset($datos['peso_bruto'])
                ? round((float) $datos['peso_bruto'], 3)
                : null,
            'tipo_envase_pesaje' => $tipoPesaje,
            'tara_unitaria_envase' => $esPesajeEnvases ? $taraPesaje : null,
            'observacion' => $datos['observacion'] ?? null,
            // Solo en devoluciones, para conservar el hash de las solicitudes anteriores.
            ...($esDevolucion ? [
                'devolucion_productor_csg_id' => $datos['devolucion_productor_csg_id'] ?? null,
                'devolucion_guia_salida' => $datos['devolucion_guia_salida'] ?? null,
                ...(! empty($datos['hora_ingreso']) ? ['hora_ingreso' => $datos['hora_ingreso']] : []),
            ] : []),
        ];
    }

    /**
     * Columnas de devolución de la recepción: el productor debe estar asociado y activo para el cliente.
     *
     * @param  array<string, mixed>  $payload
     * @return array{devolucion_productor_csg_id: ?string, devolucion_productor_snapshot: ?array, devolucion_guia_salida: ?string}
     */
    private function datosDevolucion(array $payload, Cliente $cliente): array
    {
        if (! array_key_exists('devolucion_productor_csg_id', $payload)) {
            return ['devolucion_productor_csg_id' => null, 'devolucion_productor_snapshot' => null, 'devolucion_guia_salida' => null];
        }
        $productor = $cliente->productoresCsg()->wherePivot('activo', true)->find($payload['devolucion_productor_csg_id']);
        if ($productor === null) {
            throw ValidationException::withMessages([
                'devolucion_productor_csg_id' => 'Selecciona un productor CSG asociado al cliente de la recepción.',
            ]);
        }

        return [
            'devolucion_productor_csg_id' => $productor->id,
            'devolucion_productor_snapshot' => ['codigo' => $productor->codigo, 'nombre' => $productor->razon_social],
            'devolucion_guia_salida' => $payload['devolucion_guia_salida'],
        ];
    }

    /** @param  array{devolucion_productor_csg_id: ?string, devolucion_guia_salida: ?string}  $devolucion */
    private function asegurarCronologiaDevolucion(array $devolucion, Cliente $cliente, Temporada $temporada, CarbonImmutable $ingresoAt): void
    {
        if ($devolucion['devolucion_productor_csg_id'] === null) {
            return;
        }
        app(ServicioDevolucionEnvases::class)->asegurarPosteriorASalida(
            $cliente, $temporada, $devolucion['devolucion_guia_salida'], $ingresoAt, 'hora_ingreso',
        );
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
            || $recepcion->detallesEnvases->isEmpty()
            || collect($this->tarasPesaje($recepcion))->contains(fn (float $tara): bool => $tara <= 0)) {
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

    /** @return array<string, int> cantidad declarada por tipo de envase a pesar */
    private function cantidadesDeclaradasParaPesaje(RecepcionRomana $recepcion): array
    {
        $declaradas = $recepcion->detallesEnvases
            ->sortBy(fn ($detalle): string => $detalle->tipo_envase->value)
            ->mapWithKeys(fn ($detalle): array => [$detalle->tipo_envase->value => (int) $detalle->cantidad_declarada])
            ->all();
        if ($declaradas === [] || min($declaradas) < 1) {
            throw new ConflictoOperacion(
                'El envase configurado para pesaje no está declarado en la recepción.',
            );
        }

        return $declaradas;
    }

    /** @return array<string, float> tara por envase de cada tipo configurado */
    private function tarasPesaje(RecepcionRomana $recepcion): array
    {
        return $recepcion->detallesEnvases
            ->mapWithKeys(fn ($detalle): array => [$detalle->tipo_envase->value => (float) ($detalle->tara_unitaria_salida
                ?? ($detalle->tipo_envase === $recepcion->tipo_envase_pesaje ? $recepcion->tara_unitaria_envase : 0))])
            ->all();
    }

    /** @return array<string, array{cantidad: int, peso_tara_gramos: int, peso_neto_gramos: int}> lo pesado en tandas activas */
    private function pesadoPorTipo(RecepcionRomana $recepcion): array
    {
        $pesado = [];
        $reparto = app(ServicioRepartoEnvases::class);
        foreach ($recepcion->pesajesEnvases()->whereNull('anulado_at')->orderBy('secuencia')->get() as $pesaje) {
            foreach ($reparto->lineasConFruta($recepcion->especie_validacion_id, $pesaje->lineas()) as $linea) {
                $actual = $pesado[$linea['tipo_envase']] ?? ['cantidad' => 0, 'peso_tara_gramos' => 0, 'peso_neto_gramos' => 0];
                $pesado[$linea['tipo_envase']] = [
                    'cantidad' => $actual['cantidad'] + $linea['cantidad'],
                    'peso_tara_gramos' => $actual['peso_tara_gramos'] + (int) round($linea['peso_tara'] * 1000),
                    'peso_neto_gramos' => $actual['peso_neto_gramos'] + (int) round($linea['peso_neto'] * 1000),
                ];
            }
        }

        return $pesado;
    }

    /**
     * Envases que trae la tanda. La cantidad total del contrato anterior solo
     * sirve cuando la recepción pesa un único tipo.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $declaradas
     * @return list<array{tipo_envase: string, cantidad: int}>
     */
    private function lineasSolicitadasTanda(array $payload, array $declaradas): array
    {
        if (! isset($payload['envases'])) {
            if (count($declaradas) !== 1) {
                throw ValidationException::withMessages([
                    'envases' => 'Esta recepción pesa varios tipos de envase: indica cuántos de cada tipo trae la tanda.',
                ]);
            }

            return [['tipo_envase' => (string) array_key_first($declaradas), 'cantidad' => (int) $payload['cantidad_envases']]];
        }
        if ($payload['envases'] === []) {
            throw ValidationException::withMessages(['envases' => 'Cada lectura debe contener al menos un envase.']);
        }
        foreach ($payload['envases'] as $linea) {
            if (! isset($declaradas[$linea['tipo_envase']])) {
                throw ValidationException::withMessages([
                    'envases' => TipoEnvaseRomana::from($linea['tipo_envase'])->etiqueta().' no forma parte del pesaje de esta recepción.',
                ]);
            }
        }

        return $payload['envases'];
    }

    /**
     * Reparte el neto de una tanda entre sus tipos de envase. Con un solo tipo
     * todo el neto es suyo; si se mezclan, se reparte según el peso de referencia
     * de cada envase para la especie. El resto de gramos queda en el último tipo.
     *
     * @param  list<array{tipo_envase: string, cantidad: int}>  $lineas
     * @return list<int>
     */
    private function repartirNetoTanda(RecepcionRomana $recepcion, array $lineas, int $netoGramos): array
    {
        if (count($lineas) === 1) {
            return [$netoGramos];
        }
        // Bins con sus totes: la fruta está en los totes y el bin no tiene neto propio.
        $contenedores = app(ServicioRepartoEnvases::class)->contenedores($recepcion->especie_validacion_id, array_column($lineas, 'tipo_envase'));
        $conFruta = array_keys(array_filter($lineas, fn (array $linea): bool => ! in_array($linea['tipo_envase'], $contenedores, true)));
        if ($contenedores !== [] && count($conFruta) === 1) {
            return array_map(fn (int $indice): int => $indice === $conFruta[0] ? $netoGramos : 0, array_keys($lineas));
        }
        $referencias = app(ServicioRepartoEnvases::class)->configuracion($recepcion->especie_validacion_id)['referencias'];
        $pesos = [];
        foreach ($lineas as $linea) {
            $referencia = (int) round(($referencias[$linea['tipo_envase']] ?? 0) * 1000);
            if ($referencia <= 0) {
                throw ValidationException::withMessages([
                    'envases' => sprintf(
                        'Falta configurar el peso de referencia de %s para esta especie en Administración: con él se reparte el neto de una tanda que mezcla tipos de envase.',
                        TipoEnvaseRomana::from($linea['tipo_envase'])->etiqueta(),
                    ),
                ]);
            }
            $pesos[] = $linea['cantidad'] * $referencia;
        }
        $base = array_sum($pesos);
        $netos = array_map(fn (int $peso): int => intdiv($netoGramos * $peso, $base), $pesos);
        $netos[array_key_last($netos)] += $netoGramos - array_sum($netos);

        return $netos;
    }

    /** @return array<string, mixed> */
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
        $resumen = [
            'cantidad_envases_pesados' => $cantidad,
            'peso_bruto' => $pesoBruto,
            'peso_tara' => $pesoTara,
            'peso_neto' => $pesoNeto,
            'tipo_envase_calculo_neto' => $recepcion->tipo_envase_pesaje?->value,
            'cantidad_envase_calculo_neto' => $cantidad,
            'peso_neto_por_envase' => $cantidad > 0
                ? round($pesoNeto / $cantidad, 3)
                : null,
            'reparto_neto_envases' => null,
        ];
        if ($recepcion->detallesEnvases->count() < 2) {
            return $resumen;
        }

        // Varios tipos: el neto pesado de cada uno se congela como reparto. Los lotes
        // de MP reciben así el neto real de sus bins y totes, no un promedio.
        $reparto = [];
        foreach ($this->pesadoPorTipo($recepcion) as $tipo => $pesado) {
            if ($pesado['cantidad'] < 1) {
                continue;
            }
            $unitario = round($pesado['peso_neto_gramos'] / $pesado['cantidad'] / 1000, 3);
            $reparto[$tipo] = [
                'tipo_envase' => $tipo,
                'cantidad' => $pesado['cantidad'],
                'peso_referencia' => $unitario,
                'peso_neto_unitario' => $unitario,
                'peso_neto' => $pesado['peso_neto_gramos'] / 1000,
            ];
        }
        ksort($reparto);
        $reparto = array_values($reparto);
        if ($reparto === []) {
            return [...$resumen, 'cantidad_envase_calculo_neto' => 0];
        }
        // Con bins que contienen totes, el neto por envase es el de los totes.
        $principal = ServicioRepartoEnvases::principal($reparto);

        return [
            ...$resumen,
            'tipo_envase_calculo_neto' => $principal['tipo_envase'],
            'cantidad_envase_calculo_neto' => $principal['cantidad'],
            'peso_neto_por_envase' => $principal['peso_neto_unitario'],
            'reparto_neto_envases' => $reparto,
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

        if ($recepcion->reparto_neto_envases) {
            $cantidades = collect($payload['envases'])->mapWithKeys(fn ($e): array => [$e['tipo_envase'] => (int) $e['cantidad']])->all();
            $salidas = $recepcion->salidasEnvases->keyBy(fn ($e): string => $e->tipo_envase->value);
            $taraEnvases = 0;
            $tipos = array_unique([...array_keys($cantidades), ...$salidas->keys()->all()]);
            foreach ($tipos as $tipo) {
                $taraTipo = $recepcion->detallesEnvases->first(fn ($e): bool => $e->tipo_envase->value === $tipo)?->tara_unitaria_salida
                    ?? $salidas->get($tipo)?->tara_unitaria;
                if (! $taraTipo) {
                    throw new ConflictoOperacion('Configura la tara del nuevo tipo de envase antes de recalcular esta recepción.');
                }
                $taraEnvases += (($cantidades[$tipo] ?? 0) - ($salidas->get($tipo)?->cantidad ?? 0)) * (float) $taraTipo;
            }
            $neto = round($bruto - $tara - $taraEnvases, 3);
            if ($neto <= 0) {
                throw new ConflictoOperacion('El peso neto de fruta debe ser mayor que cero.');
            }
            $seleccion = array_column($recepcion->reparto_neto_envases, 'tipo_envase');
            if ($payload['tipo_envase_calculo_neto'] && $payload['tipo_envase_calculo_neto'] !== $seleccion[0]) {
                throw new ConflictoOperacion('La corrección conserva los envases de reparto del destare.');
            }
            $referencias = array_column($recepcion->reparto_neto_envases, 'peso_referencia', 'tipo_envase');
            $reparto = app(ServicioRepartoEnvases::class)->calcular($neto, $cantidades, $seleccion, $referencias);

            return ['peso_tara' => $tara, 'peso_neto' => $neto, 'peso_tara_envases' => round($taraEnvases, 3),
                'tipo_envase_calculo_neto' => $reparto[0]['tipo_envase'], 'cantidad_envase_calculo_neto' => $reparto[0]['cantidad'],
                'peso_neto_por_envase' => round($reparto[0]['peso_neto_unitario'], 3), 'reparto_neto_envases' => $reparto];
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
            'devolucion' => $recepcion->datosDevolucion(),
            'tipo_servicio' => $recepcion->tipo_servicio->value,
            'envases' => $recepcion->detallesEnvases
                ->sortBy(fn ($detalle): string => $detalle->tipo_envase->value)
                ->map(fn ($detalle): array => [
                    'tipo_envase' => $detalle->tipo_envase->value,
                    'propiedad' => $recepcion->propiedadEnvase($detalle->tipo_envase->value)->value,
                    'cantidad' => $detalle->cantidad_declarada,
                    'tara_unitaria_salida' => $detalle->tara_unitaria_salida !== null
                        ? (float) $detalle->tara_unitaria_salida
                        : null,
                ])
                ->values()
                ->all(),
            'numero_guia_despacho' => $recepcion->numero_guia_despacho,
            'guias' => app(ServicioGuiasRecepcionRomana::class)->datos($recepcion),
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
        if (! empty($payload['hora_ingreso'])) {
            // Devolución con la hora real del reingreso: no puede quedar en el futuro.
            $ingresoAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $payload['fecha_ingreso'].' '.$payload['hora_ingreso'], $zona);
            if ($ingresoAt->gt($ahora)) {
                throw ValidationException::withMessages(['hora_ingreso' => 'La hora del reingreso no puede ser futura.']);
            }

            return $ingresoAt->utc();
        }
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
     * Diferencia positiva: envases quedan en planta. Negativa: salen envases
     * adicionales de la planta, por lo que su tara vuelve al neto de fruta.
     *
     * @param  array<string, mixed>  $payload
     * @return array{peso_tara_envases: float, taras_unitarias: array<string, float>, salidas: array<string, int>}
     */
    private function calcularTaraEnvasesSalida(RecepcionRomana $recepcion, array $payload): array
    {
        $taras = collect($payload['taras_envases'])->keyBy('tipo_envase');
        $entradas = $recepcion->detallesEnvases->mapWithKeys(
            fn ($detalle): array => [$detalle->tipo_envase->value => (int) $detalle->cantidad_validada],
        );
        $salidas = match ($payload['modo_salida_envases']) {
            'mismos' => $entradas->all(),
            'diferentes' => collect($payload['salida_envases'])
                ->mapWithKeys(fn (array $fila): array => [$fila['tipo_envase'] => $fila['cantidad']])->all(),
            'vacio' => [],
        };
        if ($payload['modo_salida_envases'] === 'diferentes'
            && $entradas->keys()->diff(array_keys($salidas))->isNotEmpty()) {
            throw new ConflictoOperacion('Indica la cantidad de salida de cada tipo validado.');
        }
        $tarasUnitarias = [];
        $diferencia = 0.0;
        foreach ($entradas->keys()->merge(array_keys($salidas))->unique() as $tipo) {
            $taraUnitaria = (float) ($taras->get($tipo)['tara_unitaria'] ?? 0);
            if ($taraUnitaria <= 0) {
                throw new ConflictoOperacion("Configura la tara unitaria de {$tipo} antes de cerrar la recepción.");
            }
            $tarasUnitarias[$tipo] = $taraUnitaria;
            $diferencia += ((int) $entradas->get($tipo, 0) - (int) ($salidas[$tipo] ?? 0)) * $taraUnitaria;
        }
        if (count($tarasUnitarias) !== $taras->count()) {
            throw new ConflictoOperacion('Las taras configuradas no coinciden con los tipos de envase.');
        }

        return [
            'peso_tara_envases' => round($diferencia, 3),
            'taras_unitarias' => $tarasUnitarias,
            'salidas' => $salidas,
        ];
    }

    private function registrarSalidaEnvase(
        RecepcionRomana $recepcion,
        string $tipo,
        int $cantidad,
        string $guia,
        User $usuario,
        CarbonImmutable $ahora,
    ): MovimientoEnvase {
        return MovimientoEnvase::create([
            // Clave estable por recepción y tipo, además del bloqueo sobre recepción.
            'operacion_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "salida-recepcion-fruta:{$recepcion->id}:{$tipo}")->toString(),
            'temporada_id' => $recepcion->temporada_id,
            'cliente_id' => $recepcion->cliente_id,
            'recepcion_romana_id' => $recepcion->id,
            'documento_tipo' => 'recepcion_romana',
            'documento_id' => $recepcion->id,
            'numero_documento' => $guia,
            'tipo_movimiento' => TipoMovimientoEnvase::SalidaRecepcionFruta,
            'tipo_envase' => $tipo,
            'cantidad' => $cantidad,
            'signo_cuenta' => -1,
            'signo_existencia' => -1,
            'propiedad' => $recepcion->propiedadEnvase($tipo),
            'ocurrido_at' => $ahora,
            'salida_at' => $ahora,
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
            'creado_por_user_id' => $usuario->id,
            'datos' => ['numero_recepcion' => $recepcion->numero_recepcion],
        ]);
    }

    /** @param array<int, array{tipo_envase: string, cantidad: int, propiedad?: string, tara_unitaria?: float}> $envases */
    private function sincronizarEnvases(RecepcionRomana $recepcion, array $envases): void
    {
        $tipos = collect($envases)->pluck('tipo_envase')->all();
        $recepcion->detallesEnvases()->whereNotIn('tipo_envase', $tipos)->delete();

        foreach ($envases as $envase) {
            $recepcion->detallesEnvases()->updateOrCreate(
                ['tipo_envase' => $envase['tipo_envase']],
                [
                    'cantidad_declarada' => $envase['cantidad'],
                    ...($recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases && ! $recepcion->esDevolucionEnvases()
                        ? ['propiedad' => $recepcion->propiedadEnvase($envase['tipo_envase'])]
                        : (isset($envase['propiedad']) ? ['propiedad' => $envase['propiedad']] : [])),
                    ...($recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases
                        ? ['tara_unitaria_salida' => $envase['tara_unitaria'] ?? $recepcion->tara_unitaria_envase]
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
            ->whereNull('eliminada_at')
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
            'detallesEnvases', 'guiasRecepcion',
            'salidasEnvases',
            'pesajesEnvases' => fn ($consulta) => $consulta
                ->with(['registradoPor', 'anuladoPor'])
                ->orderBy('secuencia'),
            'eventos' => fn ($consulta) => $consulta->with('usuario')->orderBy('ocurrido_at'),
        ]);
    }
}
