<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\ArchivoGuiaDespachoEnvase;
use App\Models\EventoGuiaDespachoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La guía de despacho tributaria se emite en el sistema de facturación. FoliOS registra su
 * número (pedido al confirmar la salida) y guarda el archivo escaneado como respaldo.
 * El GDE-… de FoliOS sigue siendo la referencia interna de la reserva y la trazabilidad.
 */
class ServicioGuiaEmitidaEnvases
{
    public const BYTES_MAXIMOS = 10 * 1024 * 1024;

    private const EXTENSIONES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Quita espacios, puntos de miles y ceros a la izquierda: «0012.345» y «12345» son el
     * mismo folio. Devuelve null si no queda un número de guía válido.
     */
    public function normalizar(?string $numero): ?string
    {
        $limpio = strtoupper(str_replace([' ', '.'], '', trim((string) $numero)));
        if (ctype_digit($limpio)) {
            $limpio = ltrim($limpio, '0');
        }

        return preg_match('/^[0-9A-Z][0-9A-Z-]{0,19}$/', $limpio) === 1 ? $limpio : null;
    }

    /** Normaliza o rechaza con un mensaje para el campo del formulario. */
    public function exigir(?string $numero, string $campo = 'numero_guia_despacho'): string
    {
        $normalizado = $this->normalizar($numero);
        if ($normalizado === null) {
            throw ValidationException::withMessages([
                $campo => 'Ingresa el N° de la guía de despacho emitida, solo el número (por ejemplo, 12345).',
            ]);
        }

        return $normalizado;
    }

    /** Un mismo folio no puede quedar en dos guías de FoliOS. */
    public function asegurarDisponible(string $numero, string $guiaId): void
    {
        $otra = GuiaDespachoEnvase::query()
            ->where('numero_guia_despacho', $numero)
            ->whereKeyNot($guiaId)
            ->first(['id', 'numero']);
        if ($otra) {
            throw ValidationException::withMessages([
                'numero_guia_despacho' => "La guía de despacho N° {$numero} ya está registrada en {$otra->numero}.",
            ]);
        }
    }

    /** Registra el número en una guía ya confirmada (historial) o lo corrige con motivo. */
    public function registrarNumero(GuiaDespachoEnvase $guia, string $numero, ?string $motivo, User $usuario): GuiaDespachoEnvase
    {
        $numero = $this->exigir($numero);

        return DB::transaction(function () use ($guia, $numero, $motivo, $usuario): GuiaDespachoEnvase {
            $guia = GuiaDespachoEnvase::query()->lockForUpdate()->findOrFail($guia->id);
            $this->asegurarSalidaConfirmada($guia, 'El N° de guía se ingresa al confirmar la salida.');
            $anterior = $guia->numero_guia_despacho;
            if ($anterior === $numero) {
                return $guia;
            }
            $motivo = trim((string) $motivo);
            if ($anterior !== null && mb_strlen($motivo) < 5) {
                throw ValidationException::withMessages([
                    'motivo' => 'Indica por qué se corrige el N° de guía.',
                ]);
            }
            $this->asegurarDisponible($numero, $guia->id);

            $guia->update([
                'numero_guia_despacho' => $numero,
                'version' => $guia->version + 1,
            ]);
            $this->evento($guia, $anterior === null ? 'numero_guia_registrado' : 'numero_guia_corregido', $usuario, [
                'anterior' => $anterior,
                'nuevo' => $numero,
                'motivo' => $motivo !== '' ? $motivo : null,
            ]);

            return $guia;
        }, attempts: 3);
    }

