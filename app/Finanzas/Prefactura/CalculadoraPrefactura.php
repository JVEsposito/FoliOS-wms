<?php

namespace App\Finanzas\Prefactura;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\ConsultaHechos;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Models\Tarifa;
use App\Finanzas\Periodo;
use App\Finanzas\Tarifario\CatalogoServicios;
use App\Finanzas\Tarifario\ClientesOperacion;
use App\Finanzas\Tarifario\Precio;
use App\Finanzas\Tarifario\ResolutorTarifas;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Prefactura en paralelo: valoriza los hechos vigentes de un mes con el tarifario. No emite
 * documentos ni reemplaza la facturación; sirve para compararla.
 *
 * - Un hecho puntual se cobra con los precios que rigen en su fecha operacional.
 * - El almacenaje (estadía) se cobra por día o fracción en hora de planta, sin días libres,
 *   con las cantidades que había al empezar cada día (el primer día, las del ingreso).
 * - Una estadía que nace en un repaletizaje (el folio nuevo hereda la custodia de sus
 *   orígenes) cobra desde el día siguiente: ese día ya lo cobran los orígenes.
 * - Un folio no paga dos veces el mismo día, aunque salga de la cámara y vuelva a entrar.
 * - Un hecho sin cliente vinculado, o sin precio ese día, queda pendiente y no se valoriza.
 * - El mes en curso se valoriza hasta hoy: es una prefactura a la fecha.
 */
final class CalculadoraPrefactura
{
    private ResolutorTarifas $resolutor;

    /** @var array<string, Moneda> */
    private array $monedas = [];

    /** @var array<string, array<string, array<string, list<array{0: int, 1: string, 2: Tarifa}>>>> cliente => servicio => día => precios */
    private array $memo = [];

    /** @var array<string, array<string, mixed>> cliente|tarifa => línea en construcción */
    private array $lineas = [];

    /** @var array<string, array<string, array<int, true>>> cliente => servicio => hechos sin precio */
    private array $sinPrecio = [];

    /** @var array<string, int> fuente => hechos sin cliente vinculado */
    private array $sinCliente = [];

    /** @var array<string, string> cliente_id => nombre leído en los hechos */
    private array $nombres = [];

    /** @var array<string, int> folio => días del mes ya cobrados (un bit por día) */
    private array $diasCobrados = [];

    private bool $conDetalle = false;

    /** @var (callable(array<string, mixed>): void)|null recibe lo que aporta cada hecho a cada línea */
    private $alAportar = null;

    private DateTimeZone $zona;

    public function __construct(
        private readonly ConsultaHechos $consulta,
        private readonly CatalogoFuentes $fuentes,
        private readonly CatalogoServicios $servicios,
        private readonly ClientesOperacion $clientes,
    ) {}

