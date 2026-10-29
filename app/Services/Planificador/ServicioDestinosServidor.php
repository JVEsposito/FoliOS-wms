<?php

namespace App\Services\Planificador;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\ModoBandaOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\UsoBandaOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\ManiobraOperacional;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Camaras\CalculadorAfinidadBanda;
use App\Services\Cargas\SectorAcopioCarga;
use App\Services\Cargas\ServicioAcopioCarga;
use Illuminate\Support\Collection;

/** Una sola geometría para estimar, preparar y reservar la secuencia completa. */
final class ServicioDestinosServidor
{
    public const VERSION = 'servidor_maniobra_completa_v1';

    private ?Collection $lectura = null;

    public static function activo(): bool
    {
        return config('planificador.mode') === 'guided'
            && config('planificador.compute') === 'server'
            && config('planificador.horizon') === 'rolling'
            && config('planificador.generacion_automatica');
    }

    public function olvidar(): void
    {
        $this->lectura = null;
    }

    /** Lectura compartida por un ciclo de arbitraje; nunca modifica inventario. */
    public function evaluar(ManiobraOperacional $maniobra): array
    {
        try {
            $this->simular($maniobra, $this->lectura ??= $this->camaras(false));

            return ['evaluado' => true, 'posible' => true, 'codigo' => 'secuencia_completa', 'detalle' => 'La secuencia completa tiene destinos y retornos posibles.'];
        } catch (ConflictoOperacion $error) {
            return ['evaluado' => true, 'posible' => false, 'codigo' => 'sin_destino', 'detalle' => $error->getMessage()];
        }
    }

    /** Se llama dentro de la transacción de toma, antes de autorizar cualquier retiro. */
    public function preparar(ManiobraOperacional $maniobra, User $usuario): void
    {
        $camaras = $this->camaras(true)->filter(fn (Camara $camara): bool => app(AlcanceOperacionalUsuario::class)->puedeVerCamara($usuario, $camara));
        $decisiones = $this->simular($maniobra, $camaras, true);
        $origenes = $maniobra->pasos()->whereNotNull('posicion_origen_id')
            ->whereNotIn('estado', [EstadoTareaMovimiento::Completada->value, EstadoTareaMovimiento::Cancelada->value])
            ->pluck('posicion_origen_id');
        $recursos = Posicion::query()->whereIn('id', $origenes)->get()
            ->concat(collect($decisiones)->map(fn ($decision) => $decision[1]))
            ->unique(fn (Posicion $p) => implode(':', [$p->camara_id, $p->banda, $p->nivel]))
            ->sortBy(fn (Posicion $p) => implode(':', [$p->camara_id, $p->banda, $p->nivel]));
        foreach ($recursos as $posicion) {
            $this->proteger($maniobra, $posicion);
        }
        foreach ($decisiones as [$tarea, $destino, $motivo]) {
            $tipo = $tarea->tipo_movimiento;
            if (in_array($tarea->contexto['tipo_decision'] ?? null, ['despeje_salida_directa', 'despeje_concentracion'], true)
                && ($tarea->contexto['tipo_movimiento_materializable'] ?? false)) {
                $tipo = $destino->camara_id === $tarea->camara_origen_id ? TipoMovimiento::Reubicacion : TipoMovimiento::TrasladoEntreCamaras;
            }
            $tarea->update([
                'tipo_movimiento' => $tipo, 'camara_destino_id' => $destino->camara_id, 'posicion_destino_id' => $destino->id,
                'contexto' => [...($tarea->contexto ?? []), 'destino_precalculado_inmutable' => true,
                    'destino_preparado_servidor' => self::VERSION, 'motivo_destino_servidor' => $motivo,
                    'destino_base_servidor' => $tarea->contexto['destino_base_servidor'] ?? [
                        'camara_id' => $tarea->camara_destino_id, 'posicion_id' => $tarea->posicion_destino_id,
                        'inmutable' => (bool) ($tarea->contexto['destino_precalculado_inmutable'] ?? false),
                    ]],
                'version' => $tarea->version + 1,
            ]);
        }
    }

