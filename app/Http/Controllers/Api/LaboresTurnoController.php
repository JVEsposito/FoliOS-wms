<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoCarga;
use App\Enums\FuncionCamarero;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\Carga;
use App\Models\FuncionCamareroTurno;
use App\Models\LaborCamarero;
use App\Models\PlanOperacional;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\TurnoPlanificador;
use App\Models\User;
use App\Services\Planificador\ServicioLaboresTurno;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class LaboresTurnoController extends Controller
{
    public function index(ServicioTemporadaActiva $temporadas, ServicioLaboresTurno $servicio): JsonResponse
    {
        $temporada = $temporadas->obtener();
        $turnos = TurnoPlanificador::query()->where('temporada_id', $temporada->id)->with(['asignaciones.usuario', 'labores' => fn ($q) => $q->latest('updated_at')->limit(100)->with(['usuario', 'dispositivo'])])
            ->orderByDesc('inicio_at')->limit(30)->get();
        $usuarios = User::query()->where('activo', true)->orderBy('name')->get()->filter(fn (User $u) => $u->can('operar-camaras-productos'));
        $vigente = $servicio->vigente($temporada->id);

        return response()->json(['data' => $turnos->map(fn ($turno) => [
            ...$servicio->turnoJson($turno), 'vigente' => $turno->id === $vigente?->id,
            'asignaciones' => $turno->asignaciones->map(fn ($a) => [...$a->only(['user_id', 'funcion_principal', 'funciones_apoyo', 'preferencia', 'disponible', 'version']), 'nombre' => $a->usuario->name])->all(),
            'labores' => $turno->labores->sortByDesc('updated_at')->take(100)->map(fn ($l) => [...$servicio->laborJson($l),
                'camarero' => $l->usuario->name, 'tablet' => $l->dispositivo->nombre])->values()->all(),
        ])->all(), 'catalogo' => [
            'usuarios' => $usuarios->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name])->values()->all(),
            'funciones' => collect(FuncionCamarero::cases())->map(fn ($f) => ['codigo' => $f->value, 'nombre' => $f->etiqueta()])->all(),
            'tuneles' => TunelPrefrio::query()->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
            'cargas' => Carga::query()->where('temporada_id', $temporada->id)->whereIn('estado', EstadoCarga::visiblesEnOperacion())
                ->orderByDesc('updated_at')->limit(150)->get(['id', 'codigo']),
            'sag' => PlanOperacional::query()->where('temporada_id', $temporada->id)->where('tipo', TipoPlanOperacional::PreparacionInspeccion)
                ->whereIn('estado', ['programado', 'en_ejecucion'])->orderByDesc('updated_at')->limit(100)->get(['id', 'titulo']),
        ], 'meta' => ['temporada_id' => $temporada->id, 'zona_horaria' => config('app.operational_timezone'),
            'ahora' => now()->toISOString(), 'vigente_id' => $vigente?->id]])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, ServicioTemporadaActiva $temporadas, ServicioLaboresTurno $servicio): JsonResponse
    {
        $datos = $request->validate([
            'id' => ['nullable', 'uuid'], 'version' => ['required_with:id', 'nullable', 'integer', 'min:1'],
            'nombre' => ['required', 'string', 'max:80'], 'inicio' => ['required', 'date_format:Y-m-d\TH:i'],
            'fin' => ['required', 'date_format:Y-m-d\TH:i'], 'asignaciones' => ['required', 'array', 'min:1', 'max:100'],
            'asignaciones.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'asignaciones.*.funcion_principal' => ['required', Rule::enum(FuncionCamarero::class)],
            'asignaciones.*.funciones_apoyo' => ['present', 'array', 'max:5'],
            'asignaciones.*.funciones_apoyo.*' => ['required', Rule::enum(FuncionCamarero::class)],
            'asignaciones.*.disponible' => ['required', 'boolean'],
            'asignaciones.*.preferencia' => ['nullable', 'array:tipo,id'],
            'asignaciones.*.preferencia.tipo' => ['required_with:asignaciones.*.preferencia', Rule::in(['tunel', 'carga', 'sag'])],
            'asignaciones.*.preferencia.id' => ['required_with:asignaciones.*.preferencia', 'uuid'],
        ]);
        $zona = config('app.operational_timezone', 'America/Santiago');
        try {
            $inicio = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $datos['inicio'], $zona);
            $fin = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $datos['fin'], $zona);
            if ($inicio->format('Y-m-d\TH:i') !== $datos['inicio'] || $fin->format('Y-m-d\TH:i') !== $datos['fin']
                || $fin->lte($inicio) || $inicio->diffInHours($fin) > 36) {
                throw new \RuntimeException;
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['fin' => 'Revisa el horario: el término debe ser posterior al inicio, con un máximo de 36 horas y horas válidas de planta.']);
        }
        $temporada = $temporadas->obtener();
        $turno = DB::transaction(function () use ($datos, $inicio, $fin, $temporada, $request, $servicio): TurnoPlanificador {
            Temporada::query()->lockForUpdate()->findOrFail($temporada->id);
            $turno = isset($datos['id']) ? TurnoPlanificador::query()->lockForUpdate()->findOrFail($datos['id']) : new TurnoPlanificador;
            abort_if($turno->exists && $turno->temporada_id !== $temporada->id, 404);
            if ($turno->exists && $turno->version !== $datos['version']) {
                throw new ConflictoOperacion('Otro supervisor cambió este turno. Actualiza antes de guardar.');
            }
            if (TurnoPlanificador::query()->where('temporada_id', $temporada->id)->when($turno->exists, fn ($q) => $q->whereKeyNot($turno->id))
                ->where('inicio_at', '<', $fin->utc())->where('fin_at', '>', $inicio->utc())->exists()) {
                throw ValidationException::withMessages(['inicio' => 'El horario se superpone con otro turno de esta temporada.']);
            }
            $anterior = $turno->exists ? $turno->load('asignaciones')->toArray() : null;
            $turno->fill(['temporada_id' => $temporada->id, 'nombre' => trim($datos['nombre']), 'inicio_at' => $inicio->utc(), 'fin_at' => $fin->utc(),
                'version' => $turno->exists ? $turno->version + 1 : 1, 'actualizado_por_user_id' => $request->user()->id]);
            if (! $turno->exists) {
                $turno->creado_por_user_id = $request->user()->id;
            }
            $turno->save();
            foreach ($datos['asignaciones'] as $fila) {
                $usuario = User::query()->findOrFail($fila['user_id']);
                if (! $usuario->activo || ! $usuario->can('operar-camaras-productos')) {
                    throw ValidationException::withMessages(['asignaciones' => 'Cada camarero debe estar activo y autorizado para operar cámaras de productos.']);
                }
                $apoyos = array_values(array_unique($fila['funciones_apoyo']));
                if (in_array($fila['funcion_principal'], $apoyos, true)) {
                    throw ValidationException::withMessages(['asignaciones' => 'La función principal no se repite como apoyo.']);
                }
                $preferencia = $fila['preferencia'] ?? null;
                if ($preferencia) {
                    $this->validarPreferencia($preferencia, $fila['funcion_principal'], $temporada->id);
                }
                $asignacion = FuncionCamareroTurno::query()->firstOrNew(['turno_id' => $turno->id, 'user_id' => $usuario->id]);
                $asignacion->fill(['funcion_principal' => $fila['funcion_principal'], 'funciones_apoyo' => $apoyos, 'preferencia' => $preferencia, 'disponible' => $fila['disponible']]);
                if ($asignacion->exists && $asignacion->isDirty()) {
                    $asignacion->version++;
                }
                $asignacion->save();
            }
            foreach ($turno->asignaciones()->whereNotIn('user_id', array_column($datos['asignaciones'], 'user_id'))->where('disponible', true)->get() as $omitida) {
                $omitida->update(['disponible' => false, 'version' => $omitida->version + 1]);
            }
            $servicio->evento($turno->id, $request->user()->id, 'configuracion_guardada', ['anterior' => $anterior, 'nueva' => $turno->fresh('asignaciones')->toArray()]);

            return $turno->refresh();
        }, attempts: 3);

        return response()->json(['data' => $servicio->turnoJson($turno)]);
    }

    private function validarPreferencia(array $p, string $funcion, string $temporadaId): void
    {
        $valida = match ($p['tipo']) {
            'tunel' => $funcion === FuncionCamarero::RecepcionTuneles->value && TunelPrefrio::query()->whereKey($p['id'])->exists(),
            'carga' => $funcion === FuncionCamarero::SeparacionCargas->value && Carga::query()->whereKey($p['id'])->where('temporada_id', $temporadaId)->whereIn('estado', EstadoCarga::visiblesEnOperacion())->exists(),
            'sag' => $funcion === FuncionCamarero::PreparacionSag->value && PlanOperacional::query()->whereKey($p['id'])->where('temporada_id', $temporadaId)->where('tipo', TipoPlanOperacional::PreparacionInspeccion)->whereIn('estado', ['programado', 'en_ejecucion'])->exists(),
            default => false,
        };
        if (! $valida) {
            throw ValidationException::withMessages(['asignaciones' => 'La preferencia debe corresponder a la función y a un objetivo vigente de esta temporada.']);
        }
    }

    public function liberar(Request $request, LaborCamarero $laborCamarero, ServicioLaboresTurno $servicio, ServicioTemporadaActiva $temporadas): JsonResponse
    {
        abort_unless($laborCamarero->turno->temporada_id === $temporadas->obtener()->id, 404);
        $datos = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $servicio->liberar($laborCamarero, $request->user(), $datos['version']);

        return response()->json(['data' => $servicio->laborJson($laborCamarero->refresh())]);
    }
}
