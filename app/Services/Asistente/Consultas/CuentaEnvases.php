<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\TipoEnvaseRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\GuiaDespachoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Envases\ServicioConciliacionSalidaEnvases;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\Gate;

/**
 * Cuenta corriente de envases con las mismas cifras que la oficina de Cuenta Envases. Un
 * saldo positivo es lo que la planta debe al cliente; uno negativo, lo que el cliente debe.
 */
class CuentaEnvases extends ConsultaPorTema
{
    private const ENLACE = '/oficina/envases/cuenta-corriente';

    /** Clientes de un mismo productor que se calculan, como máximo, al preguntar solo por el CSG. */
    private const MAXIMO_CLIENTES = 3;

    /** Parejas por conciliar que se previsualizan, como máximo; el resto se ve en la oficina. */
    private const MAXIMO_PAREJAS = 20;

    public function __construct(
        AlcanceOperacionalUsuario $alcance,
        ServicioTemporadaActiva $temporadas,
        private readonly ServicioCuentaCorrienteEnvases $cuenta,
        private readonly ServicioConciliacionSalidaEnvases $conciliacion,
    ) {
        parent::__construct($alcance, $temporadas);
    }

    public function clave(): string
    {
        return 'cuenta-envases';
    }

    public function titulo(): string
    {
        return 'Cuenta de envases';
    }

    public function descripcion(): string
    {
        return 'Cuenta corriente de envases de cada cliente en la temporada: cuánto debe la planta o el cliente. No responde la existencia física en planta ni los envases recibidos hoy.';
    }

    public function dominio(): string
    {
        return 'envases';
    }

    public function vistaPorDefecto(): ?string
    {
        return 'saldos';
    }

    public function palabrasSecundarias(): array
    {
        return ['envases', 'envase', 'bins', 'bin', 'totes', 'tote', 'esponjas'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarCuentaEnvases($usuario);
    }

    protected function palabrasDelTema(): array
    {
        return ['cuenta', 'deuda'];
    }

    protected function vistasDelTema(): array
    {
        return [
            new VistaConsulta('saldos', 'Saldos', 'Saldo de la cuenta por cliente y envase en la temporada activa, y quién debe a quién.',
                // «Saldos» solo es un saldo de pallets (saldos-pt): aquí va en frases con el envase.
                ['debe', 'debemos', 'deben', 'saldo de envases', 'saldos de envases', 'saldo de bins', 'saldo de totes'], ['cliente']),
            new VistaConsulta('por-productor', 'Saldo por productor', 'Saldo de envases por CSG (productor) dentro de la cuenta de cada cliente, con la misma atribución del estado de cuenta.',
                ['productor', 'productores', 'por productor', 'por csg'], ['cliente', 'csg']),
            new VistaConsulta('estado-cuenta', 'Estado de cuenta', 'Estado de cuenta de envases de un cliente: saldo inicial, entradas, salidas y saldo final por envase, como el documento.',
                ['estado de cuenta', 'cartola'], ['periodo', 'cliente']),
            new VistaConsulta('descuadres', 'Descuadres guía y Romana', 'Salidas de envases registradas dos veces, en una guía de despacho de envases y en el destare de Romana, con la diferencia por envase. Solo para quien ajusta la cuenta.',
                ['descuadre', 'descuadres', 'diferencia', 'diferencias', 'doble salida', 'conciliar', 'entre guia y romana'], ['cliente']),
        ];
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        return match ($vista->clave) {
            'por-productor' => $this->porProductor($parametros),
            'estado-cuenta' => $this->estadoCuenta($parametros),
            'descuadres' => $this->descuadres($parametros),
            default => $this->saldos($parametros),
        };
    }

    /** Los descuadres se ajustan en su pantalla, que exige ajustar la cuenta: la vista también. */
    public function puedeVer(User $usuario, VistaConsulta $vista): bool
    {
        return $this->puedeEjecutar($usuario) && ($vista->clave !== 'descuadres' || Gate::forUser($usuario)->allows('ajustar-cuenta-envases'));
    }

    /**
     * Parejas de guía y Romana con la misma salida física aún sin conciliar, con la
     * diferencia que muestra la previsualización de la oficina. Una pareja que la oficina
     * no puede previsualizar queda como «requiere revisión individual».
     */
    private function descuadres(ParametrosConsulta $parametros): ResultadoConsulta
    {
        $titulo = 'Descuadres entre guía y Romana';
        $enlace = '/oficina/envases/cuenta-corriente';
        $temporada = $this->temporada($parametros);
        $parejas = $temporada === null ? collect() : $this->conciliacion->pendientes($temporada->id, $parametros->clienteId)
            ->limit(self::MAXIMO_PAREJAS)
            ->get(['g.id as guia_id', 'g.numero as despacho', 'r.id as recepcion_id', 'r.numero_recepcion as recepcion', 'c.nombre as cliente']);
        if ($parejas->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, 'No hay salidas de envases registradas dos veces pendientes de conciliar.', $enlace);
        }
        $filas = [];
        foreach ($parejas as $pareja) {
            $base = ['despacho' => $pareja->despacho, 'recepcion' => $pareja->recepcion, 'cliente' => $pareja->cliente];
            try {
                $plan = $this->conciliacion->previsualizar(RecepcionRomana::findOrFail($pareja->recepcion_id), GuiaDespachoEnvase::findOrFail($pareja->guia_id), 'romana');
            } catch (ConflictoOperacion) {
                $filas[] = [...$base, 'envase' => null, 'romana' => null, 'guia' => null, 'diferencia' => null, 'nota' => 'Requiere revisión individual'];

                continue;
            }
            foreach ($plan['filas'] as $fila) {
                [$tipo, $propiedad] = explode('|', $fila['envase_propiedad']);
                $filas[] = [...$base, 'envase' => TipoEnvaseRomana::from($tipo)->etiqueta().' · '.$propiedad,
                    'romana' => $fila['romana'], 'guia' => $fila['despacho'], 'diferencia' => $fila['diferencia'], 'nota' => null];
            }
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('%d salidas de envases están registradas en una guía y en Romana, sin conciliar. Diferencia: guía menos Romana.', $parejas->count()),
            [ResultadoConsulta::cifra('Por conciliar', $parejas->count())],
            [
                ['clave' => 'despacho', 'titulo' => 'Despacho', 'tipo' => 'codigo'],
                ['clave' => 'recepcion', 'titulo' => 'Recepción', 'tipo' => 'codigo'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'envase', 'titulo' => 'Envase'],
                ['clave' => 'romana', 'titulo' => 'Romana', 'tipo' => 'numero'],
                ['clave' => 'guia', 'titulo' => 'Guía', 'tipo' => 'numero'],
                ['clave' => 'diferencia', 'titulo' => 'Diferencia', 'tipo' => 'numero'],
                ['clave' => 'nota', 'titulo' => 'Nota'],
            ],
            $filas,
            $enlace,
        );
    }

