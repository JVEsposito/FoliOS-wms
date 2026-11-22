<?php

namespace App\Services\Asistente\Consultas;

use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Consultas\ServicioConsultaOperacional;
use App\Services\Temporadas\ServicioTemporadaActiva;

/**
 * Un código suelto (recepción, folio, lote) abre lo que encuentra la Búsqueda
 * Operacional, con su mismo permiso.
 */
class ExpedientePorCodigo extends ConsultaBase
{
    public function __construct(
        AlcanceOperacionalUsuario $alcance,
        ServicioTemporadaActiva $temporadas,
        private readonly ServicioConsultaOperacional $busqueda,
    ) {
        parent::__construct($alcance, $temporadas);
    }

    public function clave(): string
    {
        return 'expediente-por-codigo';
    }

    public function titulo(): string
    {
        return 'Búsqueda por código';
    }

    public function descripcion(): string
    {
        return 'Recepciones, folios, lotes y productores que coinciden con un código.';
    }

    public function dominio(): string
    {
        return 'consultas';
    }

    public function palabrasClave(): array
    {
        return ['buscar', 'busca', 'expediente', 'codigo', 'donde', 'esta'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarOficinaConsultas($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $codigo = trim((string) $parametros->codigo);
        $enlace = '/oficina/consultas/busqueda?q='.rawurlencode($codigo);
        if ($codigo === '') {
            return ResultadoConsulta::sinDatos($this->titulo(), 'Indica un código: una recepción, un folio o un lote.', '/oficina/consultas/busqueda');
        }

        $resultados = $this->busqueda->buscar($codigo);
        $filas = [];
        foreach (['recepciones' => 'Recepción', 'folios' => 'Folio', 'lotes' => 'Lote', 'productores' => 'Productor'] as $grupo => $tipo) {
            foreach ($resultados[$grupo] ?? [] as $item) {
                $filas[] = [
                    'tipo' => $tipo,
                    'codigo' => (string) ($item['numero'] ?? $item['numero_folio'] ?? $item['numero_lote'] ?? $item['codigo'] ?? $item['csg'] ?? '—'),
                    'detalle' => (string) ($item['cliente'] ?? $item['estado'] ?? ''),
                ];
            }
        }

        if ($filas === []) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No se encontró nada con el código {$codigo}.", $enlace);
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('Se encontraron %d resultados para %s.', count($filas), $codigo),
            [ResultadoConsulta::cifra('Resultados', count($filas))],
            [
                ['clave' => 'tipo', 'titulo' => 'Tipo'],
                ['clave' => 'codigo', 'titulo' => 'Código', 'tipo' => 'codigo'],
                ['clave' => 'detalle', 'titulo' => 'Detalle'],
            ],
            $filas,
            $enlace,
        );
    }
}
