<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Integraciones\ServicioCatalogoStockOrigen;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use App\Services\Validacion\ServicioPendientesValidacionOrigen;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pendientes por validar según el stock del sistema de origen. Solo consulta los folios; lo
 * único que escribe es el catálogo de validación, cuando alguien con permiso de catálogo
 * vincula o agrega un valor que trae el origen.
 */
class PendientesValidacionOrigenController extends Controller
{
    public function index(
        Request $request,
        ServicioPendientesValidacionOrigen $pendientes,
        ServicioCatalogoStockOrigen $catalogo,
    ): JsonResponse {
        Gate::authorize('consultar-validaciones-pallet');

        return response()->json($this->datos($request, $pendientes, $catalogo))->header('Cache-Control', 'no-store, private');
    }

    /** Vincula un valor del origen a un elemento del catálogo de la temporada, o lo agrega. */
    public function catalogo(
        Request $request,
        ServicioPendientesValidacionOrigen $pendientes,
        ServicioCatalogoStockOrigen $catalogo,
    ): JsonResponse {
        Gate::authorize('administrar-catalogos-validacion');
        $datos = $request->validate([
            'clave' => ['required', 'string', 'max:500'],
            'accion' => ['required', Rule::in(['vincular', 'incorporar'])],
            'entidad_id' => ['nullable', 'required_if:accion,vincular', 'uuid'],
        ], [
            'entidad_id.required_if' => 'Elige el elemento del catálogo al que corresponde.',
        ]);
        if (! $catalogo->preparar()) {
            return response()->json(['message' => 'No hay una temporada activa con catálogo de validación.'], 409);
        }

        try {
            $resultado = $catalogo->aplicar($datos['clave'], $datos['accion'], $datos['entidad_id'] ?? null, $request->user());
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json([
            ...$this->datos($request, $pendientes, $catalogo),
            'message' => $resultado['mensaje'],
        ]);
    }

    /** Lectura inmediata, además de la programada cada cinco minutos. */
    public function leer(
        Request $request,
        ServicioLecturaStockOrigen $lecturas,
        ServicioPendientesValidacionOrigen $pendientes,
        ServicioCatalogoStockOrigen $catalogo,
    ): JsonResponse {
        Gate::authorize('consultar-validaciones-pallet');
        $config = $lecturas->configuracion();
        if (! $config['habilitada'] || ! $config['conexion']) {
            return response()->json([
                'message' => 'La lectura del stock de origen no está configurada: falta una conexión externa activa.',
            ], 409);
        }
        if (str_contains(strtolower((string) $request->header('Prefer')), 'respond-async')) {
            $operaciones = app(ServicioOperacionesIntegracion::class);

            return $operaciones->aceptada($operaciones->solicitarStock($request->user()));
        }
        $lectura = $lecturas->leer($request->user());
        if ($lectura === null) {
            return response()->json(['message' => 'Ya hay una lectura en curso. Espera unos segundos y actualiza.'], 409);
        }

        return response()->json([
            ...$this->datos($request, $pendientes, $catalogo),
            'message' => $lectura->estado === 'correcta'
                ? "Stock de origen leído: {$lectura->pallets} pallets."
                : "No se pudo leer el stock de origen. {$lectura->mensaje}",
        ], $lectura->estado === 'correcta' ? 200 : 422);
    }

    public function excel(
        Request $request,
        ServicioPendientesValidacionOrigen $pendientes,
        GeneradorLibroXlsx $excel,
    ): BinaryFileResponse {
        Gate::authorize('consultar-validaciones-pallet');
        $datos = $pendientes->resumen();
        $situaciones = [
            'observado' => 'Observado', 'rechazado' => 'Rechazado', 'conflicto' => 'En conflicto',
            'anulado' => 'Validación anulada', 'sin_folio' => 'Sin folio',
        ];
        $filas = [
            ...array_map(fn (array $pallet): array => [...$this->filaPallet($pallet), 'situacion' => 'Pendiente de validar'], $datos['pendientes']),
            ...array_map(fn (array $pallet): array => [
                ...$this->filaPallet($pallet),
                'situacion' => $situaciones[$pallet['validacion']['situacion']] ?? 'Por resolver',
                'detalle' => trim(($pallet['validacion']['motivo'] ?? '').' '.($pallet['validacion']['observacion'] ?? '')),
            ], $datos['por_resolver']),
            ...array_map(fn (array $pallet): array => [
                ...$this->filaPallet($pallet),
                'situacion' => 'Validado con diferencias',
                'estado_folio' => $pallet['folio']['estado_etiqueta'],
                'detalle' => collect([...$pallet['diferencias'], ...$pallet['sugerencias']])
                    ->map(fn (array $d): string => "{$d['etiqueta']}: origen {$d['origen']}".($d['catalogo'] !== null && $d['catalogo'] !== $d['origen'] ? " ({$d['catalogo']})" : '')." / FoliOS {$d['folios']}")
                    ->implode('; '),
            ], $datos['diferencias']),
            ...array_map(fn (array $folio): array => [
                'numero' => $folio['numero'],
                'tipo' => $folio['tipo_bulto'] === 'saldo' ? 'Saldo' : 'Pallet',
                'cajas' => $folio['cajas'],
                'cliente' => $folio['cliente'],
                'especie' => $folio['especie'],
                'variedad' => $folio['variedad'],
                'calibre' => $folio['calibre'],
                'envase' => $folio['envase'],
                'csg' => implode(', ', $folio['csgs']),
                ...$this->columnasDistribucion($folio['distribucion']),
                'situacion' => 'Solo en FoliOS',
                'estado_folio' => $folio['estado_etiqueta'],
            ], $datos['solo_en_folios']),
        ];
        $ruta = $excel->generar('Pendientes por validar', [
            ['clave' => 'situacion', 'titulo' => 'Situación'],
            ['clave' => 'numero', 'titulo' => 'N° pallet'],
            ['clave' => 'tipo', 'titulo' => 'Tipo'],
            ['clave' => 'cajas', 'titulo' => 'Cajas', 'tipo' => 'numero'],
            ['clave' => 'cliente', 'titulo' => 'Cliente'],
            ['clave' => 'especie', 'titulo' => 'Especie'],
            ['clave' => 'variedad', 'titulo' => 'Variedad'],
            ['clave' => 'calibre', 'titulo' => 'Calibre'],
            ['clave' => 'categoria', 'titulo' => 'Categoría en origen'],
            ['clave' => 'categoria_sugerida', 'titulo' => 'Categoría sugerida'],
            ['clave' => 'envase_codigo', 'titulo' => 'Cód. artículo'],
            ['clave' => 'envase', 'titulo' => 'Embalaje'],
            ['clave' => 'marca', 'titulo' => 'Marca'],
            ['clave' => 'csg', 'titulo' => 'CSG'],
            ['clave' => 'proceso', 'titulo' => 'Proceso'],
            ['clave' => 'lote', 'titulo' => 'Lote'],
            ['clave' => 'cajas_por_csg', 'titulo' => 'Cajas por CSG'],
            ['clave' => 'composicion', 'titulo' => 'Composición (CSG · lote · proceso: cajas)'],
            ['clave' => 'fecha_embalaje', 'titulo' => 'Fecha de embalaje', 'tipo' => 'fecha'],
            ['clave' => 'recibido', 'titulo' => 'En bodega desde', 'tipo' => 'fecha_hora'],
            ['clave' => 'estado_folio', 'titulo' => 'Estado en FoliOS'],
            ['clave' => 'sin_catalogo', 'titulo' => 'Falta en catálogo'],
            ['clave' => 'detalle', 'titulo' => 'Detalle'],
        ], $filas, [
            'fecha_corte' => now(config('app.operational_timezone'))->format('d-m-Y H:i'),
            'usuario' => $request->user()->name,
            'cliente' => null,
            'periodo' => 'Stock de origen leído el '.($datos['lectura']['ultima_correcta_at']
                ? CarbonImmutable::parse($datos['lectura']['ultima_correcta_at'])->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i')
                : '—').' · bodega '.$datos['fuente']['bodega'],
        ], 'Pendientes');

        return response()->download($ruta, 'pendientes-por-validar-'.now(config('app.operational_timezone'))->format('Ymd-Hi').'.xlsx')
            ->deleteFileAfterSend();
    }

    /** @param array<string, mixed> $pallet */
    private function filaPallet(array $pallet): array
    {
        $zona = config('app.operational_timezone');

        return [
            'numero' => $pallet['numero'],
            'tipo' => $pallet['tipo_bulto'] === 'saldo' ? 'Saldo' : 'Pallet',
            'cajas' => $pallet['cajas'],
            'cliente' => $pallet['cliente'] ?? $pallet['empresa'],
            'especie' => $pallet['especie'],
            'variedad' => $pallet['variedad'],
            'calibre' => $pallet['calibre'],
            'categoria' => $pallet['categoria'],
            'categoria_sugerida' => $pallet['categoria_sugerida'],
            'envase_codigo' => $pallet['envase_codigo'],
            'envase' => $pallet['envase'],
            'marca' => $pallet['marca'],
            'sin_catalogo' => implode(', ', $pallet['sin_catalogo']),
            'csg' => implode(', ', $pallet['csgs']),
            'proceso' => implode(', ', $pallet['procesos']),
            'lote' => implode(', ', $pallet['lotes']),
            ...$this->columnasDistribucion($pallet['distribucion']),
            'fecha_embalaje' => $pallet['fecha_embalaje'],
            'recibido' => $pallet['recibido_origen_at']
                ? CarbonImmutable::parse($pallet['recibido_origen_at'])->setTimezone($zona)->format('Y-m-d H:i:s')
                : null,
        ];
    }

    /**
     * La distribución en texto para el Excel: una celda con las cajas por CSG y otra con cada
     * línea de CSG, lote y proceso.
     *
     * @param  list<array<string, mixed>>  $distribucion
     * @return array{cajas_por_csg: string, composicion: string}
     */
    private function columnasDistribucion(array $distribucion): array
    {
        $grupos = collect($distribucion);

        return [
            'cajas_por_csg' => $grupos->map(fn (array $grupo): string => ($grupo['csg'] ?? 'Sin CSG').': '.$grupo['cajas'])->implode(' · '),
            'composicion' => $grupos->flatMap(fn (array $grupo): array => array_map(
                fn (array $linea): string => implode(' · ', array_filter([
                    $grupo['csg'] ?? 'Sin CSG',
                    $linea['lote'] !== null ? "lote {$linea['lote']}" : 'sin lote',
                    $linea['proceso'],
                ])).': '.$linea['cajas'],
                $grupo['lineas'],
            ))->implode('; '),
        ];
    }

    /** @return array<string, mixed> */
    private function datos(Request $request, ServicioPendientesValidacionOrigen $pendientes, ServicioCatalogoStockOrigen $catalogo): array
    {
        $puede = Gate::forUser($request->user())->allows('administrar-catalogos-validacion');

        return [
            ...$pendientes->resumen(),
            'puede_administrar_catalogo' => $puede,
            // Solo quien puede vincular necesita la lista del catálogo.
            'opciones_catalogo' => $puede ? $catalogo->opciones() : null,
        ];
    }
}
