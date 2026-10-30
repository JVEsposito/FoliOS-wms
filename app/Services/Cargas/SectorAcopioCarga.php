<?php

namespace App\Services\Cargas;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoPosicion;
use App\Enums\ModoBandaOperacional;
use App\Enums\UsoBandaOperacional;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\CargaFolio;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\ReservaTareaMovimiento;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use Illuminate\Support\Collection;

/**
 * Elige dónde crece el acopio de una carga antes de mandar pallets a la cámara. Completa
 * primero las bandas puras parcialmente ocupadas y después abre bandas libres cercanas.
 * Los grupos históricos mezclados conservan la búsqueda de un sector con capacidad.
 *
 * Un sector nuevo se expresa con un ancla virtual en el fondo de una banda (posición 0): la
 * regla de vecindad que ya usan la tablet y el servidor la entiende sin cambios, porque P01
 * de esa banda y de sus vecinas quedan como únicos destinos válidos del primer pallet.
 *
 * Las bandas se llenan desde el fondo (P01): una posición solo recibe un pallet si no hay
 * otro más adelante en la banda, para no dejar huecos inaccesibles detrás.
 */
final class SectorAcopioCarga
{
    /** @var array<string, array<string, mixed>> Mapa libre por cámara durante una lectura. */
    private array $mapas = [];

    /** @var array<string, string|null> Cámara estimada por carga durante una lectura. */
    private array $camaras = [];

    /** La próxima elección vuelve a leer la cámara: cada lectura parte del estado vigente. */
    public function olvidar(): void
    {
        $this->mapas = [];
        $this->camaras = [];
    }

    /** El resolvedor ya bloqueó estas cámaras; no mezclar su realidad con un snapshot anterior. */
    public function desdeCamarasActuales(Collection $camaras): void
    {
        foreach ($camaras as $camara) {
            $posiciones = $camara->posiciones->filter(fn ($p) => $p->banda <= $camara->cantidad_bandas
                && $p->posicion <= $camara->posiciones_por_banda && $p->nivel <= $camara->cantidad_niveles)
                ->map(function (Posicion $p): Posicion {
                    $copia = clone $p;
                    $folio = $p->ubicacionesActuales->first()?->folio;
                    $reserva = $p->reservaTareaActiva;

                    return $copia->forceFill(['ocupada_fisica' => $p->ubicacionesActuales->isNotEmpty(), 'reservada' => $reserva !== null,
                        'reservada_sag' => $p->reservaPreparacionSagActiva !== null,
                        'carga_ubicada_id' => $folio?->asignacionCargaActual?->carga_id,
                        'carga_entrante_id' => $reserva?->tareaMovimiento?->folio?->asignacionCargaActual?->carga_id]);
                })->sortBy(fn ($p) => [$p->nivel, $p->banda, $p->posicion])->values();
            $protegidas = $camara->proteccionesBandas->mapWithKeys(
                fn ($r) => [$r->nivel.':'.$r->banda => $r->maniobra_operacional_id],
            );
            $this->mapas[$camara->id] = $this->construirMapa($posiciones, $camara->bandasOperacionales->keyBy('numero'), $protegidas);
        }
    }

