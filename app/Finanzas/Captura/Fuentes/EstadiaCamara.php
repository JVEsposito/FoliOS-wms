<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FolioLeido;
use App\Finanzas\Captura\FuenteHechos;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Captura\HistorialBultos;
use App\Finanzas\Models\Hecho;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Estadía de un pallet o saldo en las cámaras de producto: desde que entra a una cámara
 * hasta que sale de la planta (despacho), se consume en un repaletizaje o se anula.
 *
 * Mientras el folio siga vigente la estadía sigue abierta, aunque salga un rato de la
 * cámara (un reordenamiento, el andén): la custodia es de la planta hasta que el pallet
 * se va. Cada estadía se reconstruye desde el historial de movimientos del folio, así que
 * leerla de nuevo siempre da el mismo resultado.
 */
final class EstadiaCamara implements FuenteHechos
{
    private const LOTE = 500;

    private const ESTADOS_TERMINALES = [
        'despachado' => 'despacho',
        'agotado' => 'repaletizaje',
        'anulado' => 'anulacion',
        'retirado_definitivo' => 'retiro_definitivo',
    ];

    public function clave(): string
    {
        return 'estadia';
    }

    public function nombre(): string
    {
        return 'Estadía en cámara';
    }

    public function descripcion(): string
    {
        return 'Permanencia de un pallet o saldo en cámaras de producto, desde que entra hasta que sale de la planta.';
    }

    public function intervalo(): bool
    {
        return true;
    }

    public function medidas(): array
    {
        return [
            'bultos' => ['nombre' => 'Bultos (pallets y saldos)', 'unidad' => 'bulto'],
            'pallets' => ['nombre' => 'Pallets completos', 'unidad' => 'pallet'],
            'saldos' => ['nombre' => 'Saldos', 'unidad' => 'saldo'],
            'cajas' => ['nombre' => 'Cajas', 'unidad' => 'caja'],
        ];
    }

    public function cambios(ContextoCaptura $contexto, ?CarbonImmutable $desde): iterable
    {
        $folioIds = $desde === null ? $this->todos($contexto) : $this->afectados($contexto, $desde);

        foreach (array_chunk($folioIds, self::LOTE) as $lote) {
            yield from $this->estadias($contexto, $lote);
        }
    }

    public function porIds(ContextoCaptura $contexto, array $origenIds): iterable
    {
        foreach (array_chunk($this->folios($origenIds), self::LOTE) as $lote) {
            yield from $this->estadias($contexto, $lote);
        }
    }

    /**
     * @param  list<string>  $origenIds
     * @return list<string>
     */
    private function folios(array $origenIds): array
    {
        return array_values(array_unique(array_map(fn (string $origenId): string => (string) strtok($origenId, '#'), $origenIds)));
    }

    /** @return list<string> */
    private function todos(ContextoCaptura $contexto): array
    {
        $camaras = array_keys($this->camaras($contexto));
        if ($camaras === []) {
            return [];
        }

        $lector = $contexto->lector;

        return collect()
            ->merge($lector->tabla('movimientos')->whereIn('camara_destino_id', $camaras)->distinct()->pluck('folio_id'))
            ->merge($lector->tabla('movimientos')->whereIn('camara_origen_id', $camaras)->distinct()->pluck('folio_id'))
            ->merge($lector->tabla('ubicaciones_actuales')->whereIn('camara_id', $camaras)->pluck('folio_id'))
            ->unique()->sort()->values()->all();
    }

