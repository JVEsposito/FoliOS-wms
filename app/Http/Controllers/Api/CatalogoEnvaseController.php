<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoEnvaseRomana;
use App\Http\Controllers\Controller;
use App\Models\EspecieValidacion;
use App\Services\Romana\ServicioRepartoEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogoEnvaseController extends Controller
{
    public function catalogo()
    {
        return response()->json(['data' => TipoEnvaseRomana::catalogo()]);
    }

    public function referencias(ServicioRepartoEnvases $servicio)
    {
        Gate::authorize('administrar-accesos');

        return response()->json(['data' => EspecieValidacion::where('temporada_id', app(ServicioTemporadaActiva::class)->buscar()?->id)
            ->where('activo', true)->orderBy('nombre')->get()->map(fn ($e): array => ['id' => $e->id, 'nombre' => $e->nombre, ...$servicio->configuracion($e->id)])]);
    }

    public function guardar(Request $request, EspecieValidacion $especieValidacion)
    {
        Gate::authorize('administrar-accesos');
        $fruta = array_column(array_filter(TipoEnvaseRomana::catalogo(), fn ($t): bool => $t['contiene_fruta']), 'codigo');
        $datos = $request->validate(['sugerido' => ['nullable', Rule::in($fruta)],
            'referencias' => ['required', 'array'], 'referencias.*.tipo_envase' => ['required', 'distinct', Rule::in($fruta)],
            'referencias.*.peso_referencia' => ['required', 'numeric', 'min:0.001', 'max:100000', 'decimal:0,3']]);
        DB::transaction(function () use ($datos, $especieValidacion, $request): void {
            EspecieValidacion::whereKey($especieValidacion->id)->lockForUpdate()->firstOrFail();
            DB::table('pesos_referencia_envases')->where('especie_validacion_id', $especieValidacion->id)->delete();
            foreach ($datos['referencias'] as $fila) {
                DB::table('pesos_referencia_envases')->insert([
                    ...$fila, 'especie_validacion_id' => $especieValidacion->id, 'actualizado_por_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('preferencias_envase_especie')->updateOrInsert(['especie_validacion_id' => $especieValidacion->id], [
                'tipo_envase' => $datos['sugerido'] ?? null, 'actualizado_por_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['data' => app(ServicioRepartoEnvases::class)->configuracion($especieValidacion->id)]);
    }
}