    /**
     * @param  list<array{banda: int, posicion: int, nivel: int}>  $puntosCarga  pallets de la carga ubicados o con destino reservado en la cámara, sin el que se está ubicando
     * @param  int  $pendientes  pallets de la carga que todavía deben quedar en el acopio (incluye los ya agrupados)
     * @param  list<string>  $posicionesPropias  destinos reservados por la tarea que se evalúa: cuentan como libres
     * @return array{puntos: list<array{banda: int, posicion: int, nivel: int}>, sector: array<string, mixed>}
     */
    public function elegir(string $camaraId, string $cargaId, array $puntosCarga, int $pendientes, array $posicionesPropias = [], ?string $maniobraId = null): array
    {
        $mapa = $this->mapa($camaraId, $maniobraId);
        $propias = array_flip($posicionesPropias);
        $libre = fn (array $celda): bool => $celda['activa'] && $celda['banda_util']
            && (! $celda['ocupada'] || isset($propias[$celda['id']]));
        $componentes = $this->componentes($puntosCarga);
        $prioritaria = $this->bandaEnLlenado($mapa, $puntosCarga, $cargaId, $propias);
        if ($prioritaria !== null) {
            $puntosBanda = array_values(array_filter($puntosCarga, fn ($p) => $p['banda'] === $prioritaria));
            $resultado = $this->resultado('grupo', $puntosBanda, max(1, $pendientes - count($puntosBanda)));
            $resultado['sector']['banda_prioritaria'] = $prioritaria;

            return $resultado;
        }

        $evaluados = [];
        foreach ($componentes as $componente) {
            $requeridas = max(1, $pendientes - count($componente));
            $capacidad = $this->capacidadCrecimiento($mapa, $componente, $requeridas, $libre);
            if ($capacidad >= $requeridas) {
                return $this->resultado('grupo', $componente, $requeridas);
            }
            $evaluados[] = ['puntos' => $componente, 'capacidad' => $capacidad, 'requeridas' => $requeridas];
        }

        $requeridas = max(1, $pendientes);
        $referencia = $componentes[0] ?? [];
        $tramos = $this->tramosLibres($mapa, $cargaId, $libre);
        $cercanos = $this->ordenarPorCercania($tramos, $referencia);
        foreach ($cercanos as $tramo) {
            if ($tramo['capacidad'] >= $requeridas) {
                return $this->sectorNuevo($tramo, $referencia, $requeridas);
            }
        }

        // Ningún sector recibe la carga completa: se usa el que más reciba, el grupo antes que
        // un sector nuevo si empatan.
        $mejorGrupo = collect($evaluados)->sortByDesc('capacidad')->first();
        $mejorTramo = collect($cercanos)->sortByDesc('capacidad')->first();
        if ($mejorGrupo && $mejorGrupo['capacidad'] > 0 && $mejorGrupo['capacidad'] >= ($mejorTramo['capacidad'] ?? 0)) {
            return $this->resultado('grupo', $mejorGrupo['puntos'], $mejorGrupo['requeridas']);
        }
        if ($mejorTramo && $mejorTramo['capacidad'] > 0) {
            return $this->sectorNuevo($mejorTramo, $referencia, $requeridas);
        }

        // Ni el grupo crece ni hay bandas libres: el pallet no se queda en el túnel por eso.
        // Va a cualquier posición válida de la cámara y la concentración lo junta después.
        return $this->resultado('libre', [], $requeridas);
    }

    /**
     * Una protección temporal no convierte una banda parcialmente cargada en llena.
     * Se cuentan también entradas comprometidas, pero nunca se inventa soporte físico.
     * El grupo llena todos los niveles utilizables de su banda antes de abrir otra.
     */
    private function bandaEnLlenado(array $mapa, array $puntos, string $cargaId, array $propias): ?int
    {
        $conteo = array_count_values(array_column($puntos, 'banda'));
        $celdasPorBanda = [];
        foreach ($mapa['bandas'] as $banda) {
            foreach ($banda['celdas'] as $celda) {
                $celdasPorBanda[$celda['banda']][] = $celda;
            }
        }
        $candidatas = [];
        foreach ($conteo as $numero => $cantidad) {
            $celdas = $celdasPorBanda[$numero] ?? [];
            // No ampliar una mezcla histórica si puede abrirse una banda de la carga.
            if (collect($celdas)->contains(fn ($c) => $c['ocupada'] && ! isset($propias[$c['id']]) && $c['carga_id'] !== $cargaId)) {
                continue;
            }
            foreach ($mapa['bandas'] as $banda) {
                if ($banda['numero'] !== $numero) {
                    continue;
                }
                $libre = fn ($c) => $c['activa'] && $c['banda_configurada'] && (! $c['ocupada'] || isset($propias[$c['id']]));
                $frente = 0;
                foreach ($banda['celdas'] as $celda) {
                    if ($celda['ocupada'] && ! isset($propias[$celda['id']])) {
                        $frente = max($frente, $celda['posicion']);
                    }
                }
                $siguiente = $this->siguienteLibre($banda, $frente, $libre);
                if ($siguiente && ($siguiente['nivel'] === 1 || collect($celdas)->contains(fn ($c) => $c['posicion'] === $siguiente['posicion'] && $c['nivel'] === $siguiente['nivel'] - 1 && $c['ocupada_fisica']))) {
                    $candidatas[] = ['banda' => (int) $numero, 'cantidad' => $cantidad];
                    break;
                }
            }
        }
        usort($candidatas, fn ($a, $b) => [-$a['cantidad'], $a['banda']] <=> [-$b['cantidad'], $b['banda']]);

        return $candidatas[0]['banda'] ?? null;
    }