    /**
     * Folios cuya estadía pudo cambiar desde la marca: se movieron, se despacharon, se
     * repaletizaron, se anularon o terminaron por otra vía; más las estadías abiertas cuyo
     * folio ya no está en una cámara de producto.
     *
     * @return list<string>
     */
    private function afectados(ContextoCaptura $contexto, CarbonImmutable $desde): array
    {
        $camaras = array_keys($this->camaras($contexto));
        $lector = $contexto->lector;
        $marca = $desde->utc()->format('Y-m-d H:i:s');

        $repaletizajes = $lector->tabla('repaletizajes')->where('updated_at', '>=', $marca)->pluck('id');
        $presentes = array_flip($lector->tabla('ubicaciones_actuales')->whereIn('camara_id', $camaras)->pluck('folio_id')->all());
        $abiertosAusentes = array_filter(
            $this->folios($contexto->abiertos($this->clave())),
            fn (string $folioId): bool => ! isset($presentes[$folioId]),
        );

        return collect()
            ->merge($lector->tabla('movimientos')->where('created_at', '>=', $marca)
                ->where(fn ($camara) => $camara->whereIn('camara_destino_id', $camaras)->orWhereIn('camara_origen_id', $camaras))
                ->distinct()->pluck('folio_id'))
            ->merge($lector->tabla('ubicaciones_actuales')->where('updated_at', '>=', $marca)->pluck('folio_id'))
            ->merge($lector->tabla('carga_folios')->where('updated_at', '>=', $marca)->pluck('folio_id'))
            ->merge($lector->tabla('repaletizaje_detalles')->whereIn('repaletizaje_id', $repaletizajes)->pluck('folio_origen_id'))
            ->merge($lector->tabla('repaletizaje_resultados')->whereIn('repaletizaje_id', $repaletizajes)->pluck('folio_id'))
            ->merge($lector->tabla('anulaciones_validacion_pallet')->where('updated_at', '>=', $marca)->pluck('folio_id'))
            ->merge($lector->tabla('folios')->where('updated_at', '>=', $marca)->whereIn('tipo_bulto', ['pallet', 'saldo'])
                ->where(fn ($terminal) => $terminal->whereIn('estado_operacional', array_keys(self::ESTADOS_TERMINALES))->orWhere('activo', false))
                ->pluck('id'))
            ->merge($abiertosAusentes)
            ->filter()->unique()->sort()->values()->all();
    }

    /** @return array<string, string> camara_id => código, solo cámaras de producto */
    private function camaras(ContextoCaptura $contexto): array
    {
        return $contexto->recordar('camaras_producto', fn (): array => $contexto->lector->tabla('camaras')
            ->where('contenido', 'productos')
            ->pluck('codigo', 'id')
            ->all());
    }

