<?php

namespace App\Services\InspeccionSag;

use App\Exceptions\ConflictoOperacion;
use App\Models\BloqueMercado;
use App\Models\Carga;
use App\Models\Folio;
use App\Models\InstructivoEmbarque;
use App\Models\Pais;
use App\Models\RetencionLoteSag;
use App\Models\User;
use App\Services\Revisiones\RevisionBandejasOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ProyeccionTrazabilidadFolio;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Control sanitario adicional: no altera ubicaciones, reservas ni estado operacional. */
class ServicioRetencionesLoteSag
{
    public function bloquearTemporada(string $temporadaId): void
    {
        // Mismo mutex para registrar/resolver retenciones y confirmar la salida física.
        DB::table('temporadas')->where('id', $temporadaId)->lockForUpdate()->first();
    }

    public function guardar(array $datos, User $usuario, ?RetencionLoteSag $retencion = null): RetencionLoteSag
    {
        Gate::forUser($usuario)->authorize('gestionar-lotes-retenidos-sag');
        $hash = hash('sha256', json_encode([$retencion?->id, $datos], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($datos, $usuario, $retencion, $hash): RetencionLoteSag {
            $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true)
                ?? throw new DomainException('No existe una temporada activa.');
            $this->bloquearTemporada($temporada->id);
            $previo = DB::table('eventos_retencion_lote_sag')->where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
            if ($previo) {
                if (! hash_equals($previo->payload_hash, $hash) || $previo->user_id !== $usuario->id) {
                    throw new ConflictoOperacion('La operación ya fue utilizada con otros datos.');
                }

                $anterior = RetencionLoteSag::findOrFail($previo->retencion_id);
                if ($anterior->temporada_id !== $temporada->id) {
                    throw new ConflictoOperacion('La operación pertenece a otra temporada.');
                }

                return $anterior;
            }
            $registro = $retencion ? RetencionLoteSag::lockForUpdate()->findOrFail($retencion->id) : new RetencionLoteSag;
            if ($retencion && ($registro->temporada_id !== $temporada->id || $registro->version !== $datos['version'])) {
                throw new ConflictoOperacion('El lote cambió o pertenece a otra temporada. Actualiza antes de resolver.');
            }
            if (! $retencion) {
                $numero = ProyeccionTrazabilidadFolio::normalizarCodigo($datos['numero_lote']);
                if (RetencionLoteSag::where('temporada_id', $temporada->id)->where('cliente_id', $datos['cliente_id'])->where('numero_lote', $numero)->exists()) {
                    throw new ConflictoOperacion('Este cliente y lote ya tienen un seguimiento SAG. Abre su registro para resolverlo.');
                }
                $registro->fill(['temporada_id' => $temporada->id, 'cliente_id' => $datos['cliente_id'], 'numero_lote' => $numero,
                    'lote_materia_prima_id' => app(ProyeccionTrazabilidadFolio::class)->loteRegistrado($temporada->id, $numero, $datos['cliente_id']),
                    'creado_por_user_id' => $usuario->id]);
            }
            $antes = $retencion ? $registro->toArray() : null;
            $destinos = $this->resolverDestinos($datos['destinos'] ?? []);
            $liberado = $datos['estado'] === 'liberado';
            $todos = ! $liberado && $datos['todos_destinos'];
            if ($datos['estado'] === 'retenido' && ! $todos && $destinos === []) {
                throw new DomainException('Selecciona los destinos restringidos o libera el lote.');
            }
            $registro->fill(collect($datos)->only(['estado', 'fecha_deteccion', 'observacion', 'referencia_informe',
                'resolucion', 'definitivo', 'instruccion_segregacion'])->all());
            $registro->fill(['todos_destinos' => $todos, 'destinos' => $todos || $liberado ? [] : $destinos,
                'instruccion_segregacion' => $liberado ? null : ($datos['instruccion_segregacion'] ?? null),
                'version' => $retencion ? $registro->version + 1 : 1, 'actualizado_por_user_id' => $usuario->id])->save();
            DB::table('eventos_retencion_lote_sag')->insert([
                'id' => (string) Str::uuid(), 'retencion_id' => $registro->id, 'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash, 'user_id' => $usuario->id, 'usuario_nombre' => $usuario->name,
                'version' => $registro->version, 'antes' => $antes ? json_encode($antes, JSON_THROW_ON_ERROR) : null,
                'despues' => json_encode($registro->toArray(), JSON_THROW_ON_ERROR), 'created_at' => now(),
                'folios_afectados' => json_encode($this->foliosDelLote($registro)->all(), JSON_THROW_ON_ERROR),
            ]);
            DB::afterCommit(fn () => app(RevisionBandejasOperacionales::class)
                ->invalidar(RevisionBandejasOperacionales::CARGAS));

            return $registro->refresh();
        }, attempts: 3);
    }

