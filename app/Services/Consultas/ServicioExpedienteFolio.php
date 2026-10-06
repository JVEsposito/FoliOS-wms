<?php

namespace App\Services\Consultas;

use App\Enums\EstadoProcesoPrefrio;
use App\Enums\TipoBulto;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\ProcesoPrefrioFolio;
use App\Models\Repaletizaje;
use App\Models\ValidacionPallet;
use BackedEnum;
use Illuminate\Support\Collection;

class ServicioExpedienteFolio
{
    public function __construct(
        private readonly ServicioExpedienteMaterial $expedienteMaterial,
    ) {}

    /** @return array<string, mixed> */
    public function obtener(Folio $folio): array
    {
        if ($folio->tipo_bulto === TipoBulto::Material) {
            return $this->expedienteMaterial->obtener($folio);
        }

        $folio->load(['temporada', 'ubicacionActual.posicion.camara']);

        $validaciones = ValidacionPallet::query()
            ->where('folio_id', $folio->id)
            ->with(['usuario', 'dispositivo'])
            ->orderBy('recibido_servidor_at')
            ->get();

        $prefrio = ProcesoPrefrioFolio::query()
            ->where('folio_id', $folio->id)
            ->with(['proceso.tunel', 'cargadoPor', 'retiradoPor'])
            ->orderBy('cargado_at')
            ->get();

        $movimientos = Movimiento::query()
            ->where('folio_id', $folio->id)
            ->with([
                'usuario',
                'camaraOrigen',
                'posicionOrigen',
                'camaraDestino',
                'posicionDestino',
            ])
            ->orderBy('recibido_servidor_at')
            ->orderBy('created_at')
            ->get();

        $repaletizajes = Repaletizaje::query()
            ->where(function ($consulta) use ($folio): void {
                $consulta->where('folio_resultante_id', $folio->id)
                    ->orWhereHas('resultados', fn ($resultados) => $resultados
                        ->where('folio_id', $folio->id))
                    ->orWhereHas('detalles', fn ($detalles) => $detalles
                        ->where('folio_origen_id', $folio->id));
            })
            ->with(['folioResultante', 'resultados.folio', 'detalles.folioOrigen', 'usuario', 'anuladoPor'])
            ->orderBy('confirmado_at')
            ->get();

        $timeline = collect()
            ->concat($this->eventosValidacion($validaciones))
            ->concat($this->eventosPrefrio($prefrio))
            ->concat($this->eventosMovimientos($movimientos))
            ->concat($this->eventosRepaletizaje($repaletizajes, $folio))
            ->filter(fn (array $evento): bool => $evento['fecha'] !== null)
            ->sortBy('fecha')
            ->values()
            ->all();

        $datosExternos = $folio->datos_externos ?? [];
        $posicion = $folio->ubicacionActual?->posicion;
        $repaAgotado = $datosExternos['consumido_en_repaletizaje'] ?? null;

        return [
            'folio' => [
                'id' => $folio->id,
                'numero' => $folio->numero_folio,
                'tipo_bulto' => $this->valor($folio->tipo_bulto),
                'estado' => $this->valor($folio->estado_operacional),
                'estado_explicado' => $repaAgotado
                    ? 'agotado_por_repaletizaje'
                    : $this->valor($folio->estado_operacional),
                'condicion_termica' => $this->valor($folio->condicion_termica),
                'cantidad_cajas' => isset($datosExternos['cantidad_cajas'])
                    ? (int) $datosExternos['cantidad_cajas']
                    : null,
                'temporada' => $folio->temporada?->codigo,
                'fecha_ingreso' => $folio->fecha_ingreso?->toIso8601String(),
                'especificaciones' => [
                    'planta_origen' => $datosExternos['planta_origen']['nombre'] ?? null,
                    'guia' => $datosExternos['guia'] ?? null,
                    'csp' => $datosExternos['csp'] ?? null,
                    'referencia_externa' => $folio->identificador_externo,
                    'cliente' => $folio->exportadora,
                    'especie' => $datosExternos['especie'] ?? null,
                    'marca' => $folio->marca,
                    'variedad' => $folio->variedad,
                    'calibre' => $folio->calibre,
                    'envase' => $datosExternos['envase'] ?? null,
                    'categoria' => $datosExternos['categoria'] ?? null,
                    'csg' => $datosExternos['csg'] ?? null,
                    'predio' => $datosExternos['predio'] ?? null,
                    'cuartel' => $datosExternos['cuartel'] ?? null,
                ],
                'ubicacion' => $posicion ? [
                    'camara' => $posicion->camara?->codigo,
                    'posicion' => $posicion->etiqueta,
                ] : null,
                'repaletizaje_agotamiento' => $repaAgotado,
            ],
            'validacion' => $validaciones->last() ? $this->resumenValidacion($validaciones->last()) : null,
            'repaletizajes' => $repaletizajes->map(fn (Repaletizaje $repa): array => [
                'codigo' => $repa->codigo,
                'modalidad' => $repa->modalidad ?? 'consolidacion',
                'estado' => $repa->estado,
                'tipo_resultado' => $repa->tipo_resultado,
                'folio_resultante' => $repa->folioResultante?->numero_folio,
                'folios_resultantes' => $repa->resultados->pluck('folio.numero_folio')->filter()->values()->all(),
                'cantidad_resultante' => $repa->cantidad_resultante,
                'campos_mix' => $repa->campos_mix ?? [],
                'confirmado_at' => $repa->confirmado_at?->toIso8601String(),
                'origenes' => $repa->detalles->map(fn ($detalle): array => [
                    'folio' => $detalle->folioOrigen?->numero_folio,
                    'cajas_antes' => $detalle->cajas_antes,
                    'cajas_aportadas' => $detalle->cajas_aportadas,
                    'cajas_despues' => $detalle->cajas_despues,
                ])->values()->all(),
            ])->values()->all(),
            'timeline' => $timeline,
            'totales' => [
                'validaciones' => $validaciones->count(),
                'procesos_prefrio' => $prefrio->count(),
                'movimientos' => $movimientos->count(),
                'repaletizajes' => $repaletizajes->count(),
            ],
        ];
    }