    /**
     * @param  list<string>  $folioIds
     * @return iterable<HechoCapturado>
     */
    private function estadias(ContextoCaptura $contexto, array $folioIds): iterable
    {
        $folios = FolioLeido::leer($contexto, $folioIds);
        if ($folios === []) {
            return;
        }

        $ids = array_keys($folios);
        $historial = HistorialBultos::leer($contexto, $ids);
        $lector = $contexto->lector;
        $movimientos = $lector->tabla('movimientos')
            ->whereIn('folio_id', $ids)
            ->orderBy('created_at')->orderBy('id')
            ->get(['folio_id', 'camara_origen_id', 'camara_destino_id', 'generado_dispositivo_at', 'created_at'])
            ->groupBy('folio_id');
        $ubicaciones = $lector->tabla('ubicaciones_actuales')
            ->whereIn('folio_id', $ids)
            ->get(['folio_id', 'camara_id', 'ubicado_at'])
            ->keyBy('folio_id');
        $despachos = $lector->tabla('carga_folios', 'cf')
            ->join('cargas as c', 'c.id', '=', 'cf.carga_id')
            ->whereIn('cf.folio_id', $ids)
            ->where('cf.estado', 'despachado')
            ->get(['cf.folio_id', 'cf.finalizado_at', 'cf.updated_at', 'c.codigo'])
            ->groupBy('folio_id');
        $consumos = $lector->tabla('repaletizaje_detalles', 'd')
            ->join('repaletizajes as r', 'r.id', '=', 'd.repaletizaje_id')
            ->whereIn('d.folio_origen_id', $ids)
            ->where('r.estado', 'confirmado')
            ->where('d.es_folio_conservado', false)
            ->where('d.cajas_despues', 0)
            ->get(['d.folio_origen_id as folio_id', 'd.cajas_antes', 'r.confirmado_at', 'r.codigo'])
            ->groupBy('folio_id');
        // El folio conservado ya venía con su estadía; solo los folios nuevos nacen aquí.
        $resultados = $lector->tabla('repaletizaje_resultados', 'x')
            ->join('repaletizajes as r', 'r.id', '=', 'x.repaletizaje_id')
            ->whereIn('x.folio_id', $ids)
            ->where(fn ($nuevo) => $nuevo->whereNull('r.folio_conservado_id')->orWhereColumn('r.folio_conservado_id', '!=', 'x.folio_id'))
            ->get(['x.folio_id', 'x.repaletizaje_id', 'x.hereda_ubicacion', 'r.confirmado_at', 'r.codigo', 'r.estado']);
        // El folio nuevo hereda la ubicación del primer origen que estaba ubicado.
        $heredadas = $resultados->isEmpty() ? collect() : $lector->tabla('repaletizaje_detalles')
            ->whereIn('repaletizaje_id', $resultados->pluck('repaletizaje_id')->unique()->values())
            ->orderBy('orden')
            ->get(['repaletizaje_id', 'snapshot_antes'])
            ->groupBy('repaletizaje_id')
            ->map(fn (Collection $detalles): ?string => $detalles
                ->map(fn (object $detalle): ?string => json_decode((string) $detalle->snapshot_antes, true)['ubicacion']['camara_id'] ?? null)
                ->filter()
                ->first());
        $resultados = $resultados
            ->map(fn (object $resultado): object => (object) [
                ...(array) $resultado,
                'camara' => $resultado->hereda_ubicacion ? $heredadas->get($resultado->repaletizaje_id) : null,
            ])
            ->groupBy('folio_id');
        $anulaciones = $lector->tabla('anulaciones_validacion_pallet')
            ->whereIn('folio_id', $ids)
            ->get(['folio_id', 'anulado_at'])
            ->groupBy('folio_id');

        foreach ($folios as $id => $folio) {
            $eventos = $this->eventos(
                $movimientos->get($id, collect()),
                $despachos->get($id, collect()),
                $consumos->get($id, collect()),
                $resultados->get($id, collect()),
                $anulaciones->get($id, collect()),
            );

            foreach ($this->recorrer($contexto, $folio, $eventos, $ubicaciones->get($id)) as $numero => $estadia) {
                yield $this->hecho($contexto, $folio, $numero + 1, $estadia, $historial);
            }
        }
    }

    /**
     * Línea de tiempo del folio. A igual hora va primero el inicio por repaletizaje, luego
     * los movimientos y al final el término (el retiro al andén antes que el despacho).
     *
     * @return list<array<string, mixed>>
     */
    private function eventos(Collection $movimientos, Collection $despachos, Collection $consumos, Collection $resultados, Collection $anulaciones): array
    {
        $eventos = [];
        $anterior = null;
        foreach ($this->ordenar($movimientos) as $movimiento) {
            // Hora del dispositivo (la PDA puede sincronizar después), sin retroceder respecto
            // del orden en que el servidor aplicó los movimientos.
            $hora = ContextoCaptura::fecha($movimiento->generado_dispositivo_at) ?? ContextoCaptura::fecha($movimiento->created_at);
            $hora = $anterior !== null && $hora->lessThan($anterior) ? $anterior : $hora;
            $anterior = $hora;
            $eventos[] = ['hora' => $hora, 'orden' => 1, 'tipo' => 'movimiento', 'origen' => $movimiento->camara_origen_id, 'destino' => $movimiento->camara_destino_id];
        }
        foreach ($resultados as $resultado) {
            $eventos[] = ['hora' => ContextoCaptura::fecha($resultado->confirmado_at), 'orden' => 0, 'tipo' => 'resultado', 'repaletizaje' => $resultado->codigo,
                'anulado' => $resultado->estado !== 'confirmado', 'camara' => $resultado->camara];
        }
        foreach ($despachos as $despacho) {
            $eventos[] = ['hora' => ContextoCaptura::fecha($despacho->finalizado_at) ?? ContextoCaptura::fecha($despacho->updated_at), 'orden' => 2, 'tipo' => 'termino', 'motivo' => 'despacho', 'carga' => $despacho->codigo];
        }
        foreach ($consumos as $consumo) {
            $eventos[] = ['hora' => ContextoCaptura::fecha($consumo->confirmado_at), 'orden' => 2, 'tipo' => 'termino', 'motivo' => 'repaletizaje', 'repaletizaje' => $consumo->codigo, 'cajas' => (int) $consumo->cajas_antes];
        }
        foreach ($anulaciones as $anulacion) {
            $eventos[] = ['hora' => ContextoCaptura::fecha($anulacion->anulado_at), 'orden' => 2, 'tipo' => 'termino', 'motivo' => 'anulacion'];
        }

        $eventos = array_values(array_filter($eventos, fn (array $evento): bool => $evento['hora'] !== null));
        usort($eventos, fn (array $a, array $b): int => [$a['hora']->getTimestamp(), $a['orden']] <=> [$b['hora']->getTimestamp(), $b['orden']]);

        return $eventos;
    }

