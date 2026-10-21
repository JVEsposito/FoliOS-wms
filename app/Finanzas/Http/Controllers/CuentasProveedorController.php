<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Models\AplicacionProveedor;
use App\Finanzas\Models\EvaluacionProveedor;
use App\Finanzas\Models\PagoProveedor;
use App\Finanzas\Models\PeriodoCompra;
use App\Finanzas\Proveedores\ConsultaCuentaProveedor;
use App\Finanzas\Proveedores\CuentaCorrienteProveedor;
use App\Finanzas\Proveedores\EvaluadorProveedor;
use App\Finanzas\Proveedores\ReportesCompras;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CuentasProveedorController extends Controller
{
    public function cuenta(Request $r, ConsultaCuentaProveedor $consulta): JsonResponse
    {
        $d = $r->validate(['fecha_corte' => ['required', 'date_format:Y-m-d'], 'proveedor_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $consulta->consultar($d['fecha_corte'], $d['proveedor_id'] ?? null)]);
    }

    public function pagos(Request $r): JsonResponse
    {
        $d = $r->validate(['proveedor_id' => ['required', 'uuid']]);

        return response()->json(['data' => PagoProveedor::query()->where('proveedor_id', $d['proveedor_id'])->orderByDesc('fecha')->orderByDesc('id')->paginate(30)]);
    }

    public function aplicaciones(Request $r): JsonResponse
    {
        $d = $r->validate(['proveedor_id' => ['required', 'uuid']]);

        return response()->json(['data' => AplicacionProveedor::query()->where('proveedor_id', $d['proveedor_id'])->orderByDesc('fecha')->orderByDesc('id')->paginate(30)]);
    }

    public function pagar(Request $r, CuentaCorrienteProveedor $cuenta): JsonResponse
    {
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'proveedor_id' => ['required', 'uuid'], 'fecha' => ['required', 'date_format:Y-m-d'], 'monto' => ['required', 'regex:/^\d{1,15}$/', 'gt:0'], 'cuenta' => ['required', 'string', 'max:30'], 'referencia' => ['required', 'string', 'min:3', 'max:180'], 'respaldo' => ['required', 'string', 'min:5', 'max:1000'], 'confirmado' => ['required', 'accepted'], 'aplicaciones' => ['present', 'array', 'max:100'], 'aplicaciones.*' => ['array:documento_id,monto'], 'aplicaciones.*.documento_id' => ['required', 'uuid', 'distinct'], 'aplicaciones.*.monto' => ['required', 'regex:/^\d{1,15}$/', 'gt:0']]);

        return response()->json(['data' => $cuenta->pagar($d, $r->user())], 201);
    }

    public function aplicar(Request $r, CuentaCorrienteProveedor $cuenta): JsonResponse
    {
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'proveedor_id' => ['required', 'uuid'], 'credito_tipo' => ['required', Rule::in(['pago', 'nota_credito'])], 'credito_id' => ['required', 'uuid'], 'documento_id' => ['required', 'uuid'], 'fecha' => ['required', 'date_format:Y-m-d'], 'monto' => ['required', 'regex:/^\d{1,15}$/', 'gt:0'], 'respaldo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $cuenta->aplicar($d, $r->user())], 201);
    }

    private function reverso(Request $r): array
    {
        return $r->validate(['fecha' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);
    }

    public function desaplicar(Request $r, AplicacionProveedor $aplicacion, CuentaCorrienteProveedor $cuenta): JsonResponse
    {
        $d = $this->reverso($r);

        return response()->json(['data' => $cuenta->desaplicar($aplicacion, $d['fecha'], $d['motivo'], $r->user())]);
    }

    public function anularPago(Request $r, PagoProveedor $pago, CuentaCorrienteProveedor $cuenta): JsonResponse
    {
        $d = $this->reverso($r);

        return response()->json(['data' => $cuenta->anularPago($pago, $d['fecha'], $d['motivo'], $r->user())]);
    }

    public function balance(Request $r, ReportesCompras $reportes): JsonResponse
    {
        $d = $this->fechas($r);

        return response()->json(['data' => $reportes->balance($d['desde'], $d['hasta'], $d['proveedor_id'] ?? null), 'periodos' => PeriodoCompra::query()->orderByDesc('mes')->limit(36)->get()]);
    }

    private function fechas(Request $r): array
    {
        return $r->validate(['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'], 'proveedor_id' => ['nullable', 'uuid']]);
    }

    public function mayor(Request $r, ReportesCompras $reportes): JsonResponse
    {
        $d = $this->fechas($r);
        $cuenta = $r->validate(['cuenta' => ['required', 'string', 'max:30']]);

        return response()->json(['data' => $reportes->mayor($cuenta['cuenta'], $d['desde'], $d['hasta'])]);
    }

    public function exportar(Request $r, ReportesCompras $reportes): StreamedResponse
    {
        $d = $this->fechas($r);
        $reporte = $reportes->balance($d['desde'], $d['hasta'], $d['proveedor_id'] ?? null);

        return response()->streamDownload(function () use ($reporte): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $seguro = fn ($s) => preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $s ?? '') ? "'".$s : ($s ?? '');
            fputcsv($out, ['CUENTA', 'NOMBRE', 'TIPO', 'SALDO INICIAL CLP', 'DEBE CLP', 'HABER CLP', 'SALDO FINAL CLP'], ';', '"', '');
            foreach ($reporte['cuentas'] as $fila) {
                fputcsv($out, [$seguro($fila['cuenta']), $seguro($fila['nombre']), $fila['tipo'], $fila['saldo_inicial'], $fila['debe'], $fila['haber'], $fila['saldo_final']], ';', '"', '');
            }
            fputcsv($out, [], ';', '"', '');
            fputcsv($out, ['PROVEEDOR', 'RUT', 'CUENTA DE GASTO', 'CECO', 'MONTO NETO CLP'], ';', '"', '');
            foreach ($reporte['gastos'] as $fila) {
                fputcsv($out, [$seguro($fila['proveedor_nombre']), $seguro($fila['proveedor_rut']), $seguro($fila['cuenta']), $seguro($fila['ceco']), $fila['monto']], ';', '"', '');
            }
            fclose($out);
        }, 'proveedores-'.$d['desde'].'-'.$d['hasta'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function periodo(Request $r, ReportesCompras $reportes): JsonResponse
    {
        $d = $r->validate(['mes' => ['required', 'date_format:Y-m'], 'cerrar' => ['required', 'boolean'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $reportes->periodo($d['mes'], $d['cerrar'], $d['motivo'], $r->user())]);
    }

    public function evaluaciones(Request $r): JsonResponse
    {
        $d = $r->validate(['proveedor_id' => ['required', 'uuid']]);

        return response()->json(['data' => EvaluacionProveedor::query()->where('proveedor_id', $d['proveedor_id'])->orderByDesc('hasta')->orderByDesc('id')->paginate(30)]);
    }

    public function evaluar(Request $r, EvaluadorProveedor $evaluador): JsonResponse
    {
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'proveedor_id' => ['required', 'uuid'], 'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'], 'conclusion' => ['required', Rule::in(['aprobado', 'condicional', 'rechazado'])], 'respaldo' => ['required', 'string', 'min:5', 'max:1000'], 'criterios' => ['required', 'array', 'min:1', 'max:20'], 'criterios.*' => ['array:nombre,peso,nota'], 'criterios.*.nombre' => ['required', 'string', 'max:100', 'distinct:ignore_case'], 'criterios.*.peso' => ['required', 'integer', 'min:1', 'max:100'], 'criterios.*.nota' => ['required', 'integer', 'min:1', 'max:5']]);

        return response()->json(['data' => $evaluador->crear($d, $r->user())], 201);
    }

    public function anularEvaluacion(Request $r, EvaluacionProveedor $evaluacion, EvaluadorProveedor $evaluador): JsonResponse
    {
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $evaluador->anular($evaluacion, $d['motivo'], $r->user())]);
    }
}
