<?php

namespace App\Services\Planificador;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\UsoBandaOperacional;
use App\Models\Camara;
use App\Models\ManiobraOperacional;
use App\Models\TareaMovimiento;
use App\Services\Cargas\SectorAcopioCarga;
use App\Services\Cargas\ServicioAcopioCarga;
use App\Services\Estiba\ServicioDestinosDespeje;
use Illuminate\Support\Collection;

/**
 * Estima, antes de ofrecer una maniobra, si su paso actual tiene hoy al menos un destino que
 * el servidor aceptaría y al que se puede llegar (las bandas se llenan desde el fondo). Una
 * maniobra sin destino posible no debe ocupar un cupo de la frontera: los camareros no pueden
 * ejecutarla y deja esperando a las que sí tienen destino.
 *
 * Solo declara «sin destino» lo que puede demostrar con el estado vigente. Un paso que no
 * sabe evaluar se considera posible, como hasta ahora, y la reserva sigue validando el
 * destino elegido.
 */
final class ServicioFactibilidadDestino
{
    private const DESPEJES = ['despeje_salida_directa', 'despeje_concentracion'];

    /** @var Collection<int, TareaMovimiento> */
    private Collection $pasos;

    /** @var array<int, string>|null */
    private ?array $camarasRollout = null;

    /** @var array<string, array<string, mixed>|null>|null */
    private ?array $acopios = null;

    /** @var Collection<string, Camara>|null */
    private ?Collection $camaras = null;

    /** @var array<string, list<array{id: string, banda: int, posicion: int, nivel: int}>> */
    private array $siguientes = [];

    public function __construct(
        private readonly SectorAcopioCarga $sector,
        private readonly ServicioAcopioCarga $acopio,
        private readonly ServicioDestinosDespeje $despejes,
        private readonly ServicioDestinosServidor $servidor,
    ) {
        $this->pasos = collect();
    }

    /**
     * Cada ciclo parte del estado vigente: se descartan los mapas y acopios leídos antes.
     *
     * @param  Collection<int, ManiobraOperacional>  $maniobras
     * @param  array<int, string>|null  $camarasRollout
     */
    public function preparar(Collection $maniobras, ?array $camarasRollout): void
    {
        $this->servidor->olvidar();
        $this->camarasRollout = $camarasRollout;
        $this->acopios = null;
        $this->camaras = null;
        $this->siguientes = [];
        $this->sector->olvidar();
        $this->pasos = $maniobras
            ->filter(fn (ManiobraOperacional $maniobra): bool => $maniobra->estado === EstadoManiobraOperacional::Pendiente)
            ->map(fn (ManiobraOperacional $maniobra): ?TareaMovimiento => $this->pasoActual($maniobra))
            ->filter()
            ->values();
    }

    /** @return array{evaluado: bool, posible: bool, codigo: string, detalle: string} */
    public static function noEvaluado(): array
    {
        return ['evaluado' => false, 'posible' => true, 'codigo' => 'no_evaluado', 'detalle' => ''];
    }

    /** @return array{evaluado: bool, posible: bool, codigo: string, detalle: string} */
    public function evaluar(ManiobraOperacional $maniobra): array
    {
        if (ServicioDestinosServidor::activo()) {
            return $this->servidor->evaluar($maniobra);
        }
        $paso = $this->pasoActual($maniobra);
        if (! $paso) {
            return $this->posible('sin_paso_actual');
        }
        if ($paso->posicion_destino_id) {
            return $this->posible('destino_definido');
        }
        if (in_array($paso->tipo_movimiento, [TipoMovimiento::Retiro, TipoMovimiento::Reversion], true)) {
            return $this->posible('sin_posicion_requerida');
        }

        $contexto = $paso->contexto ?? [];
        $uso = $contexto['uso_banda_destino'] ?? UsoBandaOperacional::TransitoProductoTerminado->value;
        if ($uso !== UsoBandaOperacional::TransitoProductoTerminado->value
            || $paso->tipo_paso_maniobra === TipoPasoManiobra::RetornoBanda) {
            return $this->posible('no_evaluable');
        }

        $tipoDecision = $contexto['tipo_decision'] ?? null;
        if (in_array($tipoDecision, self::DESPEJES, true)) {
            return $this->despeje($paso);
        }
        if ($tipoDecision === 'concentrar_carga') {
            return $this->concentracion($paso);
        }

        $plan = $paso->plan_operacional_id === $maniobra->plan_operacional_id
            ? $maniobra->planOperacional
            : $paso->planOperacional;
        if ($plan && $this->acopio->esRecepcion($plan, $paso->tipo_movimiento)) {
            $acopio = $this->acopios()[$paso->id] ?? null;
            if ($acopio) {
                return $this->acopioCarga($acopio);
            }
        }

        return $this->generico($paso);
    }

