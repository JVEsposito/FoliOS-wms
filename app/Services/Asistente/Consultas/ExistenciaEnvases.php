<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;

/**
 * Envases físicamente en planta, con las cifras de la pestaña Existencia de Cuenta
 * Envases: por propiedad (propios, de clientes y arrendados), reservados en guías en
 * borrador y disponibles; y los envases propios entregados a cada cliente.
 */
class ExistenciaEnvases extends ConsultaPorTema
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
        return 'existencia-envases';
    }

    public function titulo(): string
    {
        return 'Envases en planta';
    }

    public function descripcion(): string
    {
        return 'Envases físicamente en planta ahora: propios, de clientes y arrendados, reservados y disponibles; y los propios entregados a cada cliente. No responde saldos de la cuenta ni producto terminado.';
    }

    public function dominio(): string
    {
        return 'envases';
    }

    public function vistaPorDefecto(): ?string
    {
        return 'por-propiedad';
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
        return ['existencia', 'existencias', 'stock', 'quedan', 'inventario'];
    }

    protected function vistasDelTema(): array
    {
        return [
            new VistaConsulta('por-propiedad', 'Por propiedad', 'Envases en planta ahora por envase: propios, de clientes, arrendados, reservados en guías en borrador y disponibles.',
                ['arrendados', 'arrendado', 'arrendadas', 'propios', 'propias', 'disponibles']),
            new VistaConsulta('propios-entregados', 'Propios entregados', 'Envases propios de la planta entregados a cada cliente en la temporada, netos de anulaciones.',
                ['entregados', 'entregamos', 'propios entregados'], ['cliente']),
        ];
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);

        return $vista->clave === 'propios-entregados'
            ? $this->propiosEntregados($temporada?->id, $parametros->clienteId)
            : $this->porPropiedad($temporada?->id);
    }

    private function porPropiedad(?string $temporadaId): ResultadoConsulta
    {
        $titulo = 'Envases en planta';
        $tipos = $temporadaId === null ? [] : $this->cuenta->existencia($temporadaId)['tipos'];
        if ($tipos === []) {
            return ResultadoConsulta::sinDatos($titulo, 'No hay envases en planta.', self::ENLACE);
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('Hay %d envases en planta, %d disponibles.', array_sum(array_column(array_column($tipos, 'en_planta'), 'total')), array_sum(array_column($tipos, 'disponible'))),
            [],
            [
                ['clave' => 'envase', 'titulo' => 'Envase'],
                ['clave' => 'propios', 'titulo' => 'Propios', 'tipo' => 'numero'],
                ['clave' => 'clientes', 'titulo' => 'De clientes', 'tipo' => 'numero'],
                ['clave' => 'arrendados', 'titulo' => 'Arrendados', 'tipo' => 'numero'],
                ['clave' => 'total', 'titulo' => 'En planta', 'tipo' => 'numero'],
                ['clave' => 'reservado', 'titulo' => 'Reservado', 'tipo' => 'numero'],
                ['clave' => 'disponible', 'titulo' => 'Disponible', 'tipo' => 'numero'],
            ],
            array_map(fn (array $t): array => [
                'envase' => $t['nombre'],
                'propios' => $t['en_planta'][PropiedadEnvase::Propia->value],
                'clientes' => $t['en_planta'][PropiedadEnvase::Cliente->value],
                'arrendados' => $t['en_planta'][PropiedadEnvase::Arrendada->value],
                'total' => $t['en_planta']['total'],
                'reservado' => $t['reservado']['total'],
                'disponible' => $t['disponible'],
            ], $tipos),
            self::ENLACE,
        );
    }

    private function propiosEntregados(?string $temporadaId, ?string $clienteId): ResultadoConsulta
    {
        $titulo = 'Envases propios entregados';
        $filas = $temporadaId === null ? [] : $this->cuenta->propiosEntregados($temporadaId, $clienteId);
        if ($filas === []) {
            return ResultadoConsulta::sinDatos($titulo, 'No hay envases propios entregados a clientes en la temporada.', self::ENLACE);
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('La planta ha entregado %d envases propios a clientes en la temporada. Es lo entregado, no lo que el cliente tiene hoy.', array_sum(array_column($filas, 'cantidad'))),
            [],
            [
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'envase', 'titulo' => 'Envase'],
                ['clave' => 'cantidad', 'titulo' => 'Entregados', 'tipo' => 'numero'],
            ],
            array_map(fn (array $f): array => [
                'cliente' => $f['cliente']['nombre'],
                'envase' => TipoEnvaseRomana::from($f['tipo_envase'])->etiqueta(),
                'cantidad' => $f['cantidad'],
            ], $filas),
            self::ENLACE,
        );
    }
}
