<?php

namespace App\Comex\Expediente;

use App\Comex\LectorOperacion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que la operación sabe de cada embarque: calendario, instructivos y carga. Solo lee, por la
 * conexión de lectura de Comex; nada de esto se copia salvo lo que se guarda junto a un documento.
 */
final class EmbarquesOperacion
{
    /** Estados de la carga en que el camión ya salió de la planta. */
    public const CARGA_SALIDA = ['despachada', 'cerrada'];

    /** Folios que ya no son parte de la carga. */
    private const FOLIOS_FUERA = ['descartado', 'reemplazado'];

    public function __construct(private readonly LectorOperacion $lector) {}

    /**
     * Embarques programados entre dos fechas (inclusive), con sus instructivos.
     *
     * @return list<array<string, mixed>>
     */
    public function entre(CarbonImmutable $desde, CarbonImmutable $hasta, ?string $clienteId = null): array
    {
        $filas = $this->consulta()
            ->whereBetween('e.fecha_programada', [$desde->toDateString(), $hasta->toDateString()])
            ->when($clienteId !== null, fn ($consulta) => $consulta->where('e.cliente_id', $clienteId))
            ->orderBy('e.fecha_programada')
            ->orderBy('e.hora_programada')
            ->orderBy('e.codigo')
            ->get();

        return $this->conDetalle($filas);
    }

    /** @return array<string, mixed>|null */
    public function uno(string $embarqueId): ?array
    {
        $fila = $this->consulta()->where('e.id', $embarqueId)->first();

        return $fila === null ? null : $this->conDetalle(collect([$fila]))[0];
    }

    public function documentosSag(string $embarqueId): array
    {
        return $this->lector->tabla('documentos_sag')->where('embarque_id', $embarqueId)
            ->orderByDesc('fecha_actividad')->orderByDesc('id')->limit(100)
            ->get(['id', 'tipo', 'estado', 'numero', 'fecha_actividad'])
            ->map(fn ($d) => [...(array) $d, 'url' => '/oficina/frigorifico/contraparte-sag?documento='.rawurlencode($d->id)])->all();
    }

