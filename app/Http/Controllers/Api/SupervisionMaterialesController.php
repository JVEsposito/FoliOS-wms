<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Materiales\ConsultaSupervisionMateriales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupervisionMaterialesController extends Controller
{
    public function catalogos(ConsultaSupervisionMateriales $consulta)
    {
        return response()->json($consulta->catalogos())->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, ConsultaSupervisionMateriales $consulta)
    {
        $f = array_filter($request->validate([
            'vista' => ['required', Rule::in(['stock', 'inventario', 'almacenes', 'recepciones', 'diferencias'])],
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'cliente_id' => ['nullable', 'uuid'], 'almacen_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'integer', 'min:1'], 'tipo' => ['nullable', 'string', 'max:50'],
            'estado' => ['nullable', Rule::in(['borrador', 'en_conteo', 'en_revision', 'aprobada', 'anulada'])],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'desde' => ['required_unless:vista,stock', 'date_format:Y-m-d'],
            'hasta' => ['required_unless:vista,stock', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]), fn ($v) => $v !== null && $v !== '');
        if ($f['vista'] !== 'stock' && Carbon::parse($f['desde'])->diffInDays(Carbon::parse($f['hasta'])) > 365) {
            throw ValidationException::withMessages(['hasta' => 'Consulta un máximo de 366 días corridos por vez.']);
        }
        // Un filtro incompatible se rechaza para no mostrar resultados que parezcan respetarlo.
        foreach (['almacen_id' => ['stock', 'almacenes'], 'user_id' => ['inventario', 'almacenes', 'recepciones', 'diferencias'],
            'tipo' => ['inventario', 'almacenes', 'recepciones', 'diferencias'], 'estado' => ['diferencias'],
            'desde' => ['inventario', 'almacenes', 'recepciones', 'diferencias'], 'hasta' => ['inventario', 'almacenes', 'recepciones', 'diferencias']] as $campo => $vistas) {
            if (isset($f[$campo]) && ! in_array($f['vista'], $vistas, true)) {
                throw ValidationException::withMessages([$campo => 'Este filtro no corresponde a la vista seleccionada.']);
            }
        }

        return response()->json($consulta->consultar($f))->header('Cache-Control', 'no-store');
    }

    public function evidencia(string $fuente, string $id, ConsultaSupervisionMateriales $consulta)
    {
        abort_unless(in_array($fuente, ['evento', 'eliminacion'], true), 404);
        validator(['id' => $id], ['id' => 'required|uuid'])->validate();

        return response()->json(['data' => $consulta->evidencia($fuente, $id)])->header('Cache-Control', 'no-store');
    }
}