    /**
     * Cámara para una carga que todavía no tiene pallets ni cámara objetivo: la primera, en este
     * orden, con bandas libres para toda la carga. Primero la cámara preferente de despacho,
     * luego la que guarda más pallets del mismo cliente y al final el código. Sin espacio para
     * la carga completa en ninguna, se deja la elección a la tablet, como antes.
     *
     * @param  list<string>  $exportadoras
     */
    public function camaraParaCarga(string $cargaId, array $exportadoras, int $pendientes, ?string $maniobraId = null): ?string
    {
        $clave = $cargaId.':'.$maniobraId;
        if (array_key_exists($clave, $this->camaras)) {
            return $this->camaras[$clave];
        }

        return $this->camaras[$clave] = $this->estimarCamara($cargaId, $exportadoras, $pendientes, $maniobraId);
    }

    /** @param  list<string>  $exportadoras */
    private function estimarCamara(string $cargaId, array $exportadoras, int $pendientes, ?string $maniobraId): ?string
    {
        $despliegue = app(ServicioDesplieguePlanificador::class);
        $dirigidas = $despliegue->idsCamarasDirigidas();
        $camaras = Camara::query()
            ->where('contenido', ContenidoCamara::Productos->value)
            ->where('estado', EstadoCamara::Activa->value)
            ->when($dirigidas !== null, fn ($consulta) => $consulta->whereIn('id', $dirigidas))
            ->withCount(['ubicacionesActuales as afines' => fn ($consulta) => $consulta
                ->whereHas('folio', fn ($folio) => $folio->whereIn('exportadora', $exportadoras === [] ? [''] : $exportadoras))])
            ->get(['id', 'codigo'])
            ->sortBy(fn (Camara $camara): string => sprintf(
                '%d:%08d:%s',
                $despliegue->camaraPreferenteDespacho($camara->id, $camara->codigo) ? 0 : 1,
                99_999_999 - (int) $camara->afines,
                $camara->codigo,
            ));
        $todo = fn (array $celda): bool => $celda['activa'] && $celda['banda_util'] && ! $celda['ocupada'];
        foreach ($camaras as $camara) {
            foreach ($this->tramosLibres($this->mapa($camara->id, $maniobraId), $cargaId, $todo) as $tramo) {
                if ($tramo['capacidad'] >= max(1, $pendientes)) {
                    return $camara->id;
                }
            }
        }

        return null;
    }

    /**
     * Grupos conectados de la carga, del más grande al más chico; entre iguales, el
     * de menor banda y profundidad (el mismo desempate del grupo principal).
     *
     * @param  list<array{banda: int, posicion: int, nivel: int}>  $puntos
     * @return list<list<array{banda: int, posicion: int, nivel: int}>>
     */
    private function componentes(array $puntos): array
    {
        $pendientes = array_values($puntos);
        $componentes = [];
        while ($pendientes !== []) {
            $cola = [array_shift($pendientes)];
            $componente = [];
            while ($cola !== []) {
                $actual = array_shift($cola);
                $componente[] = $actual;
                foreach ($pendientes as $indice => $candidato) {
                    if (self::vecinos($actual, $candidato)) {
                        $cola[] = $candidato;
                        unset($pendientes[$indice]);
                    }
                }
                $pendientes = array_values($pendientes);
            }
            usort($componente, fn (array $a, array $b): int => [$a['nivel'], $a['banda'], $a['posicion']] <=> [$b['nivel'], $b['banda'], $b['posicion']]);
            $componentes[] = $componente;
        }
        usort($componentes, fn (array $a, array $b): int => count($b) <=> count($a)
            ?: [$a[0]['nivel'], $a[0]['banda'], $a[0]['posicion']] <=> [$b[0]['nivel'], $b[0]['banda'], $b[0]['posicion']]);

        return $componentes;
    }