    /** @param Collection<int, ValidacionPallet> $validaciones */
    private function eventosValidacion(Collection $validaciones): array
    {
        return $validaciones->map(fn (ValidacionPallet $validacion): array => [
            'tipo' => 'validacion',
            'fecha' => $this->fecha(
                $validacion->recibido_servidor_at
                    ?? $validacion->generado_dispositivo_at
                    ?? $validacion->created_at,
            ),
            'titulo' => 'Validación',
            'descripcion' => sprintf(
                '%s · %d cajas · %s',
                mb_strtoupper($this->valor($validacion->tipo_bulto) ?? 'BULTO'),
                (int) $validacion->cantidad_cajas,
                mb_strtoupper($this->valor($validacion->resultado) ?? 'REGISTRADO'),
            ),
            'meta' => array_filter([
                'Operador' => $validacion->usuario?->nombre,
                'Línea' => $validacion->linea_proceso,
                'Turno' => $validacion->turno,
                'Dispositivo' => $validacion->dispositivo?->codigo,
            ], fn (mixed $valor): bool => $valor !== null && $valor !== ''),
        ])->all();
    }

    /** @param Collection<int, ProcesoPrefrioFolio> $asignaciones */
    private function eventosPrefrio(Collection $asignaciones): array
    {
        $eventos = collect();

        foreach ($asignaciones as $asignacion) {
            $proceso = $asignacion->proceso;
            $eventos->push([
                'tipo' => 'prefrio',
                'fecha' => $this->fecha($asignacion->cargado_at ?? $asignacion->created_at),
                'titulo' => 'Ingreso a prefrío',
                'descripcion' => $proceso?->codigo ?? 'Proceso de prefrío',
                'meta' => array_filter([
                    'Túnel' => $proceso?->tunel?->codigo,
                    'Set point' => $proceso?->setpoint !== null ? $proceso->setpoint.' °C' : null,
                    'Temperatura inicial' => $asignacion->temperatura_inicial !== null
                        ? $asignacion->temperatura_inicial.' °C'
                        : null,
                    'Operador' => $asignacion->cargadoPor?->nombre,
                ], fn (mixed $valor): bool => $valor !== null && $valor !== ''),
            ]);

            if ($proceso?->finalizado_at) {
                $aprobado = $proceso->estado === EstadoProcesoPrefrio::Aprobado;
                $eventos->push([
                    'tipo' => 'prefrio_resultado',
                    'fecha' => $this->fecha($proceso->finalizado_at),
                    'titulo' => $aprobado ? 'Prefrío aprobado' : 'Prefrío finalizado',
                    'descripcion' => $aprobado
                        ? 'Pendiente de prefrío → Disponible'
                        : mb_strtoupper($this->valor($proceso->estado) ?? 'FINALIZADO'),
                    'meta' => array_filter([
                        'Proceso' => $proceso->codigo,
                        'Túnel' => $proceso->tunel?->codigo,
                        'Temperatura final' => $asignacion->temperatura_final !== null
                            ? $asignacion->temperatura_final.' °C'
                            : null,
                        'Resultado' => $this->valor($asignacion->estado),
                    ], fn (mixed $valor): bool => $valor !== null && $valor !== ''),
                ]);
            }
        }

        return $eventos->all();
    }

