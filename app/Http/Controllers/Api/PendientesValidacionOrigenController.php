<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use App\Services\Validacion\ServicioPendientesValidacionOrigen;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Pendientes por validar según el stock del sistema de origen. Solo consulta. */
class PendientesValidacionOrigenController extends Controller
{
    public function index(ServicioPendientesValidacionOrigen $pendientes): JsonResponse
    {
        Gate::authorize('consultar-validaciones-pallet');

        return response()->json($pendientes->resumen())->header('Cache-Control', 'no-store, private');
    }

    /** Lectura inmediata, además de la programada cada cinco minutos. */
    public function leer(
        Request $request,
        ServicioLecturaStockOrigen $lecturas,
        ServicioPendientesValidacionOrigen $pendientes,
    ): JsonResponse {
        Gate::authorize('consultar-validaciones-pallet');
        $config = $lecturas->configuracion();
        if (! $config['habilitada'] || ! $config['conexion']) {
            return response()->json([
                'message' => 'La lectura del stock de origen no está configurada: falta una conexión externa activa.',
            ], 409);
        }
        $lectura = $lecturas->leer($request->user());
        if ($lectura === null) {
            return response()->json(['message' => 'Ya hay una lectura en curso. Espera unos segundos y actualiza.'], 409);
        }

        return response()->json([
            ...$pendientes->resumen(),
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
                'detalle' => collect($pallet['diferencias'])->map(fn (array $d): string => "{$d['campo']}: origen {$d['origen']} / FoliOS {$d['folios']}")->implode('; '),
            ], $datos['diferencias']),
            ...array_map(fn (array $folio): array => [
                'numero' => $folio['numero'],
                'tipo' => $folio['tipo_bulto'] === 'saldo' ? 'Saldo' : 'Pallet',
                'cajas' => $folio['cajas'],
                'variedad' => $folio['variedad'],
                'calibre' => $folio['calibre'],
                'envase' => $folio['envase'],
                'csg' => implode(', ', $folio['csgs']),
                'situacion' => 'Solo en FoliOS',
                'estado_folio' => $folio['estado_etiqueta'],
            ], $datos['solo_en_folios']),
        ];
        $ruta = $excel->generar('Pendientes por validar', [
            ['clave' => 'situacion', 'titulo' => 'Situación'],
            ['clave' => 'numero', 'titulo' => 'N° pallet'],
            ['clave' => 'tipo', 'titulo' => 'Tipo'],
            ['clave' => 'cajas', 'titulo' => 'Cajas', 'tipo' => 'numero'],
            ['clave' => 'variedad', 'titulo' => 'Variedad'],
            ['clave' => 'calibre', 'titulo' => 'Calibre'],
            ['clave' => 'categoria', 'titulo' => 'Categoría'],
            ['clave' => 'envase', 'titulo' => 'Envase'],
            ['clave' => 'marca', 'titulo' => 'Marca'],
            ['clave' => 'csg', 'titulo' => 'CSG'],
            ['clave' => 'proceso', 'titulo' => 'Proceso'],
            ['clave' => 'lote', 'titulo' => 'Lote'],
            ['clave' => 'fecha_embalaje', 'titulo' => 'Fecha de embalaje', 'tipo' => 'fecha'],
            ['clave' => 'recibido', 'titulo' => 'En bodega desde', 'tipo' => 'fecha_hora'],
            ['clave' => 'estado_folio', 'titulo' => 'Estado en FoliOS'],
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
            'variedad' => $pallet['variedad'],
            'calibre' => $pallet['calibre'],
            'categoria' => $pallet['categoria'],
            'envase' => $pallet['envase'],
            'marca' => $pallet['marca'],
            'csg' => implode(', ', $pallet['csgs']),
            'proceso' => implode(', ', $pallet['procesos']),
            'lote' => implode(', ', $pallet['lotes']),
            'fecha_embalaje' => $pallet['fecha_embalaje'],
            'recibido' => $pallet['recibido_origen_at']
                ? CarbonImmutable::parse($pallet['recibido_origen_at'])->setTimezone($zona)->format('Y-m-d H:i:s')
                : null,
        ];
    }
}
