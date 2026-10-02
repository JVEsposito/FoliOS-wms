<?php

namespace App\Services\ValidacionMp;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\MotivoSegregacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\SegmentoEnvaseValidacionMp;
use App\Models\SegmentoValidacionMp;
use App\Models\User;
use App\Models\ValidacionMp;
use App\Models\VariedadValidacion;
use App\Services\Romana\ServicioInspeccionEnvases;
use App\Services\Romana\ServicioRepartoEnvases;
use App\Services\Temporadas\GuardiaTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServicioValidacionMp
{
    public function completarEspecie(RecepcionRomana $recepcion, string $especieId, int $version, string $operacionId, User $usuario): RecepcionRomana
    {
        return DB::transaction(function () use ($recepcion, $especieId, $version, $operacionId, $usuario): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
            app(GuardiaTemporadaActiva::class)->asegurar($recepcion);
            $hash = hash('sha256', $recepcion->id.'|'.$especieId);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $operacionId)->first();
            if ($evento) {
                if ($evento->recepcion_romana_id !== $recepcion->id || $evento->tipo !== TipoEventoRomana::EspecieCompletada || $evento->payload_hash !== $hash) {
                    throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
                }

                return $recepcion;
            }
            if ($recepcion->validacion_tomada_por_user_id !== $usuario->id || $recepcion->estado_validacion_mp !== EstadoValidacionMp::EnCurso) {
                throw new ConflictoOperacion('Solo quien tomó la recepción puede completar su especie antes de validarla.');
            }
            if ($recepcion->especie_validacion_id || $recepcion->version !== $version || ! $recepcion->tipo_recepcion->contieneFruta()) {
                throw new ConflictoOperacion('La recepción cambió o ya tiene especie asignada.');
            }
            $especie = EspecieValidacion::query()->whereKey($especieId)
                ->where('temporada_id', $recepcion->temporada_id)->where('activo', true)->first();
            if (! $especie) {
                throw ValidationException::withMessages(['especie_validacion_id' => 'La especie no está activa en la temporada de la recepción.']);
            }
            $recepcion->update(['especie_validacion_id' => $especieId, 'version' => $version + 1]);
            EventoRecepcionRomana::create([
                'operacion_id' => $operacionId, 'payload_hash' => $hash,
                'recepcion_romana_id' => $recepcion->id, 'tipo' => TipoEventoRomana::EspecieCompletada,
                'estado_anterior' => $recepcion->estado, 'estado_nuevo' => $recepcion->estado,
                'user_id' => $usuario->id, 'ocurrido_at' => now(),
                'datos' => ['especie_validacion_id' => $especieId, 'especie' => $especie->nombre],
            ]);

            return $recepcion;
        }, attempts: 3);
    }

    public function tomar(
        RecepcionRomana $recepcion,
        string $operacionId,
        User $usuario,
        ?string $dispositivoId,
    ): ValidacionMp {
        return DB::transaction(function () use ($recepcion, $operacionId, $usuario, $dispositivoId): ValidacionMp {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
            $porOperacion = ValidacionMp::query()->where('operacion_toma_id', $operacionId)->first();
            if ($porOperacion && $porOperacion->recepcion_romana_id !== $recepcion->id) {
                throw new ConflictoOperacion('El identificador de operación ya fue utilizado en otra recepción.');
            }

            $existente = ValidacionMp::query()->where('recepcion_romana_id', $recepcion->id)->first();
            if ($existente) {
                if ($existente->validador_user_id !== $usuario->id) {
                    throw new ConflictoOperacion('La recepción ya fue tomada por otro validador MP.');
                }

                return $this->cargar($existente);
            }
            if ($recepcion->estado_validacion_mp !== EstadoValidacionMp::Pendiente) {
                throw new ConflictoOperacion('La recepción no está disponible para Validación MP.');
            }

            $ahora = now();
            $validacion = ValidacionMp::create([
                'recepcion_romana_id' => $recepcion->id,
                'temporada_id' => $recepcion->temporada_id,
                'operacion_toma_id' => $operacionId,
                'estado' => EstadoValidacionMp::EnCurso,
                'validador_user_id' => $usuario->id,
                'dispositivo_id' => $dispositivoId,
                'tomada_at' => $ahora,
            ]);
            $recepcion->update([
                'estado_validacion_mp' => EstadoValidacionMp::EnCurso,
                'validacion_tomada_por_user_id' => $usuario->id,
                'validacion_tomada_at' => $ahora,
            ]);

            return $this->cargar($validacion);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $datos */
    public function confirmar(ValidacionMp $validacion, array $datos, User $usuario): ValidacionMp
    {
        return DB::transaction(function () use ($validacion, $datos, $usuario): ValidacionMp {
            $validacion = ValidacionMp::query()->lockForUpdate()->findOrFail($validacion->id);
            $recepcion = RecepcionRomana::query()->with('detallesEnvases')->lockForUpdate()->findOrFail($validacion->recepcion_romana_id);
            if ($validacion->validador_user_id !== $usuario->id) {
                throw new ConflictoOperacion('Solo el validador que tomó la recepción puede confirmarla.');
            }
            if ($validacion->estado === EstadoValidacionMp::Validada) {
                if ($validacion->operacion_confirmacion_id === $datos['operacion_id']) {
                    return $this->cargar($validacion);
                }
                throw new ConflictoOperacion('La recepción ya fue validada y sus cantidades son inmutables.');
            }
            if ($recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases
                && $recepcion->estado !== EstadoRecepcionRomana::Cerrado) {
                throw new ConflictoOperacion(
                    'Romana debe completar y cerrar el pesaje acumulativo antes de confirmar Validación MP.',
                );
            }

            $cantidades = collect($datos['envases'])->keyBy('tipo_envase');
            $tiposDeclarados = $recepcion->detallesEnvases
                ->map(fn ($detalle): string => $detalle->tipo_envase->value)
                ->sort()
                ->values();
            $tiposRecibidos = $cantidades->keys()->sort()->values();
            if ($tiposDeclarados->all() !== $tiposRecibidos->all()) {
                throw ValidationException::withMessages([
                    'envases' => 'Debes validar exactamente los tipos de envase declarados en Romana.',
                ]);
            }

            $esFruta = $recepcion->tipo_recepcion->contieneFruta();
            if ($esFruta && ! $recepcion->especie_validacion_id) {
                throw ValidationException::withMessages(['especie_validacion_id' => 'Selecciona la especie de la recepción antes de validar.']);
            }
            if ($esFruta && ($datos['tarjas_verificadas'] ?? false) !== true) {
                throw ValidationException::withMessages(['tarjas_verificadas' => 'Confirma el chequeo visual de las tarjas de campo.']);
            }
            $requiereSegregacion = $esFruta && (bool) ($datos['requiere_segregacion'] ?? false);
            $segmentos = $esFruta
                ? $this->prepararSegmentos($validacion, $recepcion, $datos, $cantidades, $requiereSegregacion)
                : [];
            $tiposReparto = $recepcion->peso_neto !== null
                ? app(ServicioRepartoEnvases::class)->tipos($recepcion)
                : array_column(array_filter(TipoEnvaseRomana::catalogo(), fn ($tipo): bool => $tipo['contiene_fruta']), 'codigo');
            foreach ($segmentos as $indice => $segmento) {
                if (! collect($segmento['envases'])->contains(fn ($e): bool => in_array($e['tipo_envase'], $tiposReparto, true) && $e['cantidad'] > 0)) {
                    throw ValidationException::withMessages(["segmentos.{$indice}.envases" => 'Cada segmento con fruta debe incluir al menos un envase de reparto.']);
                }
            }

            $ahora = now();
            foreach ($recepcion->detallesEnvases as $detalle) {
                $cantidadValidada = (int) $cantidades->get($detalle->tipo_envase->value)['cantidad_validada'];
                $detalle->update(['cantidad_validada' => $cantidadValidada]);
                $this->crearMovimiento($recepcion, $detalle->tipo_envase->value, $detalle->cantidad_declarada, $cantidadValidada, $usuario, $ahora);
            }
            foreach ($segmentos as $segmento) {
                $this->guardarSegmento($validacion, $recepcion, $segmento);
            }

            app(ServicioInspeccionEnvases::class)->guardar($recepcion, 'recepcion', $cantidades->mapWithKeys(fn ($e, $t) => [$t => (int) $e['cantidad_validada']])->all(), $datos['inspeccion_envases'] ?? null, $usuario, $datos['operacion_id']);

            $validacion->update([
                'operacion_confirmacion_id' => $datos['operacion_id'],
                'estado' => EstadoValidacionMp::Validada,
                'tarjas_verificadas' => $esFruta ? true : null,
                'requiere_segregacion' => $requiereSegregacion,
                'validada_at' => $ahora,
                'observacion' => $datos['observacion'] ?? null,
            ]);
            $recepcion->update([
                'estado_validacion_mp' => EstadoValidacionMp::Validada,
                'validado_at' => $ahora,
            ]);

            return $this->cargar($validacion);
        }, attempts: 3);
    }

    /** @return array<int, array<string, mixed>> */
    private function prepararSegmentos(
        ValidacionMp $validacion,
        RecepcionRomana $recepcion,
        array $datos,
        $cantidades,
        bool $requiereSegregacion,
    ): array {
        if (! $requiereSegregacion) {
            return [[
                'motivos' => [],
                'csg_validacion_id' => $datos['csg_validacion_id'] ?? null,
                'variedad_validacion_id' => $datos['variedad_validacion_id'] ?? null,
                'envases' => $cantidades->map(fn (array $envase, string $tipo): array => [
                    'tipo_envase' => $tipo,
                    'cantidad' => (int) $envase['cantidad_validada'],
                ])->values()->all(),
            ]];
        }

        $segmentos = $datos['segmentos'] ?? [];
        if (count($segmentos) < 2) {
            throw ValidationException::withMessages(['segmentos' => 'Una segregación debe crear al menos dos segmentos futuros.']);
        }
        $sumas = [];
        $tiposDeclarados = $cantidades->keys();
        foreach ($segmentos as $indice => $segmento) {
            $motivos = collect($segmento['motivos'] ?? [])->unique()->values();
            if ($motivos->isEmpty()) {
                throw ValidationException::withMessages(["segmentos.{$indice}.motivos" => 'Selecciona al menos un motivo de segregación.']);
            }
            foreach ($motivos as $motivo) {
                if (! in_array($motivo, array_column(MotivoSegregacionMp::cases(), 'value'), true)) {
                    throw ValidationException::withMessages(["segmentos.{$indice}.motivos" => 'El motivo de segregación no es válido.']);
                }
            }
            if (empty($segmento['csg_validacion_id'])) {
                throw ValidationException::withMessages(["segmentos.{$indice}.csg_validacion_id" => 'Selecciona el CSG que identifica el segmento.']);
            }
            if ($motivos->contains(MotivoSegregacionMp::Cuartel->value) && blank($segmento['cuartel'] ?? null)) {
                throw ValidationException::withMessages(["segmentos.{$indice}.cuartel" => 'Ingresa el cuartel que identifica el segmento.']);
            }
            if (empty($segmento['variedad_validacion_id'])) {
                throw ValidationException::withMessages(["segmentos.{$indice}.variedad_validacion_id" => 'Selecciona la variedad que identifica el segmento.']);
            }
            $envases = collect($segmento['envases'] ?? []);
            $tiposSegmento = $envases->pluck('tipo_envase');
            if ($tiposSegmento->unique()->count() !== $tiposSegmento->count()) {
                throw ValidationException::withMessages(["segmentos.{$indice}.envases" => 'No repitas un tipo de envase dentro del mismo segmento.']);
            }
            if ($tiposSegmento->diff($tiposDeclarados)->isNotEmpty()) {
                throw ValidationException::withMessages(["segmentos.{$indice}.envases" => 'Los segmentos sólo pueden distribuir envases declarados en Romana.']);
            }
            $segmentos[$indice]['motivos'] = $motivos->all();
            $segmentos[$indice]['envases'] = $envases->values()->all();
            foreach ($envases as $envase) {
                $sumas[$envase['tipo_envase']] = ($sumas[$envase['tipo_envase']] ?? 0) + (int) $envase['cantidad'];
            }
        }
        foreach ($cantidades as $tipo => $cantidad) {
            if (($sumas[$tipo] ?? 0) !== (int) $cantidad['cantidad_validada']) {
                throw ValidationException::withMessages(['segmentos' => "La distribución de {$tipo} entre segmentos no coincide con lo validado."]);
            }
        }

        return $segmentos;
    }

    /** @param array<string, mixed> $segmento */
    private function guardarSegmento(ValidacionMp $validacion, RecepcionRomana $recepcion, array $segmento): void
    {
        $secuencia = $validacion->segmentos()->count() + 1;
        $csg = CsgValidacion::query()
            ->whereKey($segmento['csg_validacion_id'])
            ->where('temporada_id', $recepcion->temporada_id)
            ->where('activo', true)
            ->disponibleParaCliente($recepcion->cliente_id)
            ->first();
        if (! $csg) {
            throw ValidationException::withMessages([
                'segmentos' => 'El CSG no está activo para la temporada y el cliente heredados de Romana.',
            ]);
        }
        $variedad = VariedadValidacion::query()->whereKey($segmento['variedad_validacion_id'])
            ->where('especie_validacion_id', $recepcion->especie_validacion_id)
            ->where('activo', true)->first();
        if (! $variedad) {
            throw ValidationException::withMessages(['segmentos' => 'La variedad no pertenece a la especie declarada en Romana.']);
        }

        $creado = SegmentoValidacionMp::create([
            'validacion_mp_id' => $validacion->id,
            'secuencia' => $secuencia,
            'motivos' => $segmento['motivos'] ?? [],
            'csg_validacion_id' => $csg?->id,
            'csg_snapshot' => $csg?->codigo,
            'cuartel' => filled($segmento['cuartel'] ?? null) ? trim($segmento['cuartel']) : null,
            'variedad_validacion_id' => $variedad?->id,
            'variedad_snapshot' => $variedad?->nombre,
            'estado' => 'pendiente_lote',
            'observacion' => $segmento['observacion'] ?? null,
        ]);
        foreach ($segmento['envases'] as $envase) {
            if ((int) $envase['cantidad'] === 0) {
                continue;
            }
            SegmentoEnvaseValidacionMp::create([
                'segmento_validacion_mp_id' => $creado->id,
                'tipo_envase' => $envase['tipo_envase'],
                'cantidad' => $envase['cantidad'],
            ]);
        }
    }

    private function crearMovimiento(
        RecepcionRomana $recepcion,
        string $tipoEnvase,
        int $declarada,
        int $validada,
        User $usuario,
        mixed $validadoAt,
    ): void {
        [$tipo, $signoCuenta, $propiedad] = match ($recepcion->tipo_recepcion) {
            TipoRecepcionRomana::FrutaConEnvases,
            TipoRecepcionRomana::FrutaPesajeEnvases => [
                TipoMovimientoEnvase::RecepcionFruta,
                1,
                PropiedadEnvase::Cliente,
            ],
            TipoRecepcionRomana::SoloEnvases => $recepcion->concepto_envases === ConceptoEnvasesRomana::Arriendo
                ? [TipoMovimientoEnvase::RecepcionArriendo, 1, PropiedadEnvase::Arrendada]
                : [TipoMovimientoEnvase::RecepcionCompra, 0, PropiedadEnvase::Propia],
        };
        MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $recepcion->temporada_id,
            'cliente_id' => $recepcion->cliente_id,
            'recepcion_romana_id' => $recepcion->id,
            'documento_tipo' => 'recepcion_romana',
            'documento_id' => $recepcion->id,
            'numero_documento' => $recepcion->numero_guia_despacho,
            'tipo_movimiento' => $tipo,
            'tipo_envase' => $tipoEnvase,
            'cantidad' => $validada,
            'signo_cuenta' => $signoCuenta,
            'signo_existencia' => 1,
            'propiedad' => $propiedad,
            'ocurrido_at' => $recepcion->ingreso_at,
            'ingreso_at' => $recepcion->ingreso_at,
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
            'creado_por_user_id' => $usuario->id,
            'datos' => [
                'numero_recepcion' => $recepcion->numero_recepcion,
                'cantidad_declarada' => $declarada,
                'cantidad_validada' => $validada,
                'diferencia' => $validada - $declarada,
                'validado_at' => $validadoAt->toAtomString(),
            ],
        ]);
    }

    private function cargar(ValidacionMp $validacion): ValidacionMp
    {
        return $validacion->refresh()->load([
            'recepcion.detallesEnvases', 'temporada', 'validador', 'dispositivo',
            'segmentos.envases', 'segmentos.csg', 'segmentos.variedad',
        ]);
    }
}