    public function resolverDestinos(array $destinos): array
    {
        $resultado = [];
        foreach ($destinos as $destino) {
            $registro = $destino['tipo'] === 'pais'
                ? Pais::where('activo', true)->find($destino['id'])
                : BloqueMercado::where('activo', true)->find($destino['id']);
            if (! $registro) {
                throw new DomainException('Un país o bloque ya no está activo. Actualiza los destinos.');
            }
            $resultado[$destino['tipo'].'|'.$registro->id] = ['tipo' => $destino['tipo'], 'id' => $registro->id,
                'codigo' => $registro->iso_alpha2 ?? $registro->codigo, 'nombre' => $registro->nombre_es ?? $registro->nombre];
        }
        ksort($resultado);

        return array_values($resultado);
    }

    public function paisesRestringidos(RetencionLoteSag $retencion, bool $bloquear = false): array
    {
        if ($retencion->todos_destinos) {
            return Pais::where('activo', true)->pluck('id')->all();
        }
        $destinos = collect($retencion->destinos);
        $ids = $destinos->where('tipo', 'pais')->pluck('id');
        $hoy = now()->toDateString();
        $miembros = DB::table('bloque_mercado_pais')->whereIn('bloque_mercado_id', $destinos->where('tipo', 'bloque')->pluck('id'))
            ->where(fn ($q) => $q->whereNull('vigente_desde')->orWhere('vigente_desde', '<=', $hoy))
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $hoy))
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->pluck('pais_id');

        return $ids->merge($miembros)->unique()->values()->all();
    }

    /** Identidad de origen: cliente y temporada del lote, incluso si el folio se trasladó. */
    public function trazas(?array $folioIds = null, bool $bloquear = false): Collection
    {
        $consulta = DB::table('trazabilidad_folio_origenes as t')
            ->join('retenciones_lote_sag as r', function ($join): void {
                $join->on('r.cliente_id', '=', 't.cliente_id')->on('r.temporada_id', '=', 't.temporada_id')
                    ->where(function ($q): void {
                        $q->whereColumn('r.numero_lote', 't.numero_lote_materia_prima')
                            ->orWhereColumn('r.lote_materia_prima_id', 't.lote_materia_prima_id');
                    });
            })
            ->join('folios as f', 'f.id', '=', 't.folio_id')
            ->where('r.estado', '!=', 'liberado')->where('t.cantidad_cajas', '>', 0);
        if ($folioIds !== null) {
            $consulta->whereIn('t.folio_id', $folioIds);
        } else {
            $consulta->where('f.activo', true)->whereNotIn('f.estado_operacional', ['despachado', 'anulado', 'agotado', 'retirado_definitivo']);
        }
        if ($bloquear) {
            $consulta->lockForUpdate(); // Lectura actual, también bajo REPEATABLE READ en MySQL.
        }

        return $consulta->get(['t.folio_id', 't.cantidad_cajas', 't.csg', 'r.id as retencion_id', 'f.numero_folio']);
    }

    public function paraFolios(array $folioIds, bool $bloquear = false): array
    {
        if ($folioIds === []) {
            return [];
        }
        $trazas = $this->trazas($folioIds, $bloquear);
        $casos = RetencionLoteSag::whereIn('id', $trazas->pluck('retencion_id')->unique())
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $resumenes = $casos->map(fn ($caso): array => ['id' => $caso->id, 'cliente_id' => $caso->cliente_id,
            'lote' => $caso->numero_lote, 'estado' => $caso->estado, 'observacion' => $caso->observacion,
            'todos_destinos' => $caso->todos_destinos, 'destinos' => $caso->destinos,
            'paises_restringidos' => $this->paisesRestringidos($caso, $bloquear), 'instruccion_segregacion' => $caso->instruccion_segregacion]);
        $resultado = [];
        foreach ($trazas->groupBy('folio_id') as $folioId => $lineas) {
            $resultado[$folioId] = $lineas->groupBy('retencion_id')->map(fn ($grupo, $id): array => [
                ...$resumenes->get($id), 'cajas_lote' => (int) $grupo->sum('cantidad_cajas'),
            ])->values()->all();
        }

        return $resultado;
    }

    public function foliosDelLote(RetencionLoteSag $caso): Collection
    {
        $trazas = DB::table('trazabilidad_folio_origenes')->where('temporada_id', $caso->temporada_id)
            ->where('cliente_id', $caso->cliente_id)->where('cantidad_cajas', '>', 0)
            ->where(function ($q) use ($caso): void {
                $q->where('numero_lote_materia_prima', $caso->numero_lote);
                if ($caso->lote_materia_prima_id) {
                    $q->orWhere('lote_materia_prima_id', $caso->lote_materia_prima_id);
                }
            })->get()->groupBy('folio_id');
        $folios = Folio::with(['ubicacionActual.posicion.camara', 'reservaCargaActual.asignacion.carga'])
            ->whereIn('id', $trazas->keys())->orderBy('numero_folio')->get();

        return $folios->map(function ($folio) use ($trazas): array {
            $lineas = $trazas->get($folio->id);
            $asignacion = $folio->reservaCargaActual?->asignacion;
            $posicion = $folio->ubicacionActual?->posicion;

            return ['id' => $folio->id, 'folio' => $folio->numero_folio, 'activo' => $folio->activo,
                'estado' => $folio->estado_operacional->value, 'cajas_lote' => (int) $lineas->sum('cantidad_cajas'),
                'cajas_pallet' => (int) ($folio->datos_externos['cantidad_cajas'] ?? 0),
                'csg' => $lineas->pluck('csg')->filter()->unique()->implode(' / '),
                'ubicacion' => trim(($posicion?->camara?->codigo ?? '').' '.($posicion?->etiqueta ?? '')),
                'carga' => $asignacion?->carga?->codigo, 'carga_id' => $asignacion?->carga_id,
                'instructivo_id' => $asignacion?->instructivo_embarque_id];
        });
    }

    public function alertasCarga(Carga $carga, ?Collection $asignaciones = null, bool $bloquear = false): array
    {
        $asignaciones ??= $carga->asignacionesActuales()->with('folio')->get();
        $porFolio = $this->paraFolios($asignaciones->pluck('folio_id')->all(), $bloquear);
        if ($porFolio === []) {
            return [];
        }
        $carga->loadMissing('embarque.instructivos');
        $instructivos = $carga->embarque?->instructivos ?? collect();
        if ($bloquear) {
            $instructivos = InstructivoEmbarque::whereHas('embarque', fn ($q) => $q->where('carga_id', $carga->id))->lockForUpdate()->get();
        }
        $paises = Pais::all();
        $alertas = [];
        foreach ($asignaciones as $asignacion) {
            $instructivo = $instructivos->firstWhere('id', $asignacion->instructivo_embarque_id)
                ?? ($instructivos->count() === 1 ? $instructivos->first() : null);
            $paisId = $instructivo?->pais_destino_id;
            if (! $paisId && $instructivo) {
                $texto = Str::upper(Str::squish($instructivo->destino_pais ?? ''));
                $coincidentes = $paises->filter(fn ($p) => in_array($texto, [Str::upper($p->iso_alpha2), Str::upper($p->iso_alpha3), Str::upper($p->nombre_es)], true));
                $paisId = $coincidentes->count() === 1 ? $coincidentes->first()->id : null;
            }
            foreach ($porFolio[$asignacion->folio_id] ?? [] as $caso) {
                $restringe = $caso['todos_destinos'] || $caso['destinos'] !== [];
                $bloqueada = $restringe && ($caso['todos_destinos'] || ! $paisId || in_array($paisId, $caso['paises_restringidos'], true));
                $alertas[] = [...$caso, 'folio_id' => $asignacion->folio_id, 'folio' => $asignacion->folio?->numero_folio,
                    'instructivo_id' => $instructivo?->id, 'destino' => $instructivo?->destino_pais,
                    'bloquea_salida' => $bloqueada, 'destino_sin_definir' => $restringe && ! $paisId];
            }
        }

        return $alertas;
    }

    public function asegurarSalida(Carga $carga, Collection $asignaciones): void
    {
        $bloqueos = collect($this->alertasCarga($carga, $asignaciones, bloquear: true))->where('bloquea_salida', true);
        if ($bloqueos->isNotEmpty()) {
            throw new DomainException('Salida impedida por SAG: '.$bloqueos->map(fn ($a) => 'folio '.$a['folio'].' · lote '.$a['lote']
                .($a['destino_sin_definir'] ? ' (define el destino del instructivo)' : ''))->unique()->take(8)->implode('; ').'. Contraparte debe resolver la restricción.');
        }
    }

    public function alertasOperacion(): array
    {
        $trazas = $this->trazas();
        $casos = RetencionLoteSag::with('cliente:id,nombre')->whereIn('id', $trazas->pluck('retencion_id')->unique())->get();

        return $casos->map(function ($caso) use ($trazas): array {
            $lineas = $trazas->where('retencion_id', $caso->id);

            return ['id' => $caso->id, 'cliente' => $caso->cliente->nombre, 'lote' => $caso->numero_lote,
                'estado' => $caso->estado, 'observacion' => $caso->observacion, 'todos_destinos' => $caso->todos_destinos,
                'destinos' => $caso->destinos, 'folios' => $lineas->pluck('folio_id')->unique()->count(),
                'cajas_lote' => (int) $lineas->sum('cantidad_cajas'), 'instruccion_segregacion' => $caso->instruccion_segregacion];
        })->all();
    }
}
