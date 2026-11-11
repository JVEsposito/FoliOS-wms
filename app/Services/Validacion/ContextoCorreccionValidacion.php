<?php

namespace App\Services\Validacion;

use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Exceptions\ConflictoOperacion;
use App\Models\ValidacionPallet;
use Illuminate\Support\Facades\DB;

/**
 * Lo que necesita el diálogo de corrección: los datos actuales del bulto, con su composición
 * por CSG, y el catálogo activo de su temporada con la forma de la PDA. Lo usan Validación
 * y el cambio de atributos PT de administración.
 */
class ContextoCorreccionValidacion
{
    public function __construct(
        private readonly CatalogoOperativoValidacion $catalogo,
        private readonly ServicioCorreccionValidacionPallet $servicio,
    ) {}

    /** @return array<string, mixed> */
    public function construir(ValidacionPallet $validacion, ?string $cliente = null): array
    {
        $validacion->loadMissing(['temporada:id,codigo,nombre,activa', 'folio:id,numero_folio,estado_operacional,condicion_termica,activo']);
        if ($validacion->estado !== EstadoValidacionPallet::Aceptada
            || $validacion->resultado !== ResultadoValidacionPallet::Aprobado
            || ! $validacion->folio) {
            throw new ConflictoOperacion('Solo se pueden corregir validaciones aprobadas que conserven su folio asociado.');
        }
        $snapshot = $validacion->snapshot ?? [];
        $composicion = collect($snapshot['composicion'] ?? [])
            ->filter(fn (mixed $linea): bool => is_array($linea) && filled($linea['origen_validacion_id'] ?? null))
            ->map(fn (array $linea): array => [
                'origen_validacion_id' => $linea['origen_validacion_id'],
                'csg' => $linea['csg'] ?? null,
                'predio' => $linea['predio'] ?? null,
                'cantidad_cajas' => (int) ($linea['cantidad_cajas'] ?? 0),
                'lote_materia_prima' => $linea['lote_materia_prima'] ?? null,
                'proceso_packing' => $linea['proceso_packing'] ?? null,
            ])
            ->values();
        if ($composicion->isEmpty()) {
            // Validaciones antiguas, anteriores a la composición por CSG.
            $composicion = collect([[
                'origen_validacion_id' => $validacion->origen_validacion_id,
                'csg' => $snapshot['origen']['csg'] ?? null,
                'predio' => $snapshot['origen']['predio'] ?? null,
                'cantidad_cajas' => (int) $validacion->cantidad_cajas,
                'lote_materia_prima' => null,
                'proceso_packing' => null,
            ]]);
        }

        return [
            'validacion' => [
                'id' => $validacion->id,
                'numero_folio' => $validacion->numero_folio,
                'tipo_bulto' => $validacion->tipo_bulto,
                'cantidad_cajas' => (int) $validacion->cantidad_cajas,
                'linea_proceso' => $validacion->linea_proceso,
                'turno' => $validacion->turno,
                'articulo_validacion_id' => $validacion->articulo_validacion_id,
                'categoria_validacion_id' => $validacion->categoria_validacion_id,
                'fecha_embalaje' => $snapshot['fecha_embalaje'] ?? null,
                'fecha_hora_validacion' => $validacion->generado_dispositivo_at?->copy()
                    ->setTimezone(config('app.operational_timezone'))->format('Y-m-d\TH:i'),
                'articulo' => $snapshot['articulo'] ?? null,
                'origen' => $snapshot['origen'] ?? null,
                'categoria' => $snapshot['categoria']['nombre'] ?? null,
                'composicion' => $composicion->all(),
                'folio' => [
                    'estado_operacional' => $validacion->folio->estado_operacional->value,
                    'condicion_termica' => $validacion->folio->condicion_termica->value,
                    'activo' => $validacion->folio->activo,
                ],
            ],
            // Artículos y combinaciones solo del cliente (el del bulto o el que se elija al corregir).
            'catalogo' => [
                'temporada' => $validacion->temporada?->only(['id', 'codigo', 'nombre', 'activa']),
                ...$this->catalogo->paraCorreccion(
                    $validacion->temporada_id,
                    $cliente ?: $this->clienteDeLaValidacion($validacion),
                ),
            ],
            'puede_cambiar_numero_folio' => $this->servicio->puedeCambiarNumeroFolio($validacion->folio),
            'exige_lote_proceso' => (bool) config('validacion.exigir_lote_proceso'),
        ];
    }

    private function clienteDeLaValidacion(ValidacionPallet $validacion): string
    {
        return (string) (DB::table('origenes_validacion')->where('id', $validacion->origen_validacion_id)->value('cliente')
            ?? ($validacion->snapshot['origen']['cliente'] ?? ''));
    }
}