    /**
     * Cuántos pallets más puede recibir el grupo creciendo por posiciones vecinas, cada una la
     * siguiente libre de su banda (se simula pallet a pallet, hasta lo requerido).
     *
     * @param  array<string, mixed>  $mapa
     * @param  list<array{banda: int, posicion: int, nivel: int}>  $grupo
     * @param  callable(array<string, mixed>): bool  $libre
     */
    private function capacidadCrecimiento(array $mapa, array $grupo, int $limite, callable $libre): int
    {
        $region = $grupo;
        $frentes = $this->frentes($mapa, $libre);
        $colocados = 0;
        while ($colocados < $limite) {
            $mejor = null;
            foreach ($mapa['bandas'] as $clave => $banda) {
                $siguiente = $this->siguienteLibre($banda, $frentes[$clave], $libre);
                if ($siguiente === null) {
                    continue;
                }
                $contactos = count(array_filter($region, fn (array $punto): bool => self::vecinos($punto, $siguiente)));
                if ($contactos === 0) {
                    continue;
                }
                $orden = [-$contactos, $siguiente['posicion'], $siguiente['nivel'], $siguiente['banda']];
                if ($mejor === null || $orden < $mejor['orden']) {
                    $mejor = ['clave' => $clave, 'celda' => $siguiente, 'orden' => $orden];
                }
            }
            if ($mejor === null) {
                break;
            }
            $region[] = ['banda' => $mejor['celda']['banda'], 'posicion' => $mejor['celda']['posicion'], 'nivel' => $mejor['celda']['nivel']];
            $frentes[$mejor['clave']] = $mejor['celda']['posicion'];
            $colocados++;
        }

        return $colocados;
    }

    /**
     * Profundidad del pallet más adelantado de cada banda: detrás de él no se puede dejar otro.
     *
     * @param  array<string, mixed>  $mapa
     * @param  callable(array<string, mixed>): bool  $libre
     * @return array<string, int>
     */
    private function frentes(array $mapa, callable $libre): array
    {
        $frentes = [];
        foreach ($mapa['bandas'] as $clave => $banda) {
            $frente = 0;
            foreach ($banda['celdas'] as $celda) {
                if ($celda['activa'] && ! $libre($celda)) {
                    $frente = max($frente, $celda['posicion']);
                }
            }
            $frentes[$clave] = $frente;
        }

        return $frentes;
    }

    /**
     * @param  array<string, mixed>  $banda
     * @param  callable(array<string, mixed>): bool  $libre
     * @return array<string, mixed>|null
     */
    private function siguienteLibre(array $banda, int $frente, callable $libre): ?array
    {
        foreach ($banda['celdas'] as $celda) {
            if ($celda['posicion'] > $frente && $libre($celda)) {
                return $celda;
            }
        }

        return null;
    }

    /**
     * Tramos de bandas completamente libres, consecutivas y del mismo nivel. Se dejan fuera las
     * bandas pegadas a pallets de otra carga, para que dos acopios no terminen mezclados.
     *
     * @param  array<string, mixed>  $mapa
     * @param  callable(array<string, mixed>): bool  $libre
     * @return list<array{nivel: int, desde: int, hasta: int, capacidad: int}>
     */
    private function tramosLibres(array $mapa, string $cargaId, callable $libre): array
    {
        $porNivel = [];
        foreach ($mapa['bandas'] as $banda) {
            $activas = array_filter($banda['celdas'], fn (array $celda): bool => $celda['activa']);
            $completa = $activas !== [] && count(array_filter($activas, $libre)) === count($activas);
            $porNivel[$banda['nivel']][$banda['numero']] = [
                'libre' => $completa,
                'capacidad' => count($activas),
                'otra_carga' => collect($banda['celdas'])->contains(fn (array $celda): bool => $celda['carga_id'] !== null && $celda['carga_id'] !== $cargaId),
            ];
        }

        $tramos = [];
        foreach ($porNivel as $nivel => $bandas) {
            ksort($bandas);
            $actual = null;
            foreach ($bandas as $numero => $banda) {
                $vecinaConOtra = ($bandas[$numero - 1]['otra_carga'] ?? false) || ($bandas[$numero + 1]['otra_carga'] ?? false);
                $util = $banda['libre'] && ! $vecinaConOtra;
                if ($util && $actual !== null && $actual['hasta'] === $numero - 1) {
                    $actual['hasta'] = $numero;
                    $actual['capacidad'] += $banda['capacidad'];

                    continue;
                }
                if ($actual !== null) {
                    $tramos[] = $actual;
                    $actual = null;
                }
                if ($util) {
                    $actual = ['nivel' => (int) $nivel, 'desde' => $numero, 'hasta' => $numero, 'capacidad' => $banda['capacidad']];
                }
            }
            if ($actual !== null) {
                $tramos[] = $actual;
            }
        }

        return $tramos;
    }

