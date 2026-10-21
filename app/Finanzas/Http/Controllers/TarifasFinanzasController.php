<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Http\PresentadorTarifas;
use App\Finanzas\Http\Requests\GuardarTarifaRequest;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Models\Tarifa;
use App\Finanzas\Periodo;
use App\Finanzas\Tarifario\CatalogoServicios;
use App\Finanzas\Tarifario\ClientesOperacion;
use App\Finanzas\Tarifario\GestorTarifas;
use App\Finanzas\Tarifario\ResolutorTarifas;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Tarifario: precios por servicio y unidad de cobro, por cliente o como tarifa general. */
class TarifasFinanzasController extends Controller
{
    /** «actuales»: vigentes hoy y programadas para después. */
    private const ESTADOS = ['actuales', Tarifa::ESTADO_VIGENTE, Tarifa::ESTADO_FUTURA, Tarifa::ESTADO_VENCIDA, Tarifa::ESTADO_ANULADA, 'todas'];

    public function __construct(
        private readonly CatalogoServicios $servicios,
        private readonly ClientesOperacion $clientes,
        private readonly GestorTarifas $gestor,
        private readonly PresentadorTarifas $presentador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $datos = validator($request->query(), [
            'cliente' => ['nullable', 'string', 'max:40'],
            'servicio' => ['nullable', Rule::in($this->servicios->claves())],
            'estado' => ['nullable', Rule::in(self::ESTADOS)],
        ], [
            'cliente.max' => 'El cliente indicado no es válido.',
            'servicio.in' => 'El servicio elegido no existe.',
            'estado.in' => 'El estado elegido no existe.',
        ])->validate();

        $hoy = Periodo::hoy();
        $monedas = $this->monedas();
        // Son decenas o cientos de precios: se leen todos para el resumen y se filtran aquí.
        $todas = Tarifa::query()->get();
        $cliente = (string) ($datos['cliente'] ?? '');
        $estado = $datos['estado'] ?? 'actuales';

        $filtradas = $todas
            ->filter(fn (Tarifa $tarifa): bool => match ($cliente) {
                '' => true,
                'general' => $tarifa->general(),
                default => $tarifa->cliente_id === $cliente,
            })
            ->filter(fn (Tarifa $tarifa): bool => ($datos['servicio'] ?? null) === null || $tarifa->servicio === $datos['servicio'])
            ->filter(fn (Tarifa $tarifa): bool => match ($estado) {
                'todas' => true,
                'actuales' => in_array($tarifa->estado($hoy), [Tarifa::ESTADO_VIGENTE, Tarifa::ESTADO_FUTURA], true),
                default => $tarifa->estado($hoy) === $estado,
            });

        $actuales = $todas->filter(fn (Tarifa $tarifa): bool => in_array($tarifa->estado($hoy), [Tarifa::ESTADO_VIGENTE, Tarifa::ESTADO_FUTURA], true));

        return response()->json([
            'data' => $this->ordenar($filtradas)->map(fn (Tarifa $tarifa): array => $this->presentador->tarifa($tarifa, $monedas, $hoy))->values(),
            'resumen' => [
                'vigentes' => $actuales->filter(fn (Tarifa $tarifa): bool => $tarifa->estado($hoy) === Tarifa::ESTADO_VIGENTE)->count(),
                'futuras' => $actuales->filter(fn (Tarifa $tarifa): bool => $tarifa->estado($hoy) === Tarifa::ESTADO_FUTURA)->count(),
                'clientes_con_precio_propio' => $actuales->whereNotNull('cliente_id')->pluck('cliente_id')->unique()->count(),
                'servicios_en_tarifa_general' => $actuales->whereNull('cliente_id')->pluck('servicio')->unique()->count(),
            ],
            'hoy' => $hoy,
            'servicios' => $this->servicios->descripcion(),
            'procesos' => app(CatalogoFuentes::class)->descripcion(),
            'monedas' => array_values(array_map(fn (Moneda $moneda): array => $this->presentador->moneda($moneda), $monedas)),
            'clientes' => $this->clientesConPrecio($todas),
            'puede_gestionar' => $request->user()->can('gestionar-tarifas-finanzas'),
            'puede_usar_rrhh' => $request->user()->can('consultar-rrhh'),
        ]);
    }

    public function show(Tarifa $tarifa): JsonResponse
    {
        return response()->json([
            'data' => $this->presentador->tarifa($tarifa, $this->monedas(), Periodo::hoy()),
            'eventos' => $tarifa->eventos()->orderByDesc('id')->get()->map(fn ($evento): array => $this->presentador->evento($evento))->values(),
        ]);
    }

    public function store(GuardarTarifaRequest $request): JsonResponse
    {
        $tarifa = $this->gestor->crear($request->validated(), $request->user());

        return response()->json(['data' => $this->presentador->tarifa($tarifa->refresh(), $this->monedas(), Periodo::hoy())], 201);
    }