    /**
     * Clientes de la operación, para configurar cómo los sigue Comex.
     *
     * @return list<array{id: string, nombre: string, activo: bool}>
     */
    public function clientes(): array
    {
        return $this->lector->tabla('clientes')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'activo'])
            ->map(fn (object $cliente): array => ['id' => (string) $cliente->id, 'nombre' => (string) $cliente->nombre, 'activo' => (bool) $cliente->activo])
            ->all();
    }

    /** @return array{id: string, nombre: string}|null */
    public function cliente(string $clienteId): ?array
    {
        $cliente = $this->lector->tabla('clientes')->where('id', $clienteId)->first(['id', 'nombre']);

        return $cliente === null ? null : ['id' => (string) $cliente->id, 'nombre' => (string) $cliente->nombre];
    }

    /**
     * Países del catálogo operativo, por código ISO.
     *
     * @return list<array{iso2: string, nombre: string}>
     */
    public function paises(): array
    {
        return $this->lector->tabla('paises')
            ->where('activo', true)
            ->orderBy('nombre_es')
            ->get(['iso_alpha2', 'nombre_es'])
            ->map(fn (object $pais): array => ['iso2' => (string) $pais->iso_alpha2, 'nombre' => (string) $pais->nombre_es])
            ->all();
    }

    /**
     * Folios de la carga del embarque con lo que necesita un packing list: una línea por cada
     * composición del pallet (un pallet mixto trae varias).
     *
     * @return list<array<string, mixed>>
     */
    public function foliosCarga(string $cargaId): array
    {
        $folios = $this->lector->tabla('carga_folios', 'cf')
            ->join('folios as f', 'f.id', '=', 'cf.folio_id')
            ->where('cf.carga_id', $cargaId)
            ->whereNotIn('cf.estado', self::FOLIOS_FUERA)
            ->orderBy('f.numero_folio')
            ->get(['cf.estado', 'f.id', 'f.numero_folio', 'f.tipo_bulto', 'f.variedad', 'f.calibre', 'f.marca', 'f.exportadora', 'f.fecha_proceso_pt', 'f.datos_externos']);

        $kilos = $this->kilosPorCaja($folios);
        $lineas = [];
        foreach ($folios as $folio) {
            $datos = json_decode((string) $folio->datos_externos, true) ?: [];
            $composicion = array_values(array_filter($datos['composicion'] ?? [], 'is_array'));
            if ($composicion === []) {
                $composicion = [['csg' => $datos['csg'] ?? null, 'predio' => $datos['predio'] ?? null, 'fecha_embalaje' => $datos['fecha_embalaje'] ?? null, 'cantidad_cajas' => $datos['cantidad_cajas'] ?? 0]];
            }

            foreach ($composicion as $indice => $linea) {
                $cajas = (int) ($linea['cantidad_cajas'] ?? 0);
                $porCaja = $kilos($folio, $datos, $linea);
                $lineas[] = [
                    'folio' => (string) $folio->numero_folio,
                    'linea' => $indice + 1,
                    'lineas_folio' => count($composicion),
                    'estado' => (string) $folio->estado,
                    'tipo_bulto' => (string) $folio->tipo_bulto,
                    'especie' => $this->texto($linea['especie'] ?? $datos['especie'] ?? null),
                    'variedad' => $this->texto($linea['variedad'] ?? $folio->variedad),
                    'calibre' => $this->texto($linea['calibre'] ?? $folio->calibre),
                    'envase' => $this->texto($linea['envase'] ?? $datos['envase'] ?? null),
                    'categoria' => $this->texto($linea['categoria'] ?? $datos['categoria'] ?? null),
                    'marca' => $this->texto($linea['marca'] ?? $folio->marca),
                    'exportadora' => $this->texto($linea['cliente'] ?? $folio->exportadora),
                    'csg' => $this->texto($linea['csg'] ?? $datos['csg'] ?? null),
                    'predio' => $this->texto($linea['predio'] ?? $datos['predio'] ?? null),
                    'fecha_embalaje' => $this->fecha($linea['fecha_embalaje'] ?? $datos['fecha_embalaje'] ?? $folio->fecha_proceso_pt),
                    'cajas' => $cajas,
                    'kilos_por_caja' => $porCaja,
                    // En diezmilésimas, como la etiqueta PT: sin errores de coma flotante.
                    'kilos' => $porCaja === null ? null : number_format(((int) round((float) $porCaja * 10000) * $cajas) / 10000, 2, '.', ''),
                ];
            }
        }

        return $lineas;
    }

    private function consulta(): Builder
    {
        return $this->lector->tabla('embarques', 'e')
            ->join('clientes as c', 'c.id', '=', 'e.cliente_id')
            ->join('temporadas as t', 't.id', '=', 'e.temporada_id')
            ->leftJoin('cargas as ca', 'ca.id', '=', 'e.carga_id')
            ->leftJoin('puertos as pe', 'pe.id', '=', 'e.puerto_embarque_id')
            ->select([
                'e.id', 'e.codigo', 'e.temporada_id', 't.nombre as temporada_nombre', 'e.cliente_id', 'c.nombre as cliente_nombre',
                'e.fecha_programada', 'e.hora_programada', 'e.modalidad', 'e.estado', 'e.nave_vuelo', 'e.transportista',
                'e.puerto_embarque', 'pe.nombre as puerto_embarque_catalogo', 'e.contenedor', 'e.sello', 'e.patente_camion',
                'e.patente_trasera', 'e.documentos', 'e.observacion', 'e.carga_id', 'ca.codigo as carga_codigo',
                'ca.estado as carga_estado', 'ca.cerrada_at as carga_cerrada_at',
            ]);
    }

    /**
     * @param  Collection<int, object>  $filas
     * @return list<array<string, mixed>>
     */
    private function conDetalle(Collection $filas): array
    {
        if ($filas->isEmpty()) {
            return [];
        }

        $instructivos = $this->lector->tabla('instructivos_embarque', 'i')
            ->leftJoin('paises as p', 'p.id', '=', 'i.pais_destino_id')
            ->leftJoin('puertos as pd', 'pd.id', '=', 'i.puerto_destino_id')
            ->whereIn('i.embarque_id', $filas->pluck('id')->all())
            ->orderBy('i.orden')
            ->get([
                'i.embarque_id', 'i.orden', 'i.numero_externo', 'i.recibidor', 'i.destino_pais', 'p.iso_alpha2', 'p.nombre_es',
                'i.destino_ciudad', 'pd.nombre as puerto_destino', 'i.cantidad_pallets', 'i.cantidad_cajas', 'i.booking', 'i.sps',
                'i.dus', 'i.planilla_sag', 'i.sello_sag',
            ])
            ->groupBy('embarque_id');

        $cargas = $filas->pluck('carga_id')->filter()->values()->all();
        $pallets = $cargas === [] ? collect() : $this->lector->tabla('carga_folios')
            ->whereIn('carga_id', $cargas)
            ->whereNotIn('estado', self::FOLIOS_FUERA)
            ->groupBy('carga_id')
            ->selectRaw('carga_id, count(*) as folios, sum(case when estado = ? then 1 else 0 end) as despachados', ['despachado'])
            ->get()
            ->keyBy('carga_id');

        return $filas->map(function (object $fila) use ($instructivos, $pallets): array {
            $carga = $fila->carga_id === null ? null : $pallets->get($fila->carga_id);

            return [
                'id' => (string) $fila->id,
                'codigo' => (string) $fila->codigo,
                'temporada_id' => (string) $fila->temporada_id,
                'temporada_nombre' => (string) $fila->temporada_nombre,
                'cliente_id' => (string) $fila->cliente_id,
                'cliente_nombre' => (string) $fila->cliente_nombre,
                'fecha_programada' => CarbonImmutable::parse($fila->fecha_programada)->toDateString(),
                'hora_programada' => substr((string) $fila->hora_programada, 0, 5),
                'modalidad' => (string) $fila->modalidad,
                'estado' => (string) $fila->estado,
                'nave_vuelo' => $fila->nave_vuelo,
                'transportista' => $fila->transportista,
                'puerto_embarque' => $fila->puerto_embarque_catalogo ?? $fila->puerto_embarque,
                'contenedor' => $fila->contenedor,
                'sello' => $fila->sello,
                'patentes' => implode(' / ', array_filter([$fila->patente_camion, $fila->patente_trasera])) ?: null,
                'documentos_operacion' => $fila->documentos,
                'observacion' => $fila->observacion,
                'carga' => $fila->carga_id === null ? null : [
                    'id' => (string) $fila->carga_id,
                    'codigo' => (string) $fila->carga_codigo,
                    'estado' => (string) $fila->carga_estado,
                    'salio' => in_array($fila->carga_estado, self::CARGA_SALIDA, true),
                    'cerrada_at' => $fila->carga_cerrada_at === null ? null : CarbonImmutable::parse($fila->carga_cerrada_at, 'UTC')->toIso8601String(),
                    'folios' => (int) ($carga->folios ?? 0),
                    'despachados' => (int) ($carga->despachados ?? 0),
                ],
                'instructivos' => ($instructivos->get($fila->id) ?? collect())->map(fn (object $instructivo): array => [
                    'orden' => (int) $instructivo->orden,
                    'numero' => $instructivo->numero_externo,
                    'recibidor' => $instructivo->recibidor,
                    'pais_iso2' => $instructivo->iso_alpha2,
                    'pais' => $instructivo->nombre_es ?? $instructivo->destino_pais,
                    'ciudad' => $instructivo->puerto_destino ?? $instructivo->destino_ciudad,
                    'pallets' => $instructivo->cantidad_pallets === null ? null : (int) $instructivo->cantidad_pallets,
                    'cajas' => $instructivo->cantidad_cajas === null ? null : (int) $instructivo->cantidad_cajas,
                    'booking' => $instructivo->booking,
                    'sps' => $instructivo->sps,
                    'dus' => $instructivo->dus,
                    'planilla_sag' => $instructivo->planilla_sag,
                    'sello_sag' => $instructivo->sello_sag,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Kilos netos por caja de cada línea, según el envase de su artículo de validación. Devuelve
     * una función para resolver línea por línea con los catálogos ya cargados.
     *
     * @param  Collection<int, object>  $folios
     * @return callable(object, array<string, mixed>, array<string, mixed>): ?string
     */
    private function kilosPorCaja(Collection $folios): callable
    {
        $envases = [];
        $articulos = [];
        $combinaciones = [];
        foreach ($folios as $folio) {
            $datos = json_decode((string) $folio->datos_externos, true) ?: [];
            if (isset($datos['combinacion_validacion_id'])) {
                $combinaciones[] = (string) $datos['combinacion_validacion_id'];
            }
            foreach (array_filter($datos['composicion'] ?? [], 'is_array') as $linea) {
                if (isset($linea['envase_validacion_id'])) {
                    $envases[] = (string) $linea['envase_validacion_id'];
                }
                if (isset($linea['articulo_validacion_id'])) {
                    $articulos[] = (string) $linea['articulo_validacion_id'];
                }
                if (isset($linea['combinacion_validacion_id'])) {
                    $combinaciones[] = (string) $linea['combinacion_validacion_id'];
                }
            }
        }

        $articuloDeCombinacion = $combinaciones === [] ? collect() : $this->lector->tabla('combinaciones_validacion')
            ->whereIn('id', array_values(array_unique($combinaciones)))
            ->pluck('articulo_validacion_id', 'id');
        $envaseDeArticulo = $this->lector->tabla('articulos_validacion')
            ->whereIn('id', array_values(array_unique([...$articulos, ...$articuloDeCombinacion->values()->all()])))
            ->pluck('envase_validacion_id', 'id');
        $kilos = $this->lector->tabla('envases_validacion')
            ->whereIn('id', array_values(array_unique([...$envases, ...$envaseDeArticulo->filter()->values()->all()])))
            ->whereNotNull('kilos_netos_por_caja')
            ->pluck('kilos_netos_por_caja', 'id');

        return function (object $folio, array $datos, array $linea) use ($articuloDeCombinacion, $envaseDeArticulo, $kilos): ?string {
            $envase = $linea['envase_validacion_id'] ?? null;
            if ($envase === null) {
                $articulo = $linea['articulo_validacion_id']
                    ?? $articuloDeCombinacion->get($linea['combinacion_validacion_id'] ?? $datos['combinacion_validacion_id'] ?? '');
                $envase = $articulo === null ? null : $envaseDeArticulo->get($articulo);
            }
            $valor = $envase === null ? null : $kilos->get($envase);

            return $valor === null ? null : rtrim(rtrim(number_format((float) $valor, 4, '.', ''), '0'), '.');
        };
    }

    private function texto(mixed $valor): ?string
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        return $texto === '' ? null : $texto;
    }

    private function fecha(mixed $valor): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