    /**
     * Con $alAportar, cada hecho valorizado se informa una vez por línea, con su cantidad y sus
     * días: así se congela un mes al cerrarlo, o se compara con lo congelado, sin acumular en
     * memoria el detalle de todo el mes.
     *
     * @param  (callable(array{cliente_id: string, tarifa_id: int, moneda: string, hecho_id: int, fuente: string, origen_id: string, version: int, referencia: ?string, desde: string, hasta: string, dias: int, cantidad: string}): void)|null  $alAportar
     * @return array{periodo: array{mes: string, inicio: string, fin: string}, corte: ?string, a_la_fecha: bool, clientes: list<array<string, mixed>>, totales: list<array<string, mixed>>, sin_cliente: list<array<string, mixed>>, resumen: array<string, int>}
     */
    public function calcular(Periodo $periodo, ?string $cliente = null, bool $conDetalle = false, ?callable $alAportar = null): array
    {
        $this->reiniciar($conDetalle);
        $this->servicios->recargar();
        $this->alAportar = $alAportar;
        $this->zona = new DateTimeZone((string) config('app.operational_timezone'));
        $hoy = Periodo::hoy();
        $inicio = $periodo->inicio->toDateString();
        $fin = $periodo->fin->toDateString();
        // Un mes futuro no tiene nada que valorizar; el mes en curso, hasta hoy.
        $corte = $hoy < $inicio ? null : min($fin, $hoy);
        $this->resolutor = ResolutorTarifas::entre($inicio, $fin);
        $this->monedas = Moneda::query()->get()->keyBy('codigo')->all();

        if ($corte !== null) {
            $dias = $this->dias($periodo, $corte);
            $intervalos = $this->fuentes->intervalos();
            $hechos = $this->consulta->delPeriodo($periodo)
                ->where('estado', Hecho::ESTADO_VIGENTE)
                ->when($cliente !== null, fn ($consulta) => $consulta->where('cliente_id', $cliente))
                ->toBase()
                ->select(['id', 'fuente', 'origen_id', 'version', 'cliente_id', 'cliente_nombre', 'referencia', 'ocurrido_at', 'termina_at', 'fecha_operacional', 'medidas', 'datos']);

            foreach ($hechos->lazyById(1000, 'id') as $fila) {
                if (! in_array($fila->fuente, $intervalos, true) && substr((string) $fila->fecha_operacional, 0, 10) > $corte) {
                    continue;
                }
                if ($fila->cliente_id === null) {
                    $this->sinCliente[$fila->fuente] = ($this->sinCliente[$fila->fuente] ?? 0) + 1;

                    continue;
                }
                $this->nombres[$fila->cliente_id] = (string) $fila->cliente_nombre;
                $medidas = json_decode((string) $fila->medidas, true) ?: [];
                if (in_array($fila->fuente, $intervalos, true)) {
                    $this->estadia($fila, $medidas, json_decode((string) $fila->datos, true) ?: [], $dias, $corte);
                } else {
                    $this->puntual($fila, $medidas);
                }
            }
        }

        return $this->resultado($periodo, $corte, $corte !== null && $hoy <= $fin);
    }

    private function reiniciar(bool $conDetalle): void
    {
        $this->conDetalle = $conDetalle;
        $this->memo = [];
        $this->lineas = [];
        $this->sinPrecio = [];
        $this->sinCliente = [];
        $this->nombres = [];
        $this->diasCobrados = [];
    }

    /**
     * Días del mes hasta el corte, con el instante en que empieza cada uno en hora de planta.
     *
     * @return array<string, int> «Y-m-d» => marca de tiempo del inicio del día
     */
    private function dias(Periodo $periodo, string $corte): array
    {
        $dias = [];
        for ($dia = $periodo->inicio; $dia->toDateString() <= $corte; $dia = $dia->addDay()) {
            $dias[$dia->toDateString()] = $dia->startOfDay()->getTimestamp();
        }

        return $dias;
    }

