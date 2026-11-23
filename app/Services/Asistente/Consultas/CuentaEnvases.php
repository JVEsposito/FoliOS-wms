<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\TipoEnvaseRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;

/**
 * Cuenta corriente de envases con las mismas cifras que la oficina de Cuenta Envases. Un
 * saldo positivo es lo que la planta debe al cliente; uno negativo, lo que el cliente debe.
 */
class CuentaEnvases extends ConsultaPorTema
{
    private const ENLACE = '/oficina/envases/cuenta-corriente';

    public function __construct(
        AlcanceOperacionalUsuario $alcance,
        ServicioTemporadaActiva $temporadas,
        private readonly ServicioCuentaCorrienteEnvases $cuenta,
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
        ];
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        return $this->saldos($parametros);
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
