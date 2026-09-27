<?php

namespace App\Console\Commands;

use App\Models\Temporada;
use App\Services\Temporadas\Cierre\ServicioDiagnosticoCierreTemporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Console\Command;

final class DiagnosticarCierreTemporada extends Command
{
    protected $signature = 'temporadas:diagnosticar
        {temporada? : Código o identificador; por defecto, la temporada activa}
        {--detalle : Lista cada registro pendiente}
        {--json : Entrega el diagnóstico completo en JSON}';

    protected $description = 'Lista lo que una temporada deja sin cerrar (solo lectura; Materiales no participa)';

    public function handle(ServicioDiagnosticoCierreTemporada $servicio): int
    {
        $argumento = $this->argument('temporada');
        $temporada = $argumento === null
            ? app(ServicioTemporadaActiva::class)->buscar()
            : Temporada::query()->where('codigo', mb_strtoupper($argumento))->orWhere('id', $argumento)->first();

        if (! $temporada) {
            $this->components->error($argumento === null ? 'No hay una temporada activa.' : 'No existe esa temporada.');

            return self::FAILURE;
        }

        $detalle = $this->option('detalle') || $this->option('json');
        $diagnostico = $servicio->diagnosticar($temporada, $detalle ? PHP_INT_MAX : 0);

        if ($this->option('json')) {
            $this->line(json_encode($diagnostico, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $diagnostico['total'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info(sprintf(
            'Temporada %s · %s%s',
            $temporada->codigo,
            $temporada->tipo->value,
            $temporada->activa ? ' · activa' : '',
        ));
        $this->table(
            ['Categoría', 'Pendientes', 'Regularizados', 'Cómo cerrar'],
            array_map(fn (array $c): array => [
                $c['etiqueta'],
                $c['aplica'] ? $c['total'] : 'no aplica',
                $c['regularizados'],
                $c['como_cerrar'],
            ], $diagnostico['categorias']),
        );

        if ($detalle) {
            foreach ($diagnostico['categorias'] as $categoria) {
                if ($categoria['items'] === []) {
                    continue;
                }
                $this->newLine();
                $this->line("<options=bold>{$categoria['etiqueta']}</>");
                $this->table(
                    ['Referencia', 'Detalle', 'Responsable', 'Fecha'],
                    array_map(fn (array $item): array => [
                        $item['referencia'],
                        $item['detalle'],
                        $item['responsable']['nombre'] ?? '—',
                        $item['fecha'] ? substr($item['fecha'], 0, 16) : '—',
                    ], $categoria['items']),
                );
            }
        }

        if ($diagnostico['total'] === 0) {
            $this->components->info('No quedan registros sin cerrar.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            'Quedan %d registros sin cerrar. Ciérralos en su módulo o regularízalos en Accesos, pestaña Temporadas, acción Cierre.',
            $diagnostico['total'],
        ));

        return self::FAILURE;
    }
}
