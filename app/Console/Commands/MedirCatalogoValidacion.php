<?php

namespace App\Console\Commands;

use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\DescargaPaginadaCatalogoValidacion;
use App\Services\Validacion\ServicioProyeccionCatalogoValidacion;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Diagnóstico de solo lectura; no reconstruye maestros, caché ni movimientos. */
class MedirCatalogoValidacion extends Command
{
    protected $signature = 'validacion:medir-catalogo
        {--paginas=3 : Máximo por colección; 0 recorre todas las páginas}
        {--json : Entrega el informe como JSON}';

    protected $description = 'Mide filas, bytes, tiempo y memoria PHP de la descarga paginada del catálogo activo';

    public function handle(ServicioTemporadaActiva $temporadas, ServicioProyeccionCatalogoValidacion $proyeccion, DescargaPaginadaCatalogoValidacion $descarga): int
    {
        $limite = filter_var($this->option('paginas'), FILTER_VALIDATE_INT);
        if ($limite === false || $limite < 0) {
            $this->error('Indica --paginas como entero mayor o igual a cero.');

            return self::INVALID;
        }
        $temporada = $temporadas->obtener();
        $conteos = $proyeccion->conteos($temporada);
        $filas = [];
        try {
            foreach (DescargaPaginadaCatalogoValidacion::COLECCIONES as $coleccion) {
                $cursor = null;
                $medida = ['coleccion' => $coleccion, 'paginas' => 0, 'filas' => 0, 'max_filas_pagina' => 0,
                    'max_bytes_pagina' => 0, 'max_memoria_php_bytes' => 0, 'max_ms_pagina' => 0, 'completa' => false];
                do {
                    memory_reset_peak_usage();
                    $inicio = hrtime(true);
                    $raw = $descarga->pagina($temporada->id, $temporada->version_catalogo, $coleccion, $cursor);
                    $pagina = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                    $cursor = $pagina['siguiente'];
                    $medida['paginas']++;
                    $medida['filas'] += count($pagina['filas']);
                    $medida['max_filas_pagina'] = max($medida['max_filas_pagina'], count($pagina['filas']));
                    $medida['max_bytes_pagina'] = max($medida['max_bytes_pagina'], strlen($raw));
                    $medida['max_memoria_php_bytes'] = max($medida['max_memoria_php_bytes'], memory_get_peak_usage(true));
                    $medida['max_ms_pagina'] = max($medida['max_ms_pagina'], round((hrtime(true) - $inicio) / 1000000, 2));
                    unset($raw, $pagina);
                } while ($cursor !== null && ($limite === 0 || $medida['paginas'] < $limite));
                $medida['completa'] = $cursor === null;
                $filas[] = $medida;
            }
        } catch (HttpExceptionInterface $error) {
            $this->error('La medición no terminó: '.$error->getMessage());

            return self::FAILURE;
        }
        $reporte = ['temporada' => $temporada->codigo, 'version' => $temporada->version_catalogo,
            'memory_limit' => ini_get('memory_limit'), 'conteos' => $conteos, 'lectura' => $filas,
            'nota' => 'Memoria del proceso CLI PHP, incluido el diagnóstico. No mide RAM del equipo ni transferencia por Wi-Fi.'];
        if ($this->option('json')) {
            $this->line(json_encode($reporte, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->info("{$temporada->codigo} · catálogo v{$temporada->version_catalogo} · memory_limit ".ini_get('memory_limit'));
            $this->table(['Colección', 'Páginas', 'Filas', 'Máx. bytes/página', 'Máx. PHP MiB', 'Máx. ms/página', 'Completa'],
                array_map(fn ($fila) => [$fila['coleccion'], $fila['paginas'], $fila['filas'], $fila['max_bytes_pagina'],
                    round($fila['max_memoria_php_bytes'] / 1048576, 2), $fila['max_ms_pagina'], $fila['completa'] ? 'Sí' : 'Muestra'], $filas));
            $this->line($reporte['nota']);
        }

        return self::SUCCESS;
    }
}
