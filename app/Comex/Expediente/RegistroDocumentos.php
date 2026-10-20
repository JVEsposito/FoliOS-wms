<?php

namespace App\Comex\Expediente;

use App\Comex\EsquemaComex;
use App\Comex\Models\ArchivoDocumento;
use App\Comex\Models\Documento;
use App\Comex\Models\EventoDocumento;
use App\Comex\Models\TipoDocumento;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Registra un documento del expediente: su estado, número, fecha y respaldo. Reemplazar el
 * respaldo deja el anterior en el historial. La versión que vio el usuario se compara antes de
 * guardar: si otro lo cambió entretanto, no se pisa.
 */
final class RegistroDocumentos
{
    public const EXTENSIONES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const CAMPOS = ['estado', 'numero', 'fecha_emision', 'observacion', 'motivo_no_aplica'];

    public function __construct(private readonly EsquemaComex $esquema) {}

    /**
     * @param  array<string, mixed>  $embarque  como lo lee EmbarquesOperacion
     * @param  array{estado: string, numero?: ?string, fecha_emision?: ?string, observacion?: ?string, motivo?: ?string, version: int}  $datos
     *
     * @throws ValidationException
     */
    public function registrar(array $embarque, TipoDocumento $tipo, array $datos, ?UploadedFile $archivo, User $usuario): Documento
    {
        if ($embarque['estado'] === 'cancelado') {
            throw ValidationException::withMessages(['embarque' => 'El embarque está cancelado: su expediente ya no se edita.']);
        }

        $estado = $datos['estado'];
        $valores = [
            'estado' => $estado,
            'numero' => $this->limpio($datos['numero'] ?? null),
            'fecha_emision' => $this->limpio($datos['fecha_emision'] ?? null),
            'observacion' => $this->limpio($datos['observacion'] ?? null),
            'motivo_no_aplica' => $estado === Documento::NO_APLICA ? $this->limpio($datos['motivo'] ?? null) : null,
        ];
        if ($estado === Documento::NO_APLICA && mb_strlen((string) $valores['motivo_no_aplica']) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Indique por qué este documento no aplica al embarque.']);
        }

        $guardado = $archivo === null ? null : $this->guardarArchivo($embarque['id'], $archivo);

        try {
            return $this->esquema->transaccion(function () use ($embarque, $tipo, $datos, $valores, $guardado, $usuario): Documento {
                $documento = Documento::query()
                    ->where('embarque_id', $embarque['id'])
                    ->where('tipo_documento_id', $tipo->id)
                    ->lockForUpdate()
                    ->first();

                if (($documento->version ?? 0) !== (int) $datos['version']) {
                    throw ValidationException::withMessages(['version' => 'Otra persona modificó este documento mientras lo editaba. Revise lo registrado y vuelva a guardar.']);
                }
                if ($documento === null && ! $tipo->activo) {
                    throw ValidationException::withMessages(['tipo' => 'Ese documento está desactivado en la configuración de Comex.']);
                }

                $vigente = $documento?->archivoVigente()->lockForUpdate()->first();
                if ($valores['estado'] === Documento::LISTO) {
                    $this->exigirCompleto($tipo, $valores, $vigente !== null || $guardado !== null);
                }

                $documento ??= new Documento([
                    'embarque_id' => $embarque['id'],
                    'tipo_documento_id' => $tipo->id,
                    'version' => 0,
                ]);
                $nuevo = ! $documento->exists;
                $anteriores = $nuevo ? array_fill_keys(self::CAMPOS, null) : $this->valores($documento);
                $cambios = $this->diferencias($anteriores, $valores);
                $archivoNuevo = $guardado !== null && ($vigente === null || ! hash_equals($vigente->sha256, $guardado['sha256']));

                if ($cambios === [] && ! $archivoNuevo) {
                    // Nada cambió (doble clic o el mismo archivo otra vez): no se registra de nuevo.
                    return $documento;
                }

                $documento->fill([
                    ...$valores,
                    'embarque_codigo' => $embarque['codigo'],
                    'cliente_id' => $embarque['cliente_id'],
                    'version' => $documento->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                    'actualizado_por_nombre' => Str::limit($usuario->name, 150, ''),
                ])->save();

                if ($cambios !== []) {
                    $this->evento($documento, match (true) {
                        $nuevo => 'registrado',
                        isset($cambios['estado']) => 'estado',
                        default => 'datos',
                    }, $anteriores['estado'], $valores['estado'], ['cambios' => $cambios], $usuario);
                }

                if ($archivoNuevo) {
                    $ahora = now();
                    $vigente?->update(['reemplazado_at' => $ahora]);
                    $registro = ArchivoDocumento::query()->create([
                        'id' => $guardado['id'],
                        'documento_id' => $documento->id,
                        'ruta' => $guardado['ruta'],
                        'nombre_original' => $guardado['nombre'],
                        'mime' => $guardado['mime'],
                        'bytes' => $guardado['bytes'],
                        'sha256' => $guardado['sha256'],
                        'subido_por_user_id' => $usuario->id,
                        'subido_por_nombre' => Str::limit($usuario->name, 150, ''),
                        'subido_at' => $ahora,
                    ]);
                    $this->evento($documento, $vigente === null ? 'archivo_subido' : 'archivo_reemplazado', $valores['estado'], $valores['estado'], [
                        'archivo_id' => $registro->id,
                        'nombre' => $registro->nombre_original,
                        'bytes' => $registro->bytes,
                        'reemplaza_archivo_id' => $vigente?->id,
                    ], $usuario);
                }

                return $documento;
            });
        } catch (QueryException $error) {
            $this->descartar($guardado);
            // Dos personas registraron el mismo documento a la vez: gana la primera.
            if ((string) $error->getCode() === '23000') {
                throw ValidationException::withMessages(['version' => 'Otra persona registró este documento mientras lo editaba. Revise lo registrado y vuelva a guardar.']);
            }

            throw $error;
        } catch (Throwable $error) {
            $this->descartar($guardado);

            throw $error;
        } finally {
            // Un archivo idéntico al vigente no se guarda dos veces.
            if ($guardado !== null && ! ArchivoDocumento::query()->whereKey($guardado['id'])->exists()) {
                $this->descartar($guardado);
            }
        }
    }

