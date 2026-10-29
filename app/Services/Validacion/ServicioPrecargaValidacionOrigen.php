<?php

namespace App\Services\Validacion;

use App\Exceptions\ConflictoOperacion;
use App\Models\ArticuloValidacion;
use App\Models\Folio;
use App\Models\LecturaStockOrigen;
use App\Models\OrigenValidacion;
use App\Models\PalletStockOrigen;
use App\Models\ValidacionPallet;
use App\Services\Integraciones\ServicioCatalogoStockOrigen;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Collection;

/**
 * Lo que la planta informa de un pallet, traducido al catálogo de validación de la temporada,
 * para que la PDA parta con esos datos. El validador solo confirma o corrige lo que no
 * coincide con el pallet físico: nada de esto crea ni modifica folios, y el registro sigue
 * validando cada dato contra el catálogo.
 *
 * Lo que no tiene equivalencia en el catálogo se informa como pendiente y queda para que el
 * validador lo complete, igual que antes.
 */
final class ServicioPrecargaValidacionOrigen
{
    public function __construct(
        private readonly ServicioLecturaStockOrigen $lecturas,
        private readonly ServicioCatalogoStockOrigen $catalogo,
    ) {}

    public function comprobarFolioNuevo(string $numeroFolio, string $temporadaId): void
    {
        if (app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido()?->id !== $temporadaId) {
            throw new ConflictoOperacion('La temporada cambió. Actualiza el catálogo de la PDA antes de consultar packing.');
        }
        $folio = Folio::where('numero_folio', $numeroFolio)->first();
        if (($folio && ! app(ProteccionFolioAnulado::class)->esAnuladoPorValidacion($folio))
            || ValidacionPallet::where('numero_folio', $numeroFolio)->where('temporada_id', $temporadaId)->where('estado', 'aceptada')->exists()) {
            throw new ConflictoOperacion('El folio ya tiene un registro en FoliOS. Revisa su historial antes de capturar otra validación.');
        }
    }

