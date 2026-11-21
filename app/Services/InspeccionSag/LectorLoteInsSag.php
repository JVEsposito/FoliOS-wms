<?php

namespace App\Services\InspeccionSag;

use DateTimeImmutable;
use DomainException;

/** Manual para Externos del Programa Origen, anexo 5, sección 1. */
class LectorLoteInsSag
{
    public function leer(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        $lineas = preg_split('/\r\n|\n|\r/', rtrim($contenido, "\r\n"));
        $encabezado = array_shift($lineas);
        if (! preg_match('/^([0-9]{5})([0-9]{4})([0-9]{8})([0-9]{3})([0-9]{3})([0-9]{8})([0-9]{4})$/D', $encabezado, $campos)) {
            throw new DomainException('La primera línea del .INS debe ser un encabezado SAG de 35 dígitos.');
        }
        if (array_pop($lineas) !== '&&') {
            throw new DomainException('El .INS debe terminar con una línea &&.');
        }

        [, $sif, $planta, $especie, $destino, $destinoSecundario, $fecha, $cantidad] = $campos;
        $fechaLote = DateTimeImmutable::createFromFormat('!Ymd', $fecha);
        if (! $fechaLote || $fechaLote->format('Ymd') !== $fecha || (int) substr($fecha, 0, 4) === 0) {
            throw new DomainException('La fecha del encabezado del .INS no es válida.');
        }
        if ((int) $cantidad !== count($lineas) || $lineas === []) {
            throw new DomainException('La cantidad de pallets del encabezado no coincide con el detalle.');
        }

        $pallets = [];
        $folios = [];
        $acumulado = 0;
        foreach ($lineas as $indice => $linea) {
            $numero = $indice + 2;
            if (! preg_match('/^([0-9]{10})([0-9]{4})$/D', $linea, $detalle) || (int) $detalle[2] < 1) {
                throw new DomainException("La línea {$numero} debe tener 10 dígitos de folio y 4 de cajas, con al menos una caja.");
            }
            [, $folio, $cajas] = $detalle;
            if (isset($folios[$folio])) {
                throw new DomainException("El folio {$folio} de la línea {$numero} está repetido.");
            }
            $folios[$folio] = true;
            $acumulado += (int) $cajas;
            $pallets[] = ['folio' => $folio, 'cajas' => (int) $cajas, 'acumulado' => $acumulado];
        }

        return [
            'sif' => $sif,
            'planta' => $planta,
            'especie_codigo' => $especie,
            'destino' => $destino,
            'destino_secundario' => $destinoSecundario,
            'fecha' => $fechaLote->format('Y-m-d'),
            'total_cajas' => $acumulado,
            'pallets' => $pallets,
        ];
    }

    public function conCertificados(array $lote, array $certificados): array
    {
        $pallets = array_column($lote['pallets'], null, 'folio');
        foreach ($certificados as $certificado) {
            $folio = $certificado['folio'];
            if (! isset($pallets[$folio])) {
                throw new DomainException("El certificado menciona un folio que no existe en el .INS ({$folio}).");
            }
            foreach (['premuestreo' => 'premuestreo', 'inspeccion' => 'inspección'] as $tipo => $nombre) {
                $codigo = $certificado[$tipo] ?? null;
                $cajas = $certificado['cajas_'.$tipo] ?? null;
                if (filled($codigo) !== filled($cajas) || (int) $cajas > $pallets[$folio]['cajas']) {
                    throw new DomainException("Para el folio {$folio}, indica el certificado de {$nombre} y una cantidad dentro de las cajas del pallet.");
                }
            }
            $pallets[$folio] = [...$pallets[$folio], ...$certificado];
        }

        return [...$lote, 'pallets' => array_values($pallets)];
    }
}
