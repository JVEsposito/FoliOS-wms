<?php

namespace App\Services\Validacion;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Models\Folio;
use App\Models\LecturaStockOrigen;
use App\Models\PalletStockOrigen;
use App\Models\ValidacionPallet;
use App\Services\Integraciones\ServicioCatalogoStockOrigen;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Compara el stock de la planta en el sistema de origen con lo validado en FoliOS:
 * - pendientes: en el origen y sin ningún intento de validación;
 * - por resolver: el último intento quedó observado, rechazado, en conflicto o anulado;
 * - con diferencias: validado, pero no coincide en tipo, cajas, cliente, marca, especie,
 *   variedad, calibre, embalaje o cajas por CSG;
 * - solo en FoliOS: validado y en planta, pero no figura en el stock del origen.
 * Cliente, marca, especie, variedad, calibre y embalaje se comparan contra el catálogo de
 * validación (ver ServicioCatalogoStockOrigen). La categoría solo se sugiere, porque cada
 * cliente usa su propia nomenclatura. Validación sigue siendo la puerta a Frigorífico: aquí
 * nada crea ni modifica folios.
 */
class ServicioPendientesValidacionOrigen
{
    /** Estados en que un folio validado sigue físicamente en la planta. */
    private const EN_PLANTA = [
        EstadoOperacionalFolio::PendientePrefrio,
        EstadoOperacionalFolio::PendienteUbicacion,
        EstadoOperacionalFolio::Disponible,
        EstadoOperacionalFolio::Bloqueado,
    ];

    /** Dimensiones del artículo que se comparan contra el catálogo, con su campo en el pallet de origen. */
    private const COMPARADAS = [
        'cliente' => ['etiqueta' => 'Cliente', 'campos' => ['empresa']],
        'marca' => ['etiqueta' => 'Marca', 'campos' => ['marca']],
        'especie' => ['etiqueta' => 'Especie', 'campos' => ['especie', 'especie_codigo']],
        'variedad' => ['etiqueta' => 'Variedad', 'campos' => ['variedad']],
        'calibre' => ['etiqueta' => 'Calibre', 'campos' => ['calibre']],
        'envase' => ['etiqueta' => 'Embalaje', 'campos' => ['envase', 'envase_codigo']],
    ];

    public function __construct(
        private readonly ServicioLecturaStockOrigen $lecturas,
        private readonly ServicioCatalogoStockOrigen $catalogo,
    ) {}