    private function proteger(ManiobraOperacional $maniobra, Posicion $posicion): void
    {
        $clave = implode(':', [$posicion->camara_id, $posicion->banda, $posicion->nivel]);
        $proteccion = ReservaBandaManiobra::query()->where('clave_bloqueo', $clave)->lockForUpdate()->first();
        if ($proteccion && $proteccion->maniobra_operacional_id !== $maniobra->id) {
            throw new ConflictoOperacion('La banda está comprometida por otra maniobra. Actualiza tus tareas antes de retirar.');
        }
        if (! $proteccion) {
            $proteccion = ReservaBandaManiobra::query()->where('maniobra_operacional_id', $maniobra->id)
                ->where('camara_id', $posicion->camara_id)->where('banda', $posicion->banda)->where('nivel', $posicion->nivel)
                ->whereNull('sesion_estiba_id')->lockForUpdate()->first() ?? new ReservaBandaManiobra;
            $proteccion->fill([
                'maniobra_operacional_id' => $maniobra->id, 'camara_id' => $posicion->camara_id,
                'banda' => $posicion->banda, 'nivel' => $posicion->nivel,
                'clave_bloqueo' => $clave, 'reservada_at' => now(), 'liberada_at' => null, 'motivo_liberacion' => null,
            ])->save();
            Camara::query()->whereKey($posicion->camara_id)->increment('revision_reservas');
        }
    }

    private function camaras(bool $bloquear): Collection
    {
        $ids = app(ServicioDesplieguePlanificador::class)->idsCamarasDirigidas();
        $consulta = Camara::query()->where('contenido', ContenidoCamara::Productos->value)
            ->where('estado', EstadoCamara::Activa->value)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id');
        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return $consulta->with([
            'bandasOperacionales' => fn ($q) => $q->when($bloquear, fn ($q) => $q->lockForUpdate()),
            'proteccionesBandas' => fn ($q) => $q->when($bloquear, fn ($q) => $q->lockForUpdate()),
            'posiciones.ubicacionesActuales' => fn ($q) => $q->when($bloquear, fn ($q) => $q->lockForUpdate()),
            'posiciones' => fn ($q) => $q->orderBy('id')->when($bloquear, fn ($q) => $q->lockForUpdate()),
            'posiciones.ubicacionesActuales.folio.asignacionCargaActual',
            'posiciones.ubicacionesActuales.folio.retencionOperacionalActiva',
            'posiciones.reservaTareaActiva' => fn ($q) => $q->conCompromisoVigente()->when($bloquear, fn ($q) => $q->lockForUpdate())->with('tareaMovimiento.folio.asignacionCargaActual'),
            'posiciones.reservaPreparacionSagActiva' => fn ($q) => $q->when($bloquear, fn ($q) => $q->lockForUpdate()),
            'posiciones.reservaPreparacionSagActiva.lote.folios',
        ])->get()->keyBy('id');
    }

