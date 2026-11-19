<?php

namespace App\Services\MateriaPrima;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\TipoRecepcionRomana;
use App\Models\Cliente;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InformeRecepcionesCsg
{
    private const FILAS_POR_PAGINA = 50;

    private const BARRAS_GRAFICO = 8;

    public function clientes(Temporada $temporada): array
    {
        return Cliente::query()->whereIn('id', $this->recepciones($temporada)->select('cliente_id'))
            ->orderBy('nombre')->orderBy('id')->get(['id', 'codigo', 'nombre'])->all();
    }

    public function generar(Temporada $temporada, Cliente $cliente, string $desde, string $hasta, ?int $pagina = null): array
    {
        $zona = config('app.operational_timezone');
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::parse($hasta, $zona)->addDay()->startOfDay()->utc();
        $recepciones = $this->recepciones($temporada)
            ->select(['id', 'numero_recepcion', 'numero_guia_despacho', 'ingreso_at', 'peso_neto'])
            ->where('cliente_id', $cliente->id)
            ->where('ingreso_at', '>=', $inicio)->where('ingreso_at', '<', $fin)
            ->with([
                'guiasRecepcion:id,recepcion_romana_id,numero_guia_despacho',
                'lotesMateriaPrima' => fn ($lotes) => $lotes
                    ->select(['id', 'recepcion_romana_id', 'segmento_validacion_mp_id', 'numero_lote',
                        'csg_validacion_id', 'csg_snapshot', 'variedad_snapshot', 'kilos_netos_confirmados'])
                    ->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)
                    ->whereNotIn('estado', [EstadoLoteMateriaPrima::Borrador->value, EstadoLoteMateriaPrima::Anulado->value])
                    ->where('kilos_netos_confirmados', '>', 0)
                    ->with(['segmento:id,guia_recepcion_romana_id', 'csg:id,productor_csg_id', 'csg.productor:id,razon_social'])
                    ->orderBy('numero_lote')->orderBy('id'),
            ])
            ->orderBy('ingreso_at')->orderBy('id');

        // El resumen y el detalle leen la misma versión de las recepciones y sus lotes.
        return DB::transaction(fn (): array => [
            'cliente' => $cliente->only(['codigo', 'nombre']),
            'temporada' => $temporada->only(['codigo', 'nombre']),
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            ...$this->consolidar($recepciones, $zona, $pagina),
        ]);
    }

    private function recepciones(Temporada $temporada): Builder
    {
        return RecepcionRomana::query()->where('temporada_id', $temporada->id)
            ->where('estado', EstadoRecepcionRomana::Cerrado->value)
            ->where('tipo_recepcion', '!=', TipoRecepcionRomana::SoloEnvases->value)
            ->whereNull('eliminada_at')->whereNotNull('peso_neto');
    }

    private function consolidar(Builder $recepciones, string $zona, ?int $pagina): array
    {
        $porCsg = $detalle = [];
        $totalRecepciones = $totalFilas = $neto = $conCsg = $pendientes = $excedentes = 0;
        $offset = (($pagina ?? 1) - 1) * self::FILAS_POR_PAGINA;
        foreach ($recepciones->lazy(200) as $recepcion) {
            $totalRecepciones++;
            $neto += $pesoRomana = (int) round((float) $recepcion->peso_neto * 1000);
            foreach ($this->filasRecepcion($recepcion, $zona, $pesoRomana) as $fila) {
                $gramos = $fila['gramos'];
                $csg = $fila['csg'];
                if ($csg !== null) {
                    $clave = 'csg:'.$csg;
                    $porCsg[$clave] ??= ['csg' => $csg, 'productor' => null, 'lotes' => 0, 'gramos' => 0];
                    $porCsg[$clave]['productor'] ??= $fila['productor'];
                    $porCsg[$clave]['lotes']++;
                    $porCsg[$clave]['gramos'] += $gramos;
                    $conCsg += $gramos;
                } elseif ($gramos < 0) {
                    $excedentes -= $gramos;
                } else {
                    $pendientes += $gramos;
                }

                if ($pagina === null || ($totalFilas >= $offset && $totalFilas < $offset + self::FILAS_POR_PAGINA)) {
                    $fila['kilos'] = $gramos / 1000;
                    $fila['rotulo_csg'] = $csg ?? ($gramos < 0 ? 'Diferencia Romana' : 'Pendiente de CSG');
                    unset($fila['gramos'], $fila['productor']);
                    $detalle[] = $fila;
                }
                $totalFilas++;
            }
        }

        $resumen = array_values($porCsg);
        usort($resumen, fn (array $a, array $b): int => ($b['gramos'] <=> $a['gramos']) ?: strnatcasecmp($a['csg'], $b['csg']));
        $resumen = array_map(fn (array $fila): array => [
            'csg' => $fila['csg'], 'productor' => $fila['productor'], 'lotes' => $fila['lotes'], 'kilos' => $fila['gramos'] / 1000,
        ], $resumen);

        return [
            'totales' => [
                'recepciones' => $totalRecepciones, 'csg' => count($resumen), 'kilos_netos' => $neto / 1000,
                'kilos_con_csg' => $conCsg / 1000, 'kilos_pendientes' => $pendientes / 1000,
                'kilos_excedentes' => $excedentes / 1000,
            ],
            'resumen' => $resumen,
            'grafico' => array_slice($resumen, 0, self::BARRAS_GRAFICO),
            'detalle' => $detalle,
            'paginacion' => [
                'pagina' => $pagina ?? 1,
                'paginas' => max(1, (int) ceil($totalFilas / self::FILAS_POR_PAGINA)),
                'total' => $totalFilas,
            ],
        ];
    }

    private function filasRecepcion(RecepcionRomana $recepcion, string $zona, int $pesoRomana): iterable
    {
        $base = [
            'fecha' => $recepcion->ingreso_at->setTimezone($zona)->format('Y-m-d H:i'),
            'recepcion' => $recepcion->numero_recepcion,
        ];
        $guias = $recepcion->guiasRecepcion->pluck('numero_guia_despacho', 'id');
        $guiaUnica = match ($guias->count()) {
            0 => $recepcion->numero_guia_despacho,
            1 => $guias->first(),
            default => null,
        };
        $asignado = 0;
        foreach ($recepcion->lotesMateriaPrima as $lote) {
            $asignado += $gramos = (int) round((float) $lote->kilos_netos_confirmados * 1000);
            $csg = trim((string) $lote->csg_snapshot);
            yield [
                ...$base,
                'guia' => $guias->get($lote->segmento?->guia_recepcion_romana_id) ?? $guiaUnica,
                'lote' => $lote->numero_lote,
                'csg' => $csg === '' ? null : $csg,
                'productor' => $lote->csg?->productor?->razon_social,
                'variedad' => $lote->variedad_snapshot,
                'gramos' => $gramos,
            ];
        }

        if ($pesoRomana !== $asignado) {
            yield [
                ...$base,
                'guia' => $guias->isNotEmpty() ? $guias->implode(' · ') : $guiaUnica,
                'lote' => null,
                'csg' => null,
                'productor' => null,
                'variedad' => null,
                'gramos' => $pesoRomana - $asignado,
            ];
        }
    }
}
