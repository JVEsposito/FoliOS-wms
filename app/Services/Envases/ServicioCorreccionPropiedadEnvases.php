<?php

namespace App\Services\Envases;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Romana\ServicioAjusteCuentaEnvasesRecepcion;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServicioCorreccionPropiedadEnvases
{
    /**
     * Corrige todas las líneas de la recepción: el concepto de Romana es único
     * para la recepción, aunque existan varios tipos de envase validados.
     *
     * Con el concepto «devolución» el ingreso se asocia a un productor CSG: cada tipo toma la
     * propiedad con que salió y abona la cuenta de ese productor. Sirve para un ingreso de solo
     * envases que ya se gestionó (por ejemplo, un camión que reingresa con los envases de una guía).
     *
     * @param  array{productor_csg_id?: ?string, guia_salida?: ?string, propiedades?: array<string, string>, ingreso_at?: ?CarbonImmutable}  $devolucion
     */
    public function corregir(
        MovimientoEnvase $seleccionado,
        ConceptoEnvasesRomana $nuevo,
        string $motivo,
        User $usuario,
        array $devolucion = [],
    ): void {
        DB::transaction(function () use ($seleccionado, $nuevo, $motivo, $usuario, $devolucion): void {
            if (! $seleccionado->recepcion_romana_id) {
                throw new ConflictoOperacion('Selecciona un ingreso de Romana validado.');
            }

            $recepcion = RecepcionRomana::query()->with('detallesEnvases')->lockForUpdate()->findOrFail($seleccionado->recepcion_romana_id);
            if ($recepcion->temporada_id !== app(ServicioTemporadaActiva::class)->buscar(bloquear: true)?->id
                || $recepcion->estado_validacion_mp !== EstadoValidacionMp::Validada
                || $recepcion->tipo_recepcion !== TipoRecepcionRomana::SoloEnvases
                || $recepcion->concepto_envases === null) {
                throw new ConflictoOperacion('Solo se corrigen ingresos de envases validados en la temporada activa.');
            }
            $conceptoAnterior = $recepcion->concepto_envases;
            if ($conceptoAnterior === $nuevo && $nuevo !== ConceptoEnvasesRomana::Devolucion) {
                throw new ConflictoOperacion('La recepción ya tiene esa propiedad.');
            }

            $ingresos = MovimientoEnvase::query()
                ->where('recepcion_romana_id', $recepcion->id)
                ->whereIn('tipo_movimiento', array_map(fn (TipoMovimientoEnvase $tipo): string => $tipo->value, TipoMovimientoEnvase::ingresosSoloEnvases()))
                ->lockForUpdate()->get();
            if (! $ingresos->contains('id', $seleccionado->id)
                || $ingresos->isEmpty()
                || $ingresos->count() !== $recepcion->detallesEnvases->count()
                || MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)
                    ->where('tipo_movimiento', TipoMovimientoEnvase::CorreccionPropiedad->value)->exists()) {
                throw new ConflictoOperacion('La recepción ya se corrigió o sus movimientos no coinciden con la validación.');
            }
            app(ServicioAjusteCuentaEnvasesRecepcion::class)->asegurarSinAjustes($recepcion);

            $tipoAnterior = self::tipoIngreso($conceptoAnterior);
            foreach ($ingresos as $ingreso) {
                if ($ingreso->tipo_movimiento !== $tipoAnterior
                    || $ingreso->propiedad !== $recepcion->propiedadEnvase($ingreso->tipo_envase->value)
                    || $ingreso->signo_existencia !== 1
                    || $ingreso->signo_cuenta !== self::signoCuenta($conceptoAnterior)
                    || $ingreso->movimiento_origen_id !== null
                    || $ingreso->temporada_id !== $recepcion->temporada_id
                    || $ingreso->cliente_id !== $recepcion->cliente_id) {
                    throw new ConflictoOperacion('Los ingresos no corresponden al concepto original de Romana.');
                }
            }

            // Incluso una guía cancelada o un despacho revertido conserva una
            // referencia histórica: en esos casos se necesita otra conciliación.
            $ids = $ingresos->pluck('id');
            if (DetalleGuiaDespachoEnvase::query()->whereIn('movimiento_origen_id', $ids)->exists()
                || MovimientoEnvase::query()->whereIn('movimiento_origen_id', $ids)->exists()) {
                throw new ConflictoOperacion('El ingreso ya figura en una reserva o guía de despacho. No se puede corregir su propiedad.');
            }

            $datosDevolucion = $nuevo === ConceptoEnvasesRomana::Devolucion
                ? $this->datosDevolucion($recepcion, $ingresos->map(fn (MovimientoEnvase $m): string => $m->tipo_envase->value)->all(), $devolucion)
                : null;
            $propiedades = $ingresos->mapWithKeys(fn (MovimientoEnvase $m): array => [
                $m->tipo_envase->value => $datosDevolucion['propiedades'][$m->tipo_envase->value] ?? match ($nuevo) {
                    ConceptoEnvasesRomana::Arriendo => PropiedadEnvase::Arrendada,
                    default => PropiedadEnvase::Propia,
                },
            ])->all();
            if ($datosDevolucion !== null && $conceptoAnterior === ConceptoEnvasesRomana::Devolucion
                && $recepcion->devolucion_productor_csg_id === $datosDevolucion['productor']['id']
                && $recepcion->devolucion_guia_salida === $datosDevolucion['guia_salida']
                && $recepcion->ingreso_at->equalTo($datosDevolucion['ingreso_at'])
                && $ingresos->every(fn (MovimientoEnvase $m): bool => $m->propiedad === $propiedades[$m->tipo_envase->value])) {
                throw new ConflictoOperacion('La devolución ya tiene ese productor, guía, fecha y propiedades.');
            }

            // Salen envases propios de la existencia: no deben quedar despachos propios sin origen.
            $propiosQueSalen = $ingresos->filter(fn (MovimientoEnvase $m): bool => $m->propiedad === PropiedadEnvase::Propia
                && $propiedades[$m->tipo_envase->value] !== PropiedadEnvase::Propia);
            if ($propiosQueSalen->isNotEmpty()
                && MovimientoEnvase::query()->where('temporada_id', $recepcion->temporada_id)
                    ->whereIn('tipo_envase', $propiosQueSalen->pluck('tipo_envase')->map(fn ($tipo) => $tipo->value))
                    ->where('propiedad', PropiedadEnvase::Propia->value)
                    ->whereNull('movimiento_origen_id')
                    ->where('tipo_movimiento', TipoMovimientoEnvase::DespachoCliente->value)->exists()) {
                throw new ConflictoOperacion('Hay despachos históricos propios sin origen identificado. Se requiere conciliación antes de corregir.');
            }

            $ahora = now();
            $tipoNuevo = self::tipoIngreso($nuevo);
            // La devolución queda en su lugar cronológico: la fecha real del reingreso.
            $ocurridoAt = $datosDevolucion['ingreso_at'] ?? $ahora;
            $antes = $this->estadoRecepcion($recepcion);
            foreach ($ingresos as $ingreso) {
                $comun = [
                    'temporada_id' => $ingreso->temporada_id,
                    'cliente_id' => $ingreso->cliente_id,
                    'recepcion_romana_id' => $recepcion->id,
                    'documento_tipo' => $ingreso->documento_tipo,
                    'documento_id' => $ingreso->documento_id,
                    'numero_documento' => $ingreso->numero_documento,
                    'tipo_envase' => $ingreso->tipo_envase,
                    'cantidad' => $ingreso->cantidad,
                    'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
                    'creado_por_user_id' => $usuario->id,
                ];
                $traza = [
                    'ingreso_original_id' => $ingreso->id,
                    'concepto_anterior' => $conceptoAnterior->value,
                    'concepto_nuevo' => $nuevo->value,
                    'motivo' => $motivo,
                    'usuario_id' => $usuario->id,
                    'corregido_at' => $ahora->toAtomString(),
                ];
                MovimientoEnvase::create([
                    ...$comun,
                    'operacion_id' => (string) Str::uuid(),
                    'tipo_movimiento' => TipoMovimientoEnvase::CorreccionPropiedad,
                    'signo_cuenta' => -$ingreso->signo_cuenta,
                    'signo_existencia' => -1,
                    'propiedad' => $ingreso->propiedad,
                    'movimiento_origen_id' => $ingreso->id,
                    'ocurrido_at' => $ahora,
                    'datos' => ['correccion_propiedad' => $traza],
                ]);
                MovimientoEnvase::create([
                    ...$comun,
                    'operacion_id' => (string) Str::uuid(),
                    'tipo_movimiento' => $tipoNuevo,
                    'signo_cuenta' => self::signoCuenta($nuevo),
                    'signo_existencia' => 1,
                    'propiedad' => $propiedades[$ingreso->tipo_envase->value],
                    'ocurrido_at' => $ocurridoAt,
                    'ingreso_at' => $ocurridoAt,
                    'datos' => [
                        'correccion_propiedad' => $traza,
                        ...($datosDevolucion ? ['devolucion' => [
                            'productor' => $datosDevolucion['productor'],
                            'guia_salida' => $datosDevolucion['guia_salida'],
                        ]] : []),
                    ],
                ]);
            }
            foreach ($recepcion->detallesEnvases as $detalle) {
                $detalle->update(['propiedad' => $propiedades[$detalle->tipo_envase->value] ?? $detalle->propiedad]);
            }
            $recepcion->update([
                'concepto_envases' => $nuevo,
                'devolucion_productor_csg_id' => $datosDevolucion['productor']['id'] ?? null,
                'devolucion_productor_snapshot' => $datosDevolucion
                    ? ['codigo' => $datosDevolucion['productor']['codigo'], 'nombre' => $datosDevolucion['productor']['nombre']] : null,
                'devolucion_guia_salida' => $datosDevolucion['guia_salida'] ?? null,
                ...($datosDevolucion ? ['ingreso_at' => $datosDevolucion['ingreso_at']] : []),
                'version' => $recepcion->version + 1,
            ]);
            $recepcion->refresh()->load('detallesEnvases');
            // Romana también conserva el antes y el después: la recepción puede cambiar de fecha y productor.
            $datosEvento = ['origen' => 'cuenta_corriente_envases', 'motivo' => $motivo, 'version' => $recepcion->version,
                'anterior' => $antes, 'posterior' => $this->estadoRecepcion($recepcion)];
            EventoRecepcionRomana::create([
                'operacion_id' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', json_encode($datosEvento, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'recepcion_romana_id' => $recepcion->id,
                'tipo' => TipoEventoRomana::CorreccionAdministrativa,
                'estado_anterior' => $recepcion->estado,
                'estado_nuevo' => $recepcion->estado,
                'user_id' => $usuario->id,
                'ocurrido_at' => $ahora,
                'datos' => $datosEvento,
            ]);
        }, attempts: 3);
    }

    public static function tipoIngreso(ConceptoEnvasesRomana $concepto): TipoMovimientoEnvase
    {
        return match ($concepto) {
            ConceptoEnvasesRomana::Compra => TipoMovimientoEnvase::RecepcionCompra,
            ConceptoEnvasesRomana::Arriendo => TipoMovimientoEnvase::RecepcionArriendo,
            ConceptoEnvasesRomana::Devolucion => TipoMovimientoEnvase::DevolucionEnvases,
        };
    }

    /** La compra de envases propios no mueve ninguna cuenta; el arriendo y la devolución sí. */
    public static function signoCuenta(ConceptoEnvasesRomana $concepto): int
    {
        return $concepto === ConceptoEnvasesRomana::Compra ? 0 : 1;
    }

    /**
     * @param  list<string>  $tipos
     * @return array{productor: array{id: string, codigo: string, nombre: string}, guia_salida: ?string, propiedades: array<string, PropiedadEnvase>, ingreso_at: CarbonImmutable}
     */
    private function datosDevolucion(RecepcionRomana $recepcion, array $tipos, array $datos): array
    {
        $productor = $recepcion->cliente->productoresCsg()->wherePivot('activo', true)->find($datos['productor_csg_id'] ?? null);
        if ($productor === null) {
            throw ValidationException::withMessages([
                'devolucion_productor_csg_id' => 'Selecciona un productor CSG asociado al cliente de la recepción.',
            ]);
        }
        $propiedades = [];
        foreach ($tipos as $tipo) {
            $propiedades[$tipo] = PropiedadEnvase::tryFrom((string) ($datos['propiedades'][$tipo] ?? ''))
                ?? throw ValidationException::withMessages(["propiedades.{$tipo}" => 'Indica la propiedad de cada envase devuelto.']);
        }
        $ingresoAt = $datos['ingreso_at'] ?? CarbonImmutable::instance($recepcion->ingreso_at);
        $inicio = $recepcion->temporada?->fecha_inicio?->toDateString();
        if ($ingresoAt->isFuture() || ($inicio !== null && $ingresoAt->lt(CarbonImmutable::parse($inicio, config('app.operational_timezone'))->startOfDay()))) {
            throw ValidationException::withMessages(['ingreso_at' => 'La fecha del reingreso debe estar dentro de la temporada y no puede ser futura.']);
        }
        $guia = mb_strtoupper(trim((string) ($datos['guia_salida'] ?? '')));
        app(ServicioDevolucionEnvases::class)->asegurarPosteriorASalida(
            $recepcion->cliente, $recepcion->temporada, $guia, $ingresoAt, 'ingreso_at',
        );

        return [
            'productor' => ['id' => $productor->id, 'codigo' => $productor->codigo, 'nombre' => $productor->razon_social],
            'guia_salida' => $guia !== '' ? $guia : null,
            'propiedades' => $propiedades,
            'ingreso_at' => $ingresoAt->utc(),
        ];
    }

    /** @return array<string, mixed> */
    private function estadoRecepcion(RecepcionRomana $recepcion): array
    {
        return [
            'concepto_envases' => $recepcion->concepto_envases?->value,
            'ingreso_at' => $recepcion->ingreso_at?->toAtomString(),
            'devolucion' => $recepcion->datosDevolucion(),
            'propiedades' => $recepcion->detallesEnvases->mapWithKeys(fn ($d): array => [
                $d->tipo_envase->value => $recepcion->propiedadEnvase($d->tipo_envase->value)->value,
            ])->sortKeys()->all(),
        ];
    }
}