    /** @return array<string, mixed> */
    public function resumen(): array
    {
        $config = $this->lecturas->configuracion();
        $bodega = $config['bodega'];
        $pallets = PalletStockOrigen::query()
            ->where('bodega', $bodega)
            ->where('presente', true)
            ->orderBy('recibido_origen_at')
            ->orderBy('numero_pallet')
            ->get();
        $numeros = $pallets->pluck('numero_pallet');
        $folios = Folio::query()
            ->whereIn('numero_folio', $numeros)
            ->get(['id', 'numero_folio', 'tipo_bulto', 'estado_operacional', 'activo', 'calibre', 'variedad', 'marca', 'exportadora', 'datos_externos', 'created_at'])
            ->keyBy(fn (Folio $folio): string => mb_strtoupper($folio->numero_folio));
        $intentos = ValidacionPallet::query()
            ->whereIn('numero_folio', $numeros)
            ->orderBy('numero_intento')
            ->get(['numero_folio', 'numero_intento', 'resultado', 'estado', 'motivo', 'observacion', 'recibido_servidor_at'])
            ->keyBy(fn (ValidacionPallet $intento): string => mb_strtoupper($intento->numero_folio));

        $temporada = $this->catalogo->preparar();
        $pendientes = [];
        $porResolver = [];
        $diferencias = [];
        $validados = 0;
        $palletsSinCatalogo = 0;
        foreach ($pallets as $pallet) {
            $resuelto = $this->catalogo->resolver($pallet);
            if ($resuelto['sin_catalogo'] !== []) {
                $palletsSinCatalogo++;
            }
            $folio = $folios->get($pallet->numero_pallet);
            $vigente = $folio && ! ($folio->estado_operacional === EstadoOperacionalFolio::Anulado && ! $folio->activo);
            if ($vigente) {
                $validados++;
                [$campos, $sugerencias] = $this->diferencias($pallet, $folio, $resuelto);
                if ($campos !== []) {
                    $diferencias[] = [...$this->pallet($pallet, $resuelto), 'folio' => $this->folio($folio), 'diferencias' => $campos, 'sugerencias' => $sugerencias];
                }

                continue;
            }
            $intento = $intentos->get($pallet->numero_pallet);
            if ($intento) {
                $porResolver[] = [...$this->pallet($pallet, $resuelto), 'validacion' => [
                    'situacion' => $this->situacion($intento, $folio),
                    'intento' => $intento->numero_intento,
                    'motivo' => $intento->motivo?->value,
                    'observacion' => $intento->observacion,
                    'registrado_at' => $intento->recibido_servidor_at?->toAtomString(),
                ]];

                continue;
            }
            $pendientes[] = $this->pallet($pallet, $resuelto);
        }
        $catalogoPendiente = $this->catalogo->pendientes();

        $soloEnFolios = Folio::query()
            ->where('origen_sistema', 'validacion')
            ->where('activo', true)
            ->whereIn('tipo_bulto', [TipoBulto::Pallet->value, TipoBulto::Saldo->value])
            ->whereIn('estado_operacional', array_map(fn (EstadoOperacionalFolio $estado): string => $estado->value, self::EN_PLANTA))
            ->when($numeros->isNotEmpty(), fn ($consulta) => $consulta->whereNotIn('numero_folio', $numeros))
            ->orderBy('created_at')
            ->limit(500)
            ->get(['id', 'numero_folio', 'tipo_bulto', 'estado_operacional', 'activo', 'calibre', 'variedad', 'marca', 'exportadora', 'datos_externos', 'created_at'])
            ->map(fn (Folio $folio): array => $this->folio($folio))
            ->values()
            ->all();
        $hayCopia = PalletStockOrigen::query()->where('bodega', $bodega)->exists();

        return [
            'fuente' => [
                'habilitada' => $config['habilitada'],
                'conexion' => $config['conexion']?->nombre,
                'tabla' => $config['tabla'],
                'bodega' => $bodega,
                'temporada' => $config['temporada'],
                'temporada_catalogo' => $temporada?->nombre,
            ],
            'lectura' => $this->estadoLectura($bodega),
            'resumen' => [
                'en_origen' => $pallets->count(),
                'validados' => $validados,
                'pendientes' => count($pendientes),
                'cajas_pendientes' => array_sum(array_column($pendientes, 'cajas')),
                'por_resolver' => count($porResolver),
                'con_diferencias' => count($diferencias),
                // Sin una lectura correcta, todo lo validado aparecería como «solo en FoliOS».
                'solo_en_folios' => $hayCopia ? count($soloEnFolios) : 0,
                'catalogo_pendiente' => count($catalogoPendiente),
                'pallets_sin_catalogo' => $palletsSinCatalogo,
            ],
            'pendientes' => $pendientes,
            'por_resolver' => $porResolver,
            'diferencias' => $diferencias,
            'solo_en_folios' => $hayCopia ? $soloEnFolios : [],
            'catalogo' => $catalogoPendiente,
        ];
    }