    private function estadoCuenta(ParametrosConsulta $parametros): ResultadoConsulta
    {
        $titulo = 'Estado de cuenta de envases';
        $cliente = $parametros->clienteId ? Cliente::find($parametros->clienteId) : null;
        if ($cliente === null) {
            return ResultadoConsulta::sinDatos($titulo, 'Dime de qué cliente: el estado de cuenta es por cliente.', self::ENLACE);
        }
        $temporada = $this->temporada($parametros);
        // Sin periodo nombrado, el estado de cuenta es el de toda la temporada, como el documento.
        [$desde, $hasta] = $parametros->periodoExplicito ? $parametros->jornadas() : [null, null];
        $tipos = $temporada === null ? [] : $this->cuenta->estadoCuenta($cliente, $temporada, $desde, $hasta)['tipos'];
        $cuando = $parametros->periodoExplicito ? "del {$this->periodo($parametros)}" : 'de la temporada';
        if ($tipos === []) {
            return ResultadoConsulta::sinDatos($titulo, "{$cliente->nombre} no tiene movimientos de envases {$cuando}.", self::ENLACE);
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('Estado de cuenta de envases de %s %s: %s.', $cliente->nombre, $cuando, implode(', ', array_map(
                fn (array $t): string => "{$t['nombre']} {$t['saldo_final']}", $tipos,
            ))),
            [],
            [
                ['clave' => 'envase', 'titulo' => 'Envase'],
                ['clave' => 'inicial', 'titulo' => 'Saldo inicial', 'tipo' => 'numero'],
                ['clave' => 'entradas', 'titulo' => 'Entradas', 'tipo' => 'numero'],
                ['clave' => 'salidas', 'titulo' => 'Salidas', 'tipo' => 'numero'],
                ['clave' => 'final', 'titulo' => 'Saldo final', 'tipo' => 'numero'],
            ],
            array_map(fn (array $t): array => [
                'envase' => $t['nombre'], 'inicial' => $t['saldo_inicial'], 'entradas' => $t['entradas'], 'salidas' => $t['salidas'], 'final' => $t['saldo_final'],
            ], $tipos),
            self::ENLACE,
        );
    }

