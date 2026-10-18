<?php

namespace App\Finanzas\Console\Commands;

use App\Finanzas\Captura\CapturaEnCurso;
use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Captura\ServicioCapturaHechos;
use App\Finanzas\Models\Lectura;
use Illuminate\Console\Command;

class CapturarHechosFinanzas extends Command
{
    protected $signature = 'finanzas:capturar-hechos
        {--fuente=* : Leer solo estas fuentes (por defecto, todas)}
        {--conciliar : Revisar además que los hechos vigentes sigan existiendo en la operación}
        {--programada : Lectura del programador de tareas (respeta FINANZAS_CAPTURA_HABILITADA)}';

    protected $description = 'Captura en Finanzas los hechos valorizables de la operación (solo lee la operación).';

    public function handle(ServicioCapturaHechos $captura, CatalogoFuentes $fuentes): int
    {
        $programada = (bool) $this->option('programada');
        if ($programada && ! config('finanzas.captura.habilitada')) {
            $this->components->info('La captura programada de Finanzas está pausada (FINANZAS_CAPTURA_HABILITADA=false).');

            return self::SUCCESS;
        }

        try {
            $lectura = $captura->capturar(
                origen: $programada ? Lectura::ORIGEN_PROGRAMADA : Lectura::ORIGEN_CONSOLA,
                fuentes: $this->option('fuente') ?: null,
                conciliar: (bool) $this->option('conciliar'),
            );
        } catch (CapturaEnCurso $enCurso) {
            $this->components->warn($enCurso->getMessage());

            return self::SUCCESS;
        }

        $nombres = array_map(fn ($fuente): string => $fuente->nombre(), $fuentes->todas());
        $this->table(
            ['Fuente', 'Leídos', 'Nuevos', 'Versiones', 'Anulados', 'Eliminados', 'Sin cambios', 'ms'],
            collect($lectura->resumen ?? [])->map(fn (array $conteo, string $clave): array => isset($conteo['error'])
                ? [$nombres[$clave] ?? $clave, 'Error: '.$conteo['error'], '', '', '', '', '', '']
                : [$nombres[$clave] ?? $clave, $conteo['leidos'], $conteo['nuevos'], $conteo['versiones'], $conteo['anulados'], $conteo['eliminados'], $conteo['sin_cambios'], $conteo['duracion_ms']])
                ->values()->all(),
        );

        if ($lectura->estado !== Lectura::ESTADO_COMPLETADA) {
            $this->components->error($lectura->error ?? 'La captura terminó con errores.');

            return self::FAILURE;
        }

        $this->components->info("Captura {$lectura->id} completada en {$lectura->duracion_ms} ms.");

        return self::SUCCESS;
    }
}