    /** @param  array<string, mixed>  $medidas */
    private function puntual(object $fila, array $medidas): void
    {
        $fecha = substr((string) $fila->fecha_operacional, 0, 10);
        foreach ($this->servicios->paraHecho($fila->fuente, $fecha, conceptoDeclarado: (json_decode((string) $fila->datos, true) ?: [])['concepto_declarado'] ?? null) as $servicio) {
            $precios = $this->aplicables($fila->cliente_id, $servicio, $fecha);
            if ($precios === []) {
                $this->sinPrecio[$fila->cliente_id][$servicio][$fila->id] = true;

                continue;
            }
            foreach ($precios as [, $unidad, $tarifa]) {
                $this->sumar($fila, $tarifa, $medidas[$unidad] ?? 0, 0, $fecha);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $medidas
     * @param  array<string, mixed>  $datos
     * @param  array<string, int>  $dias
     */
    private function estadia(object $fila, array $medidas, array $datos, array $dias, string $corte): void
    {
        // Fechas nativas: un mes con miles de estadías hace estas cuentas miles de veces.
        $ingreso = (int) strtotime($fila->ocurrido_at.' UTC');
        $salida = $fila->termina_at === null ? null : (int) strtotime($fila->termina_at.' UTC');
        if ($salida !== null && $salida <= $ingreso) {
            return;
        }

        $primerDia = $this->fechaLocal($ingreso);
        if (($datos['repaletizaje_ingreso'] ?? null) !== null) {
            $primerDia = gmdate('Y-m-d', (int) strtotime($primerDia.' 00:00 UTC +1 day'));
        }
        // Una salida justo a medianoche no cobra el día que empieza en ese instante.
        $ultimoDia = $salida === null ? $corte : min($corte, $this->fechaLocal($salida - 1));
        if ($primerDia > $ultimoDia) {
            return;
        }

        $ingresoCivil = strtotime($this->fechaLocal($ingreso).' UTC');
        $tramos = $this->tramos($datos['tramos'] ?? [], $medidas, $ingreso);
        $unTramo = count($tramos) === 1 ? $tramos[0][2] : null;
        $folio = (string) strtok((string) $fila->origen_id, '#');
        $cliente = (string) $fila->cliente_id;
        $servicio = (string) $fila->fuente;
        $cobrados = $this->diasCobrados[$folio] ?? 0;

        // Se acumula por precio con enteros y se pasa a decimal exacto una vez por estadía.
        $tarifas = [];
        $cantidades = [];
        $diasPorPrecio = [];
        $desdes = [];
        $hastas = [];
        $indice = -1;
        foreach ($dias as $dia => $inicioDia) {
            $bit = 1 << ++$indice;
            if ($dia < $primerDia) {
                continue;
            }
            if ($dia > $ultimoDia) {
                break;
            }
            if (($cobrados & $bit) !== 0) {
                continue;
            }
            $cobrados |= $bit;

            // Diferencia de fechas civiles, sin dividir segundos por 24 h (cambio de hora).
            $diaEstadia = (int) ((strtotime($dia.' UTC') - $ingresoCivil) / 86400) + 1;
            $delDia = $unTramo ?? $this->medidasAl($tramos, max($inicioDia, $ingreso));
            foreach ($this->servicios->paraHecho($servicio, $dia, $diaEstadia) as $concepto) {
                $precios = $this->aplicables($cliente, $concepto, $dia);
                if ($precios === []) {
                    $this->sinPrecio[$cliente][$concepto][$fila->id] = true;

                    continue;
                }
                foreach ($precios as [$id, $unidad, $tarifa]) {
                    $valor = $delDia[$unidad] ?? 0;
                    if (! is_numeric($valor) || $valor == 0) {
                        continue;
                    }
                    if (! isset($tarifas[$id])) {
                        $tarifas[$id] = $tarifa;
                        $cantidades[$id] = 0;
                        $diasPorPrecio[$id] = 0;
                        $desdes[$id] = $dia;
                    }
                    $cantidades[$id] = is_int($valor) && is_int($cantidades[$id])
                        ? $cantidades[$id] + $valor
                        : BigDecimal::of((string) $cantidades[$id])->plus((string) $valor);
                    $diasPorPrecio[$id]++;
                    $hastas[$id] = $dia;
                }
            }
        }
        $this->diasCobrados[$folio] = $cobrados;

        foreach ($tarifas as $id => $tarifa) {
            $this->sumar($fila, $tarifa, (string) $cantidades[$id], $diasPorPrecio[$id], $desdes[$id], $hastas[$id]);
        }
    }

    private function fechaLocal(int $instante): string
    {
        return (new DateTimeImmutable('@'.$instante))->setTimezone($this->zona)->format('Y-m-d');
    }

    /**
     * Tramos de cantidades de la estadía como marcas de tiempo [desde, hasta, medidas]. Sin
     * tramos (hechos capturados antes de registrarlos), rigen las medidas del hecho.
     *
     * @param  array<string, mixed>  $medidas
     * @return list<array{0: int, 1: ?int, 2: array<string, mixed>}>
     */
    private function tramos(mixed $tramos, array $medidas, int $ingreso): array
    {
        $lista = [];
        foreach (is_array($tramos) ? $tramos : [] as $tramo) {
            if (! is_array($tramo) || ! isset($tramo['desde_at'], $tramo['medidas'])) {
                continue;
            }
            $desde = strtotime((string) $tramo['desde_at']);
            if ($desde === false) {
                continue;
            }
            $hasta = isset($tramo['hasta_at']) ? strtotime((string) $tramo['hasta_at']) : null;
            $lista[] = [$desde, $hasta === false ? null : $hasta, (array) $tramo['medidas']];
        }

        return $lista === [] ? [[$ingreso, null, $medidas]] : $lista;
    }

    /**
     * Medidas del tramo que rige en un instante; antes del primero, las del primero.
     *
     * @param  list<array{0: int, 1: ?int, 2: array<string, mixed>}>  $tramos
     * @return array<string, mixed>
     */
    private function medidasAl(array $tramos, int $instante): array
    {
        $vigente = $tramos[0][2];
        foreach ($tramos as [$desde, $hasta, $medidas]) {
            if ($desde > $instante) {
                break;
            }
            $vigente = $medidas;
            if ($hasta === null || $instante < $hasta) {
                break;
            }
        }

        return $vigente;
    }

    /**
     * Precios del día como [id, unidad, tarifa]: leer atributos de Eloquent cientos de miles
     * de veces cuesta más que el cálculo.
     *
     * @return list<array{0: int, 1: string, 2: Tarifa}>
     */
    private function aplicables(string $cliente, string $servicio, string $dia): array
    {
        return $this->memo[$cliente][$servicio][$dia] ??= array_map(
            fn (Tarifa $tarifa): array => [(int) $tarifa->id, (string) $tarifa->unidad, $tarifa],
            $this->resolutor->aplicables($cliente, $servicio, $dia),
        );
    }

    private function sumar(object $fila, Tarifa $tarifa, mixed $valor, int $dias, string $desde, ?string $hasta = null): void
    {
        $cantidad = BigDecimal::of(is_numeric($valor) ? (string) $valor : '0');
        if ($cantidad->isZero()) {
            return;
        }

        $clave = $fila->cliente_id.'|'.$tarifa->id;
        $this->lineas[$clave] ??= [
            'cliente_id' => $fila->cliente_id,
            'tarifa' => $tarifa,
            'cantidad' => BigDecimal::zero(),
            'dias' => 0,
            'hechos' => [],
            'detalle' => [],
        ];
        $linea = &$this->lineas[$clave];
        $linea['cantidad'] = $linea['cantidad']->plus($cantidad);
        $linea['dias'] += $dias;
        $linea['hechos'][$fila->id] = true;

        if ($this->alAportar !== null) {
            ($this->alAportar)([
                'cliente_id' => (string) $fila->cliente_id,
                'tarifa_id' => (int) $tarifa->id,
                'moneda' => (string) $tarifa->moneda,
                'hecho_id' => (int) $fila->id,
                'fuente' => (string) $fila->fuente,
                'origen_id' => (string) $fila->origen_id,
                'version' => (int) $fila->version,
                'referencia' => $fila->referencia,
                'desde' => $desde,
                'hasta' => $hasta ?? $desde,
                'dias' => $dias,
                'cantidad' => (string) $cantidad,
            ]);
        }

        if ($this->conDetalle) {
            $linea['detalle'][$fila->id] ??= [
                'hecho_id' => (int) $fila->id,
                'referencia' => $fila->referencia,
                'desde' => $desde,
                'hasta' => $desde,
                'dias' => 0,
                'cantidad' => BigDecimal::zero(),
            ];
            $detalle = &$linea['detalle'][$fila->id];
            $detalle['hasta'] = $hasta ?? $desde;
            $detalle['dias'] += $dias;
            $detalle['cantidad'] = $detalle['cantidad']->plus($cantidad);
        }
    }

    /** @return array<string, mixed> */
    private function resultado(Periodo $periodo, ?string $corte, bool $aLaFecha): array
    {
        $porCliente = [];
        $totales = [];
        foreach ($this->lineas as $linea) {
            $tarifa = $linea['tarifa'];
            $moneda = $this->monedas[$tarifa->moneda] ?? null;
            $precio = Precio::normalizar((string) $tarifa->precio);
            $monto = $linea['cantidad']->multipliedBy($precio)->toScale($moneda?->decimales ?? 0, RoundingMode::HalfUp);
            $cliente = $linea['cliente_id'];

            $porCliente[$cliente]['lineas'][] = [
                'servicio' => $tarifa->servicio,
                'servicio_nombre' => $this->servicios->nombreServicio($tarifa->servicio),
                'unidad' => $tarifa->unidad,
                'unidad_nombre' => $this->servicios->nombreUnidad($tarifa->servicio, $tarifa->unidad),
                'tarifa_id' => $tarifa->id,
                'origen' => $tarifa->general() ? 'general' : 'propio',
                'precio' => $precio,
                'moneda' => $tarifa->moneda,
                'simbolo' => $moneda?->simbolo ?? $tarifa->moneda,
                'decimales' => $moneda?->decimales ?? 0,
                'vigente_desde' => $tarifa->vigente_desde->toDateString(),
                'vigente_hasta' => $tarifa->vigente_hasta?->toDateString(),
                'cantidad' => Precio::normalizar((string) $linea['cantidad']),
                'dias' => $linea['dias'],
                'hechos' => count($linea['hechos']),
                'monto' => (string) $monto,
                ...($this->conDetalle ? ['detalle' => $this->detalle($linea['detalle'])] : []),
            ];
            $porCliente[$cliente]['totales'][$tarifa->moneda] = ($porCliente[$cliente]['totales'][$tarifa->moneda] ?? BigDecimal::zero())->plus($monto);
            $totales[$tarifa->moneda] = ($totales[$tarifa->moneda] ?? BigDecimal::zero())->plus($monto);
        }
        foreach (array_keys($this->sinPrecio) as $cliente) {
            $porCliente[$cliente] ??= [];
        }

        $clientes = [];
        foreach ($porCliente as $cliente => $datos) {
            $lineas = $datos['lineas'] ?? [];
            usort($lineas, fn (array $a, array $b): int => [$this->servicios->orden($a['servicio'], $a['unidad']), $a['vigente_desde']]
                <=> [$this->servicios->orden($b['servicio'], $b['unidad']), $b['vigente_desde']]);
            $clientes[] = [
                'cliente_id' => $cliente,
                'cliente_nombre' => $this->clientes->buscar($cliente)['nombre'] ?? $this->nombres[$cliente] ?? $cliente,
                'totales' => $this->totales($datos['totales'] ?? []),
                'lineas' => $lineas,
                'sin_precio' => array_values(array_map(fn (string $servicio, array $hechos): array => [
                    'servicio' => $servicio,
                    'nombre' => $this->servicios->nombreServicio($servicio),
                    'hechos' => count($hechos),
                ], array_keys($this->sinPrecio[$cliente] ?? []), $this->sinPrecio[$cliente] ?? [])),
            ];
        }
        usort($clientes, fn (array $a, array $b): int => strcasecmp($a['cliente_nombre'], $b['cliente_nombre']));

        $valorizados = [];
        foreach ($this->lineas as $linea) {
            $valorizados += $linea['hechos'];
        }

        $sinPrecio = [];
        foreach ($this->sinPrecio as $servicios) {
            foreach ($servicios as $hechos) {
                $sinPrecio += $hechos;
            }
        }

        return [
            'periodo' => $periodo->aArreglo(),
            'corte' => $corte,
            'a_la_fecha' => $aLaFecha,
            'clientes' => $clientes,
            'totales' => $this->totales($totales),
            'sin_cliente' => array_values(array_map(fn (string $fuente, int $hechos): array => [
                'fuente' => $fuente,
                'nombre' => $this->servicios->nombreServicio($fuente),
                'hechos' => $hechos,
            ], array_keys($this->sinCliente), $this->sinCliente)),
            'resumen' => [
                'clientes' => count(array_filter($clientes, fn (array $cliente): bool => $cliente['lineas'] !== [])),
                'hechos_valorizados' => count($valorizados),
                'hechos_sin_precio' => count($sinPrecio),
                'hechos_sin_cliente' => array_sum($this->sinCliente),
            ],
        ];
    }

    /**
     * Hechos de una línea por fecha, para revisarla como se revisa una factura.
     *
     * @param  array<int, array<string, mixed>>  $detalle
     * @return list<array<string, mixed>>
     */
    private function detalle(array $detalle): array
    {
        $lista = array_values(array_map(fn (array $hecho): array => [
            ...$hecho,
            'cantidad' => Precio::normalizar((string) $hecho['cantidad']),
        ], $detalle));
        usort($lista, fn (array $a, array $b): int => [$a['desde'], (string) $a['referencia']] <=> [$b['desde'], (string) $b['referencia']]);

        return $lista;
    }

    /**
     * @param  array<string, BigDecimal>  $montos
     * @return list<array{moneda: string, simbolo: string, decimales: int, monto: string}>
     */
    private function totales(array $montos): array
    {
        ksort($montos);

        return array_values(array_map(fn (string $codigo, BigDecimal $monto): array => [
            'moneda' => $codigo,
            'simbolo' => $this->monedas[$codigo]->simbolo ?? $codigo,
            'decimales' => $this->monedas[$codigo]->decimales ?? 0,
            'monto' => (string) $monto,
        ], array_keys($montos), $montos));
    }
}