    /**
     * Saldo por CSG dentro de la cuenta de cada cliente. Con un CSG y sin cliente, los
     * clientes son los del productor de ese CSG: una fila por cliente, sin sumarlos.
     */
    private function porProductor(ParametrosConsulta $parametros): ResultadoConsulta
    {
        $titulo = 'Saldo de envases por productor';
        $csg = $parametros->csgId ? CsgValidacion::with('productor.clientes')->find($parametros->csgId) : null;
        $clientes = match (true) {
            $parametros->clienteId !== null => Cliente::query()->whereKey($parametros->clienteId)->get(),
            $csg !== null => $csg->productor?->clientes->take(self::MAXIMO_CLIENTES) ?? collect(),
            default => null,
        };
        if ($clientes === null) {
            return ResultadoConsulta::sinDatos($titulo, 'Dime de qué cliente o CSG: el saldo por productor es parte de la cuenta de un cliente.', self::ENLACE);
        }
        $temporada = $this->temporada($parametros);
        $filas = [];
        foreach ($temporada === null ? [] : $clientes as $cliente) {
            foreach ($this->cuenta->estadoCuenta($cliente, $temporada, null, null)['resumen_csg'] as $grupo) {
                if ($csg !== null && (string) $grupo['codigo'] !== $csg->codigo) {
                    continue;
                }
                foreach ($grupo['tipos'] as $tipo) {
                    if ($tipo['saldo_final'] !== 0) {
                        $filas[] = ['cliente' => $cliente->nombre, 'csg' => $grupo['codigo'] ?? 'Sin CSG', 'envase' => $tipo['nombre'], 'saldo' => $tipo['saldo_final']];
                    }
                }
            }
        }
        if ($filas === []) {
            return ResultadoConsulta::sinDatos($titulo, $csg !== null ? "El CSG {$csg->codigo} no tiene saldo de envases en la temporada." : 'No hay saldos de envases por productor en la temporada.', self::ENLACE);
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('%d saldos de envases por productor en la temporada. Positivo: la planta debe; negativo: el productor debe.', count($filas)),
            [],
            [
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'csg', 'titulo' => 'CSG', 'tipo' => 'codigo'],
                ['clave' => 'envase', 'titulo' => 'Envase'],
                ['clave' => 'saldo', 'titulo' => 'Saldo', 'tipo' => 'numero'],
            ],
            $filas,
            self::ENLACE,
        );
    }

    private function saldos(ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        $saldos = $temporada === null ? [] : array_values(array_filter(
            $this->cuenta->saldos($temporada->id, $parametros->clienteId),
            fn (array $fila): bool => $fila['saldo'] !== 0,
        ));
        if ($saldos === []) {
            return ResultadoConsulta::sinDatos('Saldos de envases', 'No hay saldos de envases pendientes en la temporada.', self::ENLACE);
        }
        usort($saldos, fn (array $a, array $b): int => [$a['cliente']['nombre'], TipoEnvaseRomana::from($a['tipo_envase'])->orden()]
            <=> [$b['cliente']['nombre'], TipoEnvaseRomana::from($b['tipo_envase'])->orden()]);
        $planta = array_sum(array_map(fn (array $f): int => max($f['saldo'], 0), $saldos));
        $clientes = array_sum(array_map(fn (array $f): int => max(-$f['saldo'], 0), $saldos));

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            'Saldos de envases',
            sprintf('La planta debe %d envases y los clientes deben %d, en %d saldos de la temporada.', $planta, $clientes, count($saldos)),
            [ResultadoConsulta::cifra('Planta debe', $planta), ResultadoConsulta::cifra('Clientes deben', $clientes)],
            [
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'envase', 'titulo' => 'Envase'],
                ['clave' => 'saldo', 'titulo' => 'Saldo', 'tipo' => 'numero'],
                ['clave' => 'quien', 'titulo' => 'Quién debe'],
            ],
            array_map(fn (array $f): array => [
                'cliente' => $f['cliente']['nombre'],
                'envase' => TipoEnvaseRomana::from($f['tipo_envase'])->etiqueta(),
                'saldo' => $f['saldo'],
                'quien' => $f['saldo'] > 0 ? 'Planta debe' : 'Cliente debe',
            ], $saldos),
            self::ENLACE,
        );
    }
}
