<?php

namespace App\Console\Commands;

use App\Services\MateriaPrima\ServicioCompletarEnvasesSegmentos;
use Illuminate\Console\Command;

class CompletarEnvasesSegmentosMateriaPrima extends Command
{
    protected $signature = 'materia-prima:completar-envases-segmentos {--aplicar : Aplicar los cambios de la vista previa dentro de una transacción}';

    protected $description = 'Previsualiza o completa envases de lotes sin entregar de la temporada activa';

    public function handle(ServicioCompletarEnvasesSegmentos $servicio): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $resultado = $servicio->ejecutar($aplicar);
        if ($resultado['temporada'] === null) {
            $this->warn('No existe una temporada activa. No se modificó ningún lote.');

            return self::SUCCESS;
        }

        $this->info(($aplicar ? 'APLICADO' : 'VISTA PREVIA (sin escrituras)').' · Temporada '.$resultado['temporada']);
        $etiquetas = [
            'lotes_completados' => 'Lotes con envases agregados',
            'brutos_recalculados' => 'Brutos recalculados',
            'segmentos_cerrados' => 'Segmentos cerrados',
            'segmentos_divididos' => 'Segmentos divididos (sin tocar)',
            'omitidos_otras_temporadas' => 'Lotes de otras temporadas (sin tocar)',
            'omitidos_entregados' => 'Lotes entregados o parcialmente entregados (sin tocar)',
            'omitidos_anulados' => 'Lotes anulados (sin tocar)',
            'omitidos_cantidades_incompatibles' => 'Lotes con cantidades incompatibles (revisar)',
        ];
        $this->table(['Resumen', 'Cantidad'], array_map(
            fn (string $clave, string $etiqueta): array => [$etiqueta, $resultado['resumen'][$clave]],
            array_keys($etiquetas), array_values($etiquetas),
        ));
        $this->table(['Lote', 'Envases agregados', 'Bruto anterior', 'Bruto nuevo', 'Estado'], array_map(
            fn (array $fila): array => [
                $fila['numero_lote'],
                implode(', ', array_map(
                    fn (array $envase): string => $envase['tipo_envase'].' × '.$envase['cantidad'],
                    $fila['envases_agregados'],
                )) ?: '—',
                number_format($fila['bruto_anterior'], 3, ',', '.'),
                number_format($fila['bruto_nuevo'], 3, ',', '.'),
                $fila['estado'],
            ],
            $resultado['lotes'],
        ));

        return self::SUCCESS;
    }
}