    /**
     * @param  array<string, mixed>  $resuelto
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function diferencias(PalletStockOrigen $pallet, Folio $folio, array $resuelto): array
    {
        $datos = $folio->datos_externos ?? [];
        $campos = [];
        if (! in_array($folio->estado_operacional, self::EN_PLANTA, true)) {
            $campos[] = ['campo' => 'estado', 'etiqueta' => 'Estado', 'origen' => 'En stock de la planta', 'catalogo' => null, 'folios' => $this->estado($folio->estado_operacional)];
        }
        if ($folio->tipo_bulto->value !== $pallet->tipo_bulto) {
            $campos[] = ['campo' => 'tipo', 'etiqueta' => 'Tipo', 'origen' => $this->tipo($pallet->tipo_bulto), 'catalogo' => null, 'folios' => $this->tipo($folio->tipo_bulto->value)];
        }
        $cajasFolio = (int) ($datos['cantidad_cajas'] ?? 0);
        if ($cajasFolio !== $pallet->cajas) {
            $campos[] = ['campo' => 'cajas', 'etiqueta' => 'Cajas', 'origen' => $pallet->cajas, 'catalogo' => null, 'folios' => $cajasFolio];
        }
        $enFolios = [
            'cliente' => $folio->exportadora,
            'marca' => $folio->marca,
            'especie' => $datos['especie'] ?? null,
            'variedad' => $folio->variedad,
            'calibre' => $folio->calibre,
            'envase' => $datos['envase'] ?? null,
        ];
        foreach (self::COMPARADAS as $campo => $definicion) {
            $origen = $this->valorOrigen($pallet, $campo);
            if ($origen === null) {
                continue;
            }
            if (array_intersect($definicion['campos'], $pallet->mezclados ?? []) !== []) {
                // Un pallet validado tiene un solo artículo: si el origen mezcla variedades o calibres, no coincide.
                if (in_array($campo, ['variedad', 'calibre'], true)) {
                    $campos[] = ['campo' => $campo, 'etiqueta' => $definicion['etiqueta'], 'origen' => $origen.' (mezclado)', 'catalogo' => null, 'folios' => $enFolios[$campo]];
                }

                continue;
            }
            // Sin equivalencia en el catálogo no hay con qué comparar: queda en «Catálogo». El calibre
            // se sigue comparando tal cual, como antes de existir la equivalencia.
            $esperado = $resuelto[$campo]?->nombre ?? ($campo === 'calibre' ? $origen : null);
            if ($esperado !== null && $this->clave($esperado) !== $this->clave($enFolios[$campo])) {
                $campos[] = ['campo' => $campo, 'etiqueta' => $definicion['etiqueta'], 'origen' => $origen, 'catalogo' => $resuelto[$campo]?->nombre, 'folios' => $enFolios[$campo]];
            }
        }
        $sugerencias = [];
        $categoria = $resuelto['categoria']?->nombre;
        if ($categoria !== null && $this->clave($categoria) !== $this->clave($datos['categoria'] ?? null)) {
            $sugerencias[] = ['campo' => 'categoria', 'etiqueta' => 'Categoría', 'origen' => $pallet->categoria, 'catalogo' => $categoria, 'folios' => $datos['categoria'] ?? null];
        }
        $csgOrigen = $this->cajasPorCsg(collect($pallet->composicion), 'csg', 'cajas');
        $csgFolio = $this->cajasPorCsg(collect($datos['composicion'] ?? []), 'csg', 'cantidad_cajas');
        if ($csgFolio !== [] && $csgOrigen !== $csgFolio) {
            $campos[] = ['campo' => 'csg', 'etiqueta' => 'Cajas por CSG', 'origen' => $this->textoCsg($csgOrigen), 'catalogo' => null, 'folios' => $this->textoCsg($csgFolio)];
        }

        return [$campos, $sugerencias];
    }

    private function valorOrigen(PalletStockOrigen $pallet, string $campo): ?string
    {
        $valor = match ($campo) {
            'cliente' => $pallet->empresa,
            'especie' => $pallet->especie ?: $pallet->especie_codigo,
            'envase' => collect([$pallet->envase_codigo, $pallet->envase])->filter()->unique()->implode(' · '),
            default => $pallet->getAttribute($campo),
        };

        return $this->normalizar($valor) === null ? null : trim((string) $valor);
    }

    /** Mismo criterio que el catálogo: sin distinguir mayúsculas, tildes ni espacios. */
    private function clave(?string $valor): string
    {
        return mb_strtoupper(Str::squish(Str::ascii((string) $valor)));
    }

    /** @return array<string, int> */
    private function cajasPorCsg(Collection $lineas, string $csg, string $cajas): array
    {
        $resultado = $lineas
            ->filter(fn (mixed $linea): bool => is_array($linea))
            ->groupBy(fn (array $linea): string => $this->normalizar($linea[$csg] ?? null) ?? 'SIN CSG')
            ->map(fn (Collection $grupo): int => (int) $grupo->sum(fn (array $linea): int => (int) ($linea[$cajas] ?? 0)))
            ->all();
        ksort($resultado);

        return $resultado;
    }

    /** @param array<string, int> $cajas */
    private function textoCsg(array $cajas): string
    {
        return collect($cajas)->map(fn (int $total, string $csg): string => "{$csg}: {$total}")->implode(' · ');
    }

    private function situacion(ValidacionPallet $intento, ?Folio $folio): string
    {
        return match (true) {
            $folio !== null => 'anulado',
            $intento->estado === EstadoValidacionPallet::Conflicto => 'conflicto',
            $intento->estado === EstadoValidacionPallet::Anulada => 'anulado',
            $intento->resultado === ResultadoValidacionPallet::Rechazado => 'rechazado',
            $intento->resultado === ResultadoValidacionPallet::Observado => 'observado',
            default => 'sin_folio',
        };
    }

