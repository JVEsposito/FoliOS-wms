<?php

namespace App\Services\ControlDespacho;

use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\ControlDespacho;
use App\Models\FotoControlDespacho;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Materiales\GeneradorMiniaturaItemMaterial;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Fotos de la planilla R-24. Hoy son opcionales; el set estándar se define en
 * {@see FormularioControlDespacho::tiposFoto()}. Se guardan en el disco privado.
 */
class ServicioFotosControlDespacho
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly GeneradorMiniaturaItemMaterial $miniaturas,
    ) {}

    /** @return array{0: FotoControlDespacho, 1: bool} */
    public function subir(ControlDespacho $control, UploadedFile $archivo, string $tipo, string $operacion, User $usuario): array
    {
        if (! $this->alcance->puedeRegistrarControlDespacho($usuario)) {
            throw new OperacionNoAutorizada('El usuario no está autorizado para registrar la planilla de consolidación.');
        }
        $tipos = FormularioControlDespacho::tiposFoto();
        $mime = $archivo->getMimeType();
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $ext || ! isset($tipos[$tipo]) || $archivo->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['archivo' => 'Usa JPG, PNG o WebP de hasta 5 MiB.']);
        }
        $sha = hash_file('sha256', $archivo->getRealPath());
        $existente = $this->repetida($control, $operacion, $tipo, $sha);
        if ($existente) {
            return [$existente, false];
        }

        $id = (string) Str::uuid();
        $miniatura = $this->miniaturas->generar($archivo);
        $base = 'control-despacho/'.$control->id;
        $original = $base.'/'.$id.'.'.$ext;
        $rutaMiniatura = $base.'/'.$id.'-mini.jpg';
        $guardada = false;
        try {
            if (! Storage::disk('local')->putFileAs($base, $archivo, basename($original)) || ! Storage::disk('local')->put($rutaMiniatura, $miniatura['jpeg'])) {
                throw new RuntimeException('No se pudieron guardar las fotos del despacho.');
            }
            $foto = DB::transaction(function () use ($control, $tipo, $tipos, $operacion, $usuario, $sha, $id, $mime, $archivo, $miniatura, $original, $rutaMiniatura): FotoControlDespacho {
                $bloqueado = ControlDespacho::query()->lockForUpdate()->findOrFail($control->id);
                if ($bloqueado->estaCerrado()) {
                    throw new DomainException('La planilla está cerrada: no admite fotos nuevas.');
                }
                $activas = FotoControlDespacho::query()->where('control_despacho_id', $bloqueado->id)->where('tipo', $tipo)->lockForUpdate()->count();
                if ($activas >= $tipos[$tipo]['maximo']) {
                    throw ValidationException::withMessages(['archivo' => "Se admiten hasta {$tipos[$tipo]['maximo']} fotos de «{$tipos[$tipo]['nombre']}»."]);
                }
                $orden = (int) FotoControlDespacho::withTrashed()->where('control_despacho_id', $bloqueado->id)->where('tipo', $tipo)->max('orden') + 1;

                return FotoControlDespacho::create([
                    'id' => $id, 'control_despacho_id' => $bloqueado->id, 'tipo' => $tipo, 'orden' => $orden,
                    'ruta_original' => $original, 'ruta_miniatura' => $rutaMiniatura, 'mime' => $mime,
                    'bytes' => $archivo->getSize(), 'ancho' => $miniatura['ancho'], 'alto' => $miniatura['alto'],
                    'sha256' => $sha, 'operacion_id' => $operacion, 'subida_por_user_id' => $usuario->id,
                ]);
            }, 3);
            $guardada = true;

            return [$foto, true];
        } catch (UniqueConstraintViolationException $error) {
            return [$this->repetida($control, $operacion, $tipo, $sha) ?? throw $error, false];
        } finally {
            if (! $guardada) {
                Storage::disk('local')->delete([$original, $rutaMiniatura]);
            }
        }
    }

    public function eliminar(ControlDespacho $control, FotoControlDespacho $foto, User $usuario): void
    {
        if (! $this->alcance->puedeRegistrarControlDespacho($usuario)) {
            throw new OperacionNoAutorizada('El usuario no está autorizado para registrar la planilla de consolidación.');
        }
        DB::transaction(function () use ($control, $foto, $usuario): void {
            $bloqueado = ControlDespacho::query()->lockForUpdate()->findOrFail($control->id);
            if ($bloqueado->estaCerrado()) {
                throw new DomainException('La planilla está cerrada: sus fotos ya no se quitan.');
            }
            $fila = FotoControlDespacho::query()->where('control_despacho_id', $bloqueado->id)->whereKey($foto->id)->lockForUpdate()->firstOrFail();
            // Una foto de un borrador se reemplaza sin dejar rastro; queda la fila eliminada.
            $fila->update(['eliminada_por_user_id' => $usuario->id]);
            $fila->delete();
        }, 3);
    }

    private function repetida(ControlDespacho $control, string $operacion, string $tipo, string $sha): ?FotoControlDespacho
    {
        $existente = FotoControlDespacho::withTrashed()->where('operacion_id', $operacion)->first();
        if (! $existente) {
            return null;
        }
        if ($existente->control_despacho_id !== $control->id || $existente->tipo !== $tipo || ! hash_equals($existente->sha256, $sha)) {
            throw new ConflictoOperacion('El operacion_id ya fue utilizado con otra foto.');
        }

        return $existente;
    }
}
