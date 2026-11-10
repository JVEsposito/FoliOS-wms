<?php

namespace App\Services\IntegridadOperacional\Reglas;

use App\Enums\SeveridadHallazgoIntegridadOperacional;
use App\Services\IntegridadOperacional\HallazgoIntegridadDetectado;
use Illuminate\Support\Facades\DB;

final class ReglaBalanceRepaletizaje implements ReglaIntegridadOperacional
{
    public const CODIGO = 'repaletizaje_desbalanceado';

    public function codigo(): string
    {
        return self::CODIGO;
    }

    public function nombre(): string
    {
        return 'Conservación de cajas en repaletizajes';
    }

    public function modulo(): string
    {
        return 'repaletizaje';
    }

    public function evaluar(): iterable
    {
        $entradas = DB::table('repaletizaje_detalles')
            ->selectRaw('repaletizaje_id, SUM(cajas_aportadas) as total_entradas, SUM(kilos_aportados) as kilos_entradas')
            ->groupBy('repaletizaje_id');
        $salidas = DB::table('repaletizaje_resultados')
            ->selectRaw('repaletizaje_id, SUM(cantidad_resultante) as total_salidas, SUM(cantidad_resultante * kilos_netos_por_caja) as kilos_salidas')
            ->groupBy('repaletizaje_id');

        return DB::table('repaletizajes as repaletizaje')
            ->leftJoinSub($entradas, 'entradas', 'entradas.repaletizaje_id', '=', 'repaletizaje.id')
            ->leftJoinSub($salidas, 'salidas', 'salidas.repaletizaje_id', '=', 'repaletizaje.id')
            ->where('repaletizaje.estado', 'confirmado')
            ->where(function ($q): void {
                $q->where(fn ($cajas) => $cajas->where('repaletizaje.modalidad', '!=', 'reembalaje')
                    ->whereRaw('COALESCE(entradas.total_entradas, 0) <> COALESCE(salidas.total_salidas, 0)'))
                    ->orWhere(fn ($kilos) => $kilos->where('repaletizaje.modalidad', 'reembalaje')
                        ->where(function ($balance): void {
                            $balance->whereNull('repaletizaje.kilos_entrada')->orWhereNull('repaletizaje.kilos_salida')->orWhereNull('repaletizaje.kilos_merma')
                                ->orWhereRaw('repaletizaje.kilos_entrada <> repaletizaje.kilos_salida + repaletizaje.kilos_merma')
                                ->orWhereRaw('repaletizaje.kilos_entrada <> COALESCE(entradas.kilos_entradas, 0)')
                                ->orWhereRaw('repaletizaje.kilos_salida <> COALESCE(salidas.kilos_salidas, 0)')
                                ->orWhere('repaletizaje.kilos_merma', '<', 0);
                        }));
            })
            ->orderBy('repaletizaje.confirmado_at')
            ->get([
                'repaletizaje.id',
                'repaletizaje.codigo',
                'repaletizaje.modalidad',
                'repaletizaje.confirmado_at',
                'repaletizaje.kilos_entrada', 'repaletizaje.kilos_salida', 'repaletizaje.kilos_merma',
                'entradas.total_entradas',
                'salidas.total_salidas',
            ])
            ->map(fn (object $fila): HallazgoIntegridadDetectado => new HallazgoIntegridadDetectado(
                reglaCodigo: self::CODIGO,
                severidad: SeveridadHallazgoIntegridadOperacional::Critico,
                modulo: $this->modulo(),
                entidadTipo: 'repaletizaje',
                entidadId: $fila->id,
                referencia: $fila->codigo,
                titulo: 'Repaletizaje con entradas y salidas desbalanceadas',
                detalle: $fila->modalidad === 'reembalaje' ? sprintf('%s: entrada %s kg, salida %s kg y merma %s kg; revisar la cuadratura y los formatos.', $fila->codigo, $fila->kilos_entrada, $fila->kilos_salida, $fila->kilos_merma) : sprintf(
                    '%s registra %d cajas aportadas y %d cajas resultantes.',
                    $fila->codigo,
                    (int) ($fila->total_entradas ?? 0),
                    (int) ($fila->total_salidas ?? 0),
                ),
                contexto: [
                    'modalidad' => $fila->modalidad,
                    'total_entradas' => (int) ($fila->total_entradas ?? 0),
                    'total_salidas' => (int) ($fila->total_salidas ?? 0),
                    'confirmado_at' => $fila->confirmado_at,
                ],
            ));
    }
}
