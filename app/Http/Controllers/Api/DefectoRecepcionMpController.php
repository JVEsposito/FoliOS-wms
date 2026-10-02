<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoEnvaseRomana;
use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\DefectoRecepcionMp;
use App\Models\EvidenciaDefectoRecepcionMp;
use App\Models\PersonalAccessToken;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\ValidacionMp;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\MateriaPrima\RegistroDefectosRecepcionPdf;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class DefectoRecepcionMpController extends Controller
{
    private const CATEGORIAS = ['envase_danado', 'envase_sucio', 'producto_danado', 'otro'];

    private const MAX_FOTO_KIB = 5120;

    public function porRecepcion(Request $request, RecepcionRomana $recepcion): JsonResponse
    {
        $this->asegurarTemporadaActiva($recepcion);
        $this->asegurarValidadorAsignado($recepcion, $request);

        $defectos = DefectoRecepcionMp::query()
            ->where('recepcion_romana_id', $recepcion->id)
            ->with(['validador:id,name', 'dispositivo:id,codigo', 'evidencias'])
            ->orderByDesc('registrado_at')->orderByDesc('id')->get();

        return response()->json(['data' => $defectos->map($this->representar(...))])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, RecepcionRomana $recepcion): JsonResponse
    {
        $this->asegurarTemporadaActiva($recepcion);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken
            && $token->dispositivo_id !== null
            && $token->can('tablet:validacion_mp'), 403, 'Registra los defectos desde una tablet autorizada.');

        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'categoria' => ['required', Rule::in(self::CATEGORIAS)],
            'tipo_envase' => ['nullable', Rule::enum(TipoEnvaseRomana::class)],
            'cantidad_afectada' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'descripcion' => ['required', 'string', 'max:2000'],
            'fotografias' => ['required', 'array', 'min:1', 'max:3'],
            'fotografias.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_FOTO_KIB],
            'fotografia_guia' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_FOTO_KIB],
        ]);

        $hash = $this->hashPeticion($datos);
        $guardados = [];
        $nuevo = false;

        try {
            $defecto = DB::transaction(function () use ($recepcion, $request, $token, $datos, $hash, &$guardados, &$nuevo): DefectoRecepcionMp {
                $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
                $this->asegurarTemporadaActiva($recepcion);
                $validacion = $this->asegurarValidadorAsignado($recepcion, $request);

                $existente = DefectoRecepcionMp::query()->where('operacion_id', $datos['operacion_id'])->first();
                if ($existente) {
                    if ($existente->recepcion_romana_id !== $recepcion->id || $existente->payload_hash !== $hash) {
                        throw new ConflictoOperacion('El identificador de operación ya se usó para un registro diferente.');
                    }

                    return $existente;
                }

                $defecto = DefectoRecepcionMp::create([
                    'operacion_id' => $datos['operacion_id'],
                    'payload_hash' => $hash,
                    'recepcion_romana_id' => $recepcion->id,
                    'validacion_mp_id' => $validacion->id,
                    'temporada_id' => $recepcion->temporada_id,
                    'numero_recepcion_snapshot' => $recepcion->numero_recepcion,
                    'numero_guia_snapshot' => $recepcion->numero_guia_despacho,
                    'cliente_nombre_snapshot' => $recepcion->cliente_nombre_snapshot,
                    'categoria' => $datos['categoria'],
                    'tipo_envase' => $datos['tipo_envase'] ?? null,
                    'cantidad_afectada' => $datos['cantidad_afectada'] ?? null,
                    'descripcion' => trim($datos['descripcion']),
                    'registrado_por_user_id' => $request->user()->id,
                    'dispositivo_id' => $token->dispositivo_id,
                    'registrado_at' => now(),
                ]);

                foreach ($datos['fotografias'] as $posicion => $foto) {
                    $this->guardarEvidencia($defecto, $foto, 'defecto', $posicion, $guardados);
                }
                if (isset($datos['fotografia_guia'])) {
                    $this->guardarEvidencia($defecto, $datos['fotografia_guia'], 'guia', 0, $guardados);
                }
                $nuevo = true;

                return $defecto;
            });
        } catch (\Throwable $excepcion) {
            if ($guardados !== []) {
                Storage::disk('local')->delete($guardados);
            }
            throw $excepcion;
        }

        $defecto->load(['validador:id,name', 'dispositivo:id,codigo', 'evidencias']);

        return response()->json(['data' => $this->representar($defecto)], $nuevo ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request): JsonResponse
    {
        $consulta = $this->consulta($this->filtros($request));
        $pagina = $consulta->orderByDesc('registrado_at')->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($pagina->items())->map($this->representar(...)),
            'pagina' => $pagina->currentPage(),
            'paginas' => $pagina->lastPage(),
            'total' => $pagina->total(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function temporadas(): JsonResponse
    {
        return response()->json(['data' => Temporada::query()->select('id', 'codigo', 'nombre', 'activa')
            ->productivas()
            ->where(fn ($consulta) => $consulta
                ->whereIn('id', DefectoRecepcionMp::query()->select('temporada_id'))
                ->orWhere('activa', true))
            ->orderByDesc('activa')->orderByDesc('codigo')->get()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function exportar(
        Request $request,
        string $formato,
        GeneradorLibroXlsx $excel,
        RegistroDefectosRecepcionPdf $pdf,
    ): BinaryFileResponse|Response {
        abort_unless(in_array($formato, ['xlsx', 'pdf', 'zip'], true), 404);
        $filtros = $this->filtros($request);
        $consulta = $this->consulta($filtros);
        $limite = match ($formato) {
            'zip' => 150,
            'pdf' => 300,
            default => 2000,
        };
        if ($consulta->count() > $limite) {
            throw ValidationException::withMessages(['filtros' => "La descarga {$formato} admite hasta {$limite} registros; acota los filtros de fecha o categoría."]);
        }
        $defectos = $consulta->orderByDesc('registrado_at')->orderByDesc('id')->get();
        $temporada = Temporada::query()->find($filtros['temporada_id'] ?? app(ServicioTemporadaActiva::class)->buscar()?->id);
        $codigo = $temporada?->codigo ?? 'sin-temporada';
        $nombre = 'defectos-recepcion-'.Str::slug($codigo).'-'.now()->format('Ymd-His');
        if ($formato === 'pdf') {
            return response($pdf->generar($defectos, $codigo), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$nombre.'.pdf"',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $columnas = [
            ['clave' => 'fecha', 'titulo' => 'Fecha de registro', 'ancho' => 23],
            ['clave' => 'temporada', 'titulo' => 'Temporada', 'ancho' => 18],
            ['clave' => 'recepcion', 'titulo' => 'N° recepción', 'ancho' => 22],
            ['clave' => 'guia', 'titulo' => 'N° guía de despacho', 'ancho' => 24],
            ['clave' => 'cliente', 'titulo' => 'Cliente', 'ancho' => 30],
            ['clave' => 'categoria', 'titulo' => 'Categoría', 'ancho' => 22],
            ['clave' => 'envase', 'titulo' => 'Tipo de envase', 'ancho' => 19],
            ['clave' => 'cantidad', 'titulo' => 'Cantidad afectada', 'ancho' => 20],
            ['clave' => 'descripcion', 'titulo' => 'Descripción', 'ancho' => 65],
            ['clave' => 'validador', 'titulo' => 'Validador', 'ancho' => 26],
            ['clave' => 'tablet', 'titulo' => 'Tablet', 'ancho' => 18],
            ['clave' => 'defecto_id', 'titulo' => 'ID del registro', 'ancho' => 40],
            ['clave' => 'fotos', 'titulo' => 'Fotos (IDs)', 'ancho' => 65],
        ];
        $filas = $defectos->map(fn (DefectoRecepcionMp $defecto): array => [
            'fecha' => $defecto->registrado_at?->format('d-m-Y H:i'),
            'temporada' => $codigo,
            'recepcion' => $defecto->numero_recepcion_snapshot,
            'guia' => $defecto->numero_guia_snapshot,
            'cliente' => $defecto->cliente_nombre_snapshot,
            'categoria' => str_replace('_', ' ', $defecto->categoria),
            'envase' => $defecto->tipo_envase,
            'cantidad' => $defecto->cantidad_afectada,
            'descripcion' => $defecto->descripcion,
            'validador' => $defecto->validador?->name,
            'tablet' => $defecto->dispositivo?->codigo,
            'defecto_id' => $defecto->id,
            'fotos' => $defecto->evidencias->pluck('id')->implode(', '),
        ]);
        $rutaExcel = $excel->generar('Defectos de recepción MP', $columnas, $filas, [
            'fecha_corte' => now()->format('d-m-Y H:i'),
            'usuario' => $request->user()->name,
            'temporada' => $codigo.' · '.$defectos->count().' registros',
        ], 'Defectos');
        if ($formato === 'xlsx') {
            $respuesta = response()->download($rutaExcel, $nombre.'.xlsx', [
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend();
            $respuesta->setPrivate();

            return $respuesta;
        }

        $rutaZip = tempnam(sys_get_temp_dir(), 'estiba-defectos-');
        if ($rutaZip === false) {
            @unlink($rutaExcel);
            throw new RuntimeException('No se pudo preparar el respaldo de fotos.');
        }
        $archivo = new ZipArchive;
        try {
            if ($archivo->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo preparar el respaldo de fotos.');
            }
            if (! $archivo->addFile($rutaExcel, 'registro.xlsx')
                || ! $archivo->addFromString('registro.pdf', $pdf->generar($defectos, $codigo))) {
                throw new RuntimeException('No se pudieron agregar los registros al respaldo.');
            }
            foreach ($defectos as $defecto) {
                foreach ($defecto->evidencias as $foto) {
                    if (Storage::disk('local')->missing($foto->ruta)) {
                        throw new RuntimeException("Falta la fotografía {$foto->id} del registro {$defecto->id}.");
                    }
                    $extension = match ($foto->mime) {
                        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                        default => throw new RuntimeException('Formato de fotografía no admitido.'),
                    };
                    if (! $archivo->addFile(Storage::disk('local')->path($foto->ruta), "fotos/{$defecto->id}/{$foto->tipo}-{$foto->id}.{$extension}")) {
                        throw new RuntimeException('No se pudo incorporar una fotografía al respaldo.');
                    }
                }
            }
            if (! $archivo->close()) {
                throw new RuntimeException('No se pudo finalizar el respaldo de fotos.');
            }
        } catch (\Throwable $error) {
            $archivo->close();
            @unlink($rutaZip);
            throw $error;
        } finally {
            @unlink($rutaExcel);
        }
        $respuesta = response()->download($rutaZip, $nombre.'.zip', [
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend();
        $respuesta->setPrivate();

        return $respuesta;
    }

    /** @return array<string, mixed> */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'buscar' => ['nullable', 'string', 'max:80'],
            'categoria' => ['nullable', Rule::in(self::CATEGORIAS)],
        ]);
    }

    /** @param array<string, mixed> $filtros */
    private function consulta(array $filtros): Builder
    {
        $temporadaId = $filtros['temporada_id']
            ?? app(ServicioTemporadaActiva::class)->buscar()?->id;
        $consulta = DefectoRecepcionMp::query()->with(['validador:id,name', 'dispositivo:id,codigo', 'evidencias'])
            ->where('temporada_id', $temporadaId ?? '');
        if (isset($filtros['desde'])) {
            $consulta->whereDate('registrado_at', '>=', $filtros['desde']);
        }
        if (isset($filtros['hasta'])) {
            $consulta->whereDate('registrado_at', '<=', $filtros['hasta']);
        }
        if (isset($filtros['categoria'])) {
            $consulta->where('categoria', $filtros['categoria']);
        }
        if (isset($filtros['buscar']) && trim($filtros['buscar']) !== '') {
            $buscar = '%'.trim($filtros['buscar']).'%';
            $consulta->where(fn ($q) => $q->where('numero_recepcion_snapshot', 'like', $buscar)
                ->orWhere('numero_guia_snapshot', 'like', $buscar)
                ->orWhere('cliente_nombre_snapshot', 'like', $buscar));
        }

        return $consulta;
    }

    public function show(DefectoRecepcionMp $defecto): JsonResponse
    {
        $defecto->load(['validador:id,name', 'dispositivo:id,codigo', 'evidencias']);

        return response()->json(['data' => $this->representar($defecto)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function evidencia(Request $request, DefectoRecepcionMp $defecto, EvidenciaDefectoRecepcionMp $evidencia): BinaryFileResponse
    {
        abort_unless($evidencia->defecto_recepcion_mp_id === $defecto->id, 404);
        $puedeAuditar = Gate::allows('auditar-defectos-recepcion-mp');
        $esSuRegistroVigente = Gate::allows('validar-mp')
            && $defecto->registrado_por_user_id === $request->user()->id
            && $defecto->temporada_id === app(ServicioTemporadaActiva::class)->buscar()?->id;
        abort_unless($puedeAuditar || $esSuRegistroVigente, 403);
        abort_unless(Storage::disk('local')->exists($evidencia->ruta), 404);

        $respuesta = response()->file(Storage::disk('local')->path($evidencia->ruta), [
            'Content-Type' => $evidencia->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $respuesta->setPrivate();
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    private function asegurarTemporadaActiva(RecepcionRomana $recepcion): void
    {
        abort_unless($recepcion->temporada_id === app(ServicioTemporadaActiva::class)->buscar()?->id, 404);
    }

    private function asegurarValidadorAsignado(RecepcionRomana $recepcion, Request $request): ValidacionMp
    {
        $validacion = ValidacionMp::query()->where('recepcion_romana_id', $recepcion->id)->first();
        abort_unless($validacion && $validacion->validador_user_id === $request->user()->id, 403,
            'Solo el validador que tomó la recepción puede registrar defectos.');

        return $validacion;
    }

    /** @param array<string, mixed> $datos */
    private function hashPeticion(array $datos): string
    {
        $contenido = [
            'categoria' => $datos['categoria'],
            'tipo_envase' => $datos['tipo_envase'] ?? null,
            'cantidad_afectada' => isset($datos['cantidad_afectada']) ? (int) $datos['cantidad_afectada'] : null,
            'descripcion' => trim($datos['descripcion']),
            'fotografias' => array_map(fn (UploadedFile $foto): string => hash_file('sha256', $foto->getRealPath()), $datos['fotografias']),
            'fotografia_guia' => isset($datos['fotografia_guia'])
                ? hash_file('sha256', $datos['fotografia_guia']->getRealPath()) : null,
        ];

        return hash('sha256', json_encode($contenido, JSON_THROW_ON_ERROR));
    }

    /** @param array<int, string> $guardados */
    private function guardarEvidencia(
        DefectoRecepcionMp $defecto,
        UploadedFile $archivo,
        string $tipo,
        int $posicion,
        array &$guardados,
    ): void {
        $id = (string) Str::uuid();
        $directorio = "defectos-recepcion-mp/{$defecto->temporada_id}/{$defecto->id}";
        $ruta = Storage::disk('local')->putFileAs($directorio, $archivo, "{$id}.{$archivo->extension()}");
        if ($ruta === false) {
            throw new RuntimeException('No fue posible guardar la evidencia fotográfica.');
        }
        $guardados[] = $ruta;
        EvidenciaDefectoRecepcionMp::create([
            'id' => $id,
            'defecto_recepcion_mp_id' => $defecto->id,
            'tipo' => $tipo,
            'posicion' => $posicion,
            'ruta' => $ruta,
            'mime' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
            'sha256' => hash_file('sha256', $archivo->getRealPath()),
        ]);
    }

    /** @return array<string, mixed> */
    private function representar(DefectoRecepcionMp $defecto): array
    {
        return [
            'id' => $defecto->id,
            'recepcion_romana_id' => $defecto->recepcion_romana_id,
            'numero_recepcion' => $defecto->numero_recepcion_snapshot,
            'numero_guia_despacho' => $defecto->numero_guia_snapshot,
            'cliente' => $defecto->cliente_nombre_snapshot,
            'temporada_id' => $defecto->temporada_id,
            'categoria' => $defecto->categoria,
            'tipo_envase' => $defecto->tipo_envase,
            'cantidad_afectada' => $defecto->cantidad_afectada,
            'descripcion' => $defecto->descripcion,
            'registrado_at' => $defecto->registrado_at?->toAtomString(),
            'validador' => ['id' => $defecto->registrado_por_user_id, 'nombre' => $defecto->validador?->name],
            'dispositivo' => $defecto->dispositivo ? ['id' => $defecto->dispositivo_id, 'codigo' => $defecto->dispositivo->codigo] : null,
            'evidencias' => $defecto->evidencias->map(fn (EvidenciaDefectoRecepcionMp $foto): array => [
                'id' => $foto->id,
                'tipo' => $foto->tipo,
                'posicion' => $foto->posicion,
                'mime' => $foto->mime,
                'tamano_bytes' => $foto->tamano_bytes,
                'url' => "/api/materia-prima/defectos-recepcion/{$defecto->id}/evidencias/{$foto->id}",
            ])->values(),
        ];
    }
}
