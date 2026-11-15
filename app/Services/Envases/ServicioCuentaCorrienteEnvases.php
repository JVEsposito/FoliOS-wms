<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Models\Cliente;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\ValidacionMp;
use App\Services\Jornadas\CalendarioOperativo;
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
        'devolucion_envases' => 'Devolución de envases',
        'despacho_cliente' => 'Despacho',
        'reversion_despacho' => 'Anulación de despacho',
        'conciliacion_salida' => 'Conciliación de salida duplicada',
        'correccion_propiedad' => 'Corrección de propiedad',
        'correccion_cantidad' => 'Corrección de cantidad',
        'correccion_cuenta' => 'Corrección de saldo anterior',
        'anulacion_recepcion' => 'Anulación de recepción',
        'apertura_cuenta' => 'Apertura de cuenta',
        'ajuste_cuenta' => 'Ajuste de cuenta',
        'reversion_ajuste_cuenta' => 'Reversa de ajuste de cuenta',
        'prestamo_interno' => 'Préstamo interno de bins de planta',
        'devolucion_prestamo_interno' => 'Devolución interna de bins de planta',
        'reasignacion_salida' => 'Reasignación: sale la guía',
        'reasignacion_entrada' => 'Reasignación: entra la guía',
    ];

    /** Préstamo de bins de planta y su devolución: trazabilidad interna, con efecto neto 0. */
    private const PRESTAMO_INTERNO = [
        TipoMovimientoEnvase::PrestamoInterno,
        TipoMovimientoEnvase::DevolucionPrestamoInterno,
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

    /** «CSG 90624 · Productor · devuelve guía 2324», o sin productor si la devolución no lo trae. */
    public static function etiquetaDevolucion(?array $devolucion): string
    {
        $productor = $devolucion['productor'] ?? null;
        if (! $productor || empty($productor['codigo'])) {
            return 'Sin productor declarado';
        }

        return implode(' · ', array_filter([
            'CSG '.$productor['codigo'],
            $productor['nombre'] ?? null,
            ! empty($devolucion['guia_salida']) ? 'devuelve guía '.$devolucion['guia_salida'] : null,
        ]));
    }

    /** Datos del ajuste de administración que corrige solo la cuenta de una recepción, si lo es. */
    public static function ajusteRecepcion(MovimientoEnvase $movimiento): ?array
    {
        return $movimiento->tipo_movimiento === TipoMovimientoEnvase::AjusteCuenta && ! empty($movimiento->datos['ajuste_recepcion']['lado'])
            ? $movimiento->datos['ajuste_recepcion'] : null;
    }

    /** Datos de la guía reasignada que trae una entrada de reasignación, si lo es. */
    public static function reasignacion(MovimientoEnvase $movimiento): ?array
    {
        return $movimiento->tipo_movimiento === TipoMovimientoEnvase::ReasignacionEntrada ? ($movimiento->datos['reasignacion'] ?? null) : null;
    }

    /**
     * Tipo con el que el cliente ve el movimiento: la entrada de una guía reasignada se ve como la guía
     * original (ingreso, entrega en el destare, despacho o devolución), en la cuenta y con el productor de destino.
     */
    private static function tipoVisible(MovimientoEnvase $movimiento): TipoMovimientoEnvase
    {
        $reasignacion = self::reasignacion($movimiento);

        return $reasignacion ? (TipoMovimientoEnvase::tryFrom($reasignacion['tipo'] ?? '') ?? $movimiento->tipo_movimiento) : $movimiento->tipo_movimiento;
    }

    private static function recepcionId(MovimientoEnvase $movimiento): ?string
    {
        return $movimiento->recepcion_romana_id ?? self::reasignacion($movimiento)['recepcion_id'] ?? null;
    }

    private static function guiaDespachoId(MovimientoEnvase $movimiento): ?string
    {
        return $movimiento->documento_tipo === self::DOCUMENTO_GUIA ? $movimiento->documento_id
            : (self::reasignacion($movimiento)['guia_despacho_id'] ?? null);
    }

    /**
     * Asiento al que se suma este movimiento en la vista del cliente (la anulación, corrección o reasignación
     * de otro), o null si es un asiento propio.
     *
     * @param  Collection<string, MovimientoEnvase>  $porId
     */
    public static function corrige(MovimientoEnvase $movimiento, Collection $porId): ?string
    {
        return match ($movimiento->tipo_movimiento) {
            TipoMovimientoEnvase::ReversionDespacho => $movimiento->datos['reversa_de_movimiento_id'] ?? null,
            TipoMovimientoEnvase::ConciliacionSalida => $movimiento->datos['movimiento_conciliado_id'] ?? null,
            // Un ajuste de recepción reemplazado o revertido no se muestra: queda solo el vigente.
            TipoMovimientoEnvase::ReversionAjusteCuenta => isset($porId[$movimiento->movimiento_origen_id])
                && self::ajusteRecepcion($porId[$movimiento->movimiento_origen_id]) !== null ? $movimiento->movimiento_origen_id : null,
            // La guía reasignada sale de su cuenta como una anulación: se suma al asiento que reasigna.
            TipoMovimientoEnvase::ReasignacionSalida => $movimiento->datos['reasignacion']['raiz_id'] ?? null,
            TipoMovimientoEnvase::ReversionSalidaRecepcionFruta,
            TipoMovimientoEnvase::CorreccionCantidad,
            TipoMovimientoEnvase::CorreccionCuenta,
            TipoMovimientoEnvase::AnulacionRecepcion,
            TipoMovimientoEnvase::CorreccionPropiedad => $movimiento->movimiento_origen_id,
            default => null,
        };
    }

    /** CSG que recibió los envases del destare: el elegido en Romana o, si hay uno solo, ese. */
    private static function csgSalida(array $recepcionesCsg, ?string $recepcionId): ?array
    {
        $datos = $recepcionesCsg[$recepcionId ?? ''] ?? null;
        if (! empty($datos['salida']['codigo'])) {
            return ['codigo' => $datos['salida']['codigo'], 'nombre' => $datos['salida']['nombre'] ?? null];
        }

        return count($datos['csg'] ?? []) === 1 ? $datos['csg'][0] : null;
    }

    /**
     * CSG de un ajuste de administración de una recepción: los recibidos, al productor indicado al ajustar;
     * los entregados siguen a la salida del destare (el CSG declarado en Romana o después en el ajuste).
     */
    private static function csgAjuste(array $ajuste, array $recepcionesCsg, ?string $recepcionId): ?array
    {
        return $ajuste['lado'] === 'entregados' ? (self::csgSalida($recepcionesCsg, $recepcionId) ?? $ajuste['productor'] ?? null)
            : ($ajuste['productor'] ?? null);
    }

    private static function etiquetaProductor(?array $productor): string
    {
        return empty($productor['codigo']) ? 'Sin productor declarado'
            : 'CSG '.$productor['codigo'].(! empty($productor['nombre']) ? ' · '.$productor['nombre'] : '');
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
     * Las fechas de los filtros son jornadas de planta; los movimientos se guardan en UTC.
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
        return app(CalendarioOperativo::class)->rango($desde, $hasta);
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
        $guiasEmitidas = GuiaDespachoEnvase::query()->where('temporada_id', $filtros['temporada_id'])
            ->pluck('numero_guia_despacho', 'id');
        $saldos = [];

        foreach ($todos->lazy(500) as $movimiento) {
            $clave = $movimiento->cliente_id.'|'.$movimiento->tipo_envase->value;
            $impactoCuenta = $movimiento->cantidad * $movimiento->signo_cuenta;
            $saldos[$clave] = ($saldos[$clave] ?? 0) + $impactoCuenta;
            if (! $idsVisibles->has($movimiento->id)) {
                continue;
            }

            $revision = $movimiento->revisiones->sortByDesc('revisado_at')->first();
            $correccion = $movimiento->datos['correccion_propiedad'] ?? $movimiento->datos['correccion_cantidad'] ?? $movimiento->datos['ajuste_cuenta'] ?? $movimiento->datos['correccion_cuenta'] ?? $movimiento->datos['anulacion_recepcion'] ?? $movimiento->datos['reasignacion'] ?? null;

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
     * - en los despachos se informa el N° de la guía emitida en el sistema de facturación;
     * - el préstamo interno de bins de planta no aparece: se devuelve en el destare sin salir
     *   de la planta, y mientras el camión sigue en planta el saldo al cliente no lo descuenta.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  list<string>|null  $tiposEnvase  solo esos envases (por ejemplo, solo esponjas); null o vacío, todos.
     */
    public function estadoCuenta(Cliente $cliente, Temporada $temporada, ?string $desde, ?string $hasta, ?array $tiposEnvase = null): array
    {
        $tiposEnvase = $tiposEnvase ? array_values(array_unique($tiposEnvase)) : null;
        // Cada asiento y sus correcciones son del mismo envase: filtrar por tipo deja la cuenta de ese envase completa.
        $movimientos = MovimientoEnvase::query()
            ->where('temporada_id', $temporada->id)
            ->where('cliente_id', $cliente->id)
            ->where('signo_cuenta', '!=', 0)
            ->when($tiposEnvase, fn ($consulta) => $consulta->whereIn('tipo_envase', $tiposEnvase))
            ->whereNotIn('tipo_movimiento', array_map(fn (TipoMovimientoEnvase $tipo): string => $tipo->value, self::PRESTAMO_INTERNO))
            ->orderBy('ocurrido_at')
            ->orderBy('id')
            ->get();
        $idsGuias = $movimientos->map(fn (MovimientoEnvase $m): ?string => self::guiaDespachoId($m))->filter()->unique()->values();
        $guiasEmitidas = GuiaDespachoEnvase::query()->whereKey($idsGuias)
            ->get(['id', 'numero', 'numero_guia_despacho', 'productor_csg_id', 'productor_snapshot', 'nombre_conductor', 'rut_conductor', 'patente_camion'])->keyBy('id');
        $recepcionesCsg = $this->recepcionesCsg($movimientos);
        $guiasRecepcion = $this->guiasRecepcion($movimientos);
        [$inicio, $fin] = $this->rangoOperacional($desde, $hasta);

        $tipos = [];
        $lineas = [];
        $indices = [];
        $documentos = [];
        $saldos = [];
        $anterioresCsg = [];
        $historicos = $movimientos->where('tipo_movimiento', TipoMovimientoEnvase::CorreccionCuenta)->pluck('movimiento_origen_id')->all();
        $porDocumento = [];
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

            if ($fin !== null && $movimiento->ocurrido_at->gte($fin)) {
                continue;
            }
            // Un ajuste de administración sobre una recepción corrige sus recibidos o sus entregados,
            // con signo: −2 recibidos no se lee como 2 entregados.
            $ajusteRecepcion = self::ajusteRecepcion($movimiento);
            // Una guía reasignada se ve como la guía original, con el productor de destino.
            $reasignacion = self::reasignacion($movimiento);
            $tipoVisible = self::tipoVisible($movimiento);
            $esSalida = $ajusteRecepcion !== null ? $ajusteRecepcion['lado'] === 'entregados'
                : ($reasignacion !== null ? $reasignacion['lado'] === 'entregados'
                    : (in_array($movimiento->tipo_movimiento, self::SALIDAS, true)
                        || (($movimiento->tipo_movimiento->esAjusteCuenta() || in_array($movimiento->id, $historicos, true)) && $impacto < 0)));
            $guiaId = self::guiaDespachoId($movimiento);
            $guia = $guiaId ? $guiasEmitidas->get($guiaId) : null;
            $destinatario = $reasignacion !== null ? ($reasignacion['productor'] ?? null) : $guia?->productorDestino();
            // La devolución de un productor conserva el CSG declarado en Romana o al asociarla.
            $devolucion = $tipoVisible === TipoMovimientoEnvase::DevolucionEnvases
                ? ($movimiento->datos['devolucion'] ?? null) : null;
            $movimientoCsg = [
                'recepcion_id' => $movimiento->recepcion_romana_id,
                'movimiento' => $movimiento->tipo_movimiento->value,
                'tipo_envase' => $tipo, 'nombre_envase' => $movimiento->tipo_envase->etiqueta(),
                'orden' => $movimiento->tipo_envase->orden(),
                'entradas' => $esSalida ? 0 : $impacto, 'salidas' => $esSalida ? -$impacto : 0,
                'csg' => $reasignacion !== null ? ($reasignacion['productor'] ?? null) : match ($movimiento->tipo_movimiento) {
                    TipoMovimientoEnvase::DespachoCliente => $destinatario,
                    TipoMovimientoEnvase::SalidaRecepcionFruta => self::csgSalida($recepcionesCsg, $movimiento->recepcion_romana_id),
                    TipoMovimientoEnvase::DevolucionEnvases => $devolucion['productor'] ?? null,
                    TipoMovimientoEnvase::AjusteCuenta => $ajusteRecepcion !== null
                        ? self::csgAjuste($ajusteRecepcion, $recepcionesCsg, $movimiento->recepcion_romana_id) : null,
                    default => null,
                },
            ];
            if ($inicio !== null && $movimiento->ocurrido_at->lt($inicio)) {
                $tipos[$tipo]['saldo_inicial'] += $impacto;
                $anterioresCsg[] = $movimientoCsg;

                continue;
            }
            if ($esSalida) {
                $tipos[$tipo]['salidas'] -= $impacto;
                $tipos[$tipo]['salidas_por_propiedad'][$movimiento->propiedad->value] -= $impacto;
            } else {
                $tipos[$tipo]['entradas'] += $impacto;
            }

            // Una línea por guía y tipo de envase, como en la guía: los orígenes internos de
            // un mismo envase (FIFO, varias recepciones) no se le muestran al cliente. Una recepción
            // de fruta con varias guías se muestra separada por guía, con lo validado de cada una.
            $partes = $this->partesPorGuia($movimiento, $impacto, $esSalida, $guiasRecepcion) ?? [[
                // Los ajustes de recibidos y de entregados de una recepción son documentos distintos; cada
                // reasignación es su propio documento.
                'impacto' => $impacto, 'clave' => $reasignacion['documento_clave'] ?? $ajusteRecepcion['lado'] ?? '',
                'documento' => $guia?->numero_guia_despacho ?: $movimiento->numero_documento,
                'productor' => $reasignacion !== null ? self::etiquetaProductor($reasignacion['productor'] ?? null) : match ($movimiento->tipo_movimiento) {
                    TipoMovimientoEnvase::DespachoCliente => $destinatario
                        ? 'CSG '.$destinatario['codigo'].' · '.$destinatario['nombre'] : 'Sin productor asignado',
                    TipoMovimientoEnvase::RecepcionFruta => $recepcionesCsg[$movimiento->recepcion_romana_id]['productor'] ?? 'Sin productor declarado',
                    TipoMovimientoEnvase::DevolucionEnvases => self::etiquetaDevolucion($devolucion),
                    TipoMovimientoEnvase::AjusteCuenta => $ajusteRecepcion !== null
                        ? self::etiquetaProductor(self::csgAjuste($ajusteRecepcion, $recepcionesCsg, $movimiento->recepcion_romana_id)) : null,
                    // La entrega en el destare: el CSG elegido en Romana o el único de la recepción.
                    TipoMovimientoEnvase::SalidaRecepcionFruta => ($csgSalida = self::csgSalida($recepcionesCsg, $movimiento->recepcion_romana_id))
                        ? self::etiquetaProductor($csgSalida) : null,
                    default => null,
                },
            ]];
            $saldoLinea = $saldos[$tipo] - $impacto;
            foreach ($partes as $parte) {
                $saldoLinea += $parte['impacto'];
                $claveDocumento = implode('|', [
                    $movimiento->ocurrido_at->getTimestamp(),
                    $movimiento->documento_id ?? $movimiento->numero_documento,
                    $movimiento->tipo_movimiento->value,
                    $parte['clave'],
                ]);
                $clave = $claveDocumento.'|'.$tipo.'|'.$movimiento->propiedad->value;
                $documentos[$claveDocumento] ??= count($documentos);
                $porDocumento[$claveDocumento] ??= ['movimiento' => $movimiento, 'parte' => $parte, 'guia' => $guia,
                    'historico' => in_array($movimiento->id, $historicos, true), 'tipo' => $tipoVisible, 'tipos' => []];
                $porDocumento[$claveDocumento]['tipos'][$tipo] ??= ['nombre' => $movimiento->tipo_envase->etiqueta(),
                    'orden' => $movimiento->tipo_envase->orden(), 'entradas' => 0, 'salidas' => 0, 'propiedades' => []];
                $porDocumento[$claveDocumento]['tipos'][$tipo][$esSalida ? 'salidas' : 'entradas'] += $esSalida ? -$parte['impacto'] : $parte['impacto'];
                // Neto por propiedad (entradas +, salidas −), para el Excel con la propiedad en su columna.
                $propiedad = $movimiento->propiedad->value;
                $porDocumento[$claveDocumento]['tipos'][$tipo]['propiedades'][$propiedad]
                    = ($porDocumento[$claveDocumento]['tipos'][$tipo]['propiedades'][$propiedad] ?? 0) + $parte['impacto'];
                if (isset($indices[$clave])) {
                    $lineas[$indices[$clave]][$esSalida ? 'salida' : 'entrada'] += $esSalida ? -$parte['impacto'] : $parte['impacto'];
                    $lineas[$indices[$clave]]['saldo'] = $saldoLinea;

                    continue;
                }
                $indices[$clave] = count($lineas);
                $lineas[] = [
                    'orden' => [$documentos[$claveDocumento], $movimiento->tipo_envase->orden(), $movimiento->propiedad->value],
                    'fecha' => $this->local($movimiento->ocurrido_at),
                    'documento' => $parte['documento'],
                    'movimiento' => match (true) {
                        $ajusteRecepcion !== null => 'Ajuste de '.$ajusteRecepcion['lado'].' de '.$ajusteRecepcion['numero_recepcion'].' (administración)',
                        in_array($movimiento->id, $historicos, true) => 'Saldo anterior corregido',
                        default => self::etiquetaMovimiento($tipoVisible),
                    },
                    'productor' => $parte['productor'],
                    'tipo_envase' => $movimiento->tipo_envase->etiqueta(),
                    'propiedad' => self::etiquetaPropiedad($movimiento->propiedad),
                    'entrada' => $esSalida ? null : $parte['impacto'],
                    'salida' => $esSalida ? -$parte['impacto'] : null,
                    'saldo' => $saldoLinea,
                ];
            }
        }
        foreach ($tipos as $tipo => $resumen) {
            $tipos[$tipo]['saldo_final'] = $resumen['saldo_inicial'] + $resumen['entradas'] - $resumen['salidas'];
        }
        // Dentro de una guía con varios envases, las líneas van en el orden del catálogo.
        usort($lineas, fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);
        // Dos documentos distintos pueden compartir guía, fecha y productor.
        // La agrupación visual conserva la identidad interna del documento.
        $gruposDocumentos = [];
        foreach ($lineas as $linea) {
            $indice = $linea['orden'][0];
            $gruposDocumentos[$indice] ??= [
                'fecha' => $linea['fecha'], 'documento' => $linea['documento'],
                'movimiento' => $linea['movimiento'], 'productor' => $linea['productor'], 'lineas' => [],
            ];
            unset($linea['orden']);
            $gruposDocumentos[$indice]['lineas'][] = $linea;
        }
        $lineas = array_map(function (array $linea): array {
            unset($linea['orden']);

            return $linea;
        }, $lineas);
        [$detalleCsg, $resumenCsg, $filasExcel] = $this->detallePorCsg($porDocumento, $recepcionesCsg, $anterioresCsg, $movimientos, $inicio);
        $pendientes = DetalleEnvaseRecepcionRomana::query()
            ->whereHas('recepcion', fn (Builder $recepcion) => $recepcion
                ->where('cliente_id', $cliente->id)
                ->where('temporada_id', $temporada->id)
                ->where('estado', '!=', EstadoRecepcionRomana::Anulada->value)
                ->where('estado_validacion_mp', '!=', EstadoValidacionMp::Validada->value))
            ->count();

        return [
            'cliente' => ['codigo' => $cliente->codigo, 'nombre' => $cliente->nombre],
            'temporada' => ['codigo' => $temporada->codigo, 'nombre' => $temporada->nombre],
            'desde' => $desde,
            'hasta' => $hasta,
            // Envases elegidos para el documento; null cuando incluye todos.
            'envases_incluidos' => $tiposEnvase === null ? null : collect($tiposEnvase)
                ->map(fn (string $tipo): TipoEnvaseRomana => TipoEnvaseRomana::from($tipo))
                ->sortBy(fn (TipoEnvaseRomana $tipo): int => $tipo->orden())
                ->map(fn (TipoEnvaseRomana $tipo): string => $tipo->etiqueta())->values()->all(),
            'tipos' => collect($tipos)->sortBy('orden')->values()->all(),
            'movimientos' => $lineas,
            'grupos_documentos' => array_values($gruposDocumentos),
            'resumen_csg' => $resumenCsg,
            'detalle_csg' => $detalleCsg,
            'filas_excel' => $filasExcel,
            'lineas_pendientes_validacion' => $pendientes,
            'emitido_at' => $this->local(now()),
        ];
    }

    /**
     * Detalle por productor: cada documento se atribuye a su CSG con las reglas del resumen
     * (sin prorrateos) y el resumen se arma con esas mismas atribuciones.
     *
     * - Ingreso con fruta: el CSG de la recepción; con varios, el reparto validado de la guía
     *   o de la recepción cuando cuadra con lo ingresado. Si no cuadra, «sin productor».
     * - Entrega en el destare: el CSG de la recepción cuando es uno solo.
     * - Despacho de envases: el productor de la guía.
     * - Ajustes, aperturas y correcciones: sin productor.
     *
     * @param  array<string, array<string, mixed>>  $porDocumento  en orden cronológico
     * @param  Collection<int, MovimientoEnvase>  $movimientos
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function detallePorCsg(array $porDocumento, array $recepcionesCsg, array $anterioresCsg, Collection $movimientos, ?CarbonImmutable $inicio): array
    {
        $recepciones = RecepcionRomana::query()
            ->whereKey($movimientos->map(fn (MovimientoEnvase $m): ?string => self::recepcionId($m))->filter()->unique()->values())
            ->get(['id', 'numero_recepcion', 'numero_guia_despacho', 'numero_guia_salida', 'nombre_conductor',
                'rut_conductor', 'patente_camion', 'patente_carro', 'salida_productor_snapshot'])
            ->keyBy('id');
        $repartoGuias = $this->repartoGuias($movimientos);
        $documentos = [];
        $asignacionesResumen = [];
        $tipos = [];
        foreach ($porDocumento as ['movimiento' => $movimiento, 'parte' => $parte, 'guia' => $guia, 'historico' => $historico, 'tipo' => $tipoVisible, 'tipos' => $porTipo]) {
            foreach ($porTipo as $tipo => $fila) {
                $tipos[$tipo] = ['nombre' => $fila['nombre'], 'orden' => $fila['orden']];
            }
            $recepcion = $recepciones->get(self::recepcionId($movimiento));
            $opciones = $recepcionesCsg[$movimiento->recepcion_romana_id]['csg'] ?? [];
            $reasignacion = self::reasignacion($movimiento);
            $asignaciones = $reasignacion !== null ? [['csg' => $reasignacion['productor'] ?? null, 'compartido' => null, 'tipos' => $porTipo]] : match ($movimiento->tipo_movimiento) {
                TipoMovimientoEnvase::DespachoCliente => [['csg' => $guia?->productorDestino(), 'compartido' => null, 'tipos' => $porTipo]],
                TipoMovimientoEnvase::SalidaRecepcionFruta => [['csg' => self::csgSalida($recepcionesCsg, $movimiento->recepcion_romana_id), 'compartido' => null, 'tipos' => $porTipo]],
                // La devolución de un productor conserva el CSG declarado en Romana o al asociarla.
                TipoMovimientoEnvase::DevolucionEnvases => [['csg' => $movimiento->datos['devolucion']['productor'] ?? null, 'compartido' => null, 'tipos' => $porTipo]],
                // El ajuste de recibidos va al productor indicado al ajustar (o al único de la recepción); el de
                // entregados, al CSG de la salida del destare.
                TipoMovimientoEnvase::AjusteCuenta => [['csg' => ($ajuste = self::ajusteRecepcion($movimiento)) !== null
                    ? self::csgAjuste($ajuste, $recepcionesCsg, $movimiento->recepcion_romana_id) : null, 'compartido' => null, 'tipos' => $porTipo]],
                TipoMovimientoEnvase::RecepcionFruta => $this->asignarIngreso($porTipo,
                    $parte['clave'] !== '' ? $opciones : ($recepcionesCsg[$movimiento->recepcion_romana_id]['csg_ingreso'] ?? $opciones),
                    $parte['clave'] !== '' ? ($repartoGuias[$parte['clave']] ?? []) : ($recepcionesCsg[$movimiento->recepcion_romana_id]['envases'] ?? [])),
                default => [['csg' => null, 'compartido' => null, 'tipos' => $porTipo]],
            };
            foreach ($asignaciones as $asignacion) {
                foreach ($asignacion['tipos'] as $tipo => $fila) {
                    $asignacionesResumen[] = ['csg' => $asignacion['csg'], 'tipo_envase' => $tipo, 'nombre_envase' => $fila['nombre'],
                        'orden' => $fila['orden'], 'entradas' => $fila['entradas'], 'salidas' => $fila['salidas']];
                }
            }
            $documentos[] = ['fecha' => $this->local($movimiento->ocurrido_at), 'asignaciones' => $asignaciones, 'guia' => $parte['documento'],
                ...$this->identidadDocumento($movimiento, $parte, $guia, $recepcion, $historico, $reasignacion !== null ? [] : $opciones, $tipoVisible)];
        }
        $resumen = ResumenEnvasesPorCsg::desdeAsignaciones($asignacionesResumen, $recepcionesCsg, $anterioresCsg);
        foreach ($anterioresCsg as $anterior) {
            $tipos[$anterior['tipo_envase']] ??= ['nombre' => $anterior['nombre_envase'], 'orden' => $anterior['orden']];
        }
        $saldosIniciales = ResumenEnvasesPorCsg::saldosIniciales($recepcionesCsg, $anterioresCsg);
        $detalle = DetalleEnvasesPorCsg::armar($documentos, $saldosIniciales, $tipos);
        $nombres = collect($resumen)->mapWithKeys(fn (array $g): array => [(string) $g['codigo'] => $g['nombre']]);
        foreach ($detalle as &$grupo) {
            $grupo['nombre'] = $grupo['nombre'] ?: ($nombres[(string) $grupo['codigo']] ?? '');
        }
        unset($grupo);
        // Con los nombres completos, los CSG de una misma razón social quedan juntos.
        usort($detalle, [ResumenEnvasesPorCsg::class, 'comparar']);

        return [$detalle, $resumen, $this->filasExcel($documentos, $detalle, $saldosIniciales, $inicio === null ? null : $this->local($inicio))];
    }

    /**
     * Filas del Excel del estado de cuenta: una por documento, CSG y propiedad, con una columna por envase.
     * Las entradas van en positivo y las salidas en negativo, así la suma de cada columna es el saldo; con un
     * período, cada CSG parte con su saldo al inicio. Mismo orden que el detalle por productor: razón social,
     * CSG y fecha.
     *
     * @param  list<array<string, mixed>>  $documentos  en orden cronológico, con sus asignaciones por CSG
     * @param  list<array<string, mixed>>  $detalle  grupos ya ordenados
     * @param  array<string, array<string, int>>  $saldosIniciales  por clave de CSG y tipo de envase
     * @return list<array<string, mixed>>
     */
    private function filasExcel(array $documentos, array $detalle, array $saldosIniciales, ?string $inicio): array
    {
        $grupos = [];
        foreach ($detalle as $orden => $grupo) {
            $grupos[ResumenEnvasesPorCsg::clave($grupo['codigo'] === null ? null : ['codigo' => $grupo['codigo']])] = [
                'orden' => $orden, 'csg' => $grupo['codigo'], 'productor' => $grupo['codigo'] === null ? 'Sin productor asociado' : $grupo['nombre']];
        }
        $propiedades = array_flip(array_map(fn (PropiedadEnvase $p): string => $p->value, PropiedadEnvase::cases()));
        $filas = [];
        $agregar = function (string $clave, int $documento, string $propiedad, array $base, array $cantidades) use (&$filas, $grupos, $propiedades): void {
            $grupo = $grupos[$clave] ?? ['orden' => PHP_INT_MAX, 'csg' => null, 'productor' => 'Sin productor asociado'];
            $fila = [...$base, 'csg' => $grupo['csg'], 'productor' => $grupo['productor'],
                'propiedad' => match ($propiedad) {
                    '' => null,
                    'varias' => 'Varias',
                    default => self::etiquetaPropiedad(PropiedadEnvase::from($propiedad)),
                }];
            foreach ($cantidades as $tipo => $cantidad) {
                $fila['envase_'.$tipo] = $cantidad;
            }
            $filas[] = ['orden' => [$grupo['orden'], $documento, $propiedades[$propiedad] ?? -1], 'fila' => $fila];
        };
        if ($inicio !== null) {
            foreach ($saldosIniciales as $clave => $porTipo) {
                if ($porTipo = array_filter($porTipo)) {
                    $agregar($clave, -1, '', ['fecha' => $inicio, 'movimiento' => 'Saldo al inicio del período', 'guia' => null], $porTipo);
                }
            }
        }
        foreach ($documentos as $indice => $documento) {
            foreach ($documento['asignaciones'] as $asignacion) {
                $porPropiedad = [];
                foreach ($asignacion['tipos'] as $tipo => $fila) {
                    $neto = $fila['entradas'] - $fila['salidas'];
                    if ($fila['entradas'] === 0 && $fila['salidas'] === 0) {
                        continue;
                    }
                    $netos = array_filter($fila['propiedades'] ?? []);
                    if (count($netos) === 1) {
                        $porPropiedad[array_key_first($netos)][$tipo] = $neto;
                    } elseif ($netos && array_sum($netos) === $neto) {
                        foreach ($netos as $propiedad => $cantidad) {
                            $porPropiedad[$propiedad][$tipo] = $cantidad;
                        }
                    } else {
                        // Parte de un envase con dos propiedades en una guía repartida entre CSG: no se prorratea.
                        $porPropiedad['varias'][$tipo] = $neto;
                    }
                }
                foreach ($porPropiedad as $propiedad => $cantidades) {
                    $agregar(ResumenEnvasesPorCsg::clave($asignacion['csg']), $indice, (string) $propiedad,
                        ['fecha' => $documento['fecha'], 'movimiento' => $documento['movimiento'], 'guia' => $documento['guia']], $cantidades);
                }
            }
        }
        usort($filas, fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);

        return array_column($filas, 'fila');
    }

    /**
     * Ingreso con fruta de un CSG o repartido según lo validado; si el reparto no cuadra con lo
     * ingresado de un envase, ese envase queda sin productor, como en el resumen.
     *
     * @param  array<string, array{nombre: string, orden: int, entradas: int, salidas: int}>  $porTipo
     * @param  list<array{codigo: ?string, nombre: ?string}>  $opciones
     * @param  array<string, array<string, array{codigo: ?string, nombre: ?string, cantidad: int}>>  $reparto
     */
    private function asignarIngreso(array $porTipo, array $opciones, array $reparto): array
    {
        if (count($opciones) <= 1) {
            return [['csg' => $opciones[0] ?? null, 'compartido' => null, 'tipos' => $porTipo]];
        }
        $asignaciones = [];
        $agregar = function (?array $csg, string $tipo, array $fila) use (&$asignaciones): void {
            $clave = ResumenEnvasesPorCsg::clave($csg);
            $asignaciones[$clave] ??= ['csg' => $csg, 'compartido' => null, 'tipos' => []];
            $asignaciones[$clave]['tipos'][$tipo] = $fila;
        };
        foreach ($porTipo as $tipo => $fila) {
            $partes = array_values(array_filter($reparto[$tipo] ?? [], fn (array $p): bool => $p['cantidad'] > 0));
            $cuadra = $partes && $fila['salidas'] === 0 && array_sum(array_column($partes, 'cantidad')) === $fila['entradas'];
            if (! $cuadra) {
                $agregar(null, $tipo, $fila);

                continue;
            }
            foreach ($partes as $p) {
                $agregar(['codigo' => $p['codigo'], 'nombre' => $p['nombre']], $tipo, [...$fila, 'entradas' => $p['cantidad']]);
            }
        }
        $total = count($asignaciones);
        foreach ($asignaciones as &$asignacion) {
            if ($total > 1) {
                $otros = array_map(fn (array $a): string => $a['csg']['codigo'] ?? 'sin productor',
                    array_filter($asignaciones, fn (array $a): bool => $a !== $asignacion));
                $asignacion['compartido'] = 'Guía compartida con '.implode(', ', array_map(
                    fn (string $c): string => $c === 'sin productor' ? 'envases sin productor' : 'CSG '.$c, $otros,
                )).': aquí solo la parte de este productor.';
            }
        }
        unset($asignacion);

        return array_values($asignaciones);
    }

    /**
     * Identificación sin interpretaciones: qué documento es, quién lo trajo o se llevó los
     * envases y de qué recepción viene.
     *
     * @return array{titulo: string, chofer: ?string, referencia: ?string, movimiento: string}
     */
    private function identidadDocumento(MovimientoEnvase $movimiento, array $parte, ?GuiaDespachoEnvase $guia, ?RecepcionRomana $recepcion, bool $historico, array $opciones, ?TipoMovimientoEnvase $tipo = null): array
    {
        $numero = $parte['documento'];
        $vehiculo = fn (?string $camion, ?string $carro): ?string => $camion
            ? 'Camión '.$camion.($carro ? ' · carro '.$carro : '') : null;
        $unir = fn (array $partes): ?string => implode(' · ', array_filter($partes)) ?: null;
        // El RUT se guarda sin puntos; en el documento se lee como en la cédula.
        $rutLegible = fn (string $rut): string => preg_match('/^(\d{1,9})-([\dkK])$/', $rut, $m)
            ? number_format((int) $m[1], 0, ',', '.').'-'.strtoupper($m[2]) : $rut;
        $chofer = fn (?string $nombre, ?string $rut): ?string => $nombre ? 'Chofer '.$nombre.($rut ? ' (RUT '.$rutLegible($rut).')' : '') : null;
        if ($ajuste = self::ajusteRecepcion($movimiento)) {
            $registrado = isset($ajuste['registrado_at'])
                ? CarbonImmutable::parse($ajuste['registrado_at'])->setTimezone(config('app.operational_timezone'))->format('d-m-Y') : null;

            return [
                'titulo' => $ajuste['lado'] === 'recibidos'
                    ? 'Ajuste a la guía de ingreso N° '.($numero ?: 'sin número')
                    : ($numero ? 'Ajuste a la guía de salida N° '.$numero : 'Ajuste a la salida del destare'),
                'chofer' => null,
                'movimiento' => 'Ajuste de '.$ajuste['lado'].' por administración',
                // El motivo es trazabilidad interna (RPR-01, kardex y expediente): el estado de cuenta es formal e informativo.
                'referencia' => $unir([
                    'Recepción '.$ajuste['numero_recepcion'],
                    $registrado ? 'registrado el '.$registrado : null,
                ]),
            ];
        }

        return match ($tipo ?? $movimiento->tipo_movimiento) {
            TipoMovimientoEnvase::RecepcionFruta => [
                'titulo' => 'Guía de ingreso N° '.($numero ?: 'sin número'),
                'chofer' => $chofer($recepcion?->nombre_conductor, $recepcion?->rut_conductor),
                'movimiento' => 'Ingreso con fruta',
                'referencia' => $unir([$recepcion ? 'Recepción '.$recepcion->numero_recepcion : null, $vehiculo($recepcion?->patente_camion, $recepcion?->patente_carro)]),
            ],
            TipoMovimientoEnvase::SalidaRecepcionFruta => [
                'titulo' => $numero ? 'Guía de salida N° '.$numero : 'Salida sin guía',
                'chofer' => $chofer($recepcion?->nombre_conductor, $recepcion?->rut_conductor),
                'movimiento' => 'Entregados en el destare',
                'referencia' => $unir([
                    'Destare de '.($recepcion?->numero_recepcion ?? 'la recepción').($recepcion?->numero_guia_despacho ? ' (guía de ingreso '.$recepcion->numero_guia_despacho.')' : ''),
                    $vehiculo($recepcion?->patente_camion, $recepcion?->patente_carro),
                    count($opciones) > 1 ? (! empty($recepcion?->salida_productor_snapshot['codigo'])
                        ? 'Entregados a '.self::etiquetaProductor($recepcion->salida_productor_snapshot).' (camión con '.count($opciones).' CSG)'
                        : 'Recepción con '.count($opciones).' CSG: la entrega no se reparte por productor') : null,
                ]),
            ],
            TipoMovimientoEnvase::DevolucionEnvases => [
                'titulo' => 'Guía de ingreso N° '.($numero ?: 'sin número'),
                'chofer' => $chofer($recepcion?->nombre_conductor, $recepcion?->rut_conductor),
                'movimiento' => 'Devolución de envases',
                'referencia' => $unir([
                    $recepcion ? 'Recepción '.$recepcion->numero_recepcion : null,
                    ! empty($movimiento->datos['devolucion']['guia_salida']) ? 'devuelve la guía '.$movimiento->datos['devolucion']['guia_salida'] : null,
                    $vehiculo($recepcion?->patente_camion, $recepcion?->patente_carro),
                ]),
            ],
            TipoMovimientoEnvase::DespachoCliente => [
                'titulo' => 'Guía de despacho N° '.($numero ?: 'sin número'),
                'chofer' => $chofer($guia?->nombre_conductor, $guia?->rut_conductor),
                'movimiento' => 'Despacho de envases',
                'referencia' => $unir([$guia?->numero ? 'Despacho '.$guia->numero : null, $vehiculo($guia?->patente_camion, null)]),
            ],
            default => [
                'titulo' => 'Documento '.($numero ?: 'sin número'),
                'chofer' => null,
                'movimiento' => $historico ? 'Saldo anterior corregido' : self::etiquetaMovimiento($tipo ?? $movimiento->tipo_movimiento),
                'referencia' => $recepcion ? 'Recepción '.$recepcion->numero_recepcion : null,
            ],
        };
    }

    /**
     * Reparto validado por guía de ingreso, CSG y envase, para las recepciones con varias guías.
     *
     * @param  Collection<int, MovimientoEnvase>  $movimientos
     * @return array<string, array<string, array<string, array{codigo: ?string, nombre: ?string, cantidad: int}>>>
     */
    private function repartoGuias(Collection $movimientos): array
    {
        $recepciones = $movimientos->where('tipo_movimiento', TipoMovimientoEnvase::RecepcionFruta)
            ->pluck('recepcion_romana_id')->filter()->unique()->values();
        if ($recepciones->isEmpty()) {
            return [];
        }
        $reparto = [];
        $segmentos = SegmentoValidacionMp::query()->whereNotNull('guia_recepcion_romana_id')
            ->whereHas('validacion', fn (Builder $v) => $v->whereIn('recepcion_romana_id', $recepciones)->where('estado', EstadoValidacionMp::Validada->value))
            ->with(['csg.productor', 'envases'])->get();
        foreach ($segmentos as $segmento) {
            $codigo = $segmento->csg_snapshot ?: $segmento->csg?->codigo;
            $nombre = $segmento->csg?->productor?->razon_social ?: $segmento->csg?->predio;
            $clave = $codigo ? 'csg:'.$codigo : 'sin_csg';
            foreach ($segmento->envases as $envase) {
                $tipo = $envase->tipo_envase->value;
                $reparto[$segmento->guia_recepcion_romana_id][$tipo][$clave] ??= ['codigo' => $codigo, 'nombre' => $nombre, 'cantidad' => 0];
                $reparto[$segmento->guia_recepcion_romana_id][$tipo][$clave]['cantidad'] += $envase->cantidad + $envase->cantidad_vacia;
            }
        }

        return $reparto;
    }

    /**
     * Recepciones de fruta con más de una guía vigente: lo validado de cada guía y sus productores.
     *
     * @param  Collection<int, MovimientoEnvase>  $movimientos
     * @return array<string, list<array{id: string, numero: string, envases: array<string, int>, productor: ?string}>>
     */
    private function guiasRecepcion(Collection $movimientos): array
    {
        $recepciones = $movimientos->where('tipo_movimiento', TipoMovimientoEnvase::RecepcionFruta)
            ->pluck('recepcion_romana_id')->filter()->unique()->values();
        if ($recepciones->isEmpty()) {
            return [];
        }
        $productores = SegmentoValidacionMp::query()->whereNotNull('guia_recepcion_romana_id')
            ->whereHas('validacion', fn (Builder $v) => $v->whereIn('recepcion_romana_id', $recepciones)->where('estado', EstadoValidacionMp::Validada->value))
            ->with('csg.productor')->get()->groupBy('guia_recepcion_romana_id')
            ->map(fn (Collection $segmentos): ?string => $segmentos->map(function (SegmentoValidacionMp $s): ?string {
                $codigo = $s->csg_snapshot ?: $s->csg?->codigo;
                $nombre = $s->csg?->productor?->razon_social ?: $s->csg?->predio;

                return $codigo ? 'CSG '.$codigo.($nombre ? ' · '.$nombre : '') : null;
            })->filter()->unique()->implode(' / ') ?: null);

        // Una guía reasignada por separado ya no es parte de su recepción: la recepción sigue separada por guía.
        $reasignadas = array_flip(ServicioReasignacionEnvases::guiasReasignadas($recepciones->all()));

        return GuiaRecepcionRomana::query()->whereIn('recepcion_romana_id', $recepciones)->where('vigente', 1)
            ->whereNotNull('envases_validados')->orderBy('secuencia')->get()
            ->groupBy('recepcion_romana_id')->filter(fn (Collection $guias): bool => $guias->count() > 1)
            ->map(fn (Collection $guias): Collection => $guias->reject(fn (GuiaRecepcionRomana $g): bool => isset($reasignadas[$g->id])))
            ->filter(fn (Collection $guias): bool => $guias->isNotEmpty())
            ->map(fn (Collection $guias): array => $guias->map(fn (GuiaRecepcionRomana $g): array => [
                'id' => $g->id, 'numero' => $g->numero_guia_despacho, 'productor' => $productores[$g->id] ?? null,
                'envases' => collect($g->envases_validados)->mapWithKeys(fn ($e): array => [$e['tipo_envase'] => (int) $e['cantidad']])->all(),
            ])->values()->all())->all();
    }

    /**
     * Reparte el ingreso de una recepción con varias guías en una parte por guía. Si lo validado por guía no
     * cuadra con el ingreso vigente (por ejemplo, tras un ajuste manual de cuenta), conserva una sola línea.
     *
     * @return list<array{impacto: int, clave: string, documento: string, productor: ?string}>|null
     */
    private function partesPorGuia(MovimientoEnvase $movimiento, int $impacto, bool $esSalida, array $guiasRecepcion): ?array
    {
        $guias = $guiasRecepcion[$movimiento->recepcion_romana_id] ?? null;
        if ($esSalida || $guias === null || $movimiento->tipo_movimiento !== TipoMovimientoEnvase::RecepcionFruta) {
            return null;
        }
        $tipo = $movimiento->tipo_envase->value;
        $cantidades = array_map(fn (array $g): int => $g['envases'][$tipo] ?? 0, $guias);
        if (array_sum($cantidades) !== $impacto) {
            return null;
        }
        $partes = [];
        foreach ($guias as $i => $guia) {
            if ($cantidades[$i] > 0) {
                $partes[] = ['impacto' => $cantidades[$i], 'clave' => $guia['id'], 'documento' => $guia['numero'],
                    'productor' => $guia['productor'] ?? 'Sin productor declarado'];
            }
        }

        return $partes;
    }

    /**
     * @param  Collection<int, MovimientoEnvase>  $movimientos
     * @return array<string, array{productor: ?string, csg: array, envases: array}>
     */
    private function recepcionesCsg(Collection $movimientos): array
    {
        $recepciones = $movimientos
            ->pluck('recepcion_romana_id')->filter()->unique()->values();
        if ($recepciones->isEmpty()) {
            return [];
        }

        // Productor al que Romana entregó los envases del destare, cuando el camión trae varios CSG.
        $salidas = RecepcionRomana::query()->whereKey($recepciones)->whereNotNull('salida_productor_snapshot')
            ->get(['id', 'salida_productor_snapshot'])->pluck('salida_productor_snapshot', 'id');
        // Las guías reasignadas por separado ya no reparten el ingreso de su recepción (el destare no cambia).
        $reasignadas = array_flip(ServicioReasignacionEnvases::guiasReasignadas($recepciones->all()));

        return ValidacionMp::query()
            ->whereIn('recepcion_romana_id', $recepciones)
            ->where('estado', EstadoValidacionMp::Validada)
            ->with(['segmentos.csg.productor', 'segmentos.envases'])
            ->get(['id', 'recepcion_romana_id'])
            ->keyBy('recepcion_romana_id')
            ->map(function (ValidacionMp $validacion, string $recepcionId) use ($salidas, $reasignadas): array {
                $csg = [];
                $ingreso = [];
                $envases = [];
                $etiquetas = [];
                foreach ($validacion->segmentos as $segmento) {
                    $codigo = $segmento->csg_snapshot ?: $segmento->csg?->codigo;
                    $nombre = $segmento->csg?->productor?->razon_social ?: $segmento->csg?->predio;
                    $clave = $codigo ? 'csg:'.$codigo : 'sin_csg';
                    $csg[$clave] = ['codigo' => $codigo, 'nombre' => $nombre];
                    if ($segmento->guia_recepcion_romana_id && isset($reasignadas[$segmento->guia_recepcion_romana_id])) {
                        continue;
                    }
                    $ingreso[$clave] = $csg[$clave];
                    if ($codigo) {
                        $etiquetas[] = 'CSG '.$codigo.($nombre ? ' · '.$nombre : '');
                    }
                    foreach ($segmento->envases as $envase) {
                        $tipo = $envase->tipo_envase->value;
                        $envases[$tipo][$clave] ??= [...$csg[$clave], 'cantidad' => 0];
                        // Los vacíos también son envases del productor en la cuenta corriente.
                        $envases[$tipo][$clave]['cantidad'] += $envase->cantidad + $envase->cantidad_vacia;
                    }
                }

                // «csg»: todos los productores del camión (el destare); «csg_ingreso»: los del ingreso que sigue en la recepción.
                return ['salida' => $salidas[$recepcionId] ?? null, 'productor' => implode(' / ', array_unique($etiquetas)) ?: null,
                    'csg' => array_values($csg), 'csg_ingreso' => array_values($ingreso), 'envases' => $envases];
            })->all();
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
            // El préstamo interno no mueve la existencia: no basta para mostrar el tipo.
            $propios = $filas->where('propiedad', PropiedadEnvase::Propia->value)
                ->reject(fn (object $fila): bool => TipoMovimientoEnvase::from($fila->tipo_movimiento)->esPrestamoInterno());
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
            $corrige = self::corrige($movimiento, $porId);
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

    private function local(?DateTimeInterface $fecha): ?string
    {
        return $fecha
            ? CarbonImmutable::instance($fecha)->setTimezone(config('app.operational_timezone'))->format('Y-m-d H:i:s')
            : null;
    }
}
