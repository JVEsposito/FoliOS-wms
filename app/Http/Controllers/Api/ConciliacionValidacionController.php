<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConciliacionValidacionOrigen;
use App\Models\LecturaStockOrigen;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ComparadorConciliacionValidacion;
use App\Services\Validacion\ServicioConciliacionValidacion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConciliacionValidacionController extends Controller
{
    public function __construct(private readonly ServicioConciliacionValidacion $conciliacion) {}

    public function index(Request $request)
    {
        $q = $this->consulta($request);
        $resumen = (clone $q)->selectRaw('estado, count(*) as cantidad')->groupBy('estado')->pluck('cantidad', 'estado');
        $fuente = $this->fuente();

        return response()->json([
            'casos' => $q->with('validacion.folio')->orderByDesc('validado_at')->orderByDesc('id')
                ->paginate(25)->through(fn ($caso) => $this->serializar($caso, $fuente)),
            'resumen' => $resumen, 'fuente' => $fuente,
            'puede_gestionar' => $request->user()->can('gestionar-conciliaciones-validacion'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(ConciliacionValidacionOrigen $conciliacion, Request $request)
    {
        $request->validate(['pagina_historial' => ['nullable', 'integer', 'min:1']]);

        return response()->json([
            ...$this->serializar($conciliacion->load('validacion.folio'), $this->fuente()),
            'historial' => DB::table('eventos_conciliacion_validacion')->where('conciliacion_id', $conciliacion->id)
                ->orderByDesc('id')->paginate(25, ['*'], 'pagina_historial')
                ->through(fn ($e) => [...(array) $e, 'datos' => json_decode($e->datos, true),
                    'created_at' => CarbonImmutable::parse($e->created_at, 'UTC')->toAtomString()]),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function recomprobar()
    {
        return response()->json(['revisadas' => $this->conciliacion->recomprobar(),
            'message' => 'Conciliaciones comprobadas con la copia disponible de planta.']);
    }

    public function gestionar(ConciliacionValidacionOrigen $conciliacion, Request $request)
    {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'accion' => ['required', Rule::in(['revisar', 'gestionada'])],
            'comentario' => ['required', 'string', 'min:5', 'max:2000'],
            'referencia' => ['nullable', 'string', 'max:250'],
        ]);
        $resultado = $this->conciliacion->gestionar($conciliacion, $datos, $request->user());

        return response()->json($this->serializar($resultado->load('validacion.folio'), $this->fuente()));
    }

    public function excel(Request $request, GeneradorLibroXlsx $excel)
    {
        $fuente = $this->fuente();
        $filas = (function () use ($request, $fuente) {
            foreach ($this->consulta($request)->with('validacion.folio')->orderBy('id')->lazy(100) as $caso) {
                $actual = $this->serializar($caso, $fuente);
                foreach ($caso->comparacion as $campo => $c) {
                    yield ['folio' => $caso->numero_folio, 'estado' => $caso->estado, 'contexto' => $actual['contexto_actual'],
                        'campo' => $c['etiqueta'], 'comparacion' => $c['estado'],
                        'planta' => $this->texto($c['planta']), 'validado' => $this->texto($c['validado']),
                        'planta_inicial' => $this->texto($caso->planta_inicial[$campo] ?? null),
                        'validado_inicial' => $this->texto($caso->validacion_inicial['datos'][$campo] ?? null),
                        'equivalencia' => $this->texto($c['planta_homologada']), 'diferencia' => $c['diferencia'],
                        'validador' => $caso->validacion_inicial['usuario'], 'validado_at' => $caso->validado_at->toAtomString(),
                        'lectura_at' => $caso->lectura_at?->toAtomString(), 'captura' => $caso->captura,
                        'comprobacion_vigente' => $actual['comprobacion_vigente'] ? 'Sí' : 'No: por revisar',
                        'bodega' => $caso->bodega, 'ambito' => $caso->ambito, 'caso_id' => $caso->id];
                }
            }
        })();
        $titulos = ['folio' => 'Folio', 'estado' => 'Estado de cuadratura', 'contexto' => 'Situación operacional',
            'campo' => 'Campo', 'comparacion' => 'Resultado', 'planta' => 'Planta: última comparación', 'validado' => 'Validación vigente',
            'planta_inicial' => 'Planta: respaldo inicial', 'validado_inicial' => 'Validación: respaldo inicial',
            'equivalencia' => 'Equivalencia de planta', 'diferencia' => 'Diferencia de cajas (FoliOS - planta)',
            'validador' => 'Validador', 'validado_at' => 'Fecha de validación', 'lectura_at' => 'Fecha de lectura',
            'captura' => 'Origen del respaldo', 'comprobacion_vigente' => 'Comprobación vigente', 'bodega' => 'Bodega',
            'ambito' => 'Fuente', 'caso_id' => 'ID conciliación'];
        $columnas = [];
        foreach ($titulos as $clave => $titulo) {
            $columnas[] = ['clave' => $clave, 'titulo' => $titulo, 'ancho' => 28];
        }
        $ruta = $excel->generar('Cuadraturas de validación con planta', $columnas, $filas,
            ['generado_at' => now()->toIso8601String(), 'zona_horaria' => config('app.operational_timezone'),
                'adicionales' => [
                    ['Filtros', json_encode($request->query(), JSON_UNESCAPED_UNICODE)],
                    ['Nota', 'La captura inicial es una lectura disponible en FoliOS; no acredita que haya sido vista en la PDA.'],
                ]], 'Conciliaciones');

        return response()->download($ruta, 'conciliaciones-validacion-planta.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    private function consulta(Request $request)
    {
        $datos = $request->validate([
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'folio' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', Rule::in(ServicioConciliacionValidacion::ESTADOS)],
            'campo' => ['nullable', Rule::in(array_keys(ComparadorConciliacionValidacion::CAMPOS))],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('desde'), ['after_or_equal:desde'])],
            'otras_fuentes' => ['nullable', 'boolean'], 'incluir_anuladas' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $config = app(ServicioLecturaStockOrigen::class)->configuracion();
        $q = ConciliacionValidacionOrigen::where('temporada_id', $datos['temporada_id'] ?? app(ServicioTemporadaActiva::class)->buscar()?->id);
        if (! ($datos['otras_fuentes'] ?? false)) {
            $q->where('ambito', $this->conciliacion->ambito($config))->where('bodega', $config['bodega']);
        }
        if (! ($datos['incluir_anuladas'] ?? false)) {
            $q->whereHas('validacion', fn ($v) => $v->where('estado', 'aceptada')->where('resultado', 'aprobado'));
        }
        if (filled($datos['folio'] ?? null)) {
            $q->where('numero_folio', mb_strtoupper(trim($datos['folio'])));
        }
        if (filled($datos['estado'] ?? null)) {
            $q->where('estado', $datos['estado']);
        }
        if (filled($datos['campo'] ?? null)) {
            $q->where('comparacion->'.$datos['campo'].'->estado', 'diferente');
        }
        $zona = config('app.operational_timezone', 'America/Santiago');
        if (filled($datos['desde'] ?? null)) {
            $q->where('validado_at', '>=', CarbonImmutable::parse($datos['desde'], $zona)->utc());
        }
        if (filled($datos['hasta'] ?? null)) {
            $q->where('validado_at', '<', CarbonImmutable::parse($datos['hasta'], $zona)->addDay()->utc());
        }

        return $q;
    }

    private function fuente(): array
    {
        $config = app(ServicioLecturaStockOrigen::class)->configuracion();
        $ultima = LecturaStockOrigen::where('ambito', $config['ambito'])->where('bodega', $config['bodega'])->latest('iniciada_at')->latest('id')->first();

        return ['ambito' => $this->conciliacion->ambito($config), 'bodega' => $config['bodega'], 'lectura_id' => $ultima?->id,
            'activa' => (bool) ($config['habilitada'] && $config['conexion']),
            'temporada_id' => app(ServicioTemporadaActiva::class)->buscar()?->id,
            'conciliacion_pendiente' => $ultima?->causa === 'conciliacion_pendiente',
            'vigente' => (bool) ($config['habilitada'] && $this->conciliacion->lecturaCorresponde($ultima, $config) && $ultima?->estado === 'correcta'
                && $ultima->terminada_at?->gte(now()->subMinutes((int) config('integraciones.stock_pt.vigencia_minutos', 20))))];
    }

    private function serializar(ConciliacionValidacionOrigen $caso, array $fuente): array
    {
        $contexto = $this->conciliacion->contexto($caso->validacion, $caso->validacion_actual['datos']);

        return [...$caso->attributesToArray(), 'contexto_actual' => $contexto,
            'gestion_habilitada' => $fuente['activa'] && $caso->temporada_id === $fuente['temporada_id']
                && $caso->ambito === $fuente['ambito'] && $caso->bodega === $fuente['bodega']
                && $contexto === 'vigente' && in_array($caso->estado, ['pendiente', 'en_revision', 'reabierto', 'por_revisar'], true),
            'comprobacion_vigente' => $contexto === 'vigente' && $fuente['vigente'] && $caso->ambito === $fuente['ambito']
                && $caso->bodega === $fuente['bodega'] && $caso->lectura_id === $fuente['lectura_id']
                && ($caso->planta_actual['presente'] ?? false)
                && ! collect($caso->comparacion)->contains('estado', 'por_revisar')];
    }

    private function texto(mixed $dato): string
    {
        return is_array($dato) ? collect($dato)->map(fn ($cantidad, $csg) => $csg.': '.$cantidad)->implode(' · ') : (string) ($dato ?? '');
    }
}
