<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\ResponsableContraparteSag;
use App\Services\InspeccionSag\ServicioExistenciasSag;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContraparteSagController extends Controller
{
    public function configuracion()
    {
        $registro = DB::table('configuracion_contraparte_sag')->where('id', 'planta')->first();

        return response()->json([
            'planta' => $registro ? json_decode($registro->datos, true) : null,
            'version' => $registro?->version ?? 0,
            'responsables' => ResponsableContraparteSag::query()->orderBy('nombre')->get(),
            'puede_gestionar' => auth()->user()->can('gestionar-contraparte-sag'),
        ]);
    }

    public function guardarConfiguracion(Request $request)
    {
        $datos = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'nombre_establecimiento' => ['required', 'string', 'max:150'],
            'csp' => ['required', 'string', 'max:50'],
            'region' => ['required', 'string', 'max:100'],
            'provincia' => ['required', 'string', 'max:100'],
            'comuna' => ['required', 'string', 'max:100'],
            'oficina_sag' => ['required', 'string', 'max:150'],
        ]);
        DB::transaction(function () use ($datos, $request): void {
            $antes = DB::table('configuracion_contraparte_sag')->where('id', 'planta')->lockForUpdate()->first();
            if ((int) ($antes?->version ?? 0) !== $datos['version']) {
                throw new ConflictoOperacion('La configuración cambió. Recarga antes de guardar.');
            }
            $planta = array_diff_key($datos, ['version' => true]);
            DB::table('configuracion_contraparte_sag')->updateOrInsert(['id' => 'planta'], [
                'datos' => json_encode($planta, JSON_THROW_ON_ERROR), 'version' => $datos['version'] + 1,
                'created_at' => $antes?->created_at ?? now(), 'updated_at' => now(),
            ]);
            $this->auditar('configuracion', 'planta', $request, ['antes' => $antes, 'despues' => $planta]);
        });

        return $this->configuracion();
    }

    public function guardarResponsable(Request $request, ?ResponsableContraparteSag $responsable = null)
    {
        $datos = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'nombre' => ['required', 'string', 'max:150'], 'rut' => ['required', 'string', 'max:20'],
            'cargo' => ['required', Rule::in(['profesional', 'tecnica', 'despachador'])],
            'referencia_autorizacion' => ['required', 'string', 'max:250'],
            'activo' => ['required', 'boolean'], 'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $resultado = DB::transaction(function () use ($datos, $request, $responsable) {
            $registro = $responsable ? ResponsableContraparteSag::query()->lockForUpdate()->findOrFail($responsable->id) : new ResponsableContraparteSag;
            if ((int) ($registro->version ?? 0) !== $datos['version']) {
                throw new ConflictoOperacion('El responsable cambió. Recarga antes de guardar.');
            }
            $antes = $registro->toArray();
            $registro->fill([...$datos, 'version' => $datos['version'] + 1])->save();
            $this->auditar('responsable', $registro->id, $request, ['antes' => $antes, 'despues' => $registro->toArray()]);

            return $registro;
        });

        return response()->json($resultado, $responsable ? 200 : 201);
    }

    public function existencias(Request $request)
    {
        $datos = $request->validate([
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'folio' => ['nullable', 'string', 'max:100'], 'especie' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $temporadaId = $datos['temporada_id'] ?? app(ServicioTemporadaActiva::class)->buscar()?->id;
        $base = DB::table('saldos_existencia_sag')->where('temporada_id', $temporadaId)->where('unidades', '>', 0);
        $filtrar = function ($q) use ($datos): void {
            if (filled($datos['folio'] ?? null)) {
                $q->where('snapshot->folio', $datos['folio']);
            }
            if (filled($datos['especie'] ?? null)) {
                $q->where('snapshot->especie', $datos['especie']);
            }
        };
        $filtrar($base);
        $movimientos = DB::table('movimientos_existencia_sag')->where('temporada_id', $temporadaId);
        $filtrar($movimientos);
        if (! empty($datos['desde'])) {
            $movimientos->whereDate('created_at', '>=', $datos['desde']);
        }
        if (! empty($datos['hasta'])) {
            $movimientos->whereDate('created_at', '<=', $datos['hasta']);
        }
        $totalCajas = (clone $base)->sum('cajas');
        $totalUnidades = (clone $base)->sum('unidades');
        $pagina = $base->orderBy('folio_id')->paginate($datos['per_page'] ?? 50);
        $pagina->through(fn ($r) => [...(array) $r, 'snapshot' => json_decode($r->snapshot, true)]);

        return response()->json([
            'temporada_id' => $temporadaId, 'cajas' => $totalCajas,
            'unidades' => $totalUnidades, 'folios' => $pagina,
            'movimientos' => $movimientos->orderByDesc('created_at')->orderByDesc('id')->paginate($datos['per_page'] ?? 50, ['*'], 'pagina_movimientos')
                ->through(fn ($r) => [...(array) $r, 'snapshot' => json_decode($r->snapshot, true)]),
        ]);
    }

    public function corte(ServicioExistenciasSag $existencias)
    {
        return response()->json(['folios_revisados' => $existencias->corteInicial()]);
    }

    private function auditar(string $tipo, string $referencia, Request $request, array $datos): void
    {
        DB::table('eventos_contraparte_sag')->insert([
            'id' => (string) Str::uuid(), 'tipo' => $tipo, 'referencia' => $referencia,
            'user_id' => $request->user()->id, 'datos' => json_encode($datos, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
