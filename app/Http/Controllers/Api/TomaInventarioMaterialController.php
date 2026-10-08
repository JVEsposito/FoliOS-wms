<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Models\Camara;
use App\Models\ItemMaterial;
use App\Models\TomaInventarioMaterial;
use App\Models\TomaInventarioMaterialPosicion;
use App\Models\TomaInventarioMaterialResultado;
use App\Models\User;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Documentos\ActaTomaInventarioMaterialPdf;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Materiales\ConsultaTomaInventarioMaterial;
use App\Services\Materiales\ServicioTomaInventarioMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TomaInventarioMaterialController extends Controller
{
    public function __construct(private readonly ServicioTomaInventarioMaterial $servicio, private readonly ConsultaTomaInventarioMaterial $consulta) {}

    public function index(Request $r)
    {
        $this->servicio->supervisar($r->user());
        $temporada = app(ServicioTemporadaActiva::class)->obtener();

        return response()->json(['data' => TomaInventarioMaterial::where('temporada_id', $temporada->id)->latest()->limit(100)->get(['id', 'estado', 'version', 'categoria', 'created_at', 'abierta_at', 'aprobada_at']),
            'catalogos' => ['camaras' => Camara::where('contenido', 'materiales')->where('estado', 'activa')->get(['id', 'codigo']),
                'camareros' => User::where('rol', 'camarero_materiales')->where('activo', true)->get(['id', 'name']),
                'categorias' => ItemMaterial::where('activo', true)->distinct()->orderBy('categoria')->pluck('categoria')]])->header('Cache-Control', 'no-store');
    }

    public function store(Request $r)
    {
        $datos = $r->validate(['operacion_id' => ['required', 'uuid'], 'camara_ids' => ['required', 'array', 'min:1', 'max:100'], 'camara_ids.*' => ['required', 'uuid', 'distinct'], 'categoria' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->consulta->detalle($this->servicio->crear($datos, $r->user()))], 201);
    }

    public function show(Request $r, TomaInventarioMaterial $toma)
    {
        $this->servicio->supervisar($r->user());
        $filtros = $r->validate(['tipo' => ['nullable', Rule::in(['faltante', 'sobrante', 'coincide', 'diferencia_cantidad'])], 'item_id' => ['nullable', 'uuid'], 'categoria' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->consulta->detalle($toma, array_filter($filtros))])->header('Cache-Control', 'no-store');
    }

    public function operar(Request $r, TomaInventarioMaterial $toma, string $accion)
    {
        $datos = $r->validate($this->operacion() + ['camarero_ids' => [Rule::requiredIf($accion === 'abrir'), 'array', 'min:1', 'max:100'], 'camarero_ids.*' => ['integer', 'distinct'], 'motivo' => [Rule::requiredIf($accion === 'anular'), 'string', 'max:2000', 'regex:/\S/u']]);

        return response()->json(['data' => $this->consulta->detalle($this->servicio->operar($toma, $accion, $datos, $r->user()))]);
    }

    public function decidir(Request $r, TomaInventarioMaterialResultado $resultado)
    {
        $datos = $r->validate($this->operacion() + ['accion' => ['required', Rule::in(['ajustar', 'reubicar', 'aceptar_sin_ajuste', 'recontar'])],
            'motivo' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'posicion_destino_id' => ['required_if:accion,reubicar', 'nullable', 'uuid', 'exists:posiciones,id']]);

        return response()->json(['data' => $this->consulta->detalle($this->servicio->decidir($resultado, $datos, $r->user()))]);
    }

    public function conteo(Request $r, ContextoOperacional $contexto)
    {
        [$user] = $contexto->obtener($r);
        abort_unless($user->rol === RolUsuario::CamareroMateriales, 403);
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        $tomas = TomaInventarioMaterial::where('temporada_id', $temporada->id)->where('estado', 'en_conteo')
            ->whereHas('posiciones', fn ($q) => $q->where('user_id', $user->id))->orderBy('abierta_at')->get();

        return response()->json(['data' => $tomas->map(fn ($t) => $this->consulta->ciego($t, $user))->all()])->header('Cache-Control', 'no-store, private');
    }

    public function contar(Request $r, TomaInventarioMaterialPosicion $tarea, ContextoOperacional $contexto)
    {
        [$user] = $contexto->obtener($r);
        $datos = $r->validate(['operacion_id' => ['required', 'uuid'], 'posicion_version' => ['required', 'integer', 'min:1'], 'vacia' => ['required', 'boolean'],
            'folios' => ['present', 'array', 'max:500'], 'folios.*.numero_folio' => ['required', 'string', 'max:100'],
            'folios.*.cantidad_contada' => ['required', 'numeric', 'min:0', 'max:99999999999.999']]);
        $toma = $this->servicio->contar($tarea, $datos, $user);

        return response()->json(['data' => $this->consulta->ciego($toma, $user)])->header('Cache-Control', 'no-store, private');
    }

    public function excel(Request $r, TomaInventarioMaterial $toma, GeneradorLibroXlsx $excel)
    {
        $this->servicio->supervisar($r->user());
        $datos = $this->consulta->detalle($toma, array_filter($r->only('tipo', 'item_id', 'categoria')));
        $titulos = ['folio' => 'Folio', 'camara' => 'Cámara', 'posicion' => 'Posición', 'item' => 'Ítem', 'categoria' => 'Categoría', 'unidad' => 'Unidad',
            'cantidad_inicial' => 'Foto inicial', 'variacion_operativa' => 'Movimientos', 'esperado' => 'Esperado', 'contado' => 'Contado',
            'diferencia' => 'Diferencia', 'diferencia_absoluta' => 'Diferencia absoluta', 'diferencia_pct' => 'Diferencia %', 'tipo' => 'Resultado', 'accion' => 'Acción', 'motivo' => 'Motivo', 'ajuste_id' => 'Ajuste aplicado'];
        $columnas = collect($titulos)->map(fn ($titulo, $clave) => ['clave' => $clave, 'titulo' => $titulo, 'tipo' => in_array($clave, ['cantidad_inicial', 'variacion_operativa', 'esperado', 'contado', 'diferencia', 'diferencia_absoluta', 'diferencia_pct']) ? 'numero' : 'texto'])->values()->all();
        $ruta = $excel->generar('Toma de inventario de materiales', $columnas, $datos['diferencias'], ['toma' => $toma->id, 'estado' => $toma->estado, 'alcance' => implode(', ', $datos['camaras']), 'usuario' => $r->user()->name], 'Diferencias');

        return response()->download($ruta, 'toma-inventario.xlsx')->deleteFileAfterSend();
    }

    public function pdf(Request $r, TomaInventarioMaterial $toma, ActaTomaInventarioMaterialPdf $pdf)
    {
        $this->servicio->supervisar($r->user());

        return response($pdf->generar($this->consulta->detalle($toma)), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="acta-toma-inventario.pdf"']);
    }

    private function operacion(): array
    {
        return ['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']];
    }
}