    /**
     * @param  array<string, mixed>  $resuelto
     * @return array<string, mixed>
     */
    private function pallet(PalletStockOrigen $pallet, array $resuelto): array
    {
        $composicion = collect($pallet->composicion);

        return [
            'numero' => $pallet->numero_pallet,
            'tipo_bulto' => $pallet->tipo_bulto,
            'tipo_mezclado' => $pallet->tipo_mezclado,
            'cajas' => $pallet->cajas,
            'kilos' => (float) $pallet->kilos,
            'empresa' => $pallet->empresa,
            'cliente' => $resuelto['cliente']?->nombre,
            'especie' => $pallet->especie,
            'especie_codigo' => $pallet->especie_codigo,
            'variedad' => $pallet->variedad,
            'calibre' => $pallet->calibre,
            'categoria' => $pallet->categoria,
            // La categoría del origen no se impone: solo se sugiere la equivalente del catálogo.
            'categoria_sugerida' => $resuelto['categoria']?->nombre,
            'envase' => $pallet->envase,
            'envase_codigo' => $pallet->envase_codigo,
            'marca' => $pallet->marca,
            'sin_catalogo' => array_map(
                fn (string $dimension): string => ServicioCatalogoStockOrigen::DIMENSIONES[$dimension],
                $resuelto['sin_catalogo'],
            ),
            'origen' => $pallet->origen,
            'temporada_origen' => $pallet->temporada_origen,
            'mezclados' => $pallet->mezclados,
            'csgs' => $composicion->pluck('csg')->filter()->unique()->values()->all(),
            'procesos' => $composicion->pluck('proceso')->filter()->unique()->values()->all(),
            'lotes' => $composicion->pluck('lote')->filter()->unique()->values()->all(),
            'composicion' => $pallet->composicion,
            'fecha_embalaje' => $pallet->fecha_embalaje?->toDateString(),
            'recibido_origen_at' => $pallet->recibido_origen_at?->toAtomString(),
            'primera_lectura_at' => $pallet->primera_lectura_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function folio(Folio $folio): array
    {
        $datos = $folio->datos_externos ?? [];

        return [
            'id' => $folio->id,
            'numero' => $folio->numero_folio,
            'tipo_bulto' => $folio->tipo_bulto->value,
            'estado' => $folio->estado_operacional->value,
            'estado_etiqueta' => $this->estado($folio->estado_operacional),
            'cajas' => (int) ($datos['cantidad_cajas'] ?? 0),
            'cliente' => $folio->exportadora,
            'marca' => $folio->marca,
            'especie' => $datos['especie'] ?? null,
            'variedad' => $folio->variedad,
            'calibre' => $folio->calibre,
            'categoria' => $datos['categoria'] ?? null,
            'envase' => $datos['envase'] ?? null,
            'csgs' => collect($datos['composicion'] ?? [])->pluck('csg')->filter()->unique()->values()->all(),
            'validado_at' => $folio->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function estadoLectura(string $bodega): array
    {
        $ultima = LecturaStockOrigen::query()->where('bodega', $bodega)->latest('iniciada_at')->first();
        $correcta = LecturaStockOrigen::query()->where('bodega', $bodega)->where('estado', 'correcta')->latest('iniciada_at')->first();
        $vigencia = (int) config('integraciones.stock_pt.vigencia_minutos', 20);

        return [
            'ultima' => $ultima ? [
                'estado' => $ultima->estado,
                'causa' => $ultima->causa,
                'mensaje' => $ultima->mensaje,
                'iniciada_at' => $ultima->iniciada_at?->toAtomString(),
                'terminada_at' => $ultima->terminada_at?->toAtomString(),
                'filas' => $ultima->filas,
                'pallets' => $ultima->pallets,
                'nuevos' => $ultima->nuevos,
                'modificados' => $ultima->modificados,
                'retirados' => $ultima->retirados,
            ] : null,
            'ultima_correcta_at' => $correcta?->terminada_at?->toAtomString(),
            'desactualizada' => ! $correcta || $correcta->terminada_at === null || $correcta->terminada_at->lt(now()->subMinutes($vigencia)),
            'vigencia_minutos' => $vigencia,
        ];
    }

    private function normalizar(?string $valor): ?string
    {
        $texto = mb_strtoupper(trim((string) $valor));

        return $texto === '' ? null : $texto;
    }

    private function tipo(string $tipo): string
    {
        return $tipo === TipoBulto::Saldo->value ? 'Saldo' : 'Pallet';
    }

    private function estado(EstadoOperacionalFolio $estado): string
    {
        return match ($estado) {
            EstadoOperacionalFolio::PendientePrefrio => 'Pendiente de prefrío',
            EstadoOperacionalFolio::PendienteUbicacion => 'Pendiente de ubicación',
            EstadoOperacionalFolio::Disponible => 'Disponible',
            EstadoOperacionalFolio::Bloqueado => 'Bloqueado',
            EstadoOperacionalFolio::Anulado => 'Anulado',
            EstadoOperacionalFolio::RetiradoDefinitivo => 'Retirado',
            EstadoOperacionalFolio::Despachado => 'Despachado',
            EstadoOperacionalFolio::Agotado => 'Agotado (repaletizado)',
        };
    }
}
