<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Dotacion\CargosDotacion;
use App\Finanzas\Dotacion\LectorRrhh;
use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Models\VinculoDotacion;
use App\Finanzas\Periodo;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DotacionFinanzasController extends Controller
{
    public function index(Request $r, LectorRrhh $lector): JsonResponse
    {
        $datos = $r->validate(['mes' => ['required', 'date_format:Y-m'], 'servicio' => ['nullable', 'string', 'max:40'], 'page' => ['nullable', 'integer', 'min:1']]);
        $filas = $lector->mes(Periodo::mes($datos['mes']));
        $vinculos = VinculoDotacion::query()->whereIn('asignacion_id', $filas->getCollection()->pluck('id'))->where('servicio', $datos['servicio'] ?? '')->get()->keyBy('asignacion_id');
        $cargos = CargoAcordado::query()->whereIn('id', $vinculos->pluck('cargo_id'))->get()->keyBy('id');
        $filas->through(function (array $a) use ($vinculos, $cargos): array {
            $v = $vinculos->get($a['id']);
            $c = $v ? $cargos->get($v->cargo_id) : null;
            unset($a['huella_asistencia'], $a['huella_programacion']);

            return [...$a, 'cargo_actual' => $c ? ['id' => $c->id, 'anulado' => $c->anulado_at !== null, 'aprobado' => $c->aprobado_at !== null] : null];
        });

        return response()->json(['data' => $filas]);
    }

    public function store(Request $r, CargosDotacion $gestor): JsonResponse
    {
        $datos = $r->validate(['asignacion_id' => ['required', 'uuid'], 'servicio' => ['required', 'string', 'max:40'],
            'medida' => ['required', Rule::in(CargosDotacion::MEDIDAS)], 'fecha' => ['required', 'date_format:Y-m-d'],
            'base_cobro' => ['required', 'in:ejecucion,acuerdo,compromiso,disponibilidad'], 'respaldo' => ['required', 'string', 'min:5', 'max:500'],
            'confirmar_unidad' => ['required', 'accepted'], 'reemplaza_cargo_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $gestor->crear($datos, $r->user())], 201);
    }
}