    /**
     * Movimientos en el orden en que el servidor los aplicó. Dentro de un mismo segundo
     * (un plan que mueve varias veces el mismo folio) manda la continuidad: cada movimiento
     * parte desde donde dejó el folio el anterior.
     *
     * @return list<object>
     */
    private function ordenar(Collection $movimientos): array
    {
        $ordenados = [];
        $actual = null;
        foreach ($movimientos->groupBy('created_at') as $mismoSegundo) {
            $pendientes = $mismoSegundo->values()->all();
            while ($pendientes !== []) {
                $siguiente = array_key_first(array_filter($pendientes, fn (object $movimiento): bool => $movimiento->camara_origen_id === $actual))
                    ?? array_key_first($pendientes);
                $actual = $pendientes[$siguiente]->camara_destino_id;
                $ordenados[] = $pendientes[$siguiente];
                unset($pendientes[$siguiente]);
            }
        }

        return $ordenados;
    }

    /**
     * @param  list<array<string, mixed>>  $eventos
     * @return list<array<string, mixed>>
     */
    private function recorrer(ContextoCaptura $contexto, FolioLeido $folio, array $eventos, ?object $ubicacion): array
    {
        $camaras = $this->camaras($contexto);
        $estadias = [];
        $abierta = null;
        $presente = false;
        $repaletizado = null;
        $nueva = fn (CarbonImmutable $hora, ?string $camara, bool $estimado, ?array $repaletizaje): array => [
            'ingreso' => $hora, 'camara' => $camara === null ? null : ($camaras[$camara] ?? null), 'ingreso_estimado' => $estimado,
            'repaletizaje_ingreso' => $repaletizaje['repaletizaje'] ?? null, 'anulada' => (bool) ($repaletizaje['anulado'] ?? false),
            'fuera_desde' => null, 'interrupciones' => 0, 'salida' => null, 'motivo' => null, 'salida_estimada' => false,
            'carga' => null, 'repaletizaje_salida' => null, 'cajas' => null,
        ];

        foreach ($eventos as $evento) {
            $hora = $evento['hora'];
            if ($evento['tipo'] === 'resultado') {
                // El folio nació en un repaletizaje. Si heredó la ubicación de un origen en
                // cámara, su estadía parte ahí mismo; si no, cuando lo ubiquen.
                $repaletizado = $evento;
                if ($abierta === null && $evento['camara'] !== null && isset($camaras[$evento['camara']])) {
                    $abierta = $nueva($hora, $evento['camara'], false, $evento);
                    $presente = true;
                }

                continue;
            }

            if ($evento['tipo'] === 'termino') {
                if ($abierta !== null) {
                    $estadias[] = [...$abierta, 'salida' => $hora, 'motivo' => $evento['motivo'], 'carga' => $evento['carga'] ?? null,
                        'repaletizaje_salida' => $evento['motivo'] === 'repaletizaje' ? $evento['repaletizaje'] : null,
                        'cajas' => $evento['cajas'] ?? null];
                    $abierta = null;
                }
                $presente = false;
                $repaletizado = null;

                continue;
            }

            $desdeProducto = $evento['origen'] !== null && isset($camaras[$evento['origen']]);
            $haciaProducto = $evento['destino'] !== null && isset($camaras[$evento['destino']]);

            if ($abierta === null && $desdeProducto && ! $presente) {
                // Estaba en una cámara sin un ingreso registrado: el repaletizaje que lo creó
                // marca el inicio; sin él, el primer movimiento es lo más cercano que se sabe.
                $abierta = $nueva($repaletizado['hora'] ?? $hora, $evento['origen'], $repaletizado === null, $repaletizado);
                $presente = true;
            }

            if ($haciaProducto) {
                if ($abierta === null) {
                    $abierta = $nueva($hora, $evento['destino'], false, $repaletizado);
                } elseif ($abierta['fuera_desde'] !== null) {
                    $abierta['fuera_desde'] = null;
                    $abierta['interrupciones']++;
                }
                $presente = true;
            } elseif ($abierta !== null && $presente) {
                $abierta['fuera_desde'] = $hora;
                $presente = false;
            }
        }

        $terminal = self::ESTADOS_TERMINALES[$folio->estado] ?? ($folio->activo ? null : 'inactivo');
        $enCamara = $ubicacion !== null && isset($camaras[$ubicacion->camara_id]);

        if ($abierta === null && $enCamara && $terminal === null) {
            $abierta = $nueva(
                $repaletizado['hora'] ?? ContextoCaptura::fecha($ubicacion->ubicado_at),
                $ubicacion->camara_id,
                $repaletizado === null,
                $repaletizado,
            );
        }

        if ($abierta !== null) {
            if ($terminal !== null) {
                // Terminó sin un evento que diga cuándo: se usa la última salida de cámara o,
                // si no la hay, la última actualización del folio.
                $abierta = [...$abierta, 'salida' => $abierta['fuera_desde'] ?? ContextoCaptura::fecha($folio->actualizadoAt) ?? $abierta['ingreso'],
                    'motivo' => $terminal, 'salida_estimada' => true];
            }
            $estadias[] = $abierta;
        }

        return $estadias;
    }

