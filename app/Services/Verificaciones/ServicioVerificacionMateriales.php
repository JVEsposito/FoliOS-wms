<?php

namespace App\Services\Verificaciones;

use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\IncidenciaVerificacionUbicacion;
use App\Models\Posicion;
use App\Models\SaldoMaterialAlmacen;
use App\Models\User;
use App\Models\VerificacionUbicacion;
use App\Models\VerificacionUbicacionItem;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioVerificacionMateriales
{
    public function candidatas(VerificacionUbicacion $ronda): Builder
    {
        return Posicion::query()->select('posiciones.*')
            ->join('camaras', 'camaras.id', '=', 'posiciones.camara_id')
            ->where('camaras.estado', 'activa')->where('camaras.contenido', 'materiales')
            ->where('posiciones.estado', 'activa')
            ->whereColumn('posiciones.banda', '<=', 'camaras.cantidad_bandas')
            ->whereColumn('posiciones.posicion', '<=', 'camaras.posiciones_por_banda')
            ->whereColumn('posiciones.nivel', '<=', 'camaras.cantidad_niveles')
            ->whereDoesntHave('reservaTareaActiva')
            ->whereDoesntHave('ubicacionesActuales', fn ($q) => $q
                ->whereHas('folio', fn ($f) => $f->where('temporada_id', '!=', $ronda->temporada_id))
                ->orWhereHas('folio.tareasMovimiento', fn ($t) => $t->whereIn('estado', ['asumida', 'en_proceso']))
                ->orWhereHas('folio.tareasMovimiento.reservaActiva')
                ->orWhereIn('folio_id', DB::table('custodias_temporales_maniobra')->where('estado', 'activa')->select('folio_id')))
            ->whereNotIn('posiciones.id', DB::table('saldos_materiales_almacenes as s')
                ->where('s.cantidad_actual', '>', 0)->whereNotNull('s.posicion_id')
                ->where(fn ($q) => $q->where('s.cantidad_reservada', '>', 0)
                    ->orWhereIn('s.folio_id', DB::table('reservas_materiales')->where('estado', 'activa')->select('folio_id'))
                    ->orWhereIn('s.folio_id', DB::table('reservas_transformacion_materiales')->where('estado', 'activa')->select('folio_id')))
                ->select('s.posicion_id'))
            ->withCount('ubicacionesActuales');
    }

    public function snapshot(Posicion $posicion, string $temporada, bool $bloquear = false): array
    {
        $saldos = $this->saldos($posicion, $temporada)->orderBy('folio_id')->when($bloquear, fn ($q) => $q->lockForUpdate())->get();
        $ids = $saldos->pluck('folio_id')->all();

        return [
            'saldos' => $saldos->map(fn ($s) => [
                'id' => $s->id, 'folio_id' => $s->folio_id,
                'cantidad' => $s->cantidad_actual, 'version' => $s->version,
            ])->all(),
            'movimientos' => DB::table('movimientos')->where(fn ($q) => $q
                ->where('posicion_origen_id', $posicion->id)->orWhere('posicion_destino_id', $posicion->id))->when($bloquear, fn ($q) => $q->lockForUpdate())->count(),
            'movimientos_materiales' => DB::table('movimientos_almacenes_materiales')->whereIn('folio_id', $ids)->when($bloquear, fn ($q) => $q->lockForUpdate())->count(),
            'retiros' => DB::table('retiros_materiales')->where('posicion_id', $posicion->id)->when($bloquear, fn ($q) => $q->lockForUpdate())->count(),
        ];
    }

    private function saldos(Posicion $posicion, string $temporada): Builder
    {
        return SaldoMaterialAlmacen::query()->where('camara_id', $posicion->camara_id)
            ->where('posicion_id', $posicion->id)->where('cantidad_actual', '>', 0)
            ->whereHas('folioMaterial.folio', fn ($q) => $q->where('temporada_id', $temporada)->where('activo', true));
    }

    public function registrar(VerificacionUbicacionItem $item, User $usuario, Dispositivo $dispositivo,
        string $operacion, int $version, array $lecturas, bool $vacia, callable $reemplazar): array
    {
        if (! config('verificaciones.habilitada') || ! config('verificaciones.materiales.habilitada')
            || $usuario->rol !== RolUsuario::CamareroMateriales) {
            throw new ConflictoOperacion('La ronda de materiales solo puede registrarla el camarero asignado y debe estar habilitada.');
        }
        $lecturas = collect($lecturas)->map(fn ($l) => [
            'numero_folio' => mb_strtoupper(trim($l['numero_folio'])),
            'cantidad_contada' => isset($l['cantidad_contada']) ? round((float) $l['cantidad_contada'], 3) : null,
        ])->sortBy('numero_folio')->values()->all();
        if ($vacia && $lecturas || ! $vacia && ! $lecturas
            || count(array_unique(array_column($lecturas, 'numero_folio'))) !== count($lecturas)) {
            throw ValidationException::withMessages(['folios' => 'Escanea cada folio una sola vez o marca la posición vacía.']);
        }
        $hash = hash('sha256', json_encode(['vacia' => $vacia, 'folios' => $lecturas], JSON_THROW_ON_ERROR));
        $temporadas = app(ServicioTemporadaActiva::class);
        app(ServicioVerificacionesUbicacion::class)->vencer($temporadas->obtener());

        return DB::transaction(function () use ($item, $usuario, $dispositivo, $operacion, $version, $lecturas, $hash, $temporadas, $reemplazar): array {
            $temporada = $temporadas->obtener(bloquear: true);
            $item = VerificacionUbicacionItem::query()->lockForUpdate()->findOrFail($item->id);
            $ronda = VerificacionUbicacion::query()->lockForUpdate()->findOrFail($item->verificacion_ubicacion_id);
            if ($ronda->contenido !== 'materiales' || $ronda->user_id !== $usuario->id || $ronda->temporada_id !== $temporada->id) {
                throw new ConflictoOperacion('La ronda no pertenece al camarero de materiales y temporada activos.');
            }
            if ($item->operacion_id === $operacion) {
                if (! hash_equals((string) $item->respuesta_payload_hash, $hash)) {
                    throw new ConflictoOperacion('La operación repetida contiene otra respuesta.');
                }

                return [$ronda->load('items.posicion.camara'), $item];
            }
            if (VerificacionUbicacionItem::query()->where('operacion_id', $operacion)->exists()
                || $item->resultado !== null || $item->version !== $version
                || $ronda->estado !== 'pendiente' || $ronda->vence_at->lte(now())) {
                throw new ConflictoOperacion('La posición cambió de versión, ya se verificó o la ronda venció.');
            }
            if ($ronda->verificar_cantidad && collect($lecturas)->contains(fn ($l) => $l['cantidad_contada'] === null)) {
                throw ValidationException::withMessages(['folios' => 'Debes indicar la cantidad contada de cada folio.']);
            }
            $posicion = Posicion::findOrFail($item->posicion_id);
            $encontrados = Folio::query()->where('temporada_id', $temporada->id)
                ->whereIn('numero_folio', array_column($lecturas, 'numero_folio'))
                ->whereHas('material')->get()->keyBy('numero_folio');
            $ids = collect($item->snapshot_materiales['saldos'] ?? [])->pluck('folio_id')
                ->merge($this->saldos($posicion, $temporada->id)->pluck('folio_id'))
                ->merge($encontrados->pluck('id'))->unique()->sort()->values();
            // El mismo orden que las escrituras de custodia: folio, saldo y posición.
            $materiales = FolioMaterial::query()->with('item')->whereIn('folio_id', $ids)->orderBy('folio_id')->lockForUpdate()->get()->keyBy('folio_id');
            Folio::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $esperados = $this->saldos($posicion, $temporada->id)->orderBy('folio_id')->lockForUpdate()->get()->keyBy('folio_id');
            Posicion::query()->lockForUpdate()->findOrFail($posicion->id);
            $snapshot = $this->snapshot($posicion, $temporada->id, bloquear: true);
            // MySQL normaliza el orden de claves de objetos JSON; no es un movimiento.
            $cambio = $snapshot != $item->snapshot_materiales;
            $resultado = 'no_aplica';
            if (! $cambio) {
                $resultados = [];
                $porNumero = collect($lecturas)->keyBy('numero_folio');
                foreach ($esperados as $folioId => $saldo) {
                    $numero = Folio::findOrFail($folioId)->numero_folio;
                    $lectura = $porNumero->pull($numero);
                    $contada = $lectura['cantidad_contada'] ?? null;
                    $dentro = ! $ronda->verificar_cantidad || abs((float) $contada - (float) $saldo->cantidad_actual)
                        <= abs((float) $saldo->cantidad_actual) * (float) $ronda->tolerancia_cantidad_pct / 100 + 0.000001;
                    $tipo = ! $lectura ? 'folio_faltante' : ($dentro ? 'coincide' : 'diferencia_cantidad');
                    $resultados[] = $this->guardarFolio($item, $ronda, $posicion, $usuario, $dispositivo, [
                        'folio_esperado_id' => $folioId, 'folio_encontrado_id' => $lectura ? $folioId : null,
                        'folio_encontrado_numero' => $lectura ? $numero : null,
                        'cantidad_esperada' => $saldo->cantidad_actual, 'cantidad_contada' => $contada,
                        'unidad_medida' => $materiales[$folioId]->item->unidad_medida, 'resultado' => $tipo,
                    ]);
                }
                foreach ($porNumero as $numero => $lectura) {
                    $folio = $encontrados->get($numero);
                    $otra = $folio ? SaldoMaterialAlmacen::query()->where('folio_id', $folio->id)
                        ->where('cantidad_actual', '>', 0)->whereNotNull('posicion_id')->where('posicion_id', '!=', $posicion->id)->lockForUpdate()->value('posicion_id') : null;
                    $resultados[] = $this->guardarFolio($item, $ronda, $posicion, $usuario, $dispositivo, [
                        'folio_encontrado_id' => $folio?->id, 'folio_encontrado_numero' => $numero,
                        'cantidad_contada' => $lectura['cantidad_contada'], 'otra_posicion_id' => $otra,
                        'unidad_medida' => $folio ? $materiales[$folio->id]->item->unidad_medida : null,
                        'resultado' => 'folio_sobrante',
                    ]);
                }
                $resultado = collect($resultados)->first(fn ($r) => $r !== 'coincide') ?? 'coincide';
            }
            $item->update(['resultado' => $resultado, 'verificada_at' => now(), 'operacion_id' => $operacion,
                'dispositivo_id' => $dispositivo->id, 'respuesta_payload_hash' => $hash, 'version' => $item->version + 1]);
            if ($cambio) {
                $reemplazar($ronda);
            }
            if (! $ronda->items()->whereNull('resultado')->exists()
                && $ronda->items()->where('resultado', '!=', 'no_aplica')->count() >= $ronda->objetivo) {
                $ronda->estado = 'completada';
            }
            $ronda->version++;
            $ronda->save();
            DB::afterCommit(fn () => app(ServicioPanelGerencial::class)->invalidar());

            return [$ronda->load('items.posicion.camara'), $item];
        }, attempts: 3);
    }

    private function guardarFolio($item, $ronda, $posicion, $usuario, $dispositivo, array $datos): string
    {
        $folio = $item->folios()->create($datos);
        if ($datos['resultado'] !== 'coincide') {
            IncidenciaVerificacionUbicacion::create([
                'verificacion_ubicacion_item_id' => $item->id,
                'verificacion_ubicacion_folio_id' => $folio->id,
                'temporada_id' => $ronda->temporada_id, 'camara_id' => $posicion->camara_id,
                'posicion_id' => $posicion->id, 'reportado_por_user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo->id, 'tipo' => $datos['resultado'],
                'estado' => 'abierta', 'reportada_at' => now(),
                ...array_diff_key($datos, ['resultado' => true]),
            ]);
        }

        return $datos['resultado'];
    }
}
