<?php

namespace App\Services\Asistente\Consultas;

use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Asistente\TextoNormalizado;

/** Saldos (bultos incompletos) en existencia: dónde están y cuántas cajas tienen. */
class SaldosPt extends ConsultaProductoTerminado
{
    public function clave(): string
    {
        return 'saldos-pt';
    }

    public function titulo(): string
    {
        return 'Saldos en existencia';
    }

    public function descripcion(): string
    {
        return 'Saldos vigentes con sus cajas, cámara y posición.';
    }

    public function palabrasClave(): array
    {
        return ['saldo', 'saldos', 'incompleto', 'incompletos'];
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $saldos = $this->filas($parametros)
            ->filter(fn (array $fila): bool => TextoNormalizado::de((string) ($fila['tipo_bulto'] ?? '')) === 'saldo')
            ->groupBy('folio')
            ->map(fn ($lineas, string $folio): array => [
                'folio' => $folio,
                'cliente' => $lineas->first()['cliente'] ?? '—',
                'cajas' => $lineas->sum(fn (array $fila): int => self::cajas($fila['cantidad_cajas'] ?? null)),
                'camara' => $lineas->first()['camara'] ?? '—',
                'posicion' => $lineas->first()['posicion'] ?? '—',
            ])->sortBy('folio')->values();

        if ($saldos->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), 'No hay saldos en existencia.', '/oficina/frigorifico/existencias');
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('Hay %d saldos con %s cajas en total.', $saldos->count(), number_format($saldos->sum('cajas'), 0, ',', '.')),
            [ResultadoConsulta::cifra('Saldos', $saldos->count()), ResultadoConsulta::cifra('Cajas', $saldos->sum('cajas'))],
            [
                ['clave' => 'folio', 'titulo' => 'Folio', 'tipo' => 'folio'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'cajas', 'titulo' => 'Cajas', 'tipo' => 'numero'],
                ['clave' => 'camara', 'titulo' => 'Cámara'],
                ['clave' => 'posicion', 'titulo' => 'Posición', 'tipo' => 'codigo'],
            ],
            $saldos->all(),
            '/oficina/frigorifico/existencias',
        );
    }
}
