<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Models\Cliente;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\Temporada;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Consultas de la cuenta corriente de envases que no son la vista operacional:
 * kardex exportable con saldo acumulado, estado de cuenta por cliente y existencia
 * física por propiedad (donde se ven los envases propios, que no mueven la cuenta).
 *
 * Signos: `signo_cuenta` +1 deja envases del cliente a cargo de la planta y -1 los
 * entrega al cliente; las compras de envases propios usan 0 porque no son de nadie más.
 */
class ServicioCuentaCorrienteEnvases
{
    private const ETIQUETAS_MOVIMIENTO = [
        'recepcion_fruta' => 'Recepción con fruta',
        'salida_recepcion_fruta' => 'Devolución en recepción',
        'reversion_salida_fruta' => 'Reversa de devolución en recepción',
        'recepcion_arriendo' => 'Ingreso en arriendo',
        'recepcion_compra' => 'Compra de envases propios',
        'despacho_cliente' => 'Despacho',
        'reversion_despacho' => 'Anulación de despacho',
        'correccion_propiedad' => 'Corrección de propiedad',
        'correccion_cantidad' => 'Corrección de cantidad',
    ];

    private const DOCUMENTO_GUIA = 'guia_despacho_envases';

    /** Entregas al cliente y sus anulaciones; lo demás (recepciones y correcciones) es recibido. */
    private const SALIDAS = [
        TipoMovimientoEnvase::DespachoCliente,
        TipoMovimientoEnvase::ReversionDespacho,
        TipoMovimientoEnvase::SalidaRecepcionFruta,
        TipoMovimientoEnvase::ReversionSalidaRecepcionFruta,
    ];

    private const ETIQUETAS_PROPIEDAD = [
        'cliente' => 'Del cliente',
        'propia' => 'Propia',
        'arrendada' => 'Arrendada',
    ];

    public static function etiquetaMovimiento(TipoMovimientoEnvase $tipo): string
    {
        return self::ETIQUETAS_MOVIMIENTO[$tipo->value] ?? ucfirst(str_replace('_', ' ', $tipo->value));
    }

    public static function etiquetaPropiedad(PropiedadEnvase $propiedad): string
    {
        return self::ETIQUETAS_PROPIEDAD[$propiedad->value];
    }