    /** Sube el archivo de la guía emitida. Reemplazar deja el anterior en el historial. */
    public function subirArchivo(GuiaDespachoEnvase $guia, UploadedFile $archivo, User $usuario): ArchivoGuiaDespachoEnvase
    {
        $mime = (string) $archivo->getMimeType();
        $extension = self::EXTENSIONES[$mime] ?? null;
        if ($extension === null || $archivo->getSize() > self::BYTES_MAXIMOS) {
            throw ValidationException::withMessages([
                'archivo' => 'Sube la guía en PDF, JPG, PNG o WebP de hasta 10 MB.',
            ]);
        }
        $sha = hash_file('sha256', $archivo->getRealPath());
        $id = (string) Str::uuid();
        $directorio = 'envases/guias-despacho/'.$guia->id;
        $ruta = $directorio.'/'.$id.'.'.$extension;
        if (! Storage::disk('local')->putFileAs($directorio, $archivo, $id.'.'.$extension)) {
            throw new RuntimeException('No fue posible guardar el archivo de la guía.');
        }

        try {
            return DB::transaction(function () use ($guia, $archivo, $usuario, $mime, $sha, $id, $ruta): ArchivoGuiaDespachoEnvase {
                $guia = GuiaDespachoEnvase::query()->lockForUpdate()->findOrFail($guia->id);
                $this->asegurarSalidaConfirmada($guia, 'El archivo de la guía se sube después de confirmar la salida.');
                $vigente = $guia->archivoVigente()->lockForUpdate()->first();
                if ($vigente && hash_equals($vigente->sha256, $sha)) {
                    // El mismo archivo dos veces (doble clic o reintento): no se duplica.
                    Storage::disk('local')->delete($ruta);

                    return $vigente;
                }
                $ahora = now();
                $vigente?->update(['reemplazado_at' => $ahora]);
                $nuevo = ArchivoGuiaDespachoEnvase::create([
                    'id' => $id,
                    'guia_despacho_envase_id' => $guia->id,
                    'ruta' => $ruta,
                    'nombre_original' => Str::limit($archivo->getClientOriginalName() ?: 'guia.'.$archivo->extension(), 250, ''),
                    'mime' => $mime,
                    'bytes' => (int) $archivo->getSize(),
                    'sha256' => $sha,
                    'subido_por_user_id' => $usuario->id,
                    'subido_at' => $ahora,
                ]);
                $this->evento($guia, $vigente ? 'archivo_guia_reemplazado' : 'archivo_guia_subido', $usuario, [
                    'archivo_id' => $nuevo->id,
                    'nombre' => $nuevo->nombre_original,
                    'sha256' => $sha,
                    'bytes' => $nuevo->bytes,
                    'reemplaza_archivo_id' => $vigente?->id,
                ]);

                return $nuevo;
            }, attempts: 3);
        } catch (\Throwable $error) {
            if (! ArchivoGuiaDespachoEnvase::query()->whereKey($id)->exists()) {
                Storage::disk('local')->delete($ruta);
            }
            throw $error;
        }
    }

    public function descargarArchivo(GuiaDespachoEnvase $guia): StreamedResponse
    {
        $archivo = $guia->archivoVigente()->first();
        if (! $archivo || ! Storage::disk('local')->exists($archivo->ruta)) {
            throw new ConflictoOperacion('La guía no tiene un archivo de respaldo subido.');
        }
        $nombre = 'guia-despacho-'.Str::slug($guia->numero_guia_despacho ?: $guia->numero)
            .'.'.(self::EXTENSIONES[$archivo->mime] ?? 'pdf');

        return Storage::disk('local')->download($archivo->ruta, $nombre, [
            'Content-Type' => $archivo->mime,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function asegurarSalidaConfirmada(GuiaDespachoEnvase $guia, string $mensaje): void
    {
        if (! in_array($guia->estado, [EstadoGuiaDespachoEnvase::Confirmada, EstadoGuiaDespachoEnvase::Anulada], true)) {
            throw new ConflictoOperacion($mensaje);
        }
    }

    /** @param array<string, mixed> $datos */
    private function evento(GuiaDespachoEnvase $guia, string $tipo, User $usuario, array $datos): void
    {
        EventoGuiaDespachoEnvase::create([
            'guia_despacho_envase_id' => $guia->id,
            'tipo' => $tipo,
            'estado_anterior' => $guia->estado->value,
            'estado_nuevo' => $guia->estado->value,
            'user_id' => $usuario->id,
            'ocurrido_at' => now(),
            'datos' => $datos,
        ]);
    }
}
