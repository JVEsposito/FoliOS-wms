<?php

namespace App\Services\Gerencia;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoAdministrativoTunelPrefrio;
use App\Enums\EstadoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoDespachoMaterial;
use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoRecepcionMaterial;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoTecnicoTunelPrefrio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Enums\TipoEnvaseRomana;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\DespachoMaterial;
use App\Models\Folio;
use App\Models\LoteMateriaPrima;
use App\Models\MovimientoEnvase;
use App\Models\ProcesoPrefrio;
use App\Models\RecepcionMaterial;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\ValidacionPallet;
use App\Services\Materiales\ServicioConsultaVencimientosMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioPanelGerencial
{
    public const CLAVE_CACHE = 'gerencia:panel:resumen:v5';

    private const CLAVE_BLOQUEO = 'gerencia:panel:resumen:bloqueo';

    private const CLAVE_VERSION_CACHE = 'gerencia:panel:resumen:version';

    /**
     * @return array<string, mixed>
     */
    public function obtener(Temporada $temporada): array
    {
        $clave = $this->claveCache($temporada->id);
        $instantanea = Cache::get($clave);

        if (is_array($instantanea)) {
            return $instantanea;
        }

        try {
            return Cache::lock($this->claveBloqueo($temporada->id), 15)->block(
                5,
                fn (): array => Cache::remember(
                    $clave,
                    $this->segundosCache(),
                    fn (): array => $this->construir($temporada),
                ),
            );
        } catch (LockTimeoutException) {
            return Cache::remember(
                $clave,
                $this->segundosCache(),
                fn (): array => $this->construir($temporada),
            );
        }
    }

    public function invalidar(): void
    {
        Cache::forever(self::CLAVE_VERSION_CACHE, (string) Str::uuid());
    }

    public function claveCache(string $temporadaId): string
    {
        return implode(':', [
            self::CLAVE_CACHE,
            $this->versionCache(),
            $temporadaId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function construir(Temporada $temporada): array
    {
        $camaras = $this->camaras();
        $productos = $this->productos($temporada->id);
        $cargas = $this->cargas($temporada->id);
        $validacion = $this->validacion($temporada->id);
        $materiales = $this->materiales($temporada->id);
        $prefrio = $this->prefrio($temporada->id);
        $romana = $this->romana($temporada->id);
        $materiaPrima = $this->materiaPrima($temporada->id);
        $envases = $this->envases($temporada->id);

        return [
            'generado_at' => now()->toAtomString(),
            'actualizacion_segundos' => 30,
            'temporada' => $this->temporada($temporada),
            'temporadas' => Temporada::query()
                ->productivas()
                ->orderByDesc('activa')
                ->orderByDesc('fecha_inicio')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Temporada $opcion): array => $this->temporada($opcion))
                ->values()
                ->all(),
            'camaras' => $camaras,
            'productos' => $productos,
            'cargas' => $cargas,
            'validacion' => $validacion,
            'verificaciones' => $this->verificaciones($temporada->id),
            'materiales' => $materiales,
            'prefrio' => $prefrio,
            'romana' => $romana,
            'materia_prima' => $materiaPrima,
            'envases' => $envases,
            'alertas' => $this->alertas(
                $camaras,
                $productos,
                $cargas,
                $validacion,
                $materiales,
                $prefrio,
                $romana,
                $materiaPrima,
                $envases,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function verificaciones(string $temporadaId): array
    {
        $hoy = now();
        $camaras = Camara::query()->where('contenido', ContenidoCamara::Productos->value)
            ->pluck('codigo', 'id');
        $periodos = [];
        foreach ([7, 30] as $dias) {
            $desde = $hoy->copy()->subDays($dias);
            $items = DB::table('verificaciones_ubicacion_items as item')
                ->join('verificaciones_ubicacion as ronda', 'ronda.id', '=', 'item.verificacion_ubicacion_id')
                ->join('posiciones', 'posiciones.id', '=', 'item.posicion_id')
                ->where('ronda.temporada_id', $temporadaId)
                ->where('item.verificada_at', '>=', $desde)
                ->whereIn('item.resultado', ['coincide', 'otro_folio', 'posicion_vacia'])
                ->selectRaw("posiciones.camara_id, COUNT(*) as total, SUM(CASE WHEN item.resultado = 'coincide' THEN 1 ELSE 0 END) as correctas")
                ->groupBy('posiciones.camara_id')->get();
            $rondas = DB::table('verificaciones_ubicacion')->where('temporada_id', $temporadaId)
                ->where('turno_inicio_at', '>=', $desde);
            $generadas = (clone $rondas)->count();
            $completadas = (clone $rondas)->where('estado', 'completada')->count();
            $periodos[$dias] = [
                'cumplimiento' => [
                    'completadas' => $completadas, 'generadas' => $generadas,
                    'porcentaje' => $generadas > 0 ? round($completadas * 100 / $generadas, 1) : null,
                ],
                'camaras' => $items->map(fn ($item): array => [
                    'codigo' => $camaras[$item->camara_id] ?? 'Cámara',
                    'correctas' => (int) $item->correctas,
                    'total' => (int) $item->total,
                    'exactitud_porcentaje' => round($item->correctas * 100 / $item->total, 1),
                ])->all(),
            ];
        }

        return ['habilitada' => (bool) config('verificaciones.habilitada'), 'periodos' => $periodos];
    }

    /**
     * @return array<string, mixed>
     */
    private function camaras(): array
    {
        $camaras = Camara::query()
            ->where('estado', EstadoCamara::Activa->value)
            ->withCount([
                'posiciones as posiciones_operativas_count' => fn (Builder $consulta): Builder => $consulta
                    ->where('estado', EstadoPosicion::Activa->value)
                    ->whereColumn('banda', '<=', 'camaras.cantidad_bandas')
                    ->whereColumn('posicion', '<=', 'camaras.posiciones_por_banda')
                    ->whereColumn('nivel', '<=', 'camaras.cantidad_niveles'),
                'posiciones as posiciones_ocupadas_count' => fn (Builder $consulta): Builder => $consulta
                    ->where('estado', EstadoPosicion::Activa->value)
                    ->whereHas('ubicacionActual')
                    ->whereColumn('banda', '<=', 'camaras.cantidad_bandas')
                    ->whereColumn('posicion', '<=', 'camaras.posiciones_por_banda')
                    ->whereColumn('nivel', '<=', 'camaras.cantidad_niveles'),
                'posiciones as posiciones_no_operativas_count' => fn (Builder $consulta): Builder => $consulta
                    ->where('estado', '!=', EstadoPosicion::Activa->value)
                    ->whereColumn('banda', '<=', 'camaras.cantidad_bandas')
                    ->whereColumn('posicion', '<=', 'camaras.posiciones_por_banda')
                    ->whereColumn('nivel', '<=', 'camaras.cantidad_niveles'),
            ])
            ->orderBy('codigo')
            ->get()
            ->map(function (Camara $camara): array {
                $operativas = (int) $camara->posiciones_operativas_count;
                $ocupadas = min($operativas, (int) $camara->posiciones_ocupadas_count);

                return [
                    'id' => $camara->id,
                    'codigo' => $camara->codigo,
                    'nombre' => $camara->nombre,
                    'contenido' => $camara->contenido->value,
                    'operativas' => $operativas,
                    'ocupadas' => $ocupadas,
                    'disponibles' => max(0, $operativas - $ocupadas),
                    'no_operativas' => (int) $camara->posiciones_no_operativas_count,
                    'ocupacion_porcentaje' => $this->porcentaje($ocupadas, $operativas),
                ];
            });

        return [
            'resumen' => $this->resumenCapacidad($camaras),
            'por_contenido' => [
                'productos' => $this->resumenCapacidad(
                    $camaras->where('contenido', ContenidoCamara::Productos->value),
                ),
                'materiales' => $this->resumenCapacidad(
                    $camaras->where('contenido', ContenidoCamara::Materiales->value),
                ),
                'materia_prima' => $this->resumenCapacidad(
                    $camaras->where('contenido', ContenidoCamara::MateriaPrima->value),
                ),
            ],
            'detalle' => $camaras->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productos(string $temporadaId): array
    {
        $tramos = config('gerencia.antiguedad_pt_tramos_dias', [7, 14, 30]);
        $inicioHoy = now()->startOfDay();
        $fecha7 = $inicioHoy->copy()->subDays($tramos[0]);
        $fecha14 = $inicioHoy->copy()->subDays($tramos[1]);
        $fechaUmbral = $inicioHoy->copy()->subDays($tramos[2]);
        $base = Folio::query()
            ->where('temporada_id', $temporadaId)
            ->where('activo', true)
            ->whereIn('tipo_bulto', [TipoBulto::Pallet->value, TipoBulto::Saldo->value]);
        $conteos = (clone $base)
            ->selectRaw('COUNT(*) as total_activos')
            ->selectRaw('SUM(CASE WHEN estado_operacional = ? AND EXISTS (SELECT 1 FROM carga_folios cf INNER JOIN reservas_carga_folio r ON r.carga_folio_id = cf.id WHERE cf.folio_id = folios.id) THEN 1 ELSE 0 END) as comprometidos_carga', [EstadoOperacionalFolio::Disponible->value])
            ->selectRaw('SUM(CASE WHEN estado_operacional = ? THEN 1 ELSE 0 END) as pendientes_prefrio', [EstadoOperacionalFolio::PendientePrefrio->value])
            ->selectRaw('SUM(CASE WHEN estado_operacional = ? THEN 1 ELSE 0 END) as bloqueados', [EstadoOperacionalFolio::Bloqueado->value])
            ->selectRaw('SUM(CASE WHEN estado_operacional = ? THEN 1 ELSE 0 END) as pendientes_ubicacion', [EstadoOperacionalFolio::PendienteUbicacion->value])
            ->selectRaw('SUM(CASE WHEN fecha_ingreso >= ? THEN 1 ELSE 0 END) as ingresados_hoy', [$inicioHoy])
            ->selectRaw('SUM(CASE WHEN tipo_bulto = ? THEN 1 ELSE 0 END) as pallets', [TipoBulto::Pallet->value])
            ->selectRaw('SUM(CASE WHEN tipo_bulto = ? THEN 1 ELSE 0 END) as saldos', [TipoBulto::Saldo->value])
            ->selectRaw('SUM(CASE WHEN fecha_ingreso >= ? THEN 1 ELSE 0 END) as tramo_0_7', [$fecha7])
            ->selectRaw('SUM(CASE WHEN fecha_ingreso >= ? AND fecha_ingreso < ? THEN 1 ELSE 0 END) as tramo_8_14', [$fecha14, $fecha7])
            ->selectRaw('SUM(CASE WHEN fecha_ingreso >= ? AND fecha_ingreso < ? THEN 1 ELSE 0 END) as tramo_15_30', [$fechaUmbral, $fecha14])
            ->selectRaw('SUM(CASE WHEN fecha_ingreso < ? THEN 1 ELSE 0 END) as sobre_umbral', [$fechaUmbral])
            ->selectRaw('SUM(CASE WHEN fecha_ingreso IS NULL THEN 1 ELSE 0 END) as sin_fecha')
            ->firstOrFail();
        $total = (int) $conteos->total_activos;
        $disponibles = (clone $base)
            ->where('estado_operacional', EstadoOperacionalFolio::Disponible->value)
            ->whereDoesntHave('asignacionCargaActual')
            ->whereHas(
                'ubicacionActual.posicion',
                fn (Builder $posicion): Builder => $posicion
                    ->where('estado', EstadoPosicion::Activa->value)
                    ->whereHas(
                        'camara',
                        fn (Builder $camara): Builder => $camara
                            ->where('estado', EstadoCamara::Activa->value)
                            ->where('contenido', ContenidoCamara::Productos->value),
                    ),
            )
            ->count();
        $cargasComprometidas = DB::table('folios')
            ->join('carga_folios', 'carga_folios.folio_id', '=', 'folios.id')
            ->join('reservas_carga_folio', 'reservas_carga_folio.carga_folio_id', '=', 'carga_folios.id')
            ->where('folios.temporada_id', $temporadaId)
            ->where('folios.activo', true)
            ->whereIn('folios.tipo_bulto', [TipoBulto::Pallet->value, TipoBulto::Saldo->value])
            ->where('folios.estado_operacional', EstadoOperacionalFolio::Disponible->value)
            ->distinct()
            ->count('carga_folios.carga_id');

        $comprometidos = (int) $conteos->comprometidos_carga;
        $pendientes = (int) $conteos->pendientes_prefrio;
        $bloqueados = (int) $conteos->bloqueados;
        $pendientesUbicacion = (int) $conteos->pendientes_ubicacion;

        return [
            'total_activos' => $total,
            'disponibles_despacho' => $disponibles,
            'comprometidos_carga' => $comprometidos,
            'pendientes_prefrio' => $pendientes,
            'bloqueados' => $bloqueados,
            'pendientes_ubicacion' => $pendientesUbicacion,
            'ingresados_hoy' => (int) $conteos->ingresados_hoy,
            'pallets' => (int) $conteos->pallets,
            'saldos' => (int) $conteos->saldos,
            'cargas_comprometidas' => $cargasComprometidas,
            'antiguedad' => [
                'tramos' => [
                    ['desde_dias' => 0, 'hasta_dias' => $tramos[0], 'total' => (int) $conteos->tramo_0_7],
                    ['desde_dias' => $tramos[0] + 1, 'hasta_dias' => $tramos[1], 'total' => (int) $conteos->tramo_8_14],
                    ['desde_dias' => $tramos[1] + 1, 'hasta_dias' => $tramos[2], 'total' => (int) $conteos->tramo_15_30],
                    ['desde_dias' => $tramos[2] + 1, 'hasta_dias' => null, 'total' => (int) $conteos->sobre_umbral],
                ],
                'umbral_alerta_dias' => $tramos[2],
                'sobre_umbral' => (int) $conteos->sobre_umbral,
                'sin_fecha' => (int) $conteos->sin_fecha,
            ],
            'otros' => max(
                0,
                $total - $disponibles - $comprometidos - $pendientes - $bloqueados - $pendientesUbicacion,
            ),
            'disponibilidad_porcentaje' => $this->porcentaje($disponibles, $total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function cargas(string $temporadaId): array
    {
        $estadosActivos = collect(EstadoCarga::visiblesEnOperacion())->map->value->all();
        $porEstado = Carga::query()
            ->where('temporada_id', $temporadaId)
            ->whereIn('estado', $estadosActivos)
            ->groupBy('estado')
            ->select('estado')
            ->selectRaw('COUNT(*) as total')
            ->pluck('total', 'estado');
        $folios = CargaFolio::query()
            ->whereHas('reservaActiva')
            ->whereHas(
                'carga',
                fn (Builder $consulta): Builder => $consulta
                    ->where('temporada_id', $temporadaId)
                    ->whereIn('estado', $estadosActivos),
            );
        $detalle = Carga::query()
            ->withCount('asignacionesActuales')
            ->with([
                'camaraObjetivo:id,codigo,nombre',
                'andenPrevisto:id,codigo,nombre',
            ])
            ->where('temporada_id', $temporadaId)
            ->whereIn('estado', $estadosActivos)
            ->oldest('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Carga $carga): array => [
                'id' => $carga->id,
                'codigo' => $carga->codigo,
                'orden_embarque' => $carga->numero_orden_externa,
                'estado' => $carga->estado->value,
                'prioridad' => $carga->prioridad?->value,
                'folios_asignados' => (int) $carga->asignaciones_actuales_count,
                'camara_objetivo' => $carga->camaraObjetivo?->codigo,
                'anden' => $carga->andenPrevisto?->codigo,
                'publicada_at' => $carga->publicada_at?->toAtomString(),
                'antiguedad_minutos' => (int) $carga->created_at->diffInMinutes(now()),
            ]);

        return [
            'activas' => (int) $porEstado->sum(),
            'pendientes' => (int) $porEstado->get(EstadoCarga::Pendiente->value, 0),
            'en_preparacion' => (int) collect([
                EstadoCarga::EnPreparacion,
                EstadoCarga::EnSeparacion,
                EstadoCarga::DespachoParcial,
            ])->sum(fn (EstadoCarga $estado): int => (int) $porEstado->get($estado->value, 0)),
            'separadas' => (int) collect([
                EstadoCarga::Separada,
                EstadoCarga::SeparacionCompleta,
            ])->sum(fn (EstadoCarga $estado): int => (int) $porEstado->get($estado->value, 0)),
            'folios_pendientes' => (clone $folios)
                ->whereIn('estado', [
                    EstadoCargaFolio::Pendiente->value,
                    EstadoCargaFolio::ConIncidencia->value,
                ])
                ->count(),
            'folios_en_anden' => (clone $folios)
                ->where('estado', EstadoCargaFolio::EnAnden->value)
                ->count(),
            'folios_con_incidencia' => (clone $folios)
                ->where('estado', EstadoCargaFolio::ConIncidencia->value)
                ->count(),
            'cerradas_hoy' => Carga::query()
                ->where('temporada_id', $temporadaId)
                ->whereIn('estado', [EstadoCarga::Despachada->value, EstadoCarga::Cerrada->value])
                ->where('cerrada_at', '>=', now()->startOfDay())
                ->count(),
            'detalle' => $detalle->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validacion(string $temporadaId): array
    {
        $inicio = now()->startOfDay();
        $baseHoy = ValidacionPallet::query()
            ->where('temporada_id', $temporadaId)
            ->where('recibido_servidor_at', '>=', $inicio);
        $porResultado = (clone $baseHoy)
            ->groupBy('resultado')
            ->select('resultado')
            ->selectRaw('COUNT(*) as total')
            ->pluck('total', 'resultado');
        $ultima = ValidacionPallet::query()
            ->where('temporada_id', $temporadaId)
            ->latest('recibido_servidor_at')
            ->first(['numero_folio', 'resultado', 'recibido_servidor_at']);

        return [
            'procesados_hoy' => (clone $baseHoy)->count(),
            'aprobados_hoy' => (int) $porResultado->get(ResultadoValidacionPallet::Aprobado->value, 0),
            'observados_hoy' => (int) $porResultado->get(ResultadoValidacionPallet::Observado->value, 0),
            'rechazados_hoy' => (int) $porResultado->get(ResultadoValidacionPallet::Rechazado->value, 0),
            'conflictos_hoy' => (clone $baseHoy)
                ->where('estado', EstadoValidacionPallet::Conflicto->value)
                ->count(),
            'ultima_validacion' => $ultima ? [
                'folio' => $ultima->numero_folio,
                'resultado' => $ultima->resultado->value,
                'recibido_at' => $ultima->recibido_servidor_at?->toAtomString(),
            ] : null,
        ];
    }

    private function materiales(string $temporadaId): array
    {
        $filas = DB::table('folios_materiales as fm')
            ->join('folios as f', 'f.id', '=', 'fm.folio_id')
            ->join('items_materiales as i', 'i.id', '=', 'fm.item_material_id')
            ->join('clientes_materiales as cm', 'cm.id', '=', 'i.cliente_material_id')
            ->join('temporadas_materiales as tm', 'tm.id', '=', 'cm.temporada_material_id')
            ->leftJoin('ubicaciones_actuales as ua', 'ua.folio_id', '=', 'f.id')
            ->leftJoin('posiciones as p', 'p.id', '=', 'ua.posicion_id')
            ->leftJoin('camaras as c', 'c.id', '=', 'p.camara_id')
            ->where('tm.temporada_id', $temporadaId)
            ->where('f.temporada_id', $temporadaId)
            ->where('f.activo', true)
            ->where('i.activo', true)
            ->where('fm.cantidad_actual', '>', 0)
            ->groupBy([
                'fm.item_material_id',
                'cm.id',
                'cm.codigo',
                'cm.nombre',
                'tm.id',
                'tm.codigo',
                'tm.nombre',
                'tm.activa',
                'i.codigo',
                'i.nombre',
                'i.categoria',
                'fm.unidad_medida',
            ])
            ->select([
                'fm.item_material_id',
                'cm.id as cliente_id',
                'cm.codigo as cliente_codigo',
                'cm.nombre as cliente_nombre',
                'tm.id as temporada_id',
                'tm.codigo as temporada_codigo',
                'tm.nombre as temporada_nombre',
                'tm.activa as temporada_activa',
                'i.codigo',
                'i.nombre',
                'i.categoria',
                'fm.unidad_medida',
            ])
            ->selectRaw('COUNT(DISTINCT fm.folio_id) as folios')
            ->selectRaw('SUM(fm.cantidad_actual) as cantidad_actual')
            ->selectRaw(
                'SUM(CASE WHEN (f.estado_operacional = ? OR fm.motivo_bloqueo IS NOT NULL) '
                .'THEN fm.cantidad_actual ELSE 0 END) as cantidad_bloqueada',
                [EstadoOperacionalFolio::Bloqueado->value],
            )
            ->selectRaw(
                'SUM(CASE WHEN f.estado_operacional <> ? '
                .'AND fm.motivo_bloqueo IS NULL '
                .'AND (ua.id IS NULL OR f.estado_operacional = ?) '
                .'THEN fm.cantidad_actual ELSE 0 END) as cantidad_pendiente_ubicacion',
                [
                    EstadoOperacionalFolio::Bloqueado->value,
                    EstadoOperacionalFolio::PendienteUbicacion->value,
                ],
            )
            ->selectRaw(
                'SUM(CASE WHEN f.estado_operacional = ? '
                .'AND fm.motivo_bloqueo IS NULL '
                .'AND tm.activa = ? '
                .'AND ua.id IS NOT NULL '
                .'AND p.estado = ? '
                .'AND c.estado = ? '
                .'AND c.contenido = ? '
                .'THEN LEAST(fm.cantidad_reservada, fm.cantidad_actual) ELSE 0 END) '
                .'as cantidad_reservada',
                [
                    EstadoOperacionalFolio::Disponible->value,
                    true,
                    EstadoPosicion::Activa->value,
                    EstadoCamara::Activa->value,
                    ContenidoCamara::Materiales->value,
                ],
            )
            ->selectRaw(
                'SUM(CASE WHEN f.estado_operacional = ? '
                .'AND fm.motivo_bloqueo IS NULL '
                .'AND tm.activa = ? '
                .'AND ua.id IS NOT NULL '
                .'AND p.estado = ? '
                .'AND c.estado = ? '
                .'AND c.contenido = ? '
                .'THEN GREATEST(fm.cantidad_actual - fm.cantidad_reservada, 0) ELSE 0 END) '
                .'as cantidad_disponible',
                [
                    EstadoOperacionalFolio::Disponible->value,
                    true,
                    EstadoPosicion::Activa->value,
                    EstadoCamara::Activa->value,
                    ContenidoCamara::Materiales->value,
                ],
            )
            ->get()
            ->map(function (object $fila): array {
                $actual = round((float) $fila->cantidad_actual, 3);
                $reservada = round((float) $fila->cantidad_reservada, 3);
                $disponible = round((float) $fila->cantidad_disponible, 3);
                $bloqueada = round((float) $fila->cantidad_bloqueada, 3);
                $pendiente = round((float) $fila->cantidad_pendiente_ubicacion, 3);
                $noDisponible = round(max(
                    0,
                    $actual - $reservada - $disponible - $bloqueada - $pendiente,
                ), 3);

                return [
                    'item_id' => $fila->item_material_id,
                    'cliente' => [
                        'id' => $fila->cliente_id,
                        'codigo' => $fila->cliente_codigo,
                        'nombre' => $fila->cliente_nombre,
                    ],
                    'temporada' => [
                        'id' => $fila->temporada_id,
                        'codigo' => $fila->temporada_codigo,
                        'nombre' => $fila->temporada_nombre,
                        'activa' => (bool) $fila->temporada_activa,
                    ],
                    'codigo' => $fila->codigo,
                    'nombre' => $fila->nombre,
                    'categoria' => $fila->categoria ?: 'Sin categoría',
                    'unidad_medida' => $fila->unidad_medida,
                    'folios' => (int) $fila->folios,
                    'cantidad_actual' => $actual,
                    'cantidad_reservada' => $reservada,
                    'cantidad_disponible' => $disponible,
                    'cantidad_bloqueada' => $bloqueada,
                    'cantidad_pendiente_ubicacion' => $pendiente,
                    'cantidad_no_disponible' => $noDisponible,
                ];
            });

        $porUnidad = $filas
            ->groupBy('unidad_medida')
            ->map(function (Collection $items, string $unidad): array {
                $actual = round((float) $items->sum('cantidad_actual'), 3);
                $reservada = round((float) $items->sum('cantidad_reservada'), 3);
                $disponible = round((float) $items->sum('cantidad_disponible'), 3);
                $bloqueada = round((float) $items->sum('cantidad_bloqueada'), 3);
                $pendiente = round((float) $items->sum('cantidad_pendiente_ubicacion'), 3);
                $noDisponible = round((float) $items->sum('cantidad_no_disponible'), 3);

                return [
                    'unidad_medida' => $unidad,
                    'items_con_stock' => $items->count(),
                    'folios_con_stock' => (int) $items->sum('folios'),
                    'cantidad_actual' => $actual,
                    'cantidad_reservada' => $reservada,
                    'cantidad_disponible' => $disponible,
                    'cantidad_bloqueada' => $bloqueada,
                    'cantidad_pendiente_ubicacion' => $pendiente,
                    'cantidad_no_disponible' => $noDisponible,
                    'items' => $items
                        ->sortByDesc('cantidad_actual')
                        ->values()
                        ->all(),
                ];
            })
            ->sortKeys()
            ->values();

        return [
            'vencimientos' => app(ServicioConsultaVencimientosMaterial::class)->resumen(),
            'items_con_stock' => $filas->pluck('item_id')->unique()->count(),
            'folios_con_stock' => (int) $filas->sum('folios'),
            'despachos_abiertos' => DespachoMaterial::query()
                ->where('temporada_id', $temporadaId)
                ->whereIn('estado', [
                    EstadoDespachoMaterial::Pendiente->value,
                    EstadoDespachoMaterial::Parcial->value,
                ])
                ->count(),
            'despachos_parciales' => DespachoMaterial::query()
                ->where('temporada_id', $temporadaId)
                ->where('estado', EstadoDespachoMaterial::Parcial->value)
                ->count(),
            'recepciones_confirmadas_hoy' => RecepcionMaterial::query()
                ->where('temporada_id', $temporadaId)
                ->where('estado', EstadoRecepcionMaterial::Confirmada->value)
                ->where('confirmado_at', '>=', now()->startOfDay())
                ->count(),
            'recepciones_borrador' => RecepcionMaterial::query()
                ->where('temporada_id', $temporadaId)
                ->where('estado', EstadoRecepcionMaterial::Borrador->value)
                ->count(),
            'unidades_medida' => $porUnidad->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function materiaPrima(string $temporadaId): array
    {
        $base = LoteMateriaPrima::query()
            ->where('temporada_id', $temporadaId)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value);
        $porEstado = (clone $base)
            ->groupBy('estado')
            ->select('estado')
            ->selectRaw('COUNT(*) as total')
            ->pluck('total', 'estado');

        return [
            'lotes_activos' => (clone $base)->whereNotIn('estado', [
                EstadoLoteMateriaPrima::Borrador->value,
                EstadoLoteMateriaPrima::EntregadoProceso->value,
            ])->count(),
            'borradores' => (int) $porEstado->get(EstadoLoteMateriaPrima::Borrador->value, 0),
            'pendientes_hidrocooler' => (int) $porEstado->get(
                EstadoLoteMateriaPrima::PendienteHidrocooler->value,
                0,
            ),
            'hidrocooler_en_curso' => (int) $porEstado->get(
                EstadoLoteMateriaPrima::HidrocoolerEnCurso->value,
                0,
            ),
            'hidrocooler_retenido' => (int) $porEstado->get(
                EstadoLoteMateriaPrima::HidrocoolerRetenido->value,
                0,
            ),
            'pendientes_asignacion' => (int) $porEstado->get(
                EstadoLoteMateriaPrima::PendienteAsignacion->value,
                0,
            ),
            'directo_proceso' => (int) $porEstado->get(
                EstadoLoteMateriaPrima::DisponibleProceso->value,
                0,
            ),
            'en_camara' => (int) $porEstado->get(EstadoLoteMateriaPrima::AsignadoCamara->value, 0),
            'entrega_parcial' => (int) $porEstado->get(
                EstadoLoteMateriaPrima::EntregaParcialProceso->value,
                0,
            ),
            'entregados_proceso' => (int) $porEstado->get(
                EstadoLoteMateriaPrima::EntregadoProceso->value,
                0,
            ),
            'confirmados_hoy' => LoteMateriaPrima::query()
                ->where('temporada_id', $temporadaId)
                ->whereNotNull('confirmado_at')
                ->where('confirmado_at', '>=', now()->startOfDay())
                ->count(),
            'kilos_confirmados_hoy' => round((float) LoteMateriaPrima::query()
                ->where('temporada_id', $temporadaId)
                ->whereNotNull('confirmado_at')
                ->where('confirmado_at', '>=', now()->startOfDay())
                ->sum('kilos_netos_confirmados'), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envases(string $temporadaId): array
    {
        $inicioHoy = now()->startOfDay();
        $inicioSemana = now()->subDays(6)->startOfDay();
        $hoy = MovimientoEnvase::query()
            ->where('temporada_id', $temporadaId)
            ->where('ocurrido_at', '>=', $inicioHoy);

        $existencias = MovimientoEnvase::query()
            ->where('temporada_id', $temporadaId)
            ->groupBy('tipo_envase')
            ->select('tipo_envase')
            ->selectRaw('COALESCE(SUM(CAST(cantidad AS SIGNED) * signo_existencia), 0) as total')
            ->pluck('total', 'tipo_envase');

        $movimientosHoy = (clone $hoy)
            ->groupBy('tipo_envase')
            ->select('tipo_envase')
            ->selectRaw('COALESCE(SUM(CASE WHEN signo_existencia > 0 THEN cantidad ELSE 0 END), 0) as entradas')
            ->selectRaw('COALESCE(SUM(CASE WHEN signo_existencia < 0 THEN cantidad ELSE 0 END), 0) as salidas')
            ->get()
            ->keyBy(fn (MovimientoEnvase $movimiento): string => $movimiento->tipo_envase->value);

        $tendencia = MovimientoEnvase::query()
            ->where('temporada_id', $temporadaId)
            ->where('ocurrido_at', '>=', $inicioSemana)
            ->groupBy('tipo_envase')
            ->groupByRaw('DATE(ocurrido_at)')
            ->selectRaw('tipo_envase, DATE(ocurrido_at) as fecha')
            ->selectRaw('COALESCE(SUM(CASE WHEN signo_existencia > 0 THEN cantidad ELSE 0 END), 0) as entradas')
            ->selectRaw('COALESCE(SUM(CASE WHEN signo_existencia < 0 THEN cantidad ELSE 0 END), 0) as salidas')
            ->get()
            ->groupBy(fn (MovimientoEnvase $movimiento): string => $movimiento->tipo_envase->value);

        $saldos = MovimientoEnvase::query()
            ->where('temporada_id', $temporadaId)
            ->groupBy('cliente_id', 'tipo_envase')
            ->select('cliente_id', 'tipo_envase')
            ->selectRaw('SUM(CAST(cantidad AS SIGNED) * signo_cuenta) as saldo')
            ->with('cliente:id,nombre')
            ->get()
            ->groupBy(fn (MovimientoEnvase $movimiento): string => $movimiento->tipo_envase->value);

        $tipos = collect(TipoEnvaseRomana::cases())->mapWithKeys(function (TipoEnvaseRomana $tipo) use ($existencias, $movimientosHoy, $tendencia, $saldos, $inicioSemana): array {
            $codigo = $tipo->value;
            $dias = collect(range(0, 6))->map(function (int $desplazamiento) use ($tendencia, $codigo, $inicioSemana): array {
                $fecha = $inicioSemana->copy()->addDays($desplazamiento);
                $registro = $tendencia->get($codigo, collect())
                    ->first(fn (MovimientoEnvase $movimiento): bool => $movimiento->getAttribute('fecha') === $fecha->toDateString());

                return [
                    'fecha' => $fecha->toDateString(),
                    'etiqueta' => $fecha->format('d/m'),
                    'entradas' => (int) ($registro?->getAttribute('entradas') ?? 0),
                    'salidas' => (int) ($registro?->getAttribute('salidas') ?? 0),
                ];
            });

            return [$codigo => [
                'existencia' => (int) $existencias->get($codigo, 0),
                'entradas_hoy' => (int) ($movimientosHoy->get($codigo)?->getAttribute('entradas') ?? 0),
                'salidas_hoy' => (int) ($movimientosHoy->get($codigo)?->getAttribute('salidas') ?? 0),
                'tendencia_diaria' => $dias->all(),
                'saldos_clientes' => $saldos->get($codigo, collect())
                    ->filter(fn (MovimientoEnvase $movimiento): bool => (int) $movimiento->getAttribute('saldo') !== 0)
                    ->sortByDesc(fn (MovimientoEnvase $movimiento): int => abs((int) $movimiento->getAttribute('saldo')))
                    ->take(5)
                    ->map(fn (MovimientoEnvase $movimiento): array => [
                        'cliente' => $movimiento->cliente?->nombre ?? 'Cliente sin nombre',
                        'saldo' => (int) $movimiento->getAttribute('saldo'),
                    ])->values()->all(),
            ]];
        })->all();

        return [
            'movimientos_hoy' => (clone $hoy)->count(),
            'tipos' => $tipos,
            'pendientes_revision' => MovimientoEnvase::query()
                ->where('temporada_id', $temporadaId)
                ->where('estado_revision', EstadoRevisionMovimientoEnvase::Pendiente->value)
                ->count(),
            'observados' => MovimientoEnvase::query()
                ->where('temporada_id', $temporadaId)
                ->where('estado_revision', EstadoRevisionMovimientoEnvase::Observado->value)
                ->count(),
        ];
    }

    private function prefrio(string $temporadaId): array
    {
        $estadosActivos = collect(EstadoProcesoPrefrio::cases())
            ->filter->esActivo()
            ->map->value
            ->all();
        $tuneles = TunelPrefrio::query()
            ->withCount([
                'posiciones as posiciones_activas_count' => fn (Builder $consulta): Builder => $consulta
                    ->where('activa', true),
            ])
            ->with([
                'procesoActivo' => fn (HasOne $consulta): HasOne => $consulta
                    ->withCount([
                        'folios as folios_activos_count' => fn (Builder $folios): Builder => $folios
                            ->whereNull('retirado_at'),
                    ]),
            ])
            ->orderBy('codigo')
            ->get()
            ->map(function (TunelPrefrio $tunel) use ($temporadaId): array {
                $operativo = $tunel->estado_administrativo === EstadoAdministrativoTunelPrefrio::Activo
                    && $tunel->estado_tecnico === EstadoTecnicoTunelPrefrio::Operativo;
                $capacidad = $operativo ? (int) $tunel->posiciones_activas_count : 0;
                $ocupadas = $operativo
                    ? min($capacidad, (int) ($tunel->procesoActivo?->folios_activos_count ?? 0))
                    : 0;
                $proceso = $tunel->procesoActivo?->temporada_id === $temporadaId
                    ? $tunel->procesoActivo
                    : null;
                $transcurridos = $proceso?->iniciado_at
                    ? (int) $proceso->iniciado_at->diffInMinutes(now())
                    : null;
                $objetivo = $proceso?->duracion_objetivo_minutos;
                $atrasado = $transcurridos !== null
                    && $objetivo
                    && $transcurridos > $objetivo
                    && in_array($proceso->estado, [
                        EstadoProcesoPrefrio::EnProceso,
                        EstadoProcesoPrefrio::PendienteVerificacion,
                    ], true);

                return [
                    'id' => $tunel->id,
                    'codigo' => $tunel->codigo,
                    'nombre' => $tunel->nombre,
                    'estado_administrativo' => $tunel->estado_administrativo->value,
                    'estado_tecnico' => $tunel->estado_tecnico->value,
                    'operativo' => $operativo,
                    'capacidad' => $capacidad,
                    'ocupadas' => $ocupadas,
                    'disponibles' => max(0, $capacidad - $ocupadas),
                    'ocupacion_porcentaje' => $this->porcentaje($ocupadas, $capacidad),
                    'proceso_activo' => $proceso ? [
                        'codigo' => $proceso->codigo,
                        'estado' => $proceso->estado->value,
                        'setpoint' => $proceso->setpoint !== null ? (float) $proceso->setpoint : null,
                        'formato' => $proceso->formato_referencia,
                        'iniciado_at' => $proceso->iniciado_at?->toAtomString(),
                        'duracion_objetivo_minutos' => $objetivo,
                        'transcurridos_minutos' => $transcurridos,
                        'atrasado' => $atrasado,
                        'avance_porcentaje' => $transcurridos !== null && $objetivo
                            ? min(100, $this->porcentaje($transcurridos, $objetivo))
                            : null,
                    ] : null,
                ];
            });
        $capacidad = (int) $tuneles->sum('capacidad');
        $ocupadas = (int) $tuneles->sum('ocupadas');
        $completados = ProcesoPrefrio::query()
            ->where('temporada_id', $temporadaId)
            ->whereIn('estado', [
                EstadoProcesoPrefrio::Aprobado->value,
                EstadoProcesoPrefrio::RequiereReproceso->value,
            ])
            ->whereNotNull('iniciado_at')
            ->whereNotNull('finalizado_at')
            ->where('finalizado_at', '>=', now()->subDays(7))
            ->get(['estado', 'iniciado_at', 'finalizado_at']);
        $duraciones = $completados
            ->map(fn (ProcesoPrefrio $proceso): int => (int) $proceso->iniciado_at->diffInMinutes($proceso->finalizado_at))
            ->filter(fn (int $minutos): bool => $minutos >= 0);

        return [
            'tuneles_operativos' => $tuneles->where('operativo', true)->count(),
            'tuneles_totales' => $tuneles->count(),
            'procesos_activos' => ProcesoPrefrio::query()
                ->where('temporada_id', $temporadaId)
                ->whereIn('estado', $estadosActivos)
                ->count(),
            'procesos_atrasados' => $tuneles
                ->filter(fn (array $tunel): bool => (bool) ($tunel['proceso_activo']['atrasado'] ?? false))
                ->count(),
            'aprobados_hoy' => $completados
                ->where('estado', EstadoProcesoPrefrio::Aprobado)
                ->filter(fn (ProcesoPrefrio $proceso): bool => $proceso->finalizado_at->isToday())
                ->count(),
            'reprocesos_hoy' => $completados
                ->where('estado', EstadoProcesoPrefrio::RequiereReproceso)
                ->filter(fn (ProcesoPrefrio $proceso): bool => $proceso->finalizado_at->isToday())
                ->count(),
            'duracion_promedio_minutos_7d' => $duraciones->isNotEmpty()
                ? (int) round($duraciones->average())
                : null,
            'folios_pendientes' => Folio::query()
                ->where('temporada_id', $temporadaId)
                ->where('activo', true)
                ->where('estado_operacional', EstadoOperacionalFolio::PendientePrefrio->value)
                ->whereIn('tipo_bulto', [TipoBulto::Pallet->value, TipoBulto::Saldo->value])
                ->count(),
            'capacidad' => $capacidad,
            'ocupadas' => $ocupadas,
            'disponibles' => max(0, $capacidad - $ocupadas),
            'ocupacion_porcentaje' => $this->porcentaje($ocupadas, $capacidad),
            'tuneles' => $tuneles->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function romana(string $temporadaId): array
    {
        $hoy = CarbonImmutable::today();
        $inicio = $hoy->subDays(6)->startOfDay();
        $termino = $hoy->addDay()->startOfDay();
        $porEstado = RecepcionRomana::query()
            ->where('temporada_id', $temporadaId)
            ->whereIn('estado', [
                EstadoRecepcionRomana::EnBasculaIngreso->value,
                EstadoRecepcionRomana::EnPesajeEnvases->value,
                EstadoRecepcionRomana::EnBasculaSalida->value,
            ])
            ->groupBy('estado')
            ->select('estado')
            ->selectRaw('COUNT(*) as total')
            ->pluck('total', 'estado');
        $porDia = RecepcionRomana::query()
            ->where('temporada_id', $temporadaId)
            ->where('estado', EstadoRecepcionRomana::Cerrado->value)
            ->where('salida_at', '>=', $inicio)
            ->where('salida_at', '<', $termino)
            ->groupByRaw('DATE(salida_at)')
            ->selectRaw('DATE(salida_at) as fecha')
            ->selectRaw('COUNT(*) as recepciones')
            ->selectRaw('COALESCE(SUM(peso_neto), 0) as peso_neto')
            ->selectRaw('COALESCE(SUM(cantidad_envases_declarados), 0) as envases')
            ->selectRaw('COUNT(DISTINCT cliente_id) as clientes')
            ->get()
            ->keyBy('fecha');
        $tendencia = collect(range(6, 0))
            ->map(function (int $dias) use ($hoy, $porDia): array {
                $fecha = $hoy->subDays($dias);
                $fila = $porDia->get($fecha->toDateString());

                return [
                    'fecha' => $fecha->toDateString(),
                    'etiqueta' => $fecha->locale('es')->isoFormat('ddd D'),
                    'recepciones' => (int) ($fila?->recepciones ?? 0),
                    'peso_neto' => round((float) ($fila?->peso_neto ?? 0), 2),
                ];
            });
        $filaHoy = $porDia->get($hoy->toDateString());

        return [
            'en_bascula_ingreso' => (int) $porEstado->get(
                EstadoRecepcionRomana::EnBasculaIngreso->value,
                0,
            ),
            'en_pesaje_envases' => (int) $porEstado->get(
                EstadoRecepcionRomana::EnPesajeEnvases->value,
                0,
            ),
            'pendientes_destare' => (int) $porEstado->get(
                EstadoRecepcionRomana::EnBasculaSalida->value,
                0,
            ),
            'cerradas_hoy' => (int) ($filaHoy?->recepciones ?? 0),
            'peso_neto_hoy' => round((float) ($filaHoy?->peso_neto ?? 0), 2),
            'envases_hoy' => (int) ($filaHoy?->envases ?? 0),
            'clientes_hoy' => (int) ($filaHoy?->clientes ?? 0),
            'tendencia_diaria' => $tendencia->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $camaras
     * @return array<string, int|float>
     */
    private function resumenCapacidad(Collection $camaras): array
    {
        $operativas = (int) $camaras->sum('operativas');
        $ocupadas = (int) $camaras->sum('ocupadas');

        return [
            'camaras' => $camaras->count(),
            'operativas' => $operativas,
            'ocupadas' => $ocupadas,
            'disponibles' => max(0, $operativas - $ocupadas),
            'no_operativas' => (int) $camaras->sum('no_operativas'),
            'ocupacion_porcentaje' => $this->porcentaje($ocupadas, $operativas),
        ];
    }

    /**
     * @param  array<string, mixed>  $camaras
     * @param  array<string, mixed>  $productos
     * @param  array<string, mixed>  $cargas
     * @param  array<string, mixed>  $validacion
     * @param  array<string, mixed>  $materiales
     * @param  array<string, mixed>  $prefrio
     * @param  array<string, mixed>  $romana
     * @param  array<string, mixed>  $materiaPrima
     * @param  array<string, mixed>  $envases
     * @return array<int, array<string, string|int|float>>
     */
    private function alertas(
        array $camaras,
        array $productos,
        array $cargas,
        array $validacion,
        array $materiales,
        array $prefrio,
        array $romana,
        array $materiaPrima,
        array $envases,
    ): array {
        $alertas = collect($camaras['detalle'])
            ->filter(fn (array $camara): bool => $camara['ocupacion_porcentaje'] >= 90)
            ->map(fn (array $camara): array => [
                'nivel' => 'advertencia',
                'area' => 'Cámaras',
                'titulo' => "{$camara['codigo']} con alta ocupación",
                'detalle' => "{$camara['ocupadas']} de {$camara['operativas']} posiciones operativas ocupadas.",
                'metrica' => "{$camara['ocupacion_porcentaje']}%",
                'href' => '/oficina/frigorifico/camaras',
            ]);

        collect($prefrio['tuneles'])
            ->where('operativo', false)
            ->each(fn (array $tunel) => $alertas->push([
                'nivel' => 'critica',
                'area' => 'Prefrío',
                'titulo' => "{$tunel['codigo']} no disponible",
                'detalle' => 'El túnel está inactivo, en mantenimiento o fuera de servicio.',
                'metrica' => 'Fuera de servicio',
                'href' => '/oficina/prefrio',
            ]));

        if ($prefrio['procesos_atrasados'] > 0) {
            $alertas->push([
                'nivel' => 'critica',
                'area' => 'Prefrío',
                'titulo' => 'Procesos sobre su duración objetivo',
                'detalle' => "{$prefrio['procesos_atrasados']} proceso(s) exceden el tiempo configurado.",
                'metrica' => $prefrio['procesos_atrasados'],
                'href' => '/oficina/prefrio',
            ]);
        }

        if ($productos['bloqueados'] > 0) {
            $alertas->push([
                'nivel' => 'advertencia',
                'area' => 'Producto terminado',
                'titulo' => 'Folios bloqueados',
                'detalle' => "{$productos['bloqueados']} folio(s) requieren liberación o corrección.",
                'metrica' => $productos['bloqueados'],
                'href' => '/oficina/frigorifico/camaras',
            ]);
        }

        if ($cargas['folios_con_incidencia'] > 0) {
            $alertas->push([
                'nivel' => 'critica',
                'area' => 'Cargas',
                'titulo' => 'Folios con incidencia de despacho',
                'detalle' => "{$cargas['folios_con_incidencia']} folio(s) frenan o condicionan cargas activas.",
                'metrica' => $cargas['folios_con_incidencia'],
                'href' => '/oficina/frigorifico/despacho/estatus',
            ]);
        }

        if ($validacion['observados_hoy'] + $validacion['rechazados_hoy'] > 0) {
            $total = $validacion['observados_hoy'] + $validacion['rechazados_hoy'];
            $alertas->push([
                'nivel' => 'advertencia',
                'area' => 'Validación PT',
                'titulo' => 'Pallets observados o rechazados hoy',
                'detalle' => "{$validacion['observados_hoy']} observados y {$validacion['rechazados_hoy']} rechazados.",
                'metrica' => $total,
                'href' => '/oficina/validacion',
            ]);
        }

        if ($validacion['conflictos_hoy'] > 0) {
            $alertas->push([
                'nivel' => 'critica',
                'area' => 'Validación PT',
                'titulo' => 'Conflictos de sincronización',
                'detalle' => "{$validacion['conflictos_hoy']} validación(es) presentan conflicto de datos.",
                'metrica' => $validacion['conflictos_hoy'],
                'href' => '/oficina/validacion',
            ]);
        }

        collect($materiales['unidades_medida'])
            ->filter(fn (array $unidad): bool => $unidad['cantidad_actual'] > 0
                && $unidad['cantidad_disponible'] <= 0)
            ->each(fn (array $unidad) => $alertas->push([
                'nivel' => 'advertencia',
                'area' => 'Materiales',
                'titulo' => "Stock {$unidad['unidad_medida']} sin disponibilidad operativa",
                'detalle' => 'No queda cantidad habilitada para nuevos despachos en esta unidad de medida.',
                'metrica' => $unidad['cantidad_actual'],
                'href' => '/oficina/materiales/inventario',
            ]));

        collect($materiales['unidades_medida'])
            ->filter(fn (array $unidad): bool => $unidad['cantidad_bloqueada'] > 0)
            ->each(fn (array $unidad) => $alertas->push([
                'nivel' => 'advertencia',
                'area' => 'Materiales',
                'titulo' => "Material {$unidad['unidad_medida']} bloqueado",
                'detalle' => "{$unidad['cantidad_bloqueada']} {$unidad['unidad_medida']} requieren revisión supervisada.",
                'metrica' => $unidad['cantidad_bloqueada'],
                'href' => '/oficina/materiales/inventario',
            ]));

        collect($materiales['unidades_medida'])
            ->filter(fn (array $unidad): bool => $unidad['cantidad_pendiente_ubicacion'] > 0)
            ->each(fn (array $unidad) => $alertas->push([
                'nivel' => 'advertencia',
                'area' => 'Materiales',
                'titulo' => "Material {$unidad['unidad_medida']} sin ubicación",
                'detalle' => "{$unidad['cantidad_pendiente_ubicacion']} {$unidad['unidad_medida']} todavía no están disponibles para operación.",
                'metrica' => $unidad['cantidad_pendiente_ubicacion'],
                'href' => '/oficina/materiales/inventario',
            ]));

        if ($materiaPrima['pendientes_hidrocooler'] + $materiaPrima['pendientes_asignacion'] > 0) {
            $total = $materiaPrima['pendientes_hidrocooler'] + $materiaPrima['pendientes_asignacion'];
            $alertas->push([
                'nivel' => 'advertencia',
                'area' => 'Materia prima',
                'titulo' => 'Lotes pendientes de etapa',
                'detalle' => "{$materiaPrima['pendientes_hidrocooler']} esperan hidrocooler y {$materiaPrima['pendientes_asignacion']} esperan cámara.",
                'metrica' => $total,
                'href' => '/oficina/materia-prima',
            ]);
        }

        if ($materiaPrima['hidrocooler_retenido'] > 0) {
            $alertas->push([
                'nivel' => 'critica',
                'area' => 'Materia prima',
                'titulo' => 'Lotes retenidos en hidrocooler',
                'detalle' => "{$materiaPrima['hidrocooler_retenido']} lote(s) requieren resolución antes de continuar.",
                'metrica' => $materiaPrima['hidrocooler_retenido'],
                'href' => '/oficina/materia-prima/hidrocooler',
            ]);
        }

        if ($romana['pendientes_destare'] > 0) {
            $alertas->push([
                'nivel' => 'advertencia',
                'area' => 'Romana',
                'titulo' => 'Recepciones pendientes de cierre',
                'detalle' => "{$romana['pendientes_destare']} recepción(es) esperan destare o cierre documental.",
                'metrica' => $romana['pendientes_destare'],
                'href' => '/oficina/romana',
            ]);
        }

        if ($envases['pendientes_revision'] > 0) {
            $alertas->push([
                'nivel' => 'informativa',
                'area' => 'Envases',
                'titulo' => 'Movimientos pendientes de revisión',
                'detalle' => "{$envases['pendientes_revision']} movimiento(s) requieren chequeo documental.",
                'metrica' => $envases['pendientes_revision'],
                'href' => '/oficina/envases/cuenta-corriente',
            ]);
        }

        if ($envases['observados'] > 0) {
            $alertas->push([
                'nivel' => 'advertencia',
                'area' => 'Envases',
                'titulo' => 'Movimientos observados',
                'detalle' => "{$envases['observados']} movimiento(s) requieren gestión documental.",
                'metrica' => $envases['observados'],
                'href' => '/oficina/envases/cuenta-corriente',
            ]);
        }

        $prioridad = ['critica' => 0, 'advertencia' => 1, 'informativa' => 2];

        return $alertas
            ->sortBy(fn (array $alerta): int => $prioridad[$alerta['nivel']] ?? 3)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function temporada(Temporada $temporada): array
    {
        return [
            'id' => $temporada->id,
            'codigo' => $temporada->codigo,
            'nombre' => $temporada->nombre,
            'fecha_inicio' => $temporada->fecha_inicio?->toDateString(),
            'fecha_fin' => $temporada->fecha_fin?->toDateString(),
            'activa' => $temporada->activa,
        ];
    }

    private function claveBloqueo(string $temporadaId): string
    {
        return implode(':', [
            self::CLAVE_BLOQUEO,
            $this->versionCache(),
            $temporadaId,
        ]);
    }

    private function versionCache(): string
    {
        return (string) Cache::get(self::CLAVE_VERSION_CACHE, '1');
    }

    private function porcentaje(int|float $parte, int|float $total): float
    {
        return $total > 0 ? round(($parte / $total) * 100, 1) : 0.0;
    }

    private function segundosCache(): int
    {
        return max(1, (int) config('gerencia.cache_segundos', 60));
    }
}
