<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

final class AsignarTemaStitch extends Command
{
    protected $signature = 'oficina:tema-stitch {usuarios?* : Correos o IDs de las únicas cuentas autorizadas} {--retirar : Retirar todas las asignaciones de Stitch}';

    protected $description = 'Asigna Stitch exclusivamente a las cuentas indicadas, conservando los demás temas';

    public function handle(): int
    {
        $referencias = array_values(array_unique(array_map('trim', $this->argument('usuarios'))));
        if (($this->option('retirar') && $referencias !== []) || (! $this->option('retirar') && $referencias === [])) {
            $this->error('Indica las cuentas autorizadas o usa --retirar sin cuentas.');

            return self::FAILURE;
        }

        $usuarios = [];
        foreach ($referencias as $referencia) {
            $usuario = ctype_digit($referencia)
                ? User::find($referencia)
                : User::whereRaw('LOWER(email) = ?', [mb_strtolower($referencia)])->first();
            if (! $usuario || ! $usuario->activo || $usuario->esCliente()
                || ($usuario->perfilAcceso && ! $usuario->perfilAcceso->activo)) {
                $this->error("La cuenta {$referencia} no existe o no es una cuenta interna activa.");

                return self::FAILURE;
            }
            $usuarios[$usuario->id] = $usuario;
        }

        if (! $this->option('retirar')) {
            foreach (['personal-stitch.css', 'personal-stitch.png', 'personal-stitch-juego.png',
                'personal-stitch-saludo.png', 'personal-stitch-asomado.png',
                'personal-stitch-descanso.png', 'personal-stitch-salto.png'] as $archivo) {
                if (! is_readable(resource_path('temas-oficina/'.$archivo))) {
                    $this->error('Falta un recurso del tema Stitch: '.$archivo);

                    return self::FAILURE;
                }
            }
        }

        $directorio = Storage::disk('local')->path('temas-oficina');
        File::ensureDirectoryExists($directorio);
        $bloqueo = fopen($directorio.'/catalogo.lock', 'c');
        if ($bloqueo === false || ! flock($bloqueo, LOCK_EX)) {
            if (is_resource($bloqueo)) {
                fclose($bloqueo);
            }
            $this->error('No se pudo bloquear el catálogo de temas. No se modificó.');

            return self::FAILURE;
        }

        try {
            $ruta = $directorio.'/catalogo.json';
            $anterior = is_file($ruta) ? File::get($ruta) : null;
            try {
                $catalogo = $anterior === null ? [] : json_decode($anterior, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->error('El catálogo existente no es JSON válido. No se modificó.');

                return self::FAILURE;
            }
            if (! is_array($catalogo) || ($catalogo !== [] && array_is_list($catalogo))) {
                $this->error('El catálogo existente no es un objeto de temas. No se modificó.');

                return self::FAILURE;
            }

            if ($this->option('retirar')) {
                unset($catalogo['personal-stitch']);
            } else {
                $catalogo['personal-stitch'] = [
                    'nombre' => 'Stitch · Experimento 626', 'color' => '#081a32', 'claro' => false,
                    'usuario_ids' => array_map('strval', array_keys($usuarios)),
                ];
            }
            $contenido = json_encode((object) $catalogo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            if ($anterior !== null) {
                File::ensureDirectoryExists($directorio.'/respaldos');
                File::replace($directorio.'/respaldos/catalogo-'.now()->format('Ymd_His_u').'-'.Str::random(6).'.json', $anterior);
            }
            File::replace($ruta, $contenido);
        } finally {
            flock($bloqueo, LOCK_UN);
            fclose($bloqueo);
        }

        $this->info($this->option('retirar') ? 'Stitch retirado para todas las cuentas.' : 'Stitch habilitado exclusivamente para:');
        foreach ($usuarios as $usuario) {
            $this->line($usuario->email.' · '.$usuario->id);
        }
        $this->line('Los demás temas, perfiles y permisos se conservan. Recarga Oficina para actualizar el selector.');

        return self::SUCCESS;
    }
}