    /** @param  array<string, mixed>  $acopio */
    private function acopioCarga(array $acopio): array
    {
        if ($acopio['despacho_directo'] ?? false) {
            return $this->posible('despacho_directo');
        }
        $camaraId = $acopio['camara_objetivo_id'] ?? null;
        $carga = (string) ($acopio['carga_codigo'] ?? 'la carga');
        if (! $camaraId) {
            return $this->posible('no_evaluable');
        }
        $camara = $this->camaras()->get($camaraId);
        if (! $camara) {
            return $this->sinDestino(sprintf('La cámara de acopio de %s no recibe pallets dirigidos.', $carga));
        }
        if (! $this->sector->tienePosiciones($camaraId)) {
            return $this->posible('no_evaluable');
        }

        $puntos = $acopio['puntos'] ?? [];
        $siguientes = $this->siguientes($camaraId);
        $posible = $puntos === []
            ? $siguientes !== []
            : collect($siguientes)->contains(fn (array $celda): bool => collect($puntos)
                ->contains(fn (array $punto): bool => SectorAcopioCarga::vecinos($punto, $celda)));

        return $posible
            ? $this->posible('disponible')
            : $this->sinDestino($puntos === []
                ? sprintf('%s no tiene posiciones libres para el acopio de %s.', $this->nombre($camara), $carga)
                : sprintf('No hay posiciones libres junto al acopio de %s en %s.', $carga, $this->nombre($camara)));
    }

    private function concentracion(TareaMovimiento $paso): array
    {
        $contexto = $paso->contexto ?? [];
        $camaraId = $contexto['camara_objetivo_id'] ?? $paso->camara_destino_id;
        $camara = $camaraId ? $this->camaras()->get($camaraId) : null;
        if (! $camara || ! $this->sector->tienePosiciones($camara->id)) {
            return $this->posible('no_evaluable');
        }
        $puntos = collect(is_array($contexto['concentracion_puntos'] ?? null) ? $contexto['concentracion_puntos'] : [])
            ->filter(fn (mixed $punto): bool => is_array($punto) && isset($punto['banda'], $punto['posicion'], $punto['nivel']));
        $siguientes = collect($this->siguientes($camara->id));
        $posible = $puntos->isEmpty()
            ? $siguientes->isNotEmpty()
            : $siguientes->contains(fn (array $celda): bool => $puntos
                ->contains(fn (array $punto): bool => SectorAcopioCarga::vecinos($punto, $celda)));

        return $posible
            ? $this->posible('disponible')
            : $this->sinDestino(sprintf('No hay posiciones libres junto al grupo principal en %s.', $this->nombre($camara)));
    }