    public function descargar(ArchivoDocumento $archivo, string $nombreBase): StreamedResponse
    {
        $disco = Storage::disk('local');
        if (! $disco->exists($archivo->ruta)) {
            throw ValidationException::withMessages(['archivo' => 'El respaldo no está disponible en el servidor.']);
        }

        return $disco->download($archivo->ruta, Str::slug($nombreBase).'.'.(self::EXTENSIONES[$archivo->mime] ?? 'pdf'), [
            'Content-Type' => $archivo->mime,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param  array<string, ?string>  $valores
     *
     * @throws ValidationException
     */
    private function exigirCompleto(TipoDocumento $tipo, array $valores, bool $conArchivo): void
    {
        $errores = [];
        if ($tipo->pide_numero && $valores['numero'] === null) {
            $errores['numero'] = 'Para marcarlo listo, ingrese el número del documento.';
        }
        if ($tipo->pide_fecha && $valores['fecha_emision'] === null) {
            $errores['fecha_emision'] = 'Para marcarlo listo, ingrese la fecha de emisión.';
        }
        if ($tipo->pide_archivo && ! $conArchivo) {
            $errores['archivo'] = 'Para marcarlo listo, suba el respaldo (PDF o imagen).';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** @return array{id: string, ruta: string, nombre: string, mime: string, bytes: int, sha256: string} */
    private function guardarArchivo(string $embarqueId, UploadedFile $archivo): array
    {
        $mime = (string) $archivo->getMimeType();
        $extension = self::EXTENSIONES[$mime] ?? null;
        $maximo = (int) config('comex.bytes_maximos_archivo', 10 * 1024 * 1024);
        if ($extension === null || $archivo->getSize() > $maximo) {
            throw ValidationException::withMessages([
                'archivo' => 'Suba el respaldo en PDF, JPG, PNG o WebP de hasta '.intdiv($maximo, 1024 * 1024).' MB.',
            ]);
        }

        $id = (string) Str::uuid();
        $directorio = 'comex/documentos/'.$embarqueId;
        if (! Storage::disk('local')->putFileAs($directorio, $archivo, $id.'.'.$extension)) {
            throw new RuntimeException('No fue posible guardar el respaldo del documento.');
        }

        return [
            'id' => $id,
            'ruta' => $directorio.'/'.$id.'.'.$extension,
            'nombre' => Str::limit($archivo->getClientOriginalName() ?: 'respaldo.'.$extension, 250, ''),
            'mime' => $mime,
            'bytes' => (int) $archivo->getSize(),
            'sha256' => (string) hash_file('sha256', $archivo->getRealPath()),
        ];
    }

    /** @param  array{ruta: string}|null  $guardado */
    private function descartar(?array $guardado): void
    {
        if ($guardado !== null) {
            Storage::disk('local')->delete($guardado['ruta']);
        }
    }

    /** @return array<string, ?string> */
    private function valores(Documento $documento): array
    {
        return [
            'estado' => $documento->estado,
            'numero' => $documento->numero,
            'fecha_emision' => $documento->fecha_emision?->toDateString(),
            'observacion' => $documento->observacion,
            'motivo_no_aplica' => $documento->motivo_no_aplica,
        ];
    }

    /**
     * @param  array<string, ?string>  $antes
     * @param  array<string, ?string>  $despues
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    private function diferencias(array $antes, array $despues): array
    {
        $cambios = [];
        foreach (self::CAMPOS as $campo) {
            if (($antes[$campo] ?? null) !== ($despues[$campo] ?? null)) {
                $cambios[$campo] = [$antes[$campo] ?? null, $despues[$campo] ?? null];
            }
        }

        return $cambios;
    }

    /** @param  array<string, mixed>  $datos */
    private function evento(Documento $documento, string $tipo, ?string $anterior, ?string $nuevo, array $datos, User $usuario): void
    {
        EventoDocumento::query()->create([
            'documento_id' => $documento->id,
            'tipo' => $tipo,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'datos' => $datos,
            'user_id' => $usuario->id,
            'user_nombre' => Str::limit($usuario->name, 150, ''),
        ]);
    }

    private function limpio(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
