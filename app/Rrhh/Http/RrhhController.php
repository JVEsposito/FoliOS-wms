<?php

namespace App\Rrhh\Http;

use App\Http\Controllers\Controller;
use App\Rrhh\Asistencia\ImportadorMarcajes;
use App\Rrhh\Asistencia\ServicioMarcajes;
use App\Rrhh\Auditoria;
use App\Rrhh\ConsultaDotacion;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\GestorPersonal;
use App\Rrhh\LectorOperacion;
use App\Rrhh\Models\AsignacionPersonal;
use App\Rrhh\Models\EventoRrhh;
use App\Rrhh\Models\FuenteMarcaje;
use App\Rrhh\Models\ImportacionMarcajes;
use App\Rrhh\Models\Marcaje;
use App\Rrhh\Models\Persona;
use App\Rrhh\Models\VinculoMarcaje;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RrhhController extends Controller
{
    public function personas(Request $r, LectorOperacion $operacion): JsonResponse
    {
        $datos = $r->validate(['buscar' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $personas = Persona::query()->when($datos['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('nombre', 'like', '%'.$s.'%')->orWhere('codigo', 'like', '%'.$s.'%')))->orderBy('nombre')->paginate(50);

        return response()->json(['data' => $personas, 'clientes' => $operacion->clientes(),
            'fuentes' => FuenteMarcaje::query()->orderBy('nombre')->get()->map(fn ($f) => [...$f->toArray(), 'configurada' => $f->getRawOriginal('configuracion') !== null]),
            'permisos' => ['gestionar' => $r->user()->can('gestionar-rrhh')]]);
    }

    public function guardarPersona(Request $r, GestorPersonal $gestor): JsonResponse
    {
        return response()->json(['data' => $gestor->persona($this->datosPersona($r), null, $r->user())], 201);
    }

    public function actualizarPersona(Request $r, Persona $persona, GestorPersonal $gestor): JsonResponse
    {
        return response()->json(['data' => $gestor->persona($this->datosPersona($r, true), $persona, $r->user())]);
    }

    private function datosPersona(Request $r, bool $editar = false): array
    {
        return $r->validate(['codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'], 'nombre' => ['required', 'string', 'max:180'],
            'cargo_id' => ['nullable', 'uuid', Rule::exists('rrhh.rh_cargos', 'id')], 'documento' => ['nullable', 'string', 'max:40'], 'empleador' => ['nullable', 'string', 'max:180'], 'cargo' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'], 'ceco' => ['nullable', 'string', 'max:60'], 'activa' => ['required', 'boolean'],
            'fuente_marcaje' => ['required', 'string', 'max:60'], 'pin' => ['nullable', 'string', 'regex:/^\d{6,12}$/'],
            'version' => [$editar ? 'required' : 'nullable', 'integer', 'min:1']]);
    }

    public function asignaciones(Request $r, ConsultaDotacion $consulta): JsonResponse
    {
        $datos = $r->validate(['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'], 'page' => ['nullable', 'integer', 'min:1']]);
        $zona = config('app.operational_timezone');
        $desde = CarbonImmutable::parse($datos['desde'], $zona);
        $hasta = CarbonImmutable::parse($datos['hasta'], $zona)->addDay();
        if ($desde->diffInDays($hasta) > 31) {
            throw ValidationException::withMessages(['hasta' => 'Consulte hasta 31 días por vez.']);
        }
        $filas = AsignacionPersonal::query()->where('desde_at', '<', $hasta->utc())->where('hasta_at', '>', $desde->utc())->orderBy('desde_at')->orderBy('id')->paginate(50);
        $filas->through(fn ($fila) => $consulta->detalle($fila));

        return response()->json(['data' => $filas]);
    }

    public function asignar(Request $r, GestorPersonal $gestor): JsonResponse
    {
        $datos = $r->validate(['operacion_id' => ['required', 'uuid'], 'persona_id' => ['required', 'uuid'], 'cliente_id' => ['nullable', 'uuid'],
            'cargo' => ['required', 'string', 'max:120'], 'area' => ['required', 'string', 'max:120'], 'ceco' => ['nullable', 'string', 'max:60'],
            'desde' => ['required', 'string', 'max:40'], 'hasta' => ['required', 'string', 'max:40'], 'descanso_programado_minutos' => ['required', 'integer', 'min:0', 'max:1439'],
            'costo_hora' => ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/'], 'moneda_costo' => ['nullable', 'required_with:costo_hora', 'string', 'regex:/^[A-Z]{3}$/'],
            'observacion' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $gestor->asignar($datos, $r->user())], 201);
    }

    public function estado(Request $r, AsignacionPersonal $asignacion, GestorPersonal $gestor): JsonResponse
    {
        $datos = $r->validate(['version' => ['required', 'integer', 'min:1'], 'estado' => ['required', 'in:programada,completada,cancelada'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $gestor->estado($asignacion, $datos, $r->user())]);
    }

    public function marcar(Request $r, ServicioMarcajes $servicio): JsonResponse
    {
        $datos = $r->validate(['operacion_id' => ['required', 'uuid'], 'codigo' => ['required', 'string', 'max:40'], 'pin' => ['required', 'string', 'max:12'], 'tipo' => ['required', 'in:entrada,salida'], 'instante' => ['prohibited']]);
        $marca = $servicio->propio($datos, $r->user());

        // El puesto de marcaje no recibe la ficha personal, el documento ni el hash de PIN.
        return response()->json(['data' => ['id' => $marca->id, 'tipo' => $marca->tipo, 'instante_at' => $marca->instante_at, 'anulado' => $marca->anulado_at !== null]], 201);
    }

    public function marcajes(Request $r, Persona $persona): JsonResponse
    {
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => Marcaje::query()->where('persona_id', $persona->id)->orderByDesc('instante_at')->orderBy('id')->paginate(50)]);
    }

    public function corregir(Request $r, Marcaje $marcaje, ServicioMarcajes $servicio): JsonResponse
    {
        $datos = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500'], 'instante' => ['nullable', 'string', 'max:40'], 'tipo' => ['required_with:instante', 'in:entrada,salida']]);

        return response()->json(['data' => $servicio->corregir($marcaje, $datos, $r->user())]);
    }

    public function guardarFuente(Request $r, EsquemaRrhh $esquema, Auditoria $auditoria): JsonResponse
    {
        $datos = $r->validate(['codigo' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_-]+$/', 'not_in:folios'], 'nombre' => ['required', 'string', 'max:120'], 'tipo' => ['required', 'in:normalizado,geovictoria'], 'activa' => ['required', 'boolean'],
            'configuracion' => ['nullable', 'array:base_url,usuario,password,api_key,api_secret'], 'configuracion.*' => ['nullable', 'string', 'max:500']]);
        $fuente = $esquema->transaccion(function () use ($datos, $r, $auditoria) {
            if (FuenteMarcaje::query()->where('codigo', $datos['codigo'])->exists()) {
                throw ValidationException::withMessages(['codigo' => 'El código ya está registrado.']);
            }
            $fila = FuenteMarcaje::query()->create(['id' => (string) Str::uuid(), ...$datos]);
            $auditoria->registrar('fuente', $fila->id, 'creada', $fila->toArray(), $r->user());

            return $fila;
        });

        return response()->json(['data' => $fuente], 201);
    }

    public function configurarFuente(Request $r, FuenteMarcaje $fuente, EsquemaRrhh $esquema, Auditoria $auditoria): JsonResponse
    {
        $datos = $r->validate(['activa' => ['required', 'boolean'], 'configuracion' => ['sometimes', 'nullable', 'array:base_url,usuario,password,api_key,api_secret'], 'configuracion.*' => ['nullable', 'string', 'max:500']]);
        $esquema->transaccion(function () use ($fuente, $datos, $r, $auditoria): void {
            $fuente = FuenteMarcaje::query()->lockForUpdate()->findOrFail($fuente->id);
            $modificaCredenciales = array_key_exists('configuracion', $datos);
            if (isset($datos['configuracion'])) {
                $datos['configuracion'] = [...($fuente->configuracion ?? []), ...$datos['configuracion']];
            }
            $fuente->update($datos);
            $auditoria->registrar('fuente', $fuente->id, 'configurada', ['activa' => $datos['activa'], 'credenciales_modificadas' => $modificaCredenciales], $r->user());
        });

        return response()->json(['data' => $fuente->fresh()]);
    }

    public function vincular(Request $r, FuenteMarcaje $fuente, EsquemaRrhh $esquema, Auditoria $auditoria): JsonResponse
    {
        $datos = $r->validate(['persona_id' => ['required', 'uuid', Rule::exists('rrhh.rh_personas', 'id')], 'identificador_externo' => ['required', 'string', 'max:100']]);
        $vinculo = $esquema->transaccion(function () use ($fuente, $datos, $r, $auditoria) {
            FuenteMarcaje::query()->lockForUpdate()->findOrFail($fuente->id);
            if (VinculoMarcaje::query()->where('fuente_id', $fuente->id)->where(fn ($q) => $q->where('persona_id', $datos['persona_id'])->orWhere('identificador_externo', $datos['identificador_externo']))->exists()) {
                throw ValidationException::withMessages(['identificador_externo' => 'Ya existe un vínculo para esa persona o identificador. No se reasigna un identificador histórico.']);
            }
            $fila = VinculoMarcaje::query()->create(['fuente_id' => $fuente->id, ...$datos]);
            $auditoria->registrar('fuente', $fuente->id, 'vinculo', $fila->toArray(), $r->user());

            return $fila;
        });

        return response()->json(['data' => $vinculo], 201);
    }

    public function integracion(FuenteMarcaje $fuente): JsonResponse
    {
        return response()->json(['data' => ['vinculos' => VinculoMarcaje::query()->where('fuente_id', $fuente->id)->orderBy('id')->paginate(50),
            'importaciones' => ImportacionMarcajes::query()->where('fuente_id', $fuente->id)->latest()->limit(20)->get()]]);
    }

    public function importar(Request $r, FuenteMarcaje $fuente, ImportadorMarcajes $importador): JsonResponse
    {
        $datos = $r->validate(['marcajes' => ['required', 'array', 'min:1', 'max:'.config('rrhh.maximo_marcajes_importacion')], 'marcajes.*' => ['required', 'array']]);

        return response()->json(['data' => $importador->ejecutar($fuente, $datos['marcajes'], $r->user())], 201);
    }

    public function historial(Request $r): JsonResponse
    {
        $datos = $r->validate(['entidad' => ['required', 'in:persona,asignacion,marcaje,fuente,importacion'], 'registro_id' => ['required', 'uuid']]);

        return response()->json(['data' => EventoRrhh::query()->where($datos)->orderByDesc('id')->paginate(50)]);
    }
}