    /** Simula también los retornos, sin escribir ubicaciones ni fabricar custodias. */
    private function simular(ManiobraOperacional $maniobra, Collection $camaras, bool $bloquear = false): array
    {
        $pasos = $maniobra->pasos()->with(['planOperacional', 'posicionOrigen', 'folio.asignacionCargaActual', 'folio.retencionOperacionalActiva'])->orderBy('secuencia_maniobra')->get()
            ->filter(fn (TareaMovimiento $t): bool => ! $t->estado->esFinal());
        $acopio = app(ServicioAcopioCarga::class);
        $acopios = $bloquear ? [] : $acopio->paraTareas($pasos);
        foreach ($pasos as $paso) {
            if ($acopio->esRecepcion($paso->planOperacional, $paso->tipo_movimiento)) {
                $vigente = $bloquear ? $acopio->paraFolio($paso->folio_id, $paso->id, true) : ($acopios[$paso->id] ?? null);
                $paso->contexto = [...($paso->contexto ?? []), 'acopio_carga' => $vigente];
            }
        }
        $celdas = [];
        foreach ($camaras as $camara) {
            foreach ($camara->posiciones as $posicion) {
                if ($posicion->banda > $camara->cantidad_bandas || $posicion->posicion > $camara->posiciones_por_banda || $posicion->nivel > $camara->cantidad_niveles) {
                    continue;
                }
                $celdas[$posicion->id] = ['posicion' => $posicion, 'folio' => $posicion->ubicacionesActuales->first()?->folio,
                    'reserva' => $posicion->reservaTareaActiva, 'sag' => $posicion->reservaPreparacionSagActiva];
            }
        }
        // Índice por banda compartido con la simulación: evita recorrer toda la planta
        // para comprobar cada posición cuando las cámaras están cerca de su capacidad.
        $bandas = [];
        foreach ($celdas as $id => $celda) {
            $p = $celda['posicion'];
            $bandas[$p->camara_id][$p->banda][$id] = &$celdas[$id];
        }
        $decisiones = [];
        foreach ($pasos as $tarea) {
            if ($maniobra->estado->value === 'pendiente' && $maniobra->secuencia_actual === 1
                && isset($tarea->contexto['destino_base_servidor'])) {
                $base = $tarea->contexto['destino_base_servidor'];
                $tarea->camara_destino_id = $base['camara_id'];
                $tarea->posicion_destino_id = $base['posicion_id'];
                $tarea->contexto = [...$tarea->contexto, 'destino_precalculado_inmutable' => $base['inmutable']];
            }
            if ($tarea->estado === EstadoTareaMovimiento::EnProceso) {
                throw new ConflictoOperacion('El movimiento ya está iniciado: confirma su destino reservado o registra una incidencia.');
            }
            if ($tarea->posicion_origen_id && isset($celdas[$tarea->posicion_origen_id])) {
                if ($celdas[$tarea->posicion_origen_id]['folio']?->id !== $tarea->folio_id) {
                    throw new ConflictoOperacion('El folio '.$tarea->folio->numero_folio.' ya no está en el origen previsto. Usa NO COINCIDE.');
                }
                $origen = $celdas[$tarea->posicion_origen_id]['posicion'];
                if ($camaras[$origen->camara_id]->proteccionesBandas->contains(fn ($r) => $r->banda === $origen->banda
                    && $r->nivel === $origen->nivel && $r->maniobra_operacional_id !== $maniobra->id)) {
                    throw new ConflictoOperacion('El origen está protegido por otra maniobra. Espera su cierre o toma otra labor disponible.');
                }
                $this->validarExtraccion($tarea, $bandas[$origen->camara_id][$origen->banda]);
                $celdas[$tarea->posicion_origen_id]['folio'] = null;
            } elseif ($tarea->posicion_origen_id) {
                throw new ConflictoOperacion('La cámara de origen no está habilitada para esta maniobra. Revisa su configuración en Oficina.');
            }
            if ($tarea->tipo_movimiento === TipoMovimiento::Retiro) {
                continue;
            }
            $candidatos = [];
            foreach ($celdas as $celda) {
                $destino = $celda['posicion'];
                $celdasBanda = $bandas[$destino->camara_id][$destino->banda];
                if (! $this->admite($tarea, $celda, $celdasBanda, $camaras, $maniobra->id)) {
                    continue;
                }
                $destino = $celda['posicion'];
                $folios = collect($celdasBanda)
                    ->flatMap(fn ($c) => array_filter([$c['folio'], $c['reserva']?->tareaMovimiento?->folio]))->unique('id');
                $afinidad = app(CalculadorAfinidadBanda::class)->evaluar($tarea->folio, $folios);
                $camara = $camaras[$destino->camara_id];
                $local = $this->esDespeje($tarea) && $tarea->camara_origen_id === $camara->id;
                $preferida = app(ServicioDesplieguePlanificador::class)->camaraPreferenteDespacho($camara->id, $camara->codigo);
                $candidatos[] = ['posicion' => $destino, 'motivo' => ($local ? 'Despeje local. ' : '').$afinidad['motivo'],
                    'orden' => [$this->esDespeje($tarea) && ! $local ? 1 : 0,
                        app(CalculadorAfinidadBanda::class)->orden($afinidad), (int) $afinidad['mezclaria_clientes'],
                        $preferida ? 0 : 1, -$afinidad['puntaje'], $camara->codigo, $destino->banda, $destino->posicion, $destino->nivel]];
            }
            usort($candidatos, fn ($a, $b) => $a['orden'] <=> $b['orden']);
            $mejor = $candidatos[0] ?? null;
            if (! $mejor) {
                throw new ConflictoOperacion(sprintf('Sin destino seguro para el paso %d, folio %s. Mantén la maniobra sin nuevos retiros y solicita capacidad o retorno seguro en Oficina.',
                    $tarea->secuencia_maniobra, $tarea->folio->numero_folio));
            }
            $decisiones[] = [$tarea, $mejor['posicion'], $mejor['motivo']];
            $celdas[$mejor['posicion']->id]['folio'] = $tarea->folio;
            $celdas[$mejor['posicion']->id]['reserva'] = null;
        }

        return $decisiones;
    }

