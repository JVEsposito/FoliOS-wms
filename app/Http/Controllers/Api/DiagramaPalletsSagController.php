<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmitirDiagramaPalletsSagRequest;
use App\Http\Requests\EmitirEtiquetasMuestreoSagRequest;
use App\Http\Requests\GuardarDiagramaPalletsSagRequest;
use App\Models\Cliente;
use App\Models\DiagramaPalletsSag;
use App\Services\InspeccionSag\GeneradorDiagramaPalletsSag;
use App\Services\InspeccionSag\GeneradorEtiquetasMuestreoSag;
use App\Services\InspeccionSag\LectorCapturaMuestreoSag;
use App\Services\InspeccionSag\LectorPresampleUsda;
use App\Services\InspeccionSag\ServicioDiagramasPalletsSag;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Diagramas de presentación de pallets para inspección SAG/USDA de la temporada activa. */
class DiagramaPalletsSagController extends Controller
{
    public function catalogo(Request $request)
    {
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        $planta = json_decode(DB::table('configuracion_contraparte_sag')->where('id', 'planta')->value('datos') ?? '{}', true) ?: [];

        return response()->json([
            'temporada' => $temporada->only(['id', 'codigo', 'nombre']),
            'fecha_planta' => now()->setTimezone(config('app.operational_timezone'))->toDateString(),
            // Solo el CSP: el diagrama no lleva nombre de planta. Cada diagrama puede cambiarlo.
            'csp' => $planta['csp'] ?? null,
            'especies' => DB::table('especies_validacion')->where('temporada_id', $temporada->id)->where('activo', true)
                ->orderBy('nombre')->pluck('nombre'),
            'exportadoras' => Cliente::where('activo', true)->orderBy('nombre')->pluck('nombre'),
            'puede_gestionar' => $request->user()->can('gestionar-inspeccion-sag'),
        ]);
    }

    public function index(Request $request)
    {
        $datos = $request->validate(['texto' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $consulta = DiagramaPalletsSag::query()->where('temporada_id', app(ServicioTemporadaActiva::class)->obtener()->id);
        if (filled($datos['texto'] ?? null)) {
            $texto = '%'.addcslashes(trim($datos['texto']), '%_\\').'%';
            $consulta->where(fn ($q) => $q->where('numero', 'like', $texto)->orWhere('lote', 'like', $texto)
                ->orWhere('folio', 'like', $texto)->orWhere('exportadora', 'like', $texto));
        }
        $pagina = $consulta->orderByDesc('updated_at')->orderBy('id')->paginate(25);
        $usuarios = DB::table('users')->whereIn('id', $pagina->getCollection()->pluck('actualizado_por_user_id')->unique())->pluck('name', 'id');
        $pagina->getCollection()->transform(fn (DiagramaPalletsSag $diagrama): array => [
            ...$diagrama->only(['id', 'tipo', 'numero', 'csp', 'folio', 'lote', 'especie', 'exportadora', 'total_pallets', 'total_cajas', 'version', 'updated_at']),
            'fecha' => $diagrama->fecha?->toDateString(),
            'total_marcadas' => count($diagrama->cajas_marcadas),
            'actualizado_por' => $usuarios[$diagrama->actualizado_por_user_id] ?? null,
        ]);

        return response()->json($pagina);
    }

    public function show(DiagramaPalletsSag $diagrama)
    {
        $this->activa($diagrama);

        return $this->detalle($diagrama);
    }

    public function store(GuardarDiagramaPalletsSagRequest $request, ServicioDiagramasPalletsSag $servicio)
    {
        return $this->detalle($servicio->guardar($request->validated(), $request->user()));
    }

    public function update(GuardarDiagramaPalletsSagRequest $request, DiagramaPalletsSag $diagrama, ServicioDiagramasPalletsSag $servicio)
    {
        return $this->detalle($servicio->guardar($request->validated(), $request->user(), $diagrama));
    }

    /** PDF de lo que está en pantalla, guardado o no; la numeración se vuelve a calcular aquí. */
    public function pdf(EmitirDiagramaPalletsSagRequest $request, ServicioDiagramasPalletsSag $servicio, GeneradorDiagramaPalletsSag $generador)
    {
        $datos = $request->validated();
        $contenido = $servicio->contenido($datos);
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '-', 'Diagrama-'.mb_strtoupper($contenido['tipo'])
            .($contenido['numero'] ? '-'.$contenido['numero'] : '').'-'.mb_strtoupper($datos['papel']));

        return response($generador->pdf($contenido, $datos['papel'], (bool) $datos['mostrar_numeros'], $temporada->nombre, $request->user()->name), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'.pdf"',
        ]);
    }

    public function leerCaptura(Request $request, LectorCapturaMuestreoSag $lector)
    {
        $datos = $request->validate(['imagen' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:4096']]);

        return response()->json(['numeros' => $lector->leer($datos['imagen'])]);
    }

    /** Presample USDA/SAG escaneado: lo lee Claude, lo confirma Tesseract y la oficina lo revisa antes de aplicarlo. */
    public function leerPresample(Request $request, LectorPresampleUsda $lector)
    {
        $datos = $request->validate(['archivo' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240']],
            ['archivo.mimes' => 'Sube el presample en PDF, PNG o JPEG.']);

        return response()->json($lector->leer($datos['archivo'], $request->user()));
    }

    public function etiquetas(EmitirEtiquetasMuestreoSagRequest $request, ServicioDiagramasPalletsSag $servicio, GeneradorEtiquetasMuestreoSag $generador)
    {
        $datos = $request->validated();
        $contenido = $servicio->contenido($datos);

        return response($generador->generar($contenido, $datos['formato']), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Adhesivos-muestreo.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function activa(DiagramaPalletsSag $diagrama): void
    {
        abort_unless($diagrama->temporada_id === app(ServicioTemporadaActiva::class)->obtener()->id, 404);
    }

    private function detalle(DiagramaPalletsSag $diagrama)
    {
        return response()->json([
            'registro' => [...$diagrama->toArray(), 'fecha' => $diagrama->fecha?->toDateString()],
            'historial' => DB::table('eventos_diagrama_pallets_sag')->where('diagrama_id', $diagrama->id)->orderByDesc('version')
                ->get(['version', 'usuario_nombre', 'created_at'])
                ->map(fn ($evento): array => ['version' => (int) $evento->version, 'usuario' => $evento->usuario_nombre, 'fecha' => $evento->created_at]),
        ]);
    }
}