    /** @param array<string, mixed> $filtros */
    public function aplicarFiltros(Builder $consulta, array $filtros): void
    {
        foreach (['cliente_id', 'temporada_id', 'tipo_envase', 'estado_revision'] as $campo) {
            if (! empty($filtros[$campo])) {
                $consulta->where($campo, $filtros[$campo]);
            }
        }
        $this->filtrarDiasOperacionales($consulta, 'ocurrido_at', $filtros['desde'] ?? null, $filtros['hasta'] ?? null);
        if (! empty($filtros['buscar'])) {
            $buscar = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filtros['buscar'])).'%';
            $consulta->where(function (Builder $q) use ($buscar): void {
                $q->where('numero_documento', 'like', $buscar)
                    ->orWhereHas('cliente', fn (Builder $cliente) => $cliente->where('nombre', 'like', $buscar));
            });
        }
    }

    /**
     * Las fechas de los filtros son días de planta; los movimientos se guardan en UTC.
     * Un ingreso a las 22:00 del 14 pertenece al 14 aunque en UTC ya sea el 15.
     */
    public function filtrarDiasOperacionales(Builder $consulta, string $columna, ?string $desde, ?string $hasta): void
    {
        [$inicio, $fin] = $this->rangoOperacional($desde, $hasta);
        if ($inicio) {
            $consulta->where($columna, '>=', $inicio);
        }
        if ($fin) {
            $consulta->where($columna, '<', $fin);
        }
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} inicio incluido y fin excluido, en UTC */
    public function rangoOperacional(?string $desde, ?string $hasta): array
    {
        $zona = config('app.operational_timezone');

        return [
            filled($desde) ? CarbonImmutable::parse($desde, $zona)->startOfDay()->utc() : null,
            filled($hasta) ? CarbonImmutable::parse($hasta, $zona)->addDay()->startOfDay()->utc() : null,
        ];
    }

    /**
     * Kardex en orden cronológico con los filtros de la oficina. El saldo acumulado es el
     * de la cuenta del cliente para ese tipo de envase en la temporada: se calcula con
     * todos sus movimientos, aunque el filtro de fecha, revisión o búsqueda muestre menos.
     *
     * @param  array<string, mixed>  $filtros
     * @return iterable<int, array<string, mixed>>
     */
    public function kardex(array $filtros): iterable
    {
        $todos = MovimientoEnvase::query()
            ->with(['cliente:id,codigo,nombre', 'creadoPor:id,name', 'revisiones.usuario:id,name'])
            ->where('temporada_id', $filtros['temporada_id'])
            ->when(! empty($filtros['cliente_id']), fn (Builder $q) => $q->where('cliente_id', $filtros['cliente_id']))
            ->when(! empty($filtros['tipo_envase']), fn (Builder $q) => $q->where('tipo_envase', $filtros['tipo_envase']))
            ->orderBy('ocurrido_at')
            ->orderBy('id');
        $visibles = MovimientoEnvase::query()->select('id');
        $this->aplicarFiltros($visibles, $filtros);
        $idsVisibles = $visibles->pluck('id')->flip();
        $guiasEmitidas = $this->numerosGuiaEmitida(null, $filtros['temporada_id']);
        $saldos = [];

        foreach ($todos->lazy(500) as $movimiento) {
            $clave = $movimiento->cliente_id.'|'.$movimiento->tipo_envase->value;
            $impactoCuenta = $movimiento->cantidad * $movimiento->signo_cuenta;
            $saldos[$clave] = ($saldos[$clave] ?? 0) + $impactoCuenta;
            if (! $idsVisibles->has($movimiento->id)) {
                continue;
            }

            $revision = $movimiento->revisiones->sortByDesc('revisado_at')->first();
            $correccion = $movimiento->datos['correccion_propiedad'] ?? $movimiento->datos['correccion_cantidad'] ?? null;

            yield [
                'fecha_hora' => $this->local($movimiento->ocurrido_at),
                'cliente_codigo' => $movimiento->cliente?->codigo,
                'cliente' => $movimiento->cliente?->nombre,
                'documento' => $movimiento->numero_documento,
                'guia_emitida' => $movimiento->documento_tipo === self::DOCUMENTO_GUIA
                    ? ($guiasEmitidas[$movimiento->documento_id] ?? null)
                    : null,
                'movimiento' => self::etiquetaMovimiento($movimiento->tipo_movimiento),
                'tipo_envase' => $movimiento->tipo_envase->etiqueta(),
                'propiedad' => self::etiquetaPropiedad($movimiento->propiedad),
                'cantidad' => $movimiento->cantidad,
                // La compra de envases propios no mueve ninguna cuenta: celdas vacías, como «—» en la oficina.
                'impacto_cuenta' => $movimiento->signo_cuenta === 0 ? null : $impactoCuenta,
                'saldo_cuenta' => $movimiento->signo_cuenta === 0 ? null : $saldos[$clave],
                'impacto_existencia' => $movimiento->cantidad * $movimiento->signo_existencia,
                'revision' => ucfirst($movimiento->estado_revision->value),
                'revisado_por' => $revision?->usuario?->name,
                'nota_revision' => $revision?->nota,
                'registrado_por' => $movimiento->creadoPor?->name,
                'motivo_correccion' => is_array($correccion) ? ($correccion['motivo'] ?? null) : null,
            ];
        }
    }

    /**
     * Estado de cuenta de un cliente para enviarle: saldo inicial, entradas, salidas y
     * saldo final por tipo de envase, con el detalle de movimientos y su saldo.
     *
     * Es la vista del cliente, no la trazabilidad interna (esa es el kardex):
     * - una guía anulada y su anulación no aparecen; una corrección se aplica sobre el
     *   movimiento que corrige y no sale como línea propia;
     * - las líneas de un mismo documento, envase y propiedad se suman en una;
     * - en los despachos se informa el N° de la guía emitida en el sistema de facturación.
     *
     * @return array<string, mixed>
     */
    public function estadoCuenta(Cliente $cliente, Temporada $temporada, ?string $desde, ?string $hasta): array
    {
        $movimientos = MovimientoEnvase::query()
            ->where('temporada_id', $temporada->id)
            ->where('cliente_id', $cliente->id)
            ->where('signo_cuenta', '!=', 0)
            ->orderBy('ocurrido_at')
            ->orderBy('id')
            ->get();
        $guiasEmitidas = $this->numerosGuiaEmitida($movimientos);
        [$inicio, $fin] = $this->rangoOperacional($desde, $hasta);

        $tipos = [];
        $lineas = [];
        $indices = [];
        $documentos = [];
        $saldos = [];
        foreach ($this->vistaCliente($movimientos) as ['movimiento' => $movimiento, 'impacto' => $impacto]) {
            $tipo = $movimiento->tipo_envase->value;
            $tipos[$tipo] ??= [
                'tipo_envase' => $tipo,
                'nombre' => $movimiento->tipo_envase->etiqueta(),
                'orden' => $movimiento->tipo_envase->orden(),
                'saldo_inicial' => 0,
                'entradas' => 0,
                'salidas' => 0,
                'salidas_por_propiedad' => ['cliente' => 0, 'propia' => 0, 'arrendada' => 0],
                'saldo_final' => 0,
            ];
            $saldos[$tipo] = ($saldos[$tipo] ?? 0) + $impacto;

            if ($inicio !== null && $movimiento->ocurrido_at->lt($inicio)) {
                $tipos[$tipo]['saldo_inicial'] += $impacto;

                continue;
            }
            if ($fin !== null && $movimiento->ocurrido_at->gte($fin)) {
                continue;
            }
            $esSalida = in_array($movimiento->tipo_movimiento, self::SALIDAS, true);
            if ($esSalida) {
                $tipos[$tipo]['salidas'] -= $impacto;
                $tipos[$tipo]['salidas_por_propiedad'][$movimiento->propiedad->value] -= $impacto;
            } else {
                $tipos[$tipo]['entradas'] += $impacto;
            }

            $documento = $movimiento->documento_tipo === self::DOCUMENTO_GUIA
                ? ($guiasEmitidas[$movimiento->documento_id] ?? null) ?: $movimiento->numero_documento
                : $movimiento->numero_documento;
            // Una línea por guía y tipo de envase, como en la guía: los orígenes internos de
            // un mismo envase (FIFO, varias recepciones) no se le muestran al cliente.
            $claveDocumento = implode('|', [
                $movimiento->ocurrido_at->getTimestamp(),
                $movimiento->documento_id ?? $movimiento->numero_documento,
                $movimiento->tipo_movimiento->value,
            ]);
            $clave = $claveDocumento.'|'.$tipo.'|'.$movimiento->propiedad->value;
            $documentos[$claveDocumento] ??= count($documentos);
            if (isset($indices[$clave])) {
                $lineas[$indices[$clave]][$esSalida ? 'salida' : 'entrada'] += $esSalida ? -$impacto : $impacto;
                $lineas[$indices[$clave]]['saldo'] = $saldos[$tipo];

                continue;
            }
            $indices[$clave] = count($lineas);
            $lineas[] = [
                'orden' => [$documentos[$claveDocumento], $movimiento->tipo_envase->orden(), $movimiento->propiedad->value],
                'fecha' => $this->local($movimiento->ocurrido_at),
                'documento' => $documento,
                'movimiento' => self::etiquetaMovimiento($movimiento->tipo_movimiento),
                'tipo_envase' => $movimiento->tipo_envase->etiqueta(),
                'propiedad' => self::etiquetaPropiedad($movimiento->propiedad),
                'entrada' => $esSalida ? null : $impacto,
                'salida' => $esSalida ? -$impacto : null,
                'saldo' => $saldos[$tipo],
            ];
        }
        foreach ($tipos as $tipo => $resumen) {
            $tipos[$tipo]['saldo_final'] = $resumen['saldo_inicial'] + $resumen['entradas'] - $resumen['salidas'];
        }
        // Dentro de una guía con varios envases, las líneas van en el orden del catálogo.
        usort($lineas, fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);
        $lineas = array_map(function (array $linea): array {
            unset($linea['orden']);

            return $linea;
        }, $lineas);
        $pendientes = DetalleEnvaseRecepcionRomana::query()
            ->whereHas('recepcion', fn (Builder $recepcion) => $recepcion
                ->where('cliente_id', $cliente->id)
                ->where('temporada_id', $temporada->id)
                ->where('estado_validacion_mp', '!=', EstadoValidacionMp::Validada->value))
            ->count();

        return [
            'cliente' => ['codigo' => $cliente->codigo, 'nombre' => $cliente->nombre],
            'temporada' => ['codigo' => $temporada->codigo, 'nombre' => $temporada->nombre],
            'desde' => $desde,
            'hasta' => $hasta,
            'tipos' => collect($tipos)->sortBy('orden')->values()->all(),
            'movimientos' => $lineas,
            'lineas_pendientes_validacion' => $pendientes,
            'emitido_at' => $this->local(now()),
        ];
    }

    /**
     * Existencia física en planta por tipo y propiedad. Los envases propios no mueven la
     * cuenta de ningún cliente, así que este es el lugar donde se ven.
     *
     * @return array{tipos: list<array<string, mixed>>, propios_entregados: list<array<string, mixed>>}
     */
    public function existencia(string $temporadaId): array
    {
        $fisico = MovimientoEnvase::query()
            ->where('temporada_id', $temporadaId)
            ->selectRaw('tipo_envase, propiedad, tipo_movimiento, SUM(CAST(cantidad AS SIGNED) * signo_existencia) as cantidad')
            ->groupBy('tipo_envase', 'propiedad', 'tipo_movimiento')
            ->toBase()
            ->get();
        $reservado = DetalleGuiaDespachoEnvase::query()
            ->whereHas('guia', fn (Builder $guia) => $guia
                ->where('temporada_id', $temporadaId)
                ->where('estado', EstadoGuiaDespachoEnvase::Borrador->value))
            ->selectRaw('tipo_envase, propiedad, SUM(cantidad) as cantidad')
            ->groupBy('tipo_envase', 'propiedad')
            ->toBase()
            ->get();
        $despachos = [TipoMovimientoEnvase::DespachoCliente->value, TipoMovimientoEnvase::ReversionDespacho->value];

        $tipos = collect(TipoEnvaseRomana::cases())->map(function (TipoEnvaseRomana $tipo) use ($fisico, $reservado, $despachos): ?array {
            $filas = $fisico->where('tipo_envase', $tipo->value);
            $porPropiedad = fn (Collection $origen): array => collect(PropiedadEnvase::cases())
                ->mapWithKeys(fn (PropiedadEnvase $propiedad): array => [
                    $propiedad->value => (int) $origen->where('propiedad', $propiedad->value)->sum('cantidad'),
                ])->all();
            $enPlanta = $porPropiedad($filas);
            $reservas = $porPropiedad($reservado->where('tipo_envase', $tipo->value));
            $propios = $filas->where('propiedad', PropiedadEnvase::Propia->value);
            $despachados = -(int) $propios->whereIn('tipo_movimiento', $despachos)->sum('cantidad');

            if (! array_filter($enPlanta) && ! array_filter($reservas) && $propios->isEmpty()) {
                return null;
            }

            return [
                'tipo_envase' => $tipo->value,
                'nombre' => $tipo->etiqueta(),
                'en_planta' => [...$enPlanta, 'total' => array_sum($enPlanta)],
                'reservado' => [...$reservas, 'total' => array_sum($reservas)],
                'disponible' => array_sum($enPlanta) - array_sum($reservas),
                'propios' => [
                    'ingresados' => $enPlanta[PropiedadEnvase::Propia->value] + $despachados,
                    'despachados' => $despachados,
                    'en_planta' => $enPlanta[PropiedadEnvase::Propia->value],
                ],
            ];
        })->filter()->values()->all();

        return [
            'tipos' => $tipos,
            'propios_entregados' => $this->propiosEntregados($temporadaId),
        ];
    }

    /**
     * Envases propios despachados a cada cliente, netos de anulaciones. Las recepciones no
     * distinguen si vuelven envases propios o del cliente, así que esto no es lo que el
     * cliente tiene hoy: es lo entregado.
     *
     * @return list<array{cliente_id: string, cliente: array{codigo: string, nombre: string}, tipo_envase: string, cantidad: int}>
     */
    public function propiosEntregados(string $temporadaId, ?string $clienteId = null): array
    {
        $filas = MovimientoEnvase::query()
            ->where('temporada_id', $temporadaId)
            ->where('propiedad', PropiedadEnvase::Propia->value)
            ->whereIn('tipo_movimiento', [TipoMovimientoEnvase::DespachoCliente->value, TipoMovimientoEnvase::ReversionDespacho->value])
            ->when($clienteId, fn (Builder $q) => $q->where('cliente_id', $clienteId))
            ->selectRaw('cliente_id, tipo_envase, SUM(CAST(cantidad AS SIGNED) * -signo_existencia) as cantidad')
            ->groupBy('cliente_id', 'tipo_envase')
            ->toBase()
            ->get()
            ->filter(fn (object $fila): bool => (int) $fila->cantidad !== 0);
        $clientes = Cliente::query()->whereIn('id', $filas->pluck('cliente_id')->unique())->get(['id', 'codigo', 'nombre'])->keyBy('id');

        return $filas
            ->map(fn (object $fila): array => [
                'cliente_id' => (string) $fila->cliente_id,
                'cliente' => [
                    'codigo' => (string) $clientes->get($fila->cliente_id)?->codigo,
                    'nombre' => (string) $clientes->get($fila->cliente_id)?->nombre,
                ],
                'tipo_envase' => (string) $fila->tipo_envase,
                'cantidad' => (int) $fila->cantidad,
            ])
            ->sortBy(fn (array $fila): string => $fila['cliente']['nombre'].'|'.$fila['tipo_envase'])
            ->values()
            ->all();
    }

    /**
     * Movimientos como los ve el cliente: cada anulación o corrección se suma al
     * movimiento que corrige y no queda como línea. Lo que termina en 0 (una guía anulada,
     * un ingreso corregido por completo) desaparece. El saldo final no cambia.
     *
     * @param  Collection<int, MovimientoEnvase>  $movimientos  en orden cronológico
     * @return list<array{movimiento: MovimientoEnvase, impacto: int}>
     */
    private function vistaCliente(Collection $movimientos): array
    {
        $netos = [];
        $porId = $movimientos->keyBy('id');
        foreach ($movimientos as $movimiento) {
            $corrige = match ($movimiento->tipo_movimiento) {
                TipoMovimientoEnvase::ReversionDespacho => $movimiento->datos['reversa_de_movimiento_id'] ?? null,
                TipoMovimientoEnvase::ReversionSalidaRecepcionFruta,
                TipoMovimientoEnvase::CorreccionCantidad,
                TipoMovimientoEnvase::CorreccionPropiedad => $movimiento->movimiento_origen_id,
                default => null,
            };
            $impacto = $movimiento->cantidad * $movimiento->signo_cuenta;
            if ($corrige !== null && isset($netos[$corrige])) {
                $netos[$corrige] += $impacto;

                continue;
            }
            $netos[$movimiento->id] = $impacto;
        }

        return collect($netos)
            ->filter(fn (int $impacto): bool => $impacto !== 0)
            ->map(fn (int $impacto, string $id): array => ['movimiento' => $porId[$id], 'impacto' => $impacto])
            ->values()
            ->all();
    }

    /**
     * N° de la guía emitida en el sistema de facturación por cada guía de FoliOS.
     *
     * @param  iterable<MovimientoEnvase>|null  $movimientos  null: todas las de la temporada
     * @return array<string, string|null>
     */
    private function numerosGuiaEmitida(?iterable $movimientos, ?string $temporadaId = null): array
    {
        $ids = $movimientos === null
            ? null
            : collect($movimientos)->where('documento_tipo', self::DOCUMENTO_GUIA)->pluck('documento_id')->filter()->unique()->values();

        return GuiaDespachoEnvase::query()
            ->when($ids !== null, fn (Builder $q) => $q->whereKey($ids))
            ->when($temporadaId !== null, fn (Builder $q) => $q->where('temporada_id', $temporadaId))
            ->pluck('numero_guia_despacho', 'id')
            ->all();
    }

    private function local(?DateTimeInterface $fecha): ?string
    {
        return $fecha
            ? CarbonImmutable::instance($fecha)->setTimezone(config('app.operational_timezone'))->format('Y-m-d H:i:s')
            : null;
    }
}