    private function validarExtraccion(TareaMovimiento $tarea, array $celdas): void
    {
        $origen = $celdas[$tarea->posicion_origen_id]['posicion'];
        foreach ($celdas as $celda) {
            $p = $celda['posicion'];
            if ($p->camara_id === $origen->camara_id && $p->banda === $origen->banda
                && (($p->nivel === $origen->nivel && $p->posicion > $origen->posicion)
                    || ($p->posicion === $origen->posicion && $p->nivel > $origen->nivel))
                && ($celda['folio'] || $celda['reserva'])) {
                throw new ConflictoOperacion('La secuencia no despeja el acceso al folio '.$tarea->folio->numero_folio.'. Replanifica desde Oficina antes de retirar.');
            }
        }
    }

    private function admite(TareaMovimiento $tarea, array $celda, array $celdas, Collection $camaras, string $maniobraId): bool
    {
        $p = $celda['posicion'];
        $ctx = $tarea->contexto ?? [];
        $camara = $camaras[$p->camara_id];
        if ($p->estado !== EstadoPosicion::Activa || $celda['folio'] || ($celda['reserva'] && $celda['reserva']->tarea_movimiento_id !== $tarea->id)
            || $p->id === $tarea->posicion_origen_id) {
            return false;
        }
        if ($tarea->posicion_destino_id && ($ctx['destino_precalculado_inmutable'] ?? false) && $p->id !== $tarea->posicion_destino_id) {
            return false;
        }
        if ($camara->proteccionesBandas->contains(fn ($r) => $r->banda === $p->banda && $r->nivel === $p->nivel && $r->maniobra_operacional_id !== $maniobraId)) {
            return false;
        }
        $uso = $ctx['uso_banda_destino'] ?? UsoBandaOperacional::TransitoProductoTerminado->value;
        $banda = $camara->bandasOperacionales->firstWhere('numero', $p->banda);
        if ($banda && ($banda->modo !== ModoBandaOperacional::Operativa || ! in_array($uso, $banda->usos_permitidos ?? [], true))) {
            return false;
        }
        $reservaSagPropia = $celda['sag'] && $uso === UsoBandaOperacional::Inspeccion->value
            && $celda['sag']->tipo_espacio->value === 'pallet' && $celda['sag']->lote->folios->contains('folio_id', $tarea->folio_id);
        if ($celda['sag'] && ! $reservaSagPropia) {
            return false;
        }
        $retorno = $tarea->tipo_paso_maniobra === TipoPasoManiobra::RetornoBanda;
        if ($retorno) {
            if ($p->camara_id !== ($ctx['camara_retorno_id'] ?? null) || $p->banda !== (int) ($ctx['banda_retorno'] ?? 0)
                || $p->nivel !== (int) ($ctx['nivel_retorno'] ?? 0) || $p->posicion !== (int) ($ctx['profundidad_resultante'] ?? 0)) {
                return false;
            }
        } elseif (! $this->esDespeje($tarea)) {
            $camaraExigida = array_key_exists('acopio_carga', $ctx) && ! ($ctx['destino_precalculado_inmutable'] ?? false)
                ? ($ctx['acopio_carga']['camara_objetivo_id'] ?? null) : $tarea->camara_destino_id;
            if ($camaraExigida && $p->camara_id !== $camaraExigida) {
                return false;
            }
            if ($tarea->tipo_movimiento === TipoMovimiento::Reubicacion && $p->camara_id !== $tarea->camara_origen_id) {
                return false;
            }
            if ($tarea->tipo_movimiento === TipoMovimiento::TrasladoEntreCamaras && $p->camara_id === $tarea->camara_origen_id) {
                return false;
            }
        } else {
            foreach ($ctx['bandas_extraccion_protegidas'] ?? [] as $protegida) {
                if ($p->camara_id === $protegida['camara_id'] && $p->banda === (int) $protegida['banda'] && $p->nivel === (int) $protegida['nivel']) {
                    return false;
                }
            }
            $origen = $tarea->posicionOrigen;
            if ($origen && $p->camara_id === $origen->camara_id && $p->banda === $origen->banda && $p->nivel === $origen->nivel) {
                return false;
            }
        }
        if (($ctx['tipo_decision'] ?? null) === 'concentrar_carga'
            && ! empty($ctx['camara_objetivo_id']) && $p->camara_id !== $ctx['camara_objetivo_id']) {
            return false;
        }
        $acopio = $ctx['acopio_carga'] ?? null;
        if ($acopio && (($acopio['despacho_directo'] ?? false)
            || (($acopio['camara_objetivo_id'] ?? null) && $p->camara_id !== $acopio['camara_objetivo_id']))) {
            return false;
        }
        $sector = $acopio['sector'] ?? null;
        if (($sector['tipo'] ?? null) === 'nuevo' && isset($sector['banda_desde'], $sector['banda_hasta'])
            && ($p->nivel !== $sector['nivel'] || $p->banda < $sector['banda_desde'] || $p->banda > $sector['banda_hasta'])) {
            return false;
        }
        $puntos = $acopio['puntos'] ?? (($ctx['tipo_decision'] ?? null) === 'concentrar_carga' ? ($ctx['concentracion_puntos'] ?? []) : []);
        if ($puntos !== [] && ! collect($puntos)->contains(fn ($punto) => SectorAcopioCarga::vecinos($punto, $p->toArray()))) {
            return false;
        }
        $soporte = $p->nivel === 1;
        $frente = 0;
        $libres = [];
        foreach ($celdas as $otra) {
            $q = $otra['posicion'];
            if ($q->camara_id !== $p->camara_id || $q->banda !== $p->banda) {
                continue;
            }
            if ($otra['folio'] && (! $otra['folio']->activo || $otra['folio']->tipo_bulto !== TipoBulto::Pallet)) {
                return false;
            }
            if ($uso === UsoBandaOperacional::TransitoProductoTerminado->value && $otra['folio']?->retencionOperacionalActiva) {
                return false;
            }
            if ($q->posicion === $p->posicion && $q->nivel === $p->nivel - 1 && $otra['folio']) {
                $soporte = true;
            }
            if ($q->nivel !== $p->nivel) {
                continue;
            }
            // El sector SAG propio reserva capacidad y pasillos; todavía no es stock
            // físico delante del pallet. Las reservas de otro lote sí lo excluyen.
            $sagAjena = $otra['sag'] && ! ($reservaSagPropia
                && $otra['sag']->lote_inspeccion_sag_id === $celda['sag']->lote_inspeccion_sag_id);
            $tomada = $otra['folio'] || ($otra['reserva'] && $otra['reserva']->tarea_movimiento_id !== $tarea->id) || $sagAjena;
            if ($tomada) {
                $frente = max($frente, $q->posicion);
            } elseif ($q->estado === EstadoPosicion::Activa) {
                $libres[] = $q->posicion;
            }
        }

        return $soporte && $p->posicion > $frente && ($retorno || $reservaSagPropia || ! collect($libres)->contains(fn ($profundidad) => $profundidad > $frente && $profundidad < $p->posicion));
    }

    private function esDespeje(TareaMovimiento $tarea): bool
    {
        return in_array($tarea->contexto['tipo_decision'] ?? null, ['despeje_salida_directa', 'despeje_concentracion'], true);
    }
}
