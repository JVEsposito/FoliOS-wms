<?php

namespace App\Console\Commands;

use App\Models\Temporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

final class ProbarActivacionesTemporadaConcurrentes extends Command
{
    protected $signature = 'temporadas:probar-activaciones
        {temporada_a : UUID de una temporada preparada en el servidor de pruebas}
        {temporada_b : UUID de otra temporada preparada en el servidor de pruebas}
        {--confirmar-entorno-pruebas : Permite alternar la temporada vigente en este entorno}
        {--escenario=activar-activar : activar-activar o guardar-activar}
        {--trabajador= : Interno: temporada que activa el subproceso}
        {--inicio= : Interno: archivo barrera de sincronización}
        {--codigo-nueva= : Interno: código de la temporada creada}
        {--prefijo-nuevo= : Interno: prefijo documental de la temporada creada}
        {--inicio-nueva= : Interno: inicio de la temporada creada}
        {--fin-nueva= : Interno: término de la temporada creada}';

    protected $description = 'Prueba activar contra activar o guardar una temporada activa contra activar';

    public function handle(ServicioTemporadaGlobal $servicio): int
    {
        if (app()->environment('production') || ! $this->option('confirmar-entorno-pruebas')) {
            $this->components->error('Disponible solo fuera de producción con --confirmar-entorno-pruebas. Cambia la temporada activa.');

            return self::FAILURE;
        }

        $ids = [(string) $this->argument('temporada_a'), (string) $this->argument('temporada_b')];
        $escenario = (string) $this->option('escenario');
        if (! in_array($escenario, ['activar-activar', 'guardar-activar'], true)) {
            $this->components->error('Escenario inválido: usa activar-activar o guardar-activar.');

            return self::FAILURE;
        }
        if ($ids[0] === $ids[1] || Temporada::query()->whereKey($ids)->count() !== 2) {
            $this->components->error('Indica dos temporadas distintas y existentes.');

            return self::FAILURE;
        }

        $trabajador = $this->option('trabajador');
        if (is_string($trabajador)) {
            $permitido = in_array($trabajador, $ids, true)
                || ($escenario === 'guardar-activar' && $trabajador === 'guardar');
            if (! $permitido || ! is_string($this->option('inicio'))) {
                return self::FAILURE;
            }
            $inicio = (string) $this->option('inicio');
            if (file_put_contents($inicio.'-'.$trabajador.'.ready', 'listo') === false) {
                return self::FAILURE;
            }
            $espera = microtime(true) + 10;
            while (! file_exists($inicio) && microtime(true) < $espera) {
                usleep(10000);
            }
            if (! file_exists($inicio)) {
                $this->components->error('No llegó la señal de inicio.');

                return self::FAILURE;
            }

            if ($trabajador === 'guardar') {
                $campos = ['codigo-nueva', 'prefijo-nuevo', 'inicio-nueva', 'fin-nueva'];
                if (collect($campos)->contains(fn (string $campo): bool => ! is_string($this->option($campo)) || $this->option($campo) === '')) {
                    return self::FAILURE;
                }
                $creada = $servicio->guardar([
                    'codigo' => $this->option('codigo-nueva'),
                    'nombre' => 'Prueba de concurrencia '.$this->option('codigo-nueva'),
                    'prefijo_documental' => $this->option('prefijo-nuevo'),
                    'fecha_inicio' => $this->option('inicio-nueva'),
                    'fecha_fin' => $this->option('fin-nueva'),
                    'activa' => true,
                ]);
                $this->line('Creada y activada '.$creada->codigo);
            } else {
                $servicio->activar(Temporada::query()->findOrFail($trabajador));
                $this->line('Activada '.$trabajador);
            }

            return self::SUCCESS;
        }

        $opcionesNueva = [];
        $codigoNuevo = null;
        if ($escenario === 'guardar-activar') {
            if (app(ServicioTemporadaActiva::class)->buscar()?->id !== $ids[0]) {
                $this->components->error('Primero activa la temporada A; el escenario la usa como punto de partida y permite restaurarla al terminar.');

                return self::FAILURE;
            }

            $ultimaFecha = Temporada::query()->productivas()->whereNotNull('fecha_fin')->max('fecha_fin');
            $inicioNueva = CarbonImmutable::parse($ultimaFecha ?? now())->addYear()->startOfYear();
            if ($inicioNueva->year >= 9999) {
                $this->components->error('No queda una fecha válida para crear otra temporada productiva.');

                return self::FAILURE;
            }

            do {
                $codigoNuevo = 'CONC-'.strtoupper(Str::random(10));
            } while (Temporada::query()->where('codigo', $codigoNuevo)->exists());
            do {
                $prefijoNuevo = 'C'.strtoupper(Str::random(5));
            } while (Temporada::query()->where('prefijo_documental', $prefijoNuevo)->exists());

            $opcionesNueva = [
                '--codigo-nueva='.$codigoNuevo,
                '--prefijo-nuevo='.$prefijoNuevo,
                '--inicio-nueva='.$inicioNueva->toDateString(),
                '--fin-nueva='.$inicioNueva->addYear()->subDay()->toDateString(),
            ];
            $this->components->warn("Se creará la temporada productiva {$codigoNuevo} de forma persistente en este servidor de pruebas.");
        }

        $barrera = sys_get_temp_dir().'/temporadas-activacion-'.Str::uuid();
        $procesos = [];
        $marcas = [];
        try {
            $trabajadores = $escenario === 'guardar-activar' ? ['guardar', $ids[1]] : $ids;
            foreach ($trabajadores as $id) {
                $marcas[] = $barrera.'-'.$id.'.ready';
                $proceso = new Process([
                    PHP_BINARY, base_path('artisan'), $this->getName(), ...$ids,
                    '--confirmar-entorno-pruebas', '--escenario='.$escenario,
                    '--trabajador='.$id, '--inicio='.$barrera, ...$opcionesNueva,
                ], base_path());
                $proceso->setTimeout(30);
                $proceso->start();
                $procesos[] = $proceso;
            }
            $espera = microtime(true) + 10;
            while (count(array_filter($marcas, 'is_file')) !== count($marcas) && microtime(true) < $espera) {
                usleep(10000);
            }
            if (count(array_filter($marcas, 'is_file')) !== count($marcas)) {
                $this->components->error('Uno de los procesos no llegó a la barrera; la prueba de concurrencia no se ejecutó.');
                foreach ($procesos as $proceso) {
                    $proceso->stop(1);
                    $this->line(trim($proceso->getOutput().$proceso->getErrorOutput()));
                }

                return self::FAILURE;
            }
            file_put_contents($barrera, 'inicio');

            foreach ($procesos as $proceso) {
                $proceso->wait();
                $this->line(trim($proceso->getOutput().$proceso->getErrorOutput()));
            }
        } finally {
            @unlink($barrera);
            foreach ($marcas as $marca) {
                @unlink($marca);
            }
        }

        $activa = app(ServicioTemporadaActiva::class)->buscar(bloquear: true);
        $cantidad = Temporada::query()->where('activa', true)->count();
        $idNuevo = $codigoNuevo === null ? null : Temporada::query()->where('codigo', $codigoNuevo)->value('id');
        $finalesValidos = $escenario === 'guardar-activar' ? [$ids[1], $idNuevo] : $ids;
        $correcto = collect($procesos)->every(fn (Process $proceso): bool => $proceso->isSuccessful())
            && $cantidad === 1 && ($escenario !== 'guardar-activar' || $idNuevo !== null)
            && in_array($activa?->id, $finalesValidos, true);
        $this->line("Temporadas activas: {$cantidad}; final: ".($activa?->codigo ?? 'ninguna'));

        return $correcto ? self::SUCCESS : self::FAILURE;
    }
}
