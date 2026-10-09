<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferirClienteMaterialRequest;
use App\Http\Resources\TransferenciaClienteMaterialResource;
use App\Models\ClienteMaterial;
use App\Models\FolioMaterial;
use App\Models\PersonalAccessToken;
use App\Models\SaldoMaterialAlmacen;
use App\Models\TransferenciaClienteMaterial;
use App\Services\Materiales\ServicioAlmacenMaterial;
use App\Services\Materiales\ServicioTransferenciaClienteMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class TransferenciaClienteMaterialController extends Controller
{
    public function store(TransferirClienteMaterialRequest $request, FolioMaterial $folioMaterial, ServicioTransferenciaClienteMaterial $servicio)
    {
        $token = $request->user()->currentAccessToken();
        $dispositivoId = $token instanceof PersonalAccessToken ? $token->dispositivo_id : null;

        return (new TransferenciaClienteMaterialResource($servicio->transferir($folioMaterial, $request->validated(), $request->user(), $dispositivoId)))->response()->setStatusCode(200);
    }

    public function index(Request $request)
    {
        $this->autorizarLectura($request);

        return TransferenciaClienteMaterialResource::collection($this->consulta($request)->latest('ocurrido_at')->paginate(25)->withQueryString());
    }

    public function opciones(Request $request, ServicioAlmacenMaterial $almacenes)
    {
        Gate::authorize('transferir-folios-materiales-clientes');
        $datos = $request->validate(['folio' => ['required', 'string', 'max:100']]);
        $origen = FolioMaterial::with('item.cliente')->where(fn ($q) => $q->where('folio_id', $datos['folio'])->orWhereHas('folio', fn ($q) => $q->where('numero_folio', $datos['folio'])))->firstOrFail();
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        $bodega = $almacenes->bodegaCentral($request->user());
        $saldo = SaldoMaterialAlmacen::where('folio_id', $origen->folio_id)->where('almacen_material_id', $bodega->id)->first();
        $clientes = ClienteMaterial::with(['cliente', 'items' => fn ($q) => $q->where('activo', true)])
            ->where('activo', true)->where('cliente_id', '!=', $origen->item->cliente->cliente_id)
            ->whereHas('temporada', fn ($q) => $q->where('temporada_id', $temporada->id)->where('activa', true))
            ->whereHas('cliente', fn ($q) => $q->where('activo', true)->whereNotNull('codigo_folio_materiales'))->orderBy('nombre')->get();

        return response()->json(['data' => ['folio_origen_id' => $origen->folio_id, 'numero_folio' => $origen->folio->numero_folio,
            'unidad_medida' => $origen->unidad_medida, 'cantidad_actual' => $origen->cantidad_actual,
            'disponible_bodega' => $saldo?->cantidadDisponible() ?? 0, 'reservado_bodega' => (float) ($saldo?->cantidad_reservada ?? 0),
            'en_centros_costo' => max(0, (float) $origen->cantidad_actual - (float) ($saldo?->cantidad_actual ?? 0)),
            'tiene_reservas_activas' => $origen->reservas()->where('estado', 'activa')->exists() || $origen->reservasTransformacion()->where('estado', 'activa')->exists(),
            'clientes' => $clientes->filter(fn ($c) => preg_match('/^[A-Z]{2}$/', (string) $c->cliente->codigo_folio_materiales))->map(function ($c) use ($origen) {
                $items = $c->items->filter(fn ($i) => mb_strtolower(trim($i->unidad_medida)) === mb_strtolower(trim($origen->unidad_medida)))->values();

                return ['id' => $c->cliente_id, 'codigo' => $c->codigo, 'nombre' => $c->nombre,
                    'item_sugerido_id' => $items->firstWhere('codigo', $origen->item->codigo)?->id,
                    'items' => $items->map(fn ($i) => $i->only(['id', 'codigo', 'nombre', 'unidad_medida']))];
            })->values()]]);
    }

    public function csv(Request $request)
    {
        $this->autorizarLectura($request);
        $consulta = $this->consulta($request);

        return response()->streamDownload(function () use ($consulta) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Folio origen', 'Folio destino', 'Cliente origen', 'Cliente destino', 'Ítem origen', 'Ítem destino', 'Cantidad', 'Unidad', 'Modalidad', 'Motivo', 'Respaldo', 'Usuario'], ';', '"', '');
            foreach ($consulta->lazyById(500) as $t) {
                $fila = [$t->ocurrido_at->timezone('America/Santiago')->format('d-m-Y H:i'), $t->folioOrigen->folio->numero_folio, $t->folioDestino->folio->numero_folio,
                    $t->clienteOrigen->nombre, $t->clienteDestino->nombre, $t->itemOrigen->codigo, $t->itemDestino->codigo,
                    $t->cantidad, $t->unidad_medida, $t->modalidad, $t->motivo, $t->documento_respaldo, $t->usuario->name];
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@\-]/', (string) $v) ? "'".$v : $v, $fila), ';', '"', '');
            }
            fclose($out);
        }, 'transferencias-clientes-materiales.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function autorizarLectura(Request $request): void
    {
        abort_unless($request->user()->can('consultar-kardex-materiales') || $request->user()->can('transferir-folios-materiales-clientes'), 403);
    }

    private function consulta(Request $request)
    {
        $f = $request->validate(['cliente_id' => ['nullable', 'uuid'], 'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])], 'folio' => ['nullable', 'string', 'max:100']]);

        return TransferenciaClienteMaterial::with(['folioOrigen.folio', 'folioDestino.folio', 'clienteOrigen', 'clienteDestino', 'itemOrigen', 'itemDestino', 'usuario'])
            ->where('temporada_id', app(ServicioTemporadaActiva::class)->subconsultaId())
            ->when($f['cliente_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->where('cliente_origen_id', $id)->orWhere('cliente_destino_id', $id)))
            ->when($f['desde'] ?? null, fn ($q, $d) => $q->where('ocurrido_at', '>=', Carbon::parse($d, 'America/Santiago')->utc()))
            ->when($f['hasta'] ?? null, fn ($q, $d) => $q->where('ocurrido_at', '<', Carbon::parse($d, 'America/Santiago')->addDay()->utc()))
            ->when($f['folio'] ?? null, fn ($q, $n) => $q->where(fn ($q) => $q->whereHas('folioOrigen.folio', fn ($q) => $q->where('numero_folio', 'like', '%'.$n.'%'))->orWhereHas('folioDestino.folio', fn ($q) => $q->where('numero_folio', 'like', '%'.$n.'%'))));
    }
}
