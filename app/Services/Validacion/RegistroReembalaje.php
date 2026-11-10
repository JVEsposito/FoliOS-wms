<?php

namespace App\Services\Validacion;

use App\Models\Repaletizaje;

/** Registro propio: en reembalaje las cajas de entrada y salida no son equivalentes. */
class RegistroReembalaje
{
    public function filas(Repaletizaje $repa): array
    {
        $repa->loadMissing('usuario');
        $filas = [['Operación', 'Estado', 'Fecha operacional', 'Turno', 'Responsable', 'Movimiento', 'Folio', 'CSG', 'Variedad', 'Calibre', 'Fecha embalaje', 'Envase', 'Cajas', 'Kg netos/caja', 'Kg netos', 'Motivo merma']];
        $base = [$repa->codigo, $repa->estado, $repa->fecha_operacional?->toDateString(), $repa->turno, $repa->usuario?->name];
        foreach ($repa->snapshot['genealogia'] ?? [] as $origen) {
            foreach ($origen['composicion_aportada'] ?? [] as $linea) {
                $filas[] = [...$base, 'Entrada', $origen['numero_folio'], $linea['csg'], $linea['variedad'] ?? '', $linea['calibre'] ?? '',
                    $linea['fecha_embalaje'] ?? '', $linea['envase'] ?? '', $linea['cantidad_cajas'], $linea['kilos_netos_por_caja'], $linea['kilos_entrada'], ''];
            }
        }
        foreach ($repa->snapshot['resultados'] ?? [] as $resultado) {
            foreach ($resultado['composicion'] as $linea) {
                $filas[] = [...$base, 'Salida', $resultado['numero_folio'], $linea['csg'], $linea['variedad'] ?? '', $linea['calibre'] ?? '',
                    $linea['fecha_embalaje'] ?? '', $linea['envase'], $linea['cantidad_cajas'], $linea['kilos_netos_por_caja'],
                    CuadraturaReembalaje::kilos(CuadraturaReembalaje::unidades($linea['kilos_netos_por_caja']) * $linea['cantidad_cajas']), ''];
            }
        }
        foreach ($repa->snapshot['balance_kilos']['componentes'] as $linea) {
            $filas[] = [...$base, 'Merma', '', $linea['csg'], '', '', '', '', '', '', $linea['kilos_merma'], $repa->motivo_merma];
        }
        foreach (['entrada' => 'Total entrada', 'salida' => 'Total salida', 'merma' => 'Total merma'] as $campo => $etiqueta) {
            $filas[] = [...$base, $etiqueta, '', '', '', '', '', '', '', '', $repa->snapshot['balance_kilos']['kilos_'.$campo], ''];
        }

        return $filas;
    }

    public function escribir(Repaletizaje $repa): void
    {
        $archivo = fopen('php://output', 'w');
        fwrite($archivo, "\xEF\xBB\xBF");
        foreach ($this->filas($repa) as $fila) {
            // Identificadores y nombres no deben ejecutarse como fórmulas al abrir el CSV.
            $fila = array_map(function ($valor): string {
                $texto = (string) $valor;

                return preg_match('/^[=+@\-\t\r]/', $texto) ? "'".$texto : $texto;
            }, $fila);
            fputcsv($archivo, $fila, ';', '"', '');
        }
        fclose($archivo);
    }
}