    /**
     * Lo más cerca primero: del grupo actual de la carga, o desde la primera banda si la carga
     * todavía no tiene pallets en la cámara. Se prefiere el nivel del grupo y luego el más bajo.
     *
     * @param  list<array{nivel: int, desde: int, hasta: int, capacidad: int}>  $tramos
     * @param  list<array{banda: int, posicion: int, nivel: int}>  $referencia
     * @return list<array{nivel: int, desde: int, hasta: int, capacidad: int}>
     */
    private function ordenarPorCercania(array $tramos, array $referencia): array
    {
        $nivel = $referencia[0]['nivel'] ?? null;
        $bandas = array_column($referencia, 'banda');
        usort($tramos, function (array $a, array $b) use ($nivel, $bandas): int {
            return [$this->distancia($a, $nivel, $bandas), $a['nivel'], $a['desde']]
                <=> [$this->distancia($b, $nivel, $bandas), $b['nivel'], $b['desde']];
        });

        return $tramos;
    }

    /**
     * @param  array{nivel: int, desde: int, hasta: int, capacidad: int}  $tramo
     * @param  list<int>  $bandas
     */
    private function distancia(array $tramo, ?int $nivel, array $bandas): int
    {
        if ($bandas === []) {
            return 0;
        }
        $porNivel = $tramo['nivel'] === $nivel ? 0 : 1_000;

        return $porNivel + min(array_map(fn (int $banda): int => match (true) {
            $banda < $tramo['desde'] => $tramo['desde'] - $banda,
            $banda > $tramo['hasta'] => $banda - $tramo['hasta'],
            default => 0,
        }, $bandas));
    }

    /**
     * Ancla en el fondo de una banda del tramo. La tablet desempata por la banda menor entre
     * las vecinas del ancla, así que el ancla va una banda hacia dentro del tramo para que el
     * primer pallet caiga en el extremo más cercano al grupo y no en una banda ajena.
     *
     * @param  array{nivel: int, desde: int, hasta: int, capacidad: int}  $tramo
     * @param  list<array{banda: int, posicion: int, nivel: int}>  $referencia
     * @return array{puntos: list<array{banda: int, posicion: int, nivel: int}>, sector: array<string, mixed>}
     */
    private function sectorNuevo(array $tramo, array $referencia, int $requeridas): array
    {
        $bandaReferencia = $referencia === [] ? null : min(array_column($referencia, 'banda'));
        $haciaAbajo = $bandaReferencia !== null && $bandaReferencia > $tramo['hasta'];
        $banda = match (true) {
            $tramo['hasta'] === $tramo['desde'] => $tramo['desde'],
            $haciaAbajo => $tramo['hasta'],
            default => $tramo['desde'] + 1,
        };
        $ancla = ['banda' => $banda, 'posicion' => 0, 'nivel' => $tramo['nivel']];

        return [
            'puntos' => [$ancla],
            'sector' => [
                'tipo' => 'nuevo',
                'nivel' => $tramo['nivel'],
                'banda_desde' => $tramo['desde'],
                'banda_hasta' => $tramo['hasta'],
                'requeridas' => $requeridas,
                'ancla' => $ancla,
            ],
        ];
    }

    /**
     * La capacidad estimada no se devuelve: cambia con cada pallet de la cámara y haría
     * cambiar el acopio guardado en las tareas sin que cambie dónde deben ir.
     *
     * @param  list<array{banda: int, posicion: int, nivel: int}>  $puntos
     * @return array{puntos: list<array{banda: int, posicion: int, nivel: int}>, sector: array<string, mixed>}
     */
    private function resultado(string $tipo, array $puntos, int $requeridas): array
    {
        return [
            'puntos' => array_values($puntos),
            'sector' => [
                'tipo' => $tipo,
                'nivel' => $puntos[0]['nivel'] ?? null,
                'banda_desde' => $puntos === [] ? null : min(array_column($puntos, 'banda')),
                'banda_hasta' => $puntos === [] ? null : max(array_column($puntos, 'banda')),
                'requeridas' => $requeridas,
                'ancla' => null,
            ],
        ];
    }

