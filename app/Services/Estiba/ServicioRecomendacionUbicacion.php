<?php

namespace App\Services\Estiba;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoPosicion;
use App\Enums\ModoBandaOperacional;
use App\Enums\NivelAfinidadUbicacion;
use App\Enums\TipoBulto;
use App\Enums\UsoBandaOperacional;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Camaras\CalculadorAfinidadBanda;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use Illuminate\Support\Collection;

class ServicioRecomendacionUbicacion
{
    private const MAXIMO_RESULTADOS = 5;

    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly CalculadorAfinidadBanda $afinidad,
        private readonly ServicioDesplieguePlanificador $despliegue,
    ) {}

    /** @return array<string, mixed> */
    public function recomendar(
        Folio $folio,
        User $usuario,
        ?string $camaraConsultadaId = null,
    ): array {
        if ($folio->tipo_bulto !== TipoBulto::Pallet) {
            return [
                'aplica' => false,
                'disponible' => false,
                'uso' => null,
                'motivo' => 'La recomendación automática de este incremento aplica solo a pallets completos.',
                'criterio' => $this->criterio(),
                'mejor' => null,
                'alternativas' => [],
            ];
        }

        if ($folio->asignacionCargaActual()->exists()) {
            return [
                'aplica' => false,
                'disponible' => false,
                'uso' => null,
                'motivo' => 'El pallet posee una asignación de carga activa; su destino se resolverá con el planificador de separación y andén.',
                'criterio' => $this->criterio(),
                'mejor' => null,
                'alternativas' => [],
            ];
        }

        $camaras = Camara::query()
            ->where('estado', EstadoCamara::Activa->value)
            ->where('contenido', ContenidoCamara::Productos->value)
            ->with([
                'bandasOperacionales' => fn ($consulta) => $consulta
                    ->orderBy('numero'),
                'posiciones' => fn ($consulta) => $consulta
                    ->where('estado', EstadoPosicion::Activa->value)
                    ->with([
                        'ubicacionesActuales.folio:id,tipo_bulto,marca,exportadora,datos_externos,activo',
                        'reservaTareaActiva' => fn ($reservas) => $reservas
                            ->where('vence_at', '>', now()),
                        'reservaPreparacionSagActiva',
                    ])
                    ->orderBy('banda')
                    ->orderBy('posicion')
                    ->orderBy('nivel'),
            ])
            ->orderBy('codigo')
            ->get()
            ->filter(fn (Camara $camara): bool => $this->alcance->puedeVerCamara($usuario, $camara))
            ->values();
        $candidatos = $camaras
            ->flatMap(fn (Camara $camara): Collection => $this->candidatosCamara(
                $camara,
                $folio,
                $camaraConsultadaId,
            ))
            ->sort(function (array $izquierda, array $derecha): int {
                $preferida = fn (array $item): int => (int) $this->despliegue->camaraPreferenteDespacho(
                    $item['camara']['id'],
                    $item['camara']['codigo'],
                );

                return ($this->ordenAfinidad($izquierda['afinidad']['nivel'])
                    <=> $this->ordenAfinidad($derecha['afinidad']['nivel']))
                    ?: ($izquierda['afinidad']['mezclaria_clientes'] <=> $derecha['afinidad']['mezclaria_clientes'])
                    ?: ($preferida($derecha) <=> $preferida($izquierda))
                    ?: ($derecha['afinidad']['puntaje'] <=> $izquierda['afinidad']['puntaje'])
                    ?: strcmp($izquierda['camara']['codigo'], $derecha['camara']['codigo'])
                    ?: ($izquierda['banda']['numero'] <=> $derecha['banda']['numero'])
                    ?: ($izquierda['posicion']['posicion'] <=> $derecha['posicion']['posicion'])
                    ?: ($izquierda['posicion']['nivel'] <=> $derecha['posicion']['nivel']);
            })
            ->take(self::MAXIMO_RESULTADOS)
            ->values()
            ->map(function (array $candidato, int $indice): array {
                $candidato['orden'] = $indice + 1;

                return $candidato;
            });
        $mejor = $candidatos->first();

        return [
            'aplica' => true,
            'disponible' => $mejor !== null,
            'uso' => UsoBandaOperacional::TransitoProductoTerminado->value,
            'motivo' => $mejor
                ? $mejor['afinidad']['motivo']
                : 'No existen bandas operativas compatibles con capacidad disponible.',
            'criterio' => $this->criterio(),
            'mejor' => $mejor,
            'alternativas' => $candidatos->skip(1)->values()->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function candidatosCamara(
        Camara $camara,
        Folio $folio,
        ?string $camaraConsultadaId,
    ): Collection {
        $posicionesPorBanda = $camara->posiciones
            ->filter(fn (Posicion $posicion): bool => $posicion->banda <= $camara->cantidad_bandas
                && $posicion->posicion <= $camara->posiciones_por_banda
                && $posicion->nivel <= $camara->cantidad_niveles)
            ->groupBy('banda');

        return $camara->bandasOperacionales
            ->map(function (BandaOperacional $banda) use (
                $camara,
                $folio,
                $camaraConsultadaId,
                $posicionesPorBanda,
            ): ?array {
                if ($banda->modo !== ModoBandaOperacional::Operativa
                    || ! in_array(
                        UsoBandaOperacional::TransitoProductoTerminado->value,
                        $banda->usos_permitidos,
                        true,
                    )) {
                    return null;
                }

                /** @var Collection<int, Posicion> $posiciones */
                $posiciones = $posicionesPorBanda->get($banda->numero, collect());
                $folios = $posiciones
                    ->flatMap(fn (Posicion $posicion): Collection => $posicion->ubicacionesActuales
                        ->pluck('folio')
                        ->filter())
                    ->values();

                if ($folios->contains(fn (Folio $existente): bool => ! $existente->activo
                    || $existente->tipo_bulto !== TipoBulto::Pallet)) {
                    return null;
                }

                $libres = $posiciones
                    ->filter(fn (Posicion $posicion): bool => $posicion->ubicacionesActuales->isEmpty()
                        && $posicion->reservaTareaActiva === null
                        && $posicion->reservaPreparacionSagActiva === null)
                    ->values();
                $destino = $this->primeraPosicionViable($posiciones, $libres);

                if (! $destino) {
                    return null;
                }

                $evaluacion = $this->afinidad->evaluar($folio, $folios);

                return [
                    'camara' => [
                        'id' => $camara->id,
                        'codigo' => $camara->codigo,
                        'nombre' => $camara->nombre,
                        'version_plano' => $camara->version_plano,
                    ],
                    'banda' => [
                        'id' => $banda->id,
                        'numero' => $banda->numero,
                        'capacidad_disponible' => $libres->count(),
                        'version' => $banda->version,
                    ],
                    'posicion' => [
                        'id' => $destino->id,
                        'etiqueta' => $destino->etiqueta,
                        'banda' => $destino->banda,
                        'posicion' => $destino->posicion,
                        'nivel' => $destino->nivel,
                    ],
                    'afinidad' => [
                        'nivel' => $evaluacion['nivel']->value,
                        'puntaje' => $evaluacion['puntaje'],
                        'coincidencias' => $evaluacion['coincidencias'],
                        'mezclaria_clientes' => $evaluacion['mezclaria_clientes'],
                        'motivo' => $evaluacion['motivo'],
                    ],
                    'en_camara_consultada' => $camara->id === $camaraConsultadaId,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Elige el primer destino que respeta profundidad y soporte, evitando que
     * la propia recomendación requiera confirmar advertencias físicas.
     *
     * @param  Collection<int, Posicion>  $posiciones
     * @param  Collection<int, Posicion>  $libres
     */
    private function primeraPosicionViable(Collection $posiciones, Collection $libres): ?Posicion
    {
        return $libres
            ->filter(function (Posicion $candidata) use ($posiciones, $libres): bool {
                $hayLibreMasProfunda = $libres->contains(
                    fn (Posicion $posicion): bool => $posicion->nivel === $candidata->nivel
                        && $posicion->posicion < $candidata->posicion,
                );

                if ($hayLibreMasProfunda) {
                    return false;
                }

                if ($candidata->nivel === 1) {
                    return true;
                }

                $soporte = $posiciones->first(
                    fn (Posicion $posicion): bool => $posicion->posicion === $candidata->posicion
                        && $posicion->nivel === $candidata->nivel - 1,
                );

                return $soporte !== null && $soporte->ubicacionesActuales->isNotEmpty();
            })
            ->sortBy([
                ['posicion', 'asc'],
                ['nivel', 'asc'],
            ])
            ->first();
    }

    /** @return array<string, mixed> */
    private function criterio(): array
    {
        return [
            'jerarquia' => [
                'cliente',
                'marca_etiqueta',
                'formato_envase',
            ],
            'solo_pallets_completos' => true,
            'afinidad_dinamica' => true,
            'genera_movimiento' => false,
            'reserva_destino' => false,
            'excluye_destinos_reservados' => true,
            'camara_preferente_despacho' => config('planificador.camara_preferente_despacho'),
        ];
    }

    private function ordenAfinidad(string $nivel): int
    {
        return match ($nivel) {
            NivelAfinidadUbicacion::ClienteMarcaFormato->value => 0,
            NivelAfinidadUbicacion::ClienteMarca->value => 1,
            NivelAfinidadUbicacion::Cliente->value => 2,
            NivelAfinidadUbicacion::BandaLibre->value => 3,
            default => 4,
        };
    }
}
