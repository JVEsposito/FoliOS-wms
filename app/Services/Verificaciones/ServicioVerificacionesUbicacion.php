<?php

namespace App\Services\Verificaciones;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\IncidenciaVerificacionUbicacion;
use App\Models\Movimiento;
use App\Models\Posicion;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Models\VerificacionUbicacion;
use App\Models\VerificacionUbicacionItem;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ServicioVerificacionesUbicacion
{
    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly VentanasVerificacion $ventanas,
    ) {}

    public function actual(User $usuario, Dispositivo $dispositivo): ?VerificacionUbicacion
    {
        if (! config('verificaciones.habilitada') || $usuario->rol !== RolUsuario::CamareroFrio) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $dispositivo): VerificacionUbicacion {
            $temporada = $this->temporadas->obtener(bloquear: true);
            // El bloqueo por camarero serializa GET, inicio de sesión y solicitudes
            // simultáneas. La restricción única en BD es la segunda barrera.
            User::query()->whereKey($usuario->id)->lockForUpdate()->firstOrFail();
            $ventana = $this->ventanas->actual();
            $vencidas = VerificacionUbicacion::query()->where('user_id', $usuario->id)
                ->where('temporada_id', $temporada->id)
                ->where('estado', 'pendiente')->where('vence_at', '<=', now())
                ->update(['estado' => 'vencida', 'updated_at' => now()]);
            if ($vencidas > 0) {
                DB::afterCommit(fn () => app(ServicioPanelGerencial::class)->invalidar());
            }

            $ronda = VerificacionUbicacion::query()
                ->where('user_id', $usuario->id)
                ->where('turno_inicio_at', $ventana['inicio'])
                ->lockForUpdate()->first();
            if ($ronda) {
                if ($ronda->temporada_id !== $temporada->id) {
                    throw new ConflictoOperacion('La ronda pertenece a una temporada que ya no está activa.');
                }
                if ($ronda->estado === 'pendiente') {
                    $faltantes = $ronda->objetivo - $ronda->items()
                        ->where(fn (Builder $q) => $q->whereNull('resultado')->orWhere('resultado', '!=', 'no_aplica'))
                        ->count();
                    if ($faltantes > 0) {
                        $this->asignar($ronda, $faltantes);
                    }
                }

                return $ronda->load('items.posicion.camara');
            }

            $ronda = VerificacionUbicacion::create([
                'temporada_id' => $temporada->id,
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo->id,
                'turno_inicio_at' => $ventana['inicio'],
                'turno_fin_at' => $ventana['fin'],
                'vence_at' => $ventana['fin'],
                'estado' => 'pendiente',
                'objetivo' => max(1, (int) config('verificaciones.posiciones_por_ronda', 5)),
            ]);
            $this->asignar($ronda, $ronda->objetivo);
            DB::afterCommit(fn () => app(ServicioPanelGerencial::class)->invalidar());

            return $ronda->load('items.posicion.camara');
        }, attempts: 3);
    }

    /** @return array{VerificacionUbicacion, VerificacionUbicacionItem} */
    public function registrar(
        VerificacionUbicacionItem $item,
        User $usuario,
        Dispositivo $dispositivo,
        string $operacionId,
        int $version,
        ?string $numeroFolio,
    ): array {
        if (! config('verificaciones.habilitada')) {
            throw new ConflictoOperacion('La verificación de ubicaciones está desactivada.');
        }
        if ($usuario->rol !== RolUsuario::CamareroFrio) {
            throw new ConflictoOperacion('La ronda solo puede registrarla el camarero asignado.');
        }
        $numero = $numeroFolio === null ? null : mb_strtoupper(trim($numeroFolio));
        $hash = hash('sha256', json_encode(['folio' => $numero], JSON_THROW_ON_ERROR));

        // Persistir el vencimiento antes de lanzar un conflicto: una excepción
        // dentro de la misma transacción desharía el cambio de estado.
        $this->vencer($this->temporadas->obtener());

        return DB::transaction(function () use ($item, $usuario, $dispositivo, $operacionId, $version, $numero, $hash): array {
            $temporada = $this->temporadas->obtener(bloquear: true);
            $item = VerificacionUbicacionItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $ronda = VerificacionUbicacion::query()->whereKey($item->verificacion_ubicacion_id)
                ->lockForUpdate()->firstOrFail();
            if ($ronda->temporada_id !== $temporada->id || $ronda->user_id !== $usuario->id) {
                throw new ConflictoOperacion('La ronda no pertenece al camarero y temporada activos.');
            }
            if ($item->operacion_id === $operacionId) {
                if (! hash_equals((string) $item->respuesta_payload_hash, $hash)) {
                    throw new ConflictoOperacion('La operación repetida contiene otra respuesta.');
                }

                return [$ronda->load('items.posicion.camara'), $item];
            }
            if ($item->resultado !== null || $item->version !== $version) {
                throw new ConflictoOperacion('El ítem cambió de versión o ya fue verificado.');
            }
            if ($ronda->estado !== 'pendiente' || $ronda->vence_at->lte(now())) {
                throw new ConflictoOperacion('La ronda de este turno ya venció.');
            }

            $posicion = Posicion::query()->whereKey($item->posicion_id)->lockForUpdate()->firstOrFail();
            $ubicacion = UbicacionActual::query()->where('posicion_id', $posicion->id)->lockForUpdate()->first();
            $movio = Movimiento::query()->where(function (Builder $consulta) use ($posicion): void {
                $consulta->where('posicion_origen_id', $posicion->id)
                    ->orWhere('posicion_destino_id', $posicion->id);
            })->where('created_at', '>=', $item->created_at)->exists();
            if ($movio || $ubicacion?->id !== $item->ubicacion_asignada_id) {
                $item->update([
                    'resultado' => 'no_aplica', 'verificada_at' => now(),
                    'dispositivo_id' => $dispositivo->id, 'operacion_id' => $operacionId,
                    'respuesta_payload_hash' => $hash,
                    'version' => $item->version + 1,
                ]);
                $this->asignar($ronda, 1);
            } else {
                $encontrado = $numero === null ? null : Folio::query()
                    ->where('temporada_id', $temporada->id)
                    ->where('numero_folio', $numero)->first();
                $resultado = $numero === null
                    ? ($ubicacion === null ? 'coincide' : 'posicion_vacia')
                    : ($encontrado && $encontrado->id === $ubicacion?->folio_id ? 'coincide' : 'otro_folio');
                $item->update([
                    'folio_encontrado_id' => $encontrado?->id,
                    'folio_encontrado_numero' => $numero,
                    'resultado' => $resultado, 'verificada_at' => now(),
                    'dispositivo_id' => $dispositivo->id, 'operacion_id' => $operacionId,
                    'respuesta_payload_hash' => $hash,
                    'version' => $item->version + 1,
                ]);
                if ($resultado !== 'coincide') {
                    $otraPosicion = $encontrado ? UbicacionActual::query()
                        ->where('folio_id', $encontrado->id)->where('posicion_id', '!=', $posicion->id)
                        ->value('posicion_id') : null;
                    IncidenciaVerificacionUbicacion::create([
                        'verificacion_ubicacion_item_id' => $item->id,
                        'temporada_id' => $temporada->id,
                        'camara_id' => $posicion->camara_id,
                        'posicion_id' => $posicion->id,
                        'folio_esperado_id' => $item->folio_esperado_id,
                        'folio_encontrado_id' => $encontrado?->id,
                        'folio_encontrado_numero' => $numero,
                        'otra_posicion_id' => $otraPosicion,
                        'reportado_por_user_id' => $usuario->id,
                        'dispositivo_id' => $dispositivo->id,
                        'tipo' => $resultado, 'estado' => 'abierta', 'reportada_at' => now(),
                    ]);
                }
            }
            if ($ronda->items()->whereNull('resultado')->count() === 0
                && $ronda->items()->where('resultado', '!=', 'no_aplica')->count() >= $ronda->objetivo) {
                $ronda->update(['estado' => 'completada', 'version' => $ronda->version + 1]);
            }
            DB::afterCommit(fn () => app(ServicioPanelGerencial::class)->invalidar());

            return [$ronda->load('items.posicion.camara'), $item];
        }, attempts: 3);
    }

    public function vencer(Temporada $temporada): void
    {
        if (! config('verificaciones.habilitada')) {
            return;
        }
        DB::transaction(function () use ($temporada): void {
            if ($this->temporadas->obtener(bloquear: true)->id !== $temporada->id) {
                return;
            }
            $vencidas = VerificacionUbicacion::query()->where('temporada_id', $temporada->id)
                ->where('estado', 'pendiente')->where('vence_at', '<=', now())
                ->update(['estado' => 'vencida', 'updated_at' => now()]);
            if ($vencidas > 0) {
                DB::afterCommit(fn () => app(ServicioPanelGerencial::class)->invalidar());
            }
        });
    }

    private function asignar(VerificacionUbicacion $ronda, int $cantidad): void
    {
        $excluir = $ronda->items()->pluck('posicion_id')->all();
        $desde = now()->subDays(max(0, (int) config('verificaciones.dias_sin_repetir', 7)));
        $candidatas = Posicion::query()->select('posiciones.*')
            ->join('camaras', 'camaras.id', '=', 'posiciones.camara_id')
            ->where('camaras.estado', EstadoCamara::Activa->value)
            ->where('camaras.contenido', ContenidoCamara::Productos->value)
            ->where('posiciones.estado', EstadoPosicion::Activa->value)
            ->whereColumn('posiciones.banda', '<=', 'camaras.cantidad_bandas')
            ->whereColumn('posiciones.posicion', '<=', 'camaras.posiciones_por_banda')
            ->whereColumn('posiciones.nivel', '<=', 'camaras.cantidad_niveles')
            ->whereNotIn('posiciones.id', $excluir)
            ->whereNotIn('posiciones.id', DB::table('verificaciones_ubicacion_items')
                ->whereNotNull('verificada_at')->where('resultado', '!=', 'no_aplica')
                ->where('verificada_at', '>=', $desde)->select('posicion_id'))
            ->whereDoesntHave('reservaTareaActiva')
            ->whereDoesntHave('reservaPreparacionSagActiva')
            ->whereDoesntHave('ubicacionActual', function (Builder $consulta) use ($ronda): void {
                $consulta->whereHas('folio', fn (Builder $f) => $f->where('temporada_id', '!=', $ronda->temporada_id))
                    ->orWhereHas('folio.tareasMovimiento', fn (Builder $t) => $t->whereIn('estado', [
                        EstadoTareaMovimiento::Asumida->value, EstadoTareaMovimiento::EnProceso->value,
                    ]))
                    ->orWhereHas('folio.tareasMovimiento.reservaActiva')
                    ->orWhereHas('folio.reservaCargaActual')
                    ->orWhereIn('folio_id', DB::table('custodias_temporales_maniobra')
                        ->where('estado', 'activa')->select('folio_id'))
                    ->orWhereIn('folio_id', DB::table('carga_folios')
                        ->join('presencias_carga_anden', 'presencias_carga_anden.carga_id', '=', 'carga_folios.carga_id')
                        ->where('presencias_carga_anden.estado', 'activa')->select('carga_folios.folio_id'));
            })
            ->with('ubicacionActual:id,posicion_id,folio_id')
            ->inRandomOrder()->limit(1000)->get();
        $porCamara = $candidatas->groupBy('camara_id');
        $elegidas = collect();
        foreach ($porCamara as $grupo) {
            if ($elegidas->count() >= $cantidad) {
                break;
            }
            $elegidas->push($grupo->first());
        }
        foreach ($candidatas as $posicion) {
            if ($elegidas->count() >= $cantidad) {
                break;
            }
            if (! $elegidas->contains('id', $posicion->id)) {
                $elegidas->push($posicion);
            }
        }
        foreach ($elegidas as $posicion) {
            $ronda->items()->create([
                'posicion_id' => $posicion->id,
                'folio_esperado_id' => $posicion->ubicacionActual?->folio_id,
                'ubicacion_asignada_id' => $posicion->ubicacionActual?->id,
            ]);
        }
    }
}