    /**
     * La posición que hoy recibe un pallet en cada banda de la cámara: la más al fondo, delante
     * del último pallet ocupado o comprometido. Estima si existe un destino; la reserva vuelve
     * a validar la posición elegida.
     *
     * @return list<array{id: string, banda: int, posicion: int, nivel: int}>
     */
    public function siguientesLibres(string $camaraId): array
    {
        $mapa = $this->mapa($camaraId);
        $libre = fn (array $celda): bool => $celda['activa'] && $celda['banda_util'] && ! $celda['ocupada'];
        $frentes = $this->frentes($mapa, $libre);
        $salida = [];
        foreach ($mapa['bandas'] as $clave => $banda) {
            $celda = $this->siguienteLibre($banda, $frentes[$clave], $libre);
            if ($celda !== null) {
                $salida[] = ['id' => $celda['id'], 'banda' => $celda['banda'], 'posicion' => $celda['posicion'], 'nivel' => $celda['nivel']];
            }
        }

        return $salida;
    }

    /** No permitir que una propuesta de tablet salte un hueco de la banda prioritaria. */
    public function esSiguienteLibre(Posicion $posicion, ?string $maniobraId, array $propias): bool
    {
        $mapa = $this->mapa($posicion->camara_id, $maniobraId);
        $banda = $mapa['bandas'][$posicion->nivel.':'.$posicion->banda] ?? null;
        if (! $banda) {
            return false;
        }
        $propias = array_flip($propias);
        $libre = fn ($c) => $c['activa'] && $c['banda_util'] && ! $c['ocupada_fisica']
            && (! $c['ocupada'] || isset($propias[$c['id']]));
        $frente = $this->frentes(['bandas' => [$banda]], $libre)[0];
        $siguiente = $this->siguienteLibre($banda, $frente, $libre);
        if (($siguiente['id'] ?? null) !== $posicion->id) {
            return false;
        }

        return $posicion->nivel === 1 || collect($mapa['bandas'][($posicion->nivel - 1).':'.$posicion->banda]['celdas'] ?? [])
            ->contains(fn ($c) => $c['posicion'] === $posicion->posicion && $c['ocupada_fisica']);
    }

    /** La cámara tiene posiciones registradas: sin plano no hay nada que estimar. */
    public function tienePosiciones(string $camaraId): bool
    {
        return $this->mapa($camaraId)['bandas'] !== [];
    }

    /** @return array<string, mixed> */
    private function mapa(string $camaraId, ?string $maniobraId = null): array
    {
        $mapa = $this->mapas[$camaraId] ??= $this->leerMapa($camaraId);
        if ($maniobraId !== null) {
            // La geometría se lee una sola vez por cámara. Cada maniobra proyecta
            // su propia protección sin alterar la lectura compartida del arbitraje.
            foreach ($mapa['bandas'] as &$banda) {
                foreach ($banda['celdas'] as &$celda) {
                    if ($celda['proteccion_maniobra_id'] === $maniobraId) {
                        $celda['banda_util'] = $celda['banda_configurada'];
                    }
                }
                unset($celda);
            }
            unset($banda);
        }

        return $mapa;
    }

