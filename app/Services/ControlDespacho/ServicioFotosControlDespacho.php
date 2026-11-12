<?php

namespace App\Services\ControlDespacho;

use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\ControlDespacho;
use App\Models\FotoControlDespacho;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Fotos de la planilla R-24 (set en {@see FormularioControlDespacho::tiposFoto()}). Se guardan
 * comprimidas en el disco privado mientras la planilla está abierta; al cerrarla pasan a un
 * único PDF ({@see ArchivoFotosControlDespacho}) y sus archivos sueltos se borran.
 */
class ServicioFotosControlDespacho
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly CompresorFotoControlDespacho $compresor,
    ) {}

    /** @return array{0: FotoControlDespacho, 1: bool} */
    public function subir(ControlDespacho $control, UploadedFile $archivo, string $tipo, string $operacion, User $usuario): array
    {
        if (! $this->alcance->puedeRegistrarControlDespacho($usuario)) {
            throw new OperacionNoAutorizada('El usuario no está autorizado para registrar la planilla de consolidación.');
        }
        $tipos = FormularioControlDespacho::tiposFoto();
        if (! in_array($archivo->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || ! isset($tipos[$tipo]) || $archivo->getSize() > 8 * 1024 * 1024) {
            throw ValidationException::withMessages(['archivo' => 'Usa JPG, PNG o WebP de hasta 8 MiB.']);
        }
        $sha = hash_file('sha256', $archivo->getRealPath());
        $existente = $this->repetida($control, $operacion, $tipo, $sha);
        if ($existente) {
            return [$existente, false];
        }

        $id = (string) Str::uuid();
        $comprimida = $this->compresor->comprimir($archivo);
        $base = 'control-despacho/'.$control->id;
        $ruta = $base.'/'.$id.'.jpg';
        $rutaMiniatura = $base.'/'.$id.'-mini.jpg';
        $guardada = false;
        try {
            if (! Storage::disk('local')->put($ruta, $comprimida['jpeg']) || ! Storage::disk('local')->put($rutaMiniatura, $comprimida['miniatura'])) {
                throw new RuntimeException('No se pudieron guardar las fotos del despacho.');
            }
            $foto = DB::transaction(function () use ($control, $tipo, $tipos, $operacion, $usuario, $sha, $id, $archivo, $comprimida, $ruta, $rutaMiniatura): FotoControlDespacho {
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
                    'ruta' => $ruta, 'ruta_miniatura' => $rutaMiniatura,
                    'bytes' => strlen($comprimida['jpeg']), 'bytes_recibidos' => (int) $archivo->getSize(),
                    'ancho' => $comprimida['ancho'], 'alto' => $comprimida['alto'],
                    'sha256' => $sha, 'operacion_id' => $operacion, 'subida_por_user_id' => $usuario->id,
                ]);
            }, 3);
            $guardada = true;

            return [$foto, true];
        } catch (UniqueConstraintViolationException $error) {
            return [$this->repetida($control, $operacion, $tipo, $sha) ?? throw $error, false];
        } finally {
            if (! $guardada) {
                Storage::disk('local')->delete([$ruta, $rutaMiniatura]);
            }
        }
    }

    public function eliminar(ControlDespacho $control, FotoControlDespacho $foto, User $usuario): void
    {
        if (! $this->alcance->puedeRegistrarControlDespacho($usuario)) {
            throw new OperacionNoAutorizada('El usuario no está autorizado para registrar la planilla de consolidación.');
        }
        $archivos = [];
        DB::transaction(function () use ($control, $foto, $usuario, &$archivos): void {
            $bloqueado = ControlDespacho::query()->lockForUpdate()->findOrFail($control->id);
            if ($bloqueado->estaCerrado()) {
                throw new DomainException('La planilla está cerrada: sus fotos ya no se quitan.');
            }
            $fila = FotoControlDespacho::query()->where('control_despacho_id', $bloqueado->id)->whereKey($foto->id)->lockForUpdate()->firstOrFail();
            // La fila queda como registro de quién la quitó; su archivo se borra. Una foto ya
            // archivada sale del PDF cuando la planilla se vuelva a cerrar.
            $archivos = array_filter([$fila->ruta, $fila->ruta_miniatura]);
            $fila->update(['eliminada_por_user_id' => $usuario->id, 'ruta' => null, 'ruta_miniatura' => null]);
            $fila->delete();
        }, 3);
        Storage::disk('local')->delete(array_values($archivos));
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