    /** @param  array<string, mixed>  $estadia */
    private function hecho(ContextoCaptura $contexto, FolioLeido $folio, int $numero, array $estadia, HistorialBultos $historial): HechoCapturado
    {
        $salida = $estadia['salida'];
        $salida = $salida !== null && $salida->lessThan($estadia['ingreso']) ? $estadia['ingreso'] : $salida;
        $tramos = $historial->tramos($folio, $estadia['ingreso'], $salida);
        $medidas = $tramos[0]['medidas'] ?? $historial->delServicio($folio, $estadia['ingreso']);

        return new HechoCapturado(
            origenId: "{$folio->id}#{$numero}",
            // Un folio que nació en un repaletizaje anulado nunca estuvo en la planta.
            estado: $estadia['anulada'] ? Hecho::ESTADO_ANULADO : Hecho::ESTADO_VIGENTE,
            ocurridoAt: $estadia['ingreso'],
            terminaAt: $salida,
            clienteId: $folio->clienteId,
            clienteNombre: $folio->clienteNombre,
            temporadaId: $folio->temporadaId,
            temporadaCodigo: $contexto->temporadaCodigo($folio->temporadaId),
            referencia: $folio->numero,
            medidas: $medidas,
            datos: [
                ...$folio->datos(),
                'tipo_bulto' => $medidas['pallets'] ? 'pallet' : 'saldo',
                'tramos' => $tramos,
                'estadia' => $numero,
                'camara_ingreso' => $estadia['camara'],
                'ingreso_estimado' => $estadia['ingreso_estimado'],
                'repaletizaje_ingreso' => $estadia['repaletizaje_ingreso'],
                'motivo_salida' => $estadia['motivo'],
                'salida_estimada' => $estadia['salida_estimada'],
                'carga' => $estadia['carga'],
                'repaletizaje_salida' => $estadia['repaletizaje_salida'],
                'interrupciones' => $estadia['interrupciones'],
                'fuera_de_camara_desde' => $salida === null ? $estadia['fuera_desde']?->toIso8601String() : null,
            ],
        );
    }
}
