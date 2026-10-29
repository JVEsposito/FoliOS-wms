<?php

namespace App\Services\Validacion;

use App\Exceptions\ConflictoOperacion;
use App\Models\ConciliacionValidacionOrigen;
use App\Models\LecturaStockOrigen;
use App\Models\PalletStockOrigen;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Integraciones\ServicioCatalogoStockOrigen;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioConciliacionValidacion
{
    public const ESTADOS = ['pendiente', 'en_revision', 'cuadrado', 'reabierto', 'sin_diferencias', 'por_revisar'];

    public function __construct(
        private readonly ServicioLecturaStockOrigen $lecturas,
        private readonly ServicioCatalogoStockOrigen $catalogo,
        private readonly ComparadorConciliacionValidacion $comparador,
    ) {}

    /** Se ejecuta al registrar/corregir una validación y después de una lectura correcta. */
    public function registrar(ValidacionPallet $validacion, string $captura = 'primera_comparacion'): ?ConciliacionValidacionOrigen
    {
        $config = $this->lecturas->configuracion();
        if (! $config['habilitada'] || ! $config['conexion']) {
            return null;
        }

        return DB::transaction(function () use ($validacion, $captura, $config) {
            $temporada = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido();
            if (! $temporada || $temporada->id !== $validacion->temporada_id) {
                return null;
            }
            $validacion = ValidacionPallet::with(['folio', 'usuario:id,name'])->lockForUpdate()->findOrFail($validacion->id);
            $ambito = $this->ambito($config);
            $caso = ConciliacionValidacionOrigen::where('validacion_pallet_id', $validacion->id)
                ->where('ambito', $ambito)->where('bodega', $config['bodega'])->lockForUpdate()->first();
            if ($validacion->estado->value !== 'aceptada' || $validacion->resultado->value !== 'aprobado') {
                return $caso;
            }
            $pallet = PalletStockOrigen::where('ambito', $config['ambito'])->where('bodega', $config['bodega'])
                ->where('numero_pallet', $validacion->numero_folio)->first();
            $lectura = $pallet?->lectura_stock_origen_id ? LecturaStockOrigen::find($pallet->lectura_stock_origen_id) : null;
            if (! $this->lecturaCorresponde($lectura, $config)) {
                $pallet = null;
                $lectura = null;
            }
            $planta = $this->planta($pallet, $config);
            $datos = $this->comparador->datosValidacion($validacion->snapshot, $validacion->cantidad_cajas);
            $evidencia = ['datos' => $datos, 'snapshot' => $validacion->snapshot, 'user_id' => $validacion->user_id,
                'usuario' => $caso?->validacion_inicial['usuario'] ?? $validacion->usuario?->name,
                'dispositivo_id' => $validacion->dispositivo_id, 'intento' => $validacion->numero_intento,
                'generado_at' => $validacion->generado_dispositivo_at?->toAtomString(),
                'recibido_at' => $validacion->recibido_servidor_at?->toAtomString()];
            $contexto = $this->contexto($validacion, $datos);
            $comparacion = $this->comparador->comparar($datos, $planta);
            $diferente = collect($comparacion)->contains('estado', 'diferente');
            $completa = ! collect($comparacion)->contains('estado', 'por_revisar');
            $ultima = LecturaStockOrigen::where('ambito', $config['ambito'])->where('bodega', $config['bodega'])
                ->where(fn ($q) => $q->whereNull('numero_pallet')->orWhere('numero_pallet', $validacion->numero_folio))
                ->latest('iniciada_at')->latest('id')->first();
            $vigente = $lectura && $lectura->estado === 'correcta' && $ultima?->id === $lectura->id
                && $lectura->terminada_at?->gte(now()->subMinutes((int) config('integraciones.stock_pt.vigencia_minutos', 20)));
            $nuevaLectura = $lectura && $caso?->lectura_id !== $lectura->id;
            $estado = $caso?->estado ?? ($diferente ? 'pendiente' : 'por_revisar');
            if ($contexto === 'vigente' && $vigente && ($planta['presente'] ?? false)) {
                if ($diferente) {
                    $estado = match ($estado) {
                        'cuadrado' => 'reabierto', 'en_revision', 'reabierto' => $estado, default => 'pendiente',
                    };
                } elseif ($completa) {
                    $estado = ($caso?->tuvo_diferencias ?? false)
                        ? ($nuevaLectura ? 'cuadrado' : $estado) : 'sin_diferencias';
                }
            }
            $anterior = $caso ? $this->respaldo($caso) : null;
            $caso ??= new ConciliacionValidacionOrigen([
                'temporada_id' => $validacion->temporada_id, 'validacion_pallet_id' => $validacion->id,
                'ambito' => $ambito, 'bodega' => $config['bodega'], 'numero_folio' => $validacion->numero_folio,
                'captura' => $captura, 'validacion_inicial' => $evidencia, 'planta_inicial' => $planta,
                'validado_at' => $validacion->generado_dispositivo_at,
            ]);
            $caso->fill(['validacion_actual' => $evidencia, 'planta_actual' => $planta, 'comparacion' => $comparacion,
                'contexto' => $contexto, 'estado' => $estado,
                'tuvo_diferencias' => ($caso->tuvo_diferencias ?? false) || $diferente,
                'lectura_id' => $lectura?->id, 'lectura_at' => $lectura?->terminada_at,
                'cuadrado_at' => $estado === 'cuadrado' ? ($caso->cuadrado_at ?? now()) : null]);
            $cambio = $this->huella($anterior) !== $this->huella($this->respaldo($caso));
            if (! $caso->exists || $cambio || $nuevaLectura) {
                $caso->version = ($caso->version ?? 0) + 1;
                $caso->comprobado_at = now();
                $caso->save();
                if ($cambio) {
                    $correccion = $captura === 'correccion_autorizada' ? ($validacion->snapshot['ultima_correccion'] ?? []) : [];
                    $actor = isset($correccion['corregido_por_user_id']) ? User::find($correccion['corregido_por_user_id']) : null;
                    $this->evento($caso, $anterior === null ? 'captura' : ($actor ? 'correccion_validacion' : 'comprobacion'), [
                        'antes' => $anterior, 'despues' => $this->respaldo($caso), 'planta' => $planta,
                        'lectura_id' => $lectura?->id, 'lectura_at' => $lectura?->terminada_at?->toAtomString(),
                    ], $actor, $correccion['motivo'] ?? null);
                }
            }

            return $caso;
        }, 3);
    }

    /** GET no escribe. Esta reconciliación explícita también incorpora validaciones históricas. */
    public function recomprobar(): int
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada) {
            return 0;
        }
        $cantidad = 0;
        ValidacionPallet::where('temporada_id', $temporada->id)->where('estado', 'aceptada')->where('resultado', 'aprobado')
            ->whereNotNull('folio_id')->select(['id', 'temporada_id'])->chunkById(100, function ($validaciones) use (&$cantidad): void {
                foreach ($validaciones as $validacion) {
                    if ($this->registrar($validacion)) {
                        $cantidad++;
                    }
                }
            });

        $config = $this->lecturas->configuracion();
        $ultima = LecturaStockOrigen::whereNull('numero_pallet')->where('ambito', $config['ambito'])->where('bodega', $config['bodega'])
            ->latest('iniciada_at')->latest('id')->first();
        if ($ultima?->estado === 'correcta' && $ultima->causa === 'conciliacion_pendiente') {
            $ultima->update(['causa' => null, 'mensaje' => null]);
        }

        return $cantidad;
    }

    public function gestionar(ConciliacionValidacionOrigen $caso, array $datos, User $usuario): ConciliacionValidacionOrigen
    {
        try {
            return DB::transaction(function () use ($caso, $datos, $usuario) {
                $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
                $caso = ConciliacionValidacionOrigen::lockForUpdate()->findOrFail($caso->id);
                $config = $this->lecturas->configuracion();
                if (! $config['habilitada'] || ! $config['conexion'] || $caso->temporada_id !== $temporada->id
                    || $caso->ambito !== $this->ambito($config) || $caso->bodega !== $config['bodega']) {
                    throw new ConflictoOperacion('La conciliación no corresponde a la temporada y fuente activas.');
                }
                $hash = $this->huella($datos);
                $anterior = DB::table('eventos_conciliacion_validacion')->where('operacion_id', $datos['operacion_id'])->first();
                if ($anterior) {
                    if ($anterior->conciliacion_id !== $caso->id || (int) $anterior->user_id !== $usuario->id || ! hash_equals($anterior->payload_hash, $hash)) {
                        throw new ConflictoOperacion('El identificador de operación ya se utilizó con otros datos.');
                    }

                    return $caso;
                }
                if ($caso->version !== $datos['version']) {
                    throw new ConflictoOperacion('La conciliación cambió. Recarga antes de registrar la gestión.');
                }
                $v = $caso->validacion()->with('folio')->firstOrFail();
                if ($v->estado->value !== 'aceptada' || $this->contexto($v, $caso->validacion_actual['datos']) !== 'vigente'
                    || ! in_array($caso->estado, ['pendiente', 'en_revision', 'reabierto', 'por_revisar'], true)) {
                    throw new DomainException('Esta conciliación no admite gestiones pendientes. Consulta su historial.');
                }
                $antes = $caso->estado;
                $caso->fill(['estado' => 'en_revision', 'version' => $caso->version + 1])->save();
                DB::table('eventos_conciliacion_validacion')->insert([
                    'conciliacion_id' => $caso->id, 'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash,
                    'tipo' => $datos['accion'], 'user_id' => $usuario->id, 'usuario_nombre' => $usuario->name,
                    'comentario' => $datos['comentario'], 'referencia' => $datos['referencia'] ?? null,
                    'datos' => json_encode(['antes' => $antes, 'despues' => $caso->estado, 'version' => $caso->version], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);

                return $caso;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictoOperacion('El identificador de operación ya se utilizó. Recarga la conciliación.');
        }
    }

    public function contexto(ValidacionPallet $v, array $datos): string
    {
        if ($v->estado->value !== 'aceptada') {
            return 'validacion_anulada';
        }
        $folio = $v->folio;
        if (! $folio || ! $folio->activo || in_array($folio->estado_operacional->value, ['despachado', 'agotado', 'anulado', 'retirado_definitivo'], true)) {
            return 'salida_o_transformacion';
        }
        $f = $folio->datos_externos ?? [];
        $actual = ['cajas' => (int) ($f['cantidad_cajas'] ?? 0), 'marca' => $folio->marca, 'calibre' => $folio->calibre,
            'csg' => $this->comparador->porCsg($f['composicion'] ?? [], 'cantidad_cajas')];
        foreach (array_keys(ComparadorConciliacionValidacion::CAMPOS) as $campo) {
            if (! $this->comparador->iguales($campo, $datos[$campo], $actual[$campo])) {
                return 'folio_modificado';
            }
        }

        return 'vigente';
    }

    /** La configuración legacy también debe separar conexiones, tablas y filtros. */
    public function ambito(array $config): string
    {
        return $this->huella([$config['ambito'], $config['conexion']?->id, $config['conexion']?->version,
            $config['tabla'], $config['bodega'], $config['temporada']]);
    }

    public function lecturaCorresponde(?LecturaStockOrigen $lectura, array $config): bool
    {
        return $lectura && $config['conexion'] && $lectura->ambito === $config['ambito']
            && $lectura->conexion_externa_id === $config['conexion']->id && $lectura->tabla === $config['tabla']
            && $lectura->bodega === $config['bodega'] && $lectura->temporada_origen === $config['temporada']
            && $lectura->iniciada_at->gte($config['conexion']->updated_at);
    }

    private function planta(?PalletStockOrigen $pallet, array $config): ?array
    {
        if (! $pallet) {
            return null;
        }
        $resuelto = $this->catalogo->resolver($pallet);

        return ['pallet_id' => $pallet->id, 'huella' => $pallet->huella, 'presente' => $pallet->presente,
            'fuente' => ['conexion_id' => $config['conexion']->id, 'nombre' => $config['conexion']->nombre,
                'version' => $config['conexion']->version, 'tabla' => $config['tabla'], 'bodega' => $config['bodega'],
                'temporada' => $config['temporada'], 'ambito_stock' => $config['ambito']],
            'cajas' => $pallet->cajas, 'marca' => $pallet->marca, 'calibre' => $pallet->calibre,
            'marca_homologado' => $resuelto['marca']?->nombre, 'calibre_homologado' => $resuelto['calibre']?->nombre,
            'csg' => $this->comparador->porCsg($pallet->composicion ?? [], 'cajas'), 'mezclados' => $pallet->mezclados ?? [],
            'lectura_id' => $pallet->lectura_stock_origen_id, 'leida_at' => $pallet->ultima_lectura_at?->toAtomString()];
    }

    private function respaldo(ConciliacionValidacionOrigen $caso): array
    {
        return ['estado' => $caso->estado, 'contexto' => $caso->contexto,
            'validacion' => $caso->validacion_actual, 'comparacion' => $caso->comparacion];
    }

    private function evento(ConciliacionValidacionOrigen $caso, string $tipo, array $datos, ?User $usuario = null, ?string $comentario = null): void
    {
        DB::table('eventos_conciliacion_validacion')->insert(['conciliacion_id' => $caso->id,
            'user_id' => $usuario?->id, 'usuario_nombre' => $usuario?->name, 'comentario' => $comentario,
            'tipo' => $tipo, 'datos' => json_encode($datos, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function huella(mixed $datos): string
    {
        $ordenar = function ($valor) use (&$ordenar) {
            if (! is_array($valor)) {
                return $valor;
            }
            if (! array_is_list($valor)) {
                ksort($valor);
            }

            return array_map($ordenar, $valor);
        };

        return hash('sha256', json_encode($ordenar($datos), JSON_THROW_ON_ERROR));
    }
}
