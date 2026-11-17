<?php

namespace App\Services\Envases;

use App\Enums\TipoEnvaseRomana;
use App\Models\ReasignacionProductorEnvases;
use Illuminate\Support\Facades\Schema;

/**
 * Reasignaciones entre productores de una cuenta, listas para aplicarse sobre la atribución por CSG de cada
 * documento (estado de cuenta y Excel por productor). Se aplican en orden de registro y cada una se consume
 * una sola vez: lo que entrega el productor de origen lo recibe el de destino, sin duplicar envases.
 */
final class ReasignacionesProductorVigentes
{
    private static bool $tablaLista = false;

    /**
     * @param  array<string, list<array{origen: string, destino: array{codigo: string, nombre: ?string}, fecha: ?\DateTimeInterface, restante: array<string, int>}>>  $porClave
     */
    private function __construct(private array $porClave) {}

    public static function cargar(string $clienteId, string $temporadaId): self
    {
        if (! (self::$tablaLista = self::$tablaLista || Schema::hasTable('reasignaciones_productor_envases'))) {
            return new self([]);
        }
        $porClave = [];
        $registros = ReasignacionProductorEnvases::query()->where('cliente_id', $clienteId)->where('temporada_id', $temporadaId)
            ->orderBy('registrado_at')->orderBy('created_at')->orderBy('id')->get();
        foreach ($registros as $registro) {
            $restante = [];
            foreach ($registro->envases as $envase) {
                $restante[$envase['tipo_envase']] = ($restante[$envase['tipo_envase']] ?? 0) + (int) $envase['cantidad'];
            }
            $porClave[$registro->documento_clave][] = [
                'origen' => ResumenEnvasesPorCsg::clave($registro->productor_origen),
                'destino' => ['codigo' => (string) $registro->productor_destino['codigo'], 'nombre' => $registro->productor_destino['nombre'] ?? null],
                'fecha' => $registro->fecha_documento,
                'restante' => $restante,
            ];
        }

        return new self($porClave);
    }

    public function vacio(): bool
    {
        return $this->porClave === [];
    }

    /** Documento de la cuenta al que se le reasignan productores: la guía sin la parte por guía de ingreso. */
    public static function base(string $clave): string
    {
        return (string) preg_replace('/:guia:.*$/', '', $clave);
    }

    /** En un ingreso se reasignan entradas; en la salida del destare o un despacho, salidas. */
    public static function campo(string $clave): string
    {
        $base = self::base($clave);

        return str_starts_with($base, 'despacho:') || str_ends_with($base, ':entregados') ? 'salidas' : 'entradas';
    }

    /**
     * Mueve lo reasignado de un documento entre sus asignaciones por CSG: entradas en un ingreso y salidas en
     * una salida. Nunca toma más de lo que tiene el productor de origen en el documento.
     *
     * @param  list<array{csg: ?array, compartido?: ?string, tipos: array<string, array<string, mixed>>}>  $asignaciones
     * @return list<array{csg: ?array, compartido?: ?string, tipos: array<string, array<string, mixed>>}>
     */
    public function aplicar(?string $clave, array $asignaciones): array
    {
        if ($clave === null || empty($this->porClave[$clave])) {
            return $asignaciones;
        }
        $campo = self::campo($clave);
        $cambio = false;
        foreach ($this->porClave[$clave] as &$reasignacion) {
            foreach ($reasignacion['restante'] as $tipo => $cantidad) {
                if ($cantidad <= 0) {
                    continue;
                }
                $origen = null;
                foreach ($asignaciones as $indice => $asignacion) {
                    if (ResumenEnvasesPorCsg::clave($asignacion['csg']) === $reasignacion['origen'] && ($asignacion['tipos'][$tipo][$campo] ?? 0) > 0) {
                        $origen = $indice;

                        break;
                    }
                }
                if ($origen === null) {
                    continue;
                }
                $mover = min($cantidad, (int) $asignaciones[$origen]['tipos'][$tipo][$campo]);
                $asignaciones[$origen]['tipos'][$tipo][$campo] -= $mover;
                $destino = null;
                foreach ($asignaciones as $indice => $asignacion) {
                    if (ResumenEnvasesPorCsg::clave($asignacion['csg']) === 'csg:'.$reasignacion['destino']['codigo']) {
                        $destino = $indice;

                        break;
                    }
                }
                if ($destino === null) {
                    $asignaciones[] = ['csg' => $reasignacion['destino'], 'compartido' => null, 'tipos' => []];
                    $destino = array_key_last($asignaciones);
                }
                $asignaciones[$destino]['tipos'][$tipo] ??= [...$asignaciones[$origen]['tipos'][$tipo], 'entradas' => 0, 'salidas' => 0];
                $asignaciones[$destino]['tipos'][$tipo][$campo] += $mover;
                $reasignacion['restante'][$tipo] -= $mover;
                $cambio = true;
            }
        }
        unset($reasignacion);
        if (! $cambio) {
            return $asignaciones;
        }
        $resultado = [];
        foreach ($asignaciones as $asignacion) {
            $asignacion['tipos'] = array_filter($asignacion['tipos'], fn (array $fila): bool => ($fila['entradas'] ?? 0) !== 0 || ($fila['salidas'] ?? 0) !== 0);
            if ($asignacion['tipos'] !== []) {
                $resultado[] = $asignacion;
            }
        }

        return ServicioCuentaCorrienteEnvases::notasCompartidas($resultado);
    }

    /**
     * Líneas de saldo inicial por productor de los documentos anteriores al período: lo reasignado sale del
     * productor de origen y entra al de destino. Consume las reasignaciones, que ya no se aplican en el detalle.
     *
     * @param  array<string, true>  $basesAnteriores  documentos con movimientos antes del período
     * @param  list<string>|null  $tiposIncluidos  envases del estado de cuenta; null, todos
     * @return list<array<string, mixed>>
     */
    public function lineasAnteriores(array $basesAnteriores, ?array $tiposIncluidos): array
    {
        $lineas = [];
        foreach ($this->porClave as $clave => &$reasignaciones) {
            $base = self::base($clave);
            if (! isset($basesAnteriores[$base])) {
                continue;
            }
            foreach ($reasignaciones as &$reasignacion) {
                foreach ($reasignacion['restante'] as $tipo => $cantidad) {
                    $envase = TipoEnvaseRomana::tryFrom($tipo);
                    if ($cantidad <= 0 || $envase === null || ($tiposIncluidos !== null && ! in_array($tipo, $tiposIncluidos, true))) {
                        continue;
                    }
                    $campo = self::campo($base);
                    $origen = $reasignacion['origen'] === 'sin_csg' ? null : ['codigo' => substr($reasignacion['origen'], 4), 'nombre' => null];
                    foreach ([[$origen, -$cantidad], [$reasignacion['destino'], $cantidad]] as [$csg, $impacto]) {
                        $lineas[] = ['recepcion_id' => null, 'movimiento' => 'reasignacion_productor', 'tipo_envase' => $tipo,
                            'nombre_envase' => $envase->etiqueta(), 'orden' => $envase->orden(), 'csg' => $csg,
                            'entradas' => $campo === 'entradas' ? $impacto : 0, 'salidas' => $campo === 'salidas' ? $impacto : 0];
                    }
                    $reasignacion['restante'][$tipo] = 0;
                }
            }
            unset($reasignacion);
        }
        unset($reasignaciones);

        return $lineas;
    }
}