    /** @param Collection<int, Movimiento> $movimientos */
    private function eventosMovimientos(Collection $movimientos): array
    {
        return $movimientos->map(function (Movimiento $movimiento): array {
            $origen = $this->ubicacion(
                $movimiento->camaraOrigen?->codigo,
                $movimiento->posicionOrigen?->etiqueta,
                'Sin ubicación',
            );
            $destino = $this->ubicacion(
                $movimiento->camaraDestino?->codigo,
                $movimiento->posicionDestino?->etiqueta,
                'Sin ubicación',
            );

            return [
                'tipo' => 'movimiento',
                'fecha' => $this->fecha($movimiento->recibido_servidor_at ?? $movimiento->created_at),
                'titulo' => 'Movimiento',
                'descripcion' => $origen.' → '.$destino,
                'meta' => array_filter([
                    'Tipo' => $this->valor($movimiento->tipo_movimiento),
                    'Operador' => $movimiento->usuario?->nombre,
                    'Motivo' => $movimiento->motivo,
                ], fn (mixed $valor): bool => $valor !== null && $valor !== ''),
            ];
        })->all();
    }

    /** @param Collection<int, Repaletizaje> $repaletizajes */
    private function eventosRepaletizaje(Collection $repaletizajes, Folio $folio): array
    {
        return $repaletizajes->map(function (Repaletizaje $repa) use ($folio): array {
            $detalle = $repa->detalles->firstWhere('folio_origen_id', $folio->id);
            $esResultado = $repa->folio_resultante_id === $folio->id
                || $repa->resultados->contains('folio_id', $folio->id);
            $numerosResultado = $repa->resultados->pluck('folio.numero_folio')->filter();
            if ($numerosResultado->isEmpty() && $repa->folioResultante) {
                $numerosResultado->push($repa->folioResultante->numero_folio);
            }
            $textoResultados = $numerosResultado->implode(' + ');

            if ($repa->estado === 'anulado') {
                return [
                    'tipo' => 'repaletizaje_anulado',
                    'fecha' => $this->fecha($repa->anulado_at ?? $repa->confirmado_at),
                    'titulo' => 'Repaletizaje anulado',
                    'descripcion' => sprintf(
                        '%s · operación revertida',
                        $repa->codigo,
                    ),
                    'meta' => array_filter([
                        'Resultado' => $textoResultados,
                        'Motivo' => $repa->motivo_anulacion,
                        'Anulado por' => $repa->anuladoPor?->name,
                    ], fn (mixed $valor): bool => $valor !== null && $valor !== ''),
                    'origenes' => $repa->detalles->map(fn ($origen): array => [
                        'folio' => $origen->folioOrigen?->numero_folio,
                        'aporte' => $origen->cajas_aportadas,
                        'antes' => $origen->cajas_antes,
                        'despues' => $origen->cajas_despues,
                    ])->values()->all(),
                ];
            }

            if ($esResultado && $detalle) {
                $titulo = 'Repaletizaje · folio conservado';
                $descripcion = sprintf(
                    '%d cajas → %d cajas · resultado %s',
                    $detalle->cajas_antes,
                    $repa->cantidad_resultante,
                    mb_strtoupper($repa->tipo_resultado),
                );
            } elseif ($esResultado) {
                $titulo = 'Creado por repaletizaje';
                $descripcion = sprintf(
                    '%s · %d cajas',
                    mb_strtoupper($repa->tipo_resultado),
                    $repa->cantidad_resultante,
                );
            } elseif ($detalle && $detalle->cajas_despues === 0) {
                $titulo = 'Agotado por repaletizaje';
                $descripcion = sprintf(
                    '%d cajas → 0 · aporta %d a %s',
                    $detalle->cajas_antes,
                    $detalle->cajas_aportadas,
                    $textoResultados ?: 'folio resultante',
                );
            } else {
                $titulo = 'Saldo repaletizado';
                $descripcion = sprintf(
                    '%d cajas → %d cajas · aporta %d a %s',
                    $detalle?->cajas_antes ?? 0,
                    $detalle?->cajas_despues ?? 0,
                    $detalle?->cajas_aportadas ?? 0,
                    $textoResultados ?: 'folio resultante',
                );
            }

            return [
                'tipo' => 'repaletizaje',
                'fecha' => $this->fecha($repa->confirmado_at ?? $repa->created_at),
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'meta' => array_filter([
                    'Repa' => $repa->codigo,
                    'Resultado' => $textoResultados,
                    'Cantidad resultado' => $repa->cantidad_resultante.' cajas',
                    'Operador' => $repa->usuario?->nombre,
                    'Mix' => ($repa->campos_mix ?? []) !== []
                        ? implode(', ', $repa->campos_mix)
                        : null,
                ], fn (mixed $valor): bool => $valor !== null && $valor !== ''),
                'origenes' => $repa->detalles->map(fn ($origen): array => [
                    'folio' => $origen->folioOrigen?->numero_folio,
                    'aporte' => $origen->cajas_aportadas,
                    'antes' => $origen->cajas_antes,
                    'despues' => $origen->cajas_despues,
                ])->values()->all(),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function resumenValidacion(ValidacionPallet $validacion): array
    {
        return [
            'fecha' => $this->fecha(
                $validacion->recibido_servidor_at
                    ?? $validacion->generado_dispositivo_at
                    ?? $validacion->created_at,
            ),
            'tipo_bulto' => $this->valor($validacion->tipo_bulto),
            'cantidad_cajas' => (int) $validacion->cantidad_cajas,
            'resultado' => $this->valor($validacion->resultado),
            'linea' => $validacion->linea_proceso,
            'turno' => $validacion->turno,
            'operador' => $validacion->usuario?->nombre,
        ];
    }

    private function ubicacion(?string $camara, ?string $posicion, string $fallback): string
    {
        if (! $camara && ! $posicion) {
            return $fallback;
        }

        return collect([$camara, $posicion])->filter()->implode(' · ');
    }

    private function fecha(mixed $fecha): ?string
    {
        return $fecha?->toIso8601String();
    }

    private function valor(mixed $valor): mixed
    {
        return $valor instanceof BackedEnum ? $valor->value : $valor;
    }
}