    public function update(GuardarTarifaRequest $request, Tarifa $tarifa): JsonResponse
    {
        $tarifa = $this->gestor->modificar($tarifa, $request->validated(), $request->user());

        return response()->json(['data' => $this->presentador->tarifa($tarifa->refresh(), $this->monedas(), Periodo::hoy())]);
    }

    public function anular(Request $request, Tarifa $tarifa): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [
            'motivo.required' => 'Indique el motivo de la anulación.',
            'motivo.string' => 'El motivo debe ser texto.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
            'motivo.max' => 'El motivo no puede superar 500 caracteres.',
        ]);
        $tarifa = $this->gestor->anular($tarifa, $datos['motivo'], $request->user());

        return response()->json(['data' => $this->presentador->tarifa($tarifa->refresh(), $this->monedas(), Periodo::hoy())]);
    }

    /** Lo que se le cobraría a un cliente (o con la tarifa general) por cada servicio en un día. */
    public function efectiva(Request $request): JsonResponse
    {
        $datos = validator($request->query(), [
            'cliente' => ['nullable', 'string', 'max:40'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'cliente.max' => 'El cliente indicado no es válido.',
            'fecha.date_format' => 'La fecha no es válida.',
        ])->validate();

        $hoy = Periodo::hoy();
        $fecha = $datos['fecha'] ?? $hoy;
        $clienteId = in_array($datos['cliente'] ?? null, [null, '', 'general'], true) ? null : $datos['cliente'];
        $resolutor = ResolutorTarifas::delDia($fecha);
        $monedas = $this->monedas();

        return response()->json([
            'fecha' => $fecha,
            'cliente' => $clienteId === null ? null : [
                'id' => $clienteId,
                'nombre' => $this->clientes->buscar($clienteId)['nombre'] ?? $clienteId,
            ],
            'servicios' => array_map(function (array $servicio) use ($resolutor, $clienteId, $fecha, $monedas, $hoy): array {
                $vigente = ($servicio['vigente_desde'] === null || $servicio['vigente_desde'] <= $fecha)
                    && ($servicio['vigente_hasta'] === null || $servicio['vigente_hasta'] >= $fecha);
                $precios = $vigente ? $resolutor->aplicables($clienteId, $servicio['clave'], $fecha) : [];

                return [
                    'servicio' => $servicio['clave'],
                    'nombre' => $servicio['nombre'],
                    'concepto' => $servicio,
                    'origen' => $precios === [] ? null : ($precios[0]->general() ? 'general' : 'propio'),
                    'precios' => array_map(fn (Tarifa $tarifa): array => $this->presentador->tarifa($tarifa, $monedas, $hoy), $precios),
                ];
            }, $this->servicios->descripcion()),
        ]);
    }

    /** @return array<string, Moneda> */
    private function monedas(): array
    {
        return Moneda::query()->orderByDesc('activa')->orderBy('codigo')->get()->keyBy('codigo')->all();
    }

    /**
     * Tarifa general primero; después por cliente, en el orden del recorrido de la fruta y,
     * dentro de cada unidad, del precio más reciente al más antiguo.
     *
     * @param  Collection<int, Tarifa>  $tarifas
     * @return Collection<int, Tarifa>
     */
    private function ordenar(Collection $tarifas): Collection
    {
        return $tarifas->sort(fn (Tarifa $a, Tarifa $b): int => [
            $a->general() ? 0 : 1,
            mb_strtolower($this->presentador->nombreCliente($a)),
            $this->servicios->orden($a->servicio, $a->unidad),
            $b->vigente_desde->toDateString(),
            $b->id,
        ] <=> [
            $b->general() ? 0 : 1,
            mb_strtolower($this->presentador->nombreCliente($b)),
            $this->servicios->orden($b->servicio, $b->unidad),
            $a->vigente_desde->toDateString(),
            $a->id,
        ]);
    }

    /**
     * Clientes de la operación, más los que solo quedan en algún precio (si se borraron allá).
     *
     * @param  Collection<int, Tarifa>  $tarifas
     * @return list<array{id: string, nombre: string, activo: bool, con_precio: bool}>
     */
    private function clientesConPrecio(Collection $tarifas): array
    {
        $conPrecio = $tarifas->whereNotNull('cliente_id')->keyBy('cliente_id');
        $lista = [];
        foreach ($this->clientes->todos() as $id => $cliente) {
            $lista[$id] = ['id' => $id, 'nombre' => $cliente['nombre'], 'activo' => $cliente['activo'], 'con_precio' => $conPrecio->has($id)];
        }
        foreach ($conPrecio as $id => $tarifa) {
            $lista[$id] ??= ['id' => (string) $id, 'nombre' => $tarifa->cliente_nombre ?? (string) $id, 'activo' => false, 'con_precio' => true];
        }

        return array_values($lista);
    }
}
