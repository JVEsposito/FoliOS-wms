<?php

namespace App\Services\Materiales;

use App\Exceptions\ConflictoOperacion;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServicioCapacidadMaterial
{
    public function __construct(private readonly GeometriaCapacidadMaterial $geometria) {}

    public function catalogo(): array
    {
        $configuraciones = DB::table('capacidades_almacenes_materiales')->get()->keyBy('almacen_material_id');

        return ['almacenes' => DB::table('destinos_materiales')->where('tipo', 'fisica')->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'activo'])
            ->map(fn ($a) => [...(array) $a, 'configuracion' => isset($configuraciones[$a->id]) ? $this->presentar((array) $configuraciones[$a->id]) : null]),
            'tipos' => DB::table('tipos_pallet_materiales')->orderByDesc('activo')->orderBy('nombre')->get()->map(fn ($t) => $this->presentar((array) $t))];
    }

    public function configuracion(string $id): ?array
    {
        $r = DB::table('capacidades_almacenes_materiales')->where('almacen_material_id', $id)->first();

        return $r ? $this->presentar((array) $r) : null;
    }

    public function guardarTipo(?string $id, array $d, User $actor): array
    {
        return $this->operar('tipo', $id, $d, $actor, function () use ($id, $d, $actor) {
            $antes = $id ? DB::table('tipos_pallet_materiales')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $antes, 404);
            $this->version($antes?->version ?? 0, $d['version']);
            $registro = ['codigo' => $d['codigo'], 'nombre' => trim($d['nombre']),
                'largo_mm' => GeometriaCapacidadMaterial::mm($d['largo_m']), 'ancho_mm' => GeometriaCapacidadMaterial::mm($d['ancho_m']),
                'altura_total_mm' => GeometriaCapacidadMaterial::mm($d['altura_total_m']),
                'max_niveles' => $d['max_niveles'], 'peso_maximo_kg' => $d['peso_maximo_kg'] ?? null,
                'activo' => $d['activo'], 'version' => ($antes?->version ?? 0) + 1,
                'actualizado_por_user_id' => $actor->id, 'updated_at' => now()];
            $clave = $id ?? (string) Str::uuid();
            if ($antes) {
                DB::table('tipos_pallet_materiales')->where('id', $clave)->update($registro);
            } else {
                DB::table('tipos_pallet_materiales')->insert(['id' => $clave, 'created_at' => now(), ...$registro]);
            }

            return [$clave, $antes ? $this->presentar((array) $antes) : null, $this->presentar((array) DB::table('tipos_pallet_materiales')->where('id', $clave)->first())];
        });
    }

    public function guardarAlmacen(string $id, array $d, User $actor): array
    {
        return $this->operar('almacen', $id, $d, $actor, function () use ($id, $d, $actor) {
            $almacen = DB::table('destinos_materiales')->where('id', $id)->lockForUpdate()->first();
            abort_unless($almacen && $almacen->tipo === 'fisica' && $almacen->activo, 422, 'Selecciona un almacén físico activo.');
            $antes = DB::table('capacidades_almacenes_materiales')->where('almacen_material_id', $id)->first();
            $this->version($antes?->version ?? 0, $d['version']);
            $registro = [...$this->geometria->normalizarAlmacen($d), 'version' => ($antes?->version ?? 0) + 1,
                'actualizado_por_user_id' => $actor->id, 'updated_at' => now()];
            $registro['zonas'] = json_encode($registro['zonas'], JSON_THROW_ON_ERROR);
            if ($antes) {
                DB::table('capacidades_almacenes_materiales')->where('almacen_material_id', $id)->update($registro);
            } else {
                DB::table('capacidades_almacenes_materiales')->insert(['almacen_material_id' => $id, 'created_at' => now(), ...$registro]);
            }

            return [$id, $antes ? $this->presentar((array) $antes) : null, $this->configuracion($id)];
        });
    }

    public function guardarCarga(string $saldoId, array $d, User $actor): array
    {
        return $this->operar('carga', $saldoId, $d, $actor, function () use ($saldoId, $d, $actor) {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            $saldo = DB::table('saldos_materiales_almacenes')->where('id', $saldoId)->lockForUpdate()->first();
            abort_unless($saldo, 404);
            $this->version($saldo->version, $d['saldo_version']);
            $folio = DB::table('folios')->where('id', $saldo->folio_id)->first();
            $almacen = DB::table('destinos_materiales')->where('id', $saldo->almacen_material_id)->first();
            abort_unless($folio?->activo && (float) $saldo->cantidad_actual > 0 && $almacen?->tipo === 'fisica' && $almacen->activo
                && $folio->temporada_id === $temporada->id, 422, 'Las medidas se registran sobre saldos físicos vigentes de la temporada activa.');
            $tipo = DB::table('tipos_pallet_materiales')->where('id', $d['tipo_pallet_id'])->lockForUpdate()->first();
            abort_unless($tipo?->activo, 422, 'El tipo de pallet debe estar activo.');
            $antes = DB::table('dimensiones_cargas_materiales')->where('saldo_material_almacen_id', $saldoId)->first();
            $this->version($antes?->version ?? 0, $d['version']);
            $registro = [...$this->carga((array) $tipo, $d), 'tipo_pallet_id' => $tipo->id,
                'bultos_fisicos' => $d['bultos_fisicos'], 'saldo_version' => $saldo->version, 'cantidad_referencia' => $saldo->cantidad_actual,
                'unidad_medida' => DB::table('folios_materiales')->where('folio_id', $saldo->folio_id)->value('unidad_medida'),
                'version' => ($antes?->version ?? 0) + 1, 'actualizado_por_user_id' => $actor->id, 'updated_at' => now()];
            if ($antes) {
                DB::table('dimensiones_cargas_materiales')->where('id', $antes->id)->update($registro);
            } else {
                DB::table('dimensiones_cargas_materiales')->insert(['id' => (string) Str::uuid(), 'saldo_material_almacen_id' => $saldoId, 'created_at' => now(), ...$registro]);
            }
            $resultado = $this->presentar((array) DB::table('dimensiones_cargas_materiales')->where('saldo_material_almacen_id', $saldoId)->first());

            return [$saldoId, $antes ? $this->presentar((array) $antes) : null,
                [...$resultado, 'folio' => $folio->numero_folio, 'almacen' => $almacen->nombre]];
        });
    }

    public function cargas(array $f): array
    {
        $q = DB::table('saldos_materiales_almacenes as s')->join('folios as f', 'f.id', '=', 's.folio_id')
            ->join('folios_materiales as fm', 'fm.folio_id', '=', 'f.id')->join('items_materiales as i', 'i.id', '=', 'fm.item_material_id')
            ->join('destinos_materiales as a', 'a.id', '=', 's.almacen_material_id')
            ->where('s.almacen_material_id', $f['almacen_id'])->where('a.tipo', 'fisica')->where('a.activo', true)
            ->where('s.cantidad_actual', '>', 0)->where('f.activo', true)
            ->whereExists(fn ($t) => $t->selectRaw('1')->from('temporadas as t')->whereColumn('t.id', 'f.temporada_id')->where('t.activa', true))
            ->leftJoin('dimensiones_cargas_materiales as d', 'd.saldo_material_almacen_id', '=', 's.id')
            ->select('s.id as saldo_id', 's.version as saldo_version_actual', 'f.numero_folio as folio', 'i.nombre as item',
                's.cantidad_actual', 'fm.unidad_medida', 'd.id', 'd.tipo_pallet_id', 'd.version', 'd.saldo_version', 'd.cantidad_referencia',
                'd.largo_mm', 'd.ancho_mm', 'd.altura_total_mm', 'd.max_niveles', 'd.bultos_fisicos', 'd.peso_unitario_kg', 'd.updated_at');
        if (! empty($f['busqueda'])) {
            $texto = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['busqueda']).'%';
            $q->where(fn ($b) => $b->whereRaw("f.numero_folio LIKE ? ESCAPE '!'", [$texto])->orWhereRaw("i.nombre LIKE ? ESCAPE '!'", [$texto]));
        }
        $p = $q->orderBy('f.numero_folio')->orderBy('s.id')->paginate(25, ['*'], 'page', $f['page'] ?? 1);

        return ['data' => collect($p->items())->map(fn ($r) => [...$this->presentar((array) $r),
            'medidas_vigentes' => $r->id !== null && (int) $r->saldo_version === (int) $r->saldo_version_actual]),
            'meta' => ['pagina' => $p->currentPage(), 'paginas' => $p->lastPage(), 'total' => $p->total()]];
    }

    public function simular(string $id, array $d): array
    {
        $almacen = DB::table('destinos_materiales')->where('id', $id)->where('tipo', 'fisica')->where('activo', true)->first();
        abort_unless($almacen, 422, 'Selecciona un almacén físico activo.');
        $config = DB::table('capacidades_almacenes_materiales')->where('almacen_material_id', $id)->first();
        abort_unless($config, 422, 'Configura primero las dimensiones y zonas útiles del almacén.');
        $tipo = DB::table('tipos_pallet_materiales')->where('id', $d['tipo_pallet_id'])->first();
        abort_unless($tipo, 404);
        $carga = $this->carga((array) $tipo, $d);

        return [...$this->geometria->simular([...((array) $config), 'zonas' => json_decode($config->zonas, true)], (array) $tipo, $carga),
            'carga' => $this->presentar($carga), 'configuracion_version' => $config->version, 'tipo_version' => $tipo->version];
    }

    private function carga(array $tipo, array $d): array
    {
        return ['largo_mm' => isset($d['largo_m']) ? GeometriaCapacidadMaterial::mm($d['largo_m']) : $tipo['largo_mm'],
            'ancho_mm' => isset($d['ancho_m']) ? GeometriaCapacidadMaterial::mm($d['ancho_m']) : $tipo['ancho_mm'],
            'altura_total_mm' => isset($d['altura_total_m']) ? GeometriaCapacidadMaterial::mm($d['altura_total_m']) : $tipo['altura_total_mm'],
            'max_niveles' => (int) ($d['max_niveles'] ?? $tipo['max_niveles']), 'peso_unitario_kg' => $d['peso_unitario_kg'] ?? null];
    }

    private function operar(string $entidad, ?string $id, array $d, User $actor, Closure $operacion): array
    {
        $hash = hash('sha256', json_encode([$entidad, $id, $d], JSON_THROW_ON_ERROR));
        $reintento = function ($evento) use ($hash, $actor) {
            if ($evento->payload_hash !== $hash || (int) $evento->user_id !== (int) $actor->id) {
                throw new ConflictoOperacion('La operación ya se utilizó con otros datos o usuario.');
            }

            return json_decode($evento->resultado, true, flags: JSON_THROW_ON_ERROR);
        };
        try {
            return DB::transaction(function () use ($entidad, $d, $actor, $hash, $operacion, $reintento) {
                $evento = DB::table('eventos_capacidad_materiales')->where('operacion_id', $d['operacion_id'])->lockForUpdate()->first();
                if ($evento) {
                    return $reintento($evento);
                }
                [$clave, $anterior, $resultado] = $operacion();
                DB::table('eventos_capacidad_materiales')->insert(['id' => (string) Str::uuid(), 'operacion_id' => $d['operacion_id'],
                    'payload_hash' => $hash, 'entidad' => $entidad, 'registro_id' => $clave, 'user_id' => $actor->id,
                    'anterior' => $anterior ? json_encode($anterior, JSON_THROW_ON_ERROR) : null,
                    'resultado' => json_encode($resultado, JSON_THROW_ON_ERROR), 'ocurrido_at' => now()]);

                return $resultado;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $evento = DB::table('eventos_capacidad_materiales')->where('operacion_id', $d['operacion_id'])->first();
            if ($evento) {
                return $reintento($evento);
            }
            if ($entidad === 'tipo') {
                throw ValidationException::withMessages(['codigo' => 'Ya existe un tipo de pallet con ese código.']);
            }
            throw $e;
        }
    }

    private function version(int $actual, int|string $esperada): void
    {
        if ($actual !== (int) $esperada) {
            throw new ConflictoOperacion('Los datos cambiaron. Actualiza el panel y vuelve a revisar antes de guardar.');
        }
    }

    public function presentar(array $r): array
    {
        foreach (['largo', 'ancho', 'altura_total', 'altura_util', 'x', 'y', 'hueco_largo', 'hueco_ancho', 'hueco_altura'] as $campo) {
            if (array_key_exists($campo.'_mm', $r)) {
                $r[$campo.'_m'] = $r[$campo.'_mm'] === null ? null : (int) $r[$campo.'_mm'] / 1000;
                unset($r[$campo.'_mm']);
            }
        }
        if (isset($r['zonas'])) {
            $zonas = is_string($r['zonas']) ? json_decode($r['zonas'], true) : $r['zonas'];
            $r['superficie_util_m2'] = round(array_sum(array_map(fn ($z) => $z['largo_mm'] * $z['ancho_mm'] / 1e6, $zonas)), 3);
            $r['volumen_util_m3'] = round(array_sum(array_map(fn ($z) => $z['largo_mm'] * $z['ancho_mm'] * $z['altura_util_mm'] / 1e9, $zonas)), 3);
            $r['zonas'] = array_map(fn ($z) => $this->presentar($z), $zonas);
        }

        return $r;
    }
}
