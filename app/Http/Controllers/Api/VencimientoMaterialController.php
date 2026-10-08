<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FolioMaterial;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Materiales\ServicioConsultaVencimientosMaterial;
use App\Services\Materiales\ServicioVencimientoMaterial;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VencimientoMaterialController extends Controller
{
    public function index(Request $request, ServicioConsultaVencimientosMaterial $servicio)
    {
        $filtros = $this->validarFiltros($request);
        $paginas = $servicio->cargar($servicio->consulta([...$filtros, 'estado' => $filtros['estado'] ?? 'por_vencer']))
            ->paginate((int) ($filtros['per_page'] ?? 25));

        return response()->json([
            'data' => $paginas->getCollection()->map($servicio->serializar(...)),
            'meta' => ['current_page' => $paginas->currentPage(), 'last_page' => $paginas->lastPage(), 'total' => $paginas->total()],
            'resumen' => $servicio->resumen($filtros), 'filtros' => $servicio->filtros(),
        ]);
    }

    public function exportar(Request $request, ServicioConsultaVencimientosMaterial $servicio, GeneradorLibroXlsx $generador)
    {
        $filtros = $this->validarFiltros($request);
        $filas = $servicio->cargar($servicio->consulta([...$filtros, 'estado' => $filtros['estado'] ?? 'por_vencer']))->lazy(200)
            ->map(fn ($saldo) => [...$servicio->serializar($saldo),
                'fecha' => $saldo->folioMaterial->fecha_vencimiento->toDateString(),
                'dias' => $saldo->folioMaterial->informacionVencimiento()['dias_restantes'],
                'estado' => $saldo->folioMaterial->informacionVencimiento()['etiqueta'],
            ]);
        $columnas = collect([
            'numero_folio' => 'Folio', 'cliente' => 'Cliente', 'codigo_item' => 'Código ítem', 'item' => 'Ítem',
            'categoria' => 'Categoría', 'fecha' => 'Vencimiento', 'dias' => 'Días restantes', 'estado' => 'Estado',
            'cantidad' => 'Cantidad', 'unidad_medida' => 'Unidad', 'almacen' => 'Almacén',
            'camara' => 'Cámara', 'posicion' => 'Posición', 'motivo_bloqueo' => 'Motivo de bloqueo',
        ])->map(fn ($titulo, $clave) => ['clave' => $clave, 'titulo' => $titulo, 'ancho' => 22,
            'tipo' => in_array($clave, ['cantidad', 'dias'], true) ? 'numero' : 'texto'])->values()->all();
        $ruta = $generador->generar('Vencimientos de materiales', $columnas, $filas,
            ['fecha_corte' => now()->toAtomString(), 'usuario' => $request->user()->name, 'temporada' => 'Temporada activa']);

        return response()->download($ruta, 'vencimientos_materiales.xlsx')->deleteFileAfterSend();
    }

    public function corregir(Request $request, FolioMaterial $folioMaterial, ServicioVencimientoMaterial $servicio)
    {
        $request->merge(['motivo' => trim((string) $request->input('motivo'))]);
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'], 'fecha_vencimiento' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $evento = $servicio->corregir($folioMaterial, $datos['operacion_id'], $datos['fecha_vencimiento'], $datos['motivo'], $request->user());

        return response()->json(['data' => ['evento_id' => $evento->id, 'vencimiento' => $folioMaterial->refresh()->informacionVencimiento()]]);
    }

    private function validarFiltros(Request $request): array
    {
        return $request->validate([
            'estado' => ['nullable', Rule::in(['por_vencer', 'vencido'])],
            'cliente_id' => ['nullable', 'uuid'], 'categoria' => ['nullable', 'string', 'max:100'],
            'almacen_id' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
