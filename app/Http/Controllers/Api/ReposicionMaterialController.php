<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemMaterial;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Materiales\ServicioReposicionMaterial;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReposicionMaterialController extends Controller
{
    public function index(Request $r, ServicioReposicionMaterial $servicio)
    {
        $filtros = $this->filtros($r);
        $todas = $servicio->filas(['dias' => $filtros['dias'] ?? null], false);

        return response()->json(['data' => $servicio->filas($filtros), 'dias' => $servicio->dias($filtros['dias'] ?? null),
            'catalogos' => ['clientes' => $todas->map(fn ($f) => ['id' => $f['cliente_id'], 'nombre' => $f['cliente']])->unique('id')->values(),
                'categorias' => $todas->pluck('categoria')->filter()->unique()->sort()->values()],
            'indicador' => ['quiebre' => $todas->where('estado', 'quiebre')->count(), 'bajo_minimo' => $todas->where('estado', 'bajo_minimo')->count()]])->header('Cache-Control', 'no-store');
    }

    public function resumen(ServicioReposicionMaterial $servicio)
    {
        return response()->json(['data' => $servicio->resumen()])->header('Cache-Control', 'no-store');
    }

    public function show(Request $r, ItemMaterial $item, ServicioReposicionMaterial $servicio)
    {
        $filtros = $this->filtros($r);
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $servicio->detalle($item, $servicio->dias($filtros['dias'] ?? null), (int) $r->input('page', 1))])->header('Cache-Control', 'no-store');
    }

    public function excel(Request $r, ServicioReposicionMaterial $servicio, GeneradorLibroXlsx $excel)
    {
        $filtros = $this->filtros($r);
        $titulos = ['cliente' => 'Cliente', 'codigo' => 'Código', 'item' => 'Ítem', 'categoria' => 'Categoría', 'unidad' => 'Unidad',
            'disponible' => 'Disponible Bodega Central', 'reservado' => 'Reservado', 'bloqueado_vencido' => 'Bloqueado o vencido', 'total_empresa' => 'Total empresa',
            'consumo_periodo' => 'Consumo del período', 'consumo_diario' => 'Consumo diario', 'dias_cobertura' => 'Días de cobertura', 'cobertura_etiqueta' => 'Sin consumo',
            'stock_minimo' => 'Mínimo', 'punto_reorden' => 'Reorden', 'stock_maximo' => 'Máximo', 'cantidad_sugerida' => 'Cantidad sugerida', 'estado' => 'Estado'];
        $textos = ['cliente', 'codigo', 'item', 'categoria', 'unidad', 'cobertura_etiqueta', 'estado'];
        $columnas = collect($titulos)->map(fn ($t, $c) => ['clave' => $c, 'titulo' => $t, 'tipo' => in_array($c, $textos, true) ? 'texto' : 'numero'])->values()->all();
        $ruta = $excel->generar('Reposición de materiales', $columnas, $servicio->filas($filtros)->all(),
            ['consumo' => $servicio->dias($filtros['dias'] ?? null).' días', 'perspectiva' => 'Disponible Bodega Central', 'usuario' => $r->user()->name], 'Reposición');

        return response()->download($ruta, 'reposicion-materiales.xlsx')->deleteFileAfterSend();
    }

    private function filtros(Request $r): array
    {
        return array_filter($r->validate(['cliente_id' => ['nullable', 'uuid'], 'categoria' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(ServicioReposicionMaterial::ESTADOS)], 'dias' => ['nullable', 'integer', Rule::in([30, 60, 90])]]), fn ($v) => $v !== null && $v !== '');
    }
}
