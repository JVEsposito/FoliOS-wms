<?php

namespace App\Console\Commands;

use App\Models\Temporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

final class ProbarActivacionesTemporadaConcurrentes extends Command
{
    protected $signature = 'temporadas:probar-activaciones
        {temporada_a : UUID de una temporada preparada en el servidor de pruebas}
        {temporada_b : UUID de otra temporada preparada en el servidor de pruebas}
        {--confirmar-entorno-pruebas : Permite alternar la temporada vigente en este entorno}
        {--trabajador= : Interno: temporada que activa el subproceso}
        {--inicio= : Interno: archivo barrera de sincronización}';

    protected $description = 'Prueba dos activaciones simultáneas en el servidor de pruebas';

    public function handle(ServicioTemporadaGlobal $servicio): int
    {
        if (app()->environment('production') || ! $this->option('confirmar-entorno-pruebas')) {
            $this->components->error('Disponible solo fuera de producción con --confirmar-entorno-pruebas. Cambia la temporada activa.');

            return self::FAILURE;
        }

        $ids = [(string) $this->argument('temporada_a'), (string) $this->argument('temporada_b')];
        if ($ids[0] === $ids[1] || Temporada::query()->whereKey($ids)->count() !== 2) {
            $this->components->error('Indica dos temporadas distintas y existentes.');

            return self::FAILURE;
        }

        $trabajador = $this->option('trabajador');
        if (is_string($trabajador)) {
            if (! in_array($trabajador, $ids, true) || ! is_string($this->option('inicio'))) {
                return self::FAILURE;
            }
            $inicio = (string) $this->option('inicio');
            $espera = microtime(true) + 10;
            while (! file_exists($inicio) && microtime(true) < $espera) {
                usleep(10000);
            }
            if (! file_exists($inicio)) {
                $this->components->error('No llegó la señal de inicio.');

                return self::FAILURE;
            }

            $servicio->activar(Temporada::query()->findOrFail($trabajador));
            $this->line('Activada '.$trabajador);

            return self::SUCCESS;
        }

        $barrera = sys_get_temp_dir().'/temporadas-activacion-'.Str::uuid();
        $procesos = [];
        try {
            foreach ($ids as $id) {
                $proceso = new Process([
                    PHP_BINARY, base_path('artisan'), $this->getName(), ...$ids,
                    '--confirmar-entorno-pruebas', '--trabajador='.$id, '--inicio='.$barrera,
                ], base_path());
                $proceso->setTimeout(30);
                $proceso->start();
                $procesos[] = $proceso;
            }
            file_put_contents($barrera, 'inicio');

            foreach ($procesos as $proceso) {
                $proceso->wait();
                $this->line(trim($proceso->getOutput().$proceso->getErrorOutput()));
            }
        } finally {
            @unlink($barrera);
        }

        $activa = app(ServicioTemporadaActiva::class)->buscar(bloquear: true);
        $cantidad = Temporada::query()->where('activa', true)->count();
        $correcto = collect($procesos)->every(fn (Process $proceso): bool => $proceso->isSuccessful())
            && $cantidad === 1 && in_array($activa?->id, $ids, true);
        $this->line("Temporadas activas: {$cantidad}; final: ".($activa?->codigo ?? 'ninguna'));

        return $correcto ? self::SUCCESS : self::FAILURE;
    }
}