    private function despeje(TareaMovimiento $paso): array
    {
        if (! $paso->camara_origen_id || $this->despejes->tieneDestinoLocal($paso)) {
            return $this->posible('disponible');
        }
        $protegidas = collect($this->despejes->bandasProtegidas($paso));
        $otras = $this->camaras()->except([$paso->camara_origen_id]);
        if (! $this->sector->tienePosiciones($paso->camara_origen_id)
            && $otras->every(fn (Camara $camara): bool => ! $this->sector->tienePosiciones($camara->id))) {
            return $this->posible('no_evaluable');
        }
        foreach ($otras as $camara) {
            $libre = collect($this->siguientes($camara->id))->contains(fn (array $celda): bool => ! $protegidas
                ->contains(fn (array $banda): bool => $banda['camara_id'] === $camara->id
                    && $banda['banda'] === $celda['banda'] && $banda['nivel'] === $celda['nivel']));
            if ($libre) {
                return $this->posible('disponible');
            }
        }

        return $this->sinDestino(sprintf(
            'No hay posiciones válidas para despejar el pallet %s.',
            $paso->folio?->numero_folio ?? 'de la maniobra',
        ));
    }

    private function generico(TareaMovimiento $paso): array
    {
        $camaras = match ($paso->tipo_movimiento) {
            TipoMovimiento::Reubicacion => $this->camaras()->only([$paso->camara_origen_id]),
            TipoMovimiento::TrasladoEntreCamaras => $this->camaras()->except([$paso->camara_origen_id]),
            default => $paso->camara_destino_id
                ? $this->camaras()->only([$paso->camara_destino_id])
                : $this->camaras(),
        };
        if ($camaras->every(fn (Camara $camara): bool => ! $this->sector->tienePosiciones($camara->id))) {
            // Sin cámaras dirigidas con plano no hay nada que estimar: la tablet ya informa la
            // configuración y el rollout decide qué se publica.
            return $this->posible('no_evaluable');
        }
        foreach ($camaras as $camara) {
            $libre = collect($this->siguientes($camara->id))
                ->contains(fn (array $celda): bool => $celda['id'] !== $paso->posicion_origen_id);
            if ($libre) {
                return $this->posible('disponible');
            }
        }

        return $this->sinDestino($camaras->count() === 1
            ? sprintf('%s no tiene posiciones libres y accesibles.', $this->nombre($camaras->first()))
            : 'Las cámaras dirigidas no tienen posiciones libres y accesibles.');
    }

    /** @return array<string, array<string, mixed>|null> */
    private function acopios(): array
    {
        return $this->acopios ??= $this->acopio->paraTareas($this->pasos->filter(
            fn (TareaMovimiento $paso): bool => ! $paso->posicion_destino_id
                && $paso->tipo_movimiento !== TipoMovimiento::Retiro,
        ));
    }

    /** @return Collection<string, Camara> */
    private function camaras(): Collection
    {
        return $this->camaras ??= Camara::query()
            ->where('contenido', ContenidoCamara::Productos->value)
            ->where('estado', EstadoCamara::Activa->value)
            ->when($this->camarasRollout !== null, fn ($consulta) => $consulta->whereIn('id', $this->camarasRollout))
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre'])
            ->keyBy('id');
    }

    /** @return list<array{id: string, banda: int, posicion: int, nivel: int}> */
    private function siguientes(string $camaraId): array
    {
        return $this->siguientes[$camaraId] ??= $this->sector->siguientesLibres($camaraId);
    }

    private function pasoActual(ManiobraOperacional $maniobra): ?TareaMovimiento
    {
        return $maniobra->pasos->firstWhere('secuencia_maniobra', $maniobra->secuencia_actual)
            ?? ($maniobra->pasos->count() === 1 ? $maniobra->pasos->first() : null);
    }

    private function nombre(Camara $camara): string
    {
        return $camara->nombre ?: $camara->codigo;
    }

    /** @return array{evaluado: bool, posible: bool, codigo: string, detalle: string} */
    private function posible(string $codigo): array
    {
        return ['evaluado' => true, 'posible' => true, 'codigo' => $codigo, 'detalle' => ''];
    }

    /** @return array{evaluado: bool, posible: bool, codigo: string, detalle: string} */
    private function sinDestino(string $detalle): array
    {
        return ['evaluado' => true, 'posible' => false, 'codigo' => 'sin_destino', 'detalle' => $detalle];
    }
}