    /** @return array<string, mixed> */
    public function paraFolio(string $numeroFolio): array
    {
        $config = $this->lecturas->configuracion();
        if (! $config['habilitada'] || ! $config['conexion']) {
            return $this->noDisponible('La lectura del sistema de planta no está configurada.');
        }
        $pallet = PalletStockOrigen::query()
            ->where('ambito', $config['ambito'])
            ->where('bodega', $config['bodega'])
            ->where('numero_pallet', mb_strtoupper(trim($numeroFolio)))
            ->first();
        if (! $pallet) {
            return $this->noDisponible('El sistema de planta no tiene este pallet en bodega.');
        }
        if (! $pallet->presente) {
            return $this->noDisponible('Según la última lectura, el pallet ya salió de la bodega de planta.');
        }
        $temporada = $this->catalogo->preparar();
        if (! $temporada) {
            return $this->noDisponible('No hay una temporada activa con catálogo de validación.');
        }

        $resuelto = $this->catalogo->resolver($pallet);
        $pendientes = [];
        foreach ($pallet->mezclados ?? [] as $campo) {
            $pendientes[] = "Mezcla en origen: {$campo}. Elige el valor del pallet.";
        }
        foreach ($resuelto['sin_catalogo'] as $dimension) {
            $pendientes[] = ServicioCatalogoStockOrigen::DIMENSIONES[$dimension].' sin equivalencia en el catálogo de validación.';
        }
        $cliente = $resuelto['cliente'];
        $marca = $resuelto['marca'];
        $articulo = $this->articulo($temporada->id, $resuelto);
        if (! $articulo && collect(['cliente', 'especie', 'variedad', 'calibre', 'envase'])->every(fn (string $campo): bool => $resuelto[$campo] !== null)) {
            $pendientes[] = 'El producto del origen no está habilitado en el catálogo para este cliente.';
        }

        $composicion = collect($pallet->composicion ?? [])
            ->filter(fn (mixed $linea): bool => is_array($linea))
            // La PDA registra una línea por CSG, lote y proceso: el productor y la variedad del
            // origen no separan líneas en la validación.
            ->groupBy(fn (array $linea): string => implode('|', [
                ProyeccionTrazabilidadFolio::normalizarCodigo($linea['csg'] ?? null),
                ProyeccionTrazabilidadFolio::normalizarCodigo($linea['lote'] ?? null),
                ProyeccionTrazabilidadFolio::normalizarCodigo($linea['proceso'] ?? null),
            ]))
            ->map(function (Collection $grupo) use ($temporada, $cliente, $marca, &$pendientes): array {
                $csg = $this->texto($grupo->first()['csg'] ?? null);
                $csgValidacion = $this->catalogo->csg($csg);
                $origen = $csgValidacion && $cliente && $marca
                    ? OrigenValidacion::query()
                        ->where('temporada_id', $temporada->id)
                        ->where('activo', true)
                        ->where('cliente_validacion_id', $cliente->id)
                        ->where('marca_validacion_id', $marca->id)
                        ->where('csg_validacion_id', $csgValidacion->id)
                        ->first(['id'])
                    : null;
                if ($csgValidacion && $cliente && $marca && ! $origen) {
                    $pendientes[] = "CSG {$csg} no está habilitado para {$cliente->nombre} · {$marca->nombre}.";
                }

                return [
                    'csg' => $csg,
                    'predio' => $grupo->map(fn (array $linea): ?string => $this->texto($linea['predio'] ?? null))->filter()->first(),
                    'origen_validacion_id' => $origen?->id,
                    'cantidad_cajas' => (int) $grupo->sum(fn (array $linea): int => (int) ($linea['cajas'] ?? 0)),
                    'lote_materia_prima' => ProyeccionTrazabilidadFolio::normalizarCodigo($grupo->first()['lote'] ?? null),
                    'proceso_packing' => ProyeccionTrazabilidadFolio::normalizarCodigo($grupo->first()['proceso'] ?? null),
                ];
            })
            ->sortBy(fn (array $linea): string => implode('|', [$linea['csg'] ?? '', $linea['lote_materia_prima'] ?? '', $linea['proceso_packing'] ?? '']))
            ->values()
            ->all();
        if ($composicion === []) {
            $pendientes[] = 'El origen no informa la composición por CSG.';
        }
        if (! $pallet->fecha_embalaje) {
            $pendientes[] = 'Fecha de embalaje no informada por packing.';
        }

        $vigencia = (int) config('integraciones.stock_pt.vigencia_minutos', 20);
        $lectura = $pallet->lectura_stock_origen_id ? LecturaStockOrigen::find($pallet->lectura_stock_origen_id) : null;

        return [
            'disponible' => true,
            'numero_folio' => $pallet->numero_pallet,
            'huella' => $pallet->huella,
            'leido_at' => $pallet->ultima_lectura_at?->toAtomString(),
            'desactualizada' => ! $lectura || $lectura->estado !== 'correcta'
                || $lectura->configuracion_hash !== $this->lecturas->huellaConfiguracion($config)
                || ! $pallet->ultima_lectura_at || $pallet->ultima_lectura_at->lt(now()->subMinutes($vigencia)),
            'tipo_bulto' => $pallet->tipo_bulto,
            'cantidad_cajas' => $pallet->cajas,
            'fecha_embalaje' => $pallet->fecha_embalaje?->toDateString(),
            'articulo_validacion_id' => $articulo?->id,
            // Como en Pendientes por validar, la categoría es una sugerencia: el validador la confirma.
            'categoria_validacion_id' => $resuelto['categoria']?->id,
            // Permite completar lo conocido aunque falte una dimensión del artículo.
            'producto' => collect(['especie', 'variedad', 'calibre', 'envase'])
                ->mapWithKeys(fn (string $campo): array => [$campo => $resuelto[$campo]?->nombre])->all(),
            'composicion' => $composicion,
            'origen' => [
                'cliente' => $cliente?->nombre ?? $pallet->empresa,
                'marca' => $marca?->nombre ?? $pallet->marca,
                'especie' => $pallet->especie,
                'variedad' => $pallet->variedad,
                'calibre' => $pallet->calibre,
                'envase' => $pallet->envase,
                'categoria' => $pallet->categoria,
            ],
            'pendientes' => array_values(array_unique($pendientes)),
        ];
    }

    /** @param  array<string, mixed>  $resuelto */
    private function articulo(string $temporadaId, array $resuelto): ?ArticuloValidacion
    {
        foreach (['cliente', 'especie', 'variedad', 'calibre', 'envase'] as $campo) {
            if ($resuelto[$campo] === null) {
                return null;
            }
        }

        return ArticuloValidacion::query()
            ->where('temporada_id', $temporadaId)
            ->where('activo', true)
            ->where('cliente_validacion_id', $resuelto['cliente']->id)
            ->where('especie_validacion_id', $resuelto['especie']->id)
            ->where('variedad_validacion_id', $resuelto['variedad']->id)
            ->where('calibre_validacion_id', $resuelto['calibre']->id)
            ->where('envase_validacion_id', $resuelto['envase']->id)
            ->first(['id']);
    }

    /** @return array{disponible: false, motivo: string} */
    private function noDisponible(string $motivo): array
    {
        return ['disponible' => false, 'motivo' => $motivo];
    }

    private function texto(mixed $valor): ?string
    {
        return is_scalar($valor) && trim((string) $valor) !== '' ? trim((string) $valor) : null;
    }
}
