<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Http\Controllers\Controller;
use App\Http\Resources\ItemMaterialResource;
use App\Models\ClienteMaterial;
use App\Models\TemporadaMaterial;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Materiales\ServicioCatalogoItemsMateriales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogoItemsMaterialesController extends Controller
{
    public function index(Request $r, ServicioCatalogoItemsMateriales $servicio)
    {
        $filtros = $this->filtros($r);
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $pagina = $servicio->consulta($filtros)->orderBy('codigo')->orderBy('id')->paginate(50);

        return response()->json(['data' => ItemMaterialResource::collection($pagina->items()), 'pagina' => $pagina->currentPage(), 'paginas' => $pagina->lastPage(), 'total' => $pagina->total(),
            'catalogos' => ['temporadas' => TemporadaMaterial::orderByDesc('activa')->orderByDesc('codigo')->get(['id', 'codigo', 'nombre', 'activa']),
                'clientes' => ClienteMaterial::orderBy('nombre')->get(['id', 'nombre', 'codigo', 'temporada_material_id']),
                'categorias' => $servicio->consulta(array_intersect_key($filtros, array_flip(['temporada_id', 'cliente_id'])))->select('categoria')->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria')]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function exportar(Request $r, string $formato, ServicioCatalogoItemsMateriales $servicio, GeneradorLibroXlsx $excel)
    {
        abort_unless(in_array($formato, ['xlsx', 'csv'], true), 404);
        $filtros = $this->filtros($r);
        $cabeceras = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];
        if ($formato === 'csv') {
            return response()->streamDownload(function () use ($filtros, $servicio) {
                DB::transaction(function () use ($filtros, $servicio) {
                    $salida = fopen('php://output', 'wb');
                    try {
                        fwrite($salida, "\xEF\xBB\xBF");
                        $columnas = [...ServicioCatalogoItemsMateriales::COLUMNAS_IMPORTACION, ...ServicioCatalogoItemsMateriales::COLUMNAS_INFORMATIVAS];
                        fputcsv($salida, $columnas, ';', '"', '');
                        foreach ($servicio->filas($filtros) as $fila) {
                            fputcsv($salida, array_map(fn ($c) => $this->valorCsv($fila[$c] ?? ''), $columnas), ';', '"', '');
                        }
                    } finally {
                        fclose($salida);
                    }
                });
            }, 'catalogo-items-materiales.csv', [...$cabeceras, 'Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $ruta = DB::transaction(fn () => $excel->generar('Catálogo de ítems de materiales', $servicio->columnas(), $servicio->filas($filtros), ['usuario' => $r->user()->name, 'fecha_corte' => now()->toAtomString()], 'Items'));

        return response()->download($ruta, 'catalogo-items-materiales.xlsx', $cabeceras)->deleteFileAfterSend();
    }

    private function valorCsv(mixed $valor): mixed
    {
        // Excel debe tratar los textos como datos. El lector de importación retira
        // el tabulador con trim(), preservando el ciclo de descarga/reimportación.
        return is_string($valor) && preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $valor) === 1 ? "\t".$valor : $valor;
    }

    private function filtros(Request $r): array
    {
        return $r->validate(['temporada_id' => ['nullable', 'uuid', 'exists:temporadas_materiales,id'], 'cliente_id' => ['nullable', 'uuid', 'exists:clientes_materiales,id'],
            'categoria' => ['nullable', 'string', 'max:100'], 'tipo_item' => ['nullable', Rule::in([...array_column(CategoriaOperacionalMaterial::cases(), 'value'), 'sin_tipo'])],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos', 'todos'])]]);
    }
}
