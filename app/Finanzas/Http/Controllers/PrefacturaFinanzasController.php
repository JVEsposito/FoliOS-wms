<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Http\RespuestaPrefactura;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\LibroPrefactura;
use App\Http\Controllers\Controller;
use App\Services\Existencias\GeneradorLibroXlsx;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Prefactura en paralelo: el mes valorizado por cliente, para compararlo con la facturación. Un
 * mes cerrado se lee congelado; uno abierto se calcula al consultarlo.
 */
class PrefacturaFinanzasController extends Controller
{
    public function __construct(
        private readonly LibroPrefactura $libro,
        private readonly RespuestaPrefactura $respuesta,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->respuesta->mes($request, $this->periodo($request)));
    }

    /**
     * Un cliente con los hechos que forman cada línea. Con `tarifa`, solo el detalle de esa
     * línea: un mes de almacenaje pueden ser miles de estadías.
     */
    public function detalle(Request $request): JsonResponse
    {
        $datos = validator($request->query(), [
            'cliente' => ['required', 'string', 'uuid'],
            'tarifa' => ['nullable', 'integer', 'min:1'],
        ], [
            'cliente.required' => 'Indique el cliente.',
            'cliente.uuid' => 'El cliente indicado no es válido.',
            'tarifa.integer' => 'La línea indicada no es válida.',
            'tarifa.min' => 'La línea indicada no es válida.',
        ])->validate();
        $periodo = $this->periodo($request);
        $cliente = $this->libro->detalle($periodo, (string) $datos['cliente'], isset($datos['tarifa']) ? (int) $datos['tarifa'] : null);

        return response()->json([
            'periodo' => $periodo->aArreglo(),
            'data' => $cliente,
        ]);
    }

    /** Una fila por línea valorizada, por ajuste y por pendiente, para comparar en una planilla. */
    public function exportar(Request $request, GeneradorLibroXlsx $excel): BinaryFileResponse
    {
        $periodo = $this->periodo($request);
        $prefactura = $this->libro->mes($periodo);
        $cierre = $prefactura['cierre'];
        $corte = match (true) {
            $cierre !== null => 'mes cerrado el '.CarbonImmutable::parse($cierre['cerrado_at'])->setTimezone((string) config('app.operational_timezone'))->format('d-m-Y H:i')
                .' por '.($cierre['cerrado_por'] ?? 'usuario sin nombre').' (versión '.$cierre['version'].')',
            $prefactura['corte'] === null => 'mes futuro, sin valorizar',
            default => 'valorizado hasta el '.CarbonImmutable::parse($prefactura['corte'])->format('d-m-Y').($prefactura['a_la_fecha'] ? ' (a la fecha)' : ' (mes completo, sin cerrar)'),
        };
        $ruta = $excel->generar('Prefactura en paralelo '.$periodo->clave(), [
            ['clave' => 'cliente', 'titulo' => 'Cliente', 'ancho' => 34],
            ['clave' => 'servicio', 'titulo' => 'Servicio', 'ancho' => 26],
            ['clave' => 'unidad', 'titulo' => 'Unidad de cobro', 'ancho' => 22],
            ['clave' => 'origen', 'titulo' => 'Precio', 'ancho' => 26],
            ['clave' => 'moneda', 'titulo' => 'Moneda', 'ancho' => 9],
            ['clave' => 'precio', 'titulo' => 'Precio unitario', 'ancho' => 15, 'tipo' => 'numero'],
            ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'ancho' => 14, 'tipo' => 'numero'],
            ['clave' => 'dias', 'titulo' => 'Días', 'ancho' => 8, 'tipo' => 'numero'],
            ['clave' => 'hechos', 'titulo' => 'Hechos', 'ancho' => 9, 'tipo' => 'numero'],
            ['clave' => 'monto', 'titulo' => 'Monto', 'ancho' => 16, 'tipo' => 'numero'],
            ['clave' => 'observacion', 'titulo' => 'Observación', 'ancho' => 36],
        ], $this->filas($prefactura), [
            'fecha_corte' => now((string) config('app.operational_timezone'))->format('d-m-Y H:i'),
            'usuario' => $request->user()->name,
            'temporada' => 'No aplica: la prefactura es por mes calendario',
            'periodo' => $periodo->clave().' · '.$corte,
        ], 'Prefactura');

        return response()->download($ruta, 'prefactura-'.$periodo->clave().'.xlsx')->deleteFileAfterSend();
    }

    private function periodo(Request $request): Periodo
    {
        return $this->respuesta->periodo($request->query());
    }

    /**
     * @param  array<string, mixed>  $prefactura
     * @return iterable<array<string, mixed>>
     */
    private function filas(array $prefactura): iterable
    {
        foreach ($prefactura['clientes'] as $cliente) {
            foreach ($cliente['lineas'] as $linea) {
                yield [
                    'cliente' => $cliente['cliente_nombre'],
                    'servicio' => $linea['servicio_nombre'],
                    'unidad' => $linea['unidad_nombre'],
                    'origen' => $linea['origen'] === 'propio' ? 'Precio propio' : 'Tarifa general',
                    'moneda' => $linea['moneda'],
                    'precio' => $linea['precio'],
                    'cantidad' => $linea['cantidad'],
                    'dias' => $linea['dias'] ?: null,
                    'hechos' => $linea['hechos'],
                    'monto' => $linea['monto'],
                    'observacion' => null,
                ];
            }
            foreach ($cliente['ajustes'] as $ajuste) {
                yield [
                    'cliente' => $cliente['cliente_nombre'],
                    'servicio' => $ajuste['servicio_nombre'],
                    'unidad' => $ajuste['unidad_nombre'],
                    'origen' => 'Ajuste de '.Periodo::mes($ajuste['mes_origen'])->nombre(),
                    'moneda' => $ajuste['moneda'],
                    'precio' => $ajuste['precio'],
                    'cantidad' => $ajuste['cantidad'],
                    'dias' => $ajuste['dias'] ?: null,
                    'hechos' => null,
                    'monto' => $ajuste['monto'],
                    'observacion' => 'Diferencia aceptada después del cierre'.($ajuste['motivo'] ? ': '.$ajuste['motivo'] : '.'),
                ];
            }
            foreach ($cliente['sin_precio'] as $pendiente) {
                yield [
                    'cliente' => $cliente['cliente_nombre'],
                    'servicio' => $pendiente['nombre'],
                    'hechos' => $pendiente['hechos'],
                    'observacion' => 'Sin precio en el tarifario: no se valorizó.',
                ];
            }
        }
        foreach ($prefactura['sin_cliente'] as $pendiente) {
            yield [
                'cliente' => 'Sin cliente vinculado',
                'servicio' => $pendiente['nombre'],
                'hechos' => $pendiente['hechos'],
                'observacion' => 'Sin cliente vinculado en los maestros: no se valorizó.',
            ];
        }
    }
}