    /**
     * Estado de cada posición de la cámara para estimar capacidad. Es una lectura sin
     * bloqueos: la validación del destino elegido sigue ocurriendo al reservarlo.
     *
     * @return array<string, mixed>
     */
    private function leerMapa(string $camaraId): array
    {
        // Una sola lectura por cámara: el estado de cada posición viene en subconsultas.
        $posiciones = Posicion::query()
            ->where('camara_id', $camaraId)
            ->whereHas('camara', fn ($q) => $q->whereColumn('posiciones.banda', '<=', 'camaras.cantidad_bandas')
                ->whereColumn('posiciones.posicion', '<=', 'camaras.posiciones_por_banda')->whereColumn('posiciones.nivel', '<=', 'camaras.cantidad_niveles'))
            ->select(['id', 'banda', 'posicion', 'nivel', 'estado'])
            ->withExists([
                'ubicacionActual as ocupada_fisica',
                'reservaTareaActiva as reservada' => fn ($consulta) => $consulta->conCompromisoVigente(),
                'reservaPreparacionSagActiva as reservada_sag',
            ])
            ->addSelect(['carga_ubicada_id' => CargaFolio::query()
                ->select('carga_folios.carga_id')
                ->join('ubicaciones_actuales', 'ubicaciones_actuales.folio_id', '=', 'carga_folios.folio_id')
                ->whereColumn('ubicaciones_actuales.posicion_id', 'posiciones.id')
                ->whereHas('reservaActiva')
                ->limit(1),
                'carga_entrante_id' => CargaFolio::query()->select('carga_folios.carga_id')
                    ->join('tareas_movimiento as entrantes', 'entrantes.folio_id', '=', 'carga_folios.folio_id')
                    ->join('reservas_tareas_movimiento as reservas', 'reservas.tarea_movimiento_id', '=', 'entrantes.id')
                    ->whereColumn('reservas.bloqueo_posicion_id', 'posiciones.id')
                    ->whereIn('reservas.id', ReservaTareaMovimiento::query()->conCompromisoVigente()->select('id'))
                    ->whereHas('reservaActiva')->limit(1)])
            ->orderBy('nivel')->orderBy('banda')->orderBy('posicion')
            ->get();
        $bandas = BandaOperacional::query()->where('camara_id', $camaraId)->get(['numero', 'modo', 'usos_permitidos'])->keyBy('numero');
        $protegidas = ReservaBandaManiobra::query()->where('camara_id', $camaraId)
            ->whereNotNull('clave_bloqueo')->whereNull('liberada_at')
            ->get(['banda', 'nivel', 'maniobra_operacional_id'])
            ->mapWithKeys(fn (ReservaBandaManiobra $reserva): array => ["{$reserva->nivel}:{$reserva->banda}" => $reserva->maniobra_operacional_id]);

        return $this->construirMapa($posiciones, $bandas, $protegidas);
    }

    private function construirMapa(Collection $posiciones, Collection $bandas, Collection $protegidas): array
    {
        $mapa = ['bandas' => []];
        foreach ($posiciones as $posicion) {
            $nivel = (int) $posicion->nivel;
            $numero = (int) $posicion->banda;
            $clave = "{$nivel}:{$numero}";
            $banda = $bandas->get($numero);
            // Sin catálogo de bandas se conserva el flujo histórico, como al reservar destino.
            $configurada = $banda === null || ($banda->modo === ModoBandaOperacional::Operativa
                && in_array(UsoBandaOperacional::TransitoProductoTerminado->value, $banda->usos_permitidos ?? [], true));
            $util = ! isset($protegidas[$clave]) && $configurada;
            $mapa['bandas'][$clave] ??= ['nivel' => $nivel, 'numero' => $numero, 'celdas' => []];
            $mapa['bandas'][$clave]['celdas'][] = [
                'id' => $posicion->id,
                'banda' => $numero,
                'posicion' => (int) $posicion->posicion,
                'nivel' => $nivel,
                'activa' => $posicion->estado === EstadoPosicion::Activa,
                'banda_util' => $util,
                'banda_configurada' => $configurada,
                'proteccion_maniobra_id' => $protegidas[$clave] ?? null,
                'ocupada_fisica' => (bool) $posicion->ocupada_fisica,
                'ocupada' => (bool) $posicion->ocupada_fisica || (bool) $posicion->reservada || (bool) $posicion->reservada_sag,
                'carga_id' => $posicion->carga_ubicada_id ?? $posicion->carga_entrante_id,
            ];
        }

        return $mapa;
    }

    /**
     * @param  array{banda: int, posicion: int, nivel: int}  $a
     * @param  array{banda: int, posicion: int, nivel: int}  $b
     */
    public static function vecinos(array $a, array $b): bool
    {
        if ((int) $a['nivel'] !== (int) $b['nivel']) {
            return abs((int) $a['nivel'] - (int) $b['nivel']) === 1
                && (int) $a['banda'] === (int) $b['banda'] && (int) $a['posicion'] === (int) $b['posicion'];
        }
        $bandas = abs((int) $a['banda'] - (int) $b['banda']);
        $posiciones = abs((int) $a['posicion'] - (int) $b['posicion']);

        return ($bandas === 0 && $posiciones === 1) || ($bandas === 1 && $posiciones <= 1);
    }
}
