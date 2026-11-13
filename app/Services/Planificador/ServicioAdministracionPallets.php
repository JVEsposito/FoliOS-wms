<?php

namespace App\Services\Planificador;

use App\Enums\ContenidoCamara;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoCamara;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoOperacionSincronizacion;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\EstadoTransicionOperacional;
use App\Enums\ModoBandaOperacional;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Enums\UsoBandaOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Folio;
use App\Models\ManiobraOperacional;
use App\Models\Movimiento;
use App\Models\OperacionSincronizacion;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\TareaMovimiento;
use App\Models\TransicionOperacional;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\EstadoEvacuacionEmergencia;
use App\Services\Estiba\DetectorAdvertenciasMovimiento;
use App\Services\Estiba\ServicioExclusionPallet;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Folios\ServicioHabilitacionAlmacenamiento;
use App\Services\Temporadas\GuardiaTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class ServicioAdministracionPallets
{
    public const TIPO_CORRECCION = 'pallet.corregir_ubicacion_admin';

    private function tareas(Folio $folio): Collection
    {
        return $folio->tareasMovimiento()->whereIn('estado', [EstadoTareaMovimiento::Pendiente, EstadoTareaMovimiento::Bloqueada, EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::EnProceso])
            ->with(['maniobraOperacional', 'responsable:id,name', 'posicionDestino', 'planOperacional'])->orderBy('id')->get();
    }

    public function consultar(Folio $folio): array
    {
        Gate::authorize('administrar-camaras');
        app(GuardiaTemporadaActiva::class)->asegurar($folio);
        $tareas = $this->tareas($folio);
        $ubicacion = $folio->ubicacionActual()->with(['camara', 'posicion'])->first();

        return ['folio_id' => $folio->id, 'numero_folio' => $folio->numero_folio, 'estado' => $folio->estado_operacional->value,
            'revision' => $this->revision($folio, $ubicacion, $tareas),
            'ubicacion' => $ubicacion ? ['camara_id' => $ubicacion->camara_id, 'camara' => $ubicacion->camara?->nombre,
                'posicion_id' => $ubicacion->posicion_id, 'posicion' => $ubicacion->posicion?->etiqueta] : null,
            'tareas' => $tareas->map(fn ($t) => ['id' => $t->id, 'version' => $t->version, 'estado' => $t->estado->value,
                'maniobra_id' => $t->maniobra_operacional_id, 'instruccion' => $t->instruccion,
                'responsable' => $t->responsable?->name, 'destino' => $t->posicionDestino?->etiqueta])->all(),
            'historial' => TransicionOperacional::where('sujeto_tipo', Folio::class)->where('sujeto_id', $folio->id)
                ->where('tipo', 'like', 'pallet.%')->where('estado', EstadoTransicionOperacional::Aplicada)
                ->with('usuario:id,name')->latest('ocurrido_at')->limit(10)->get()
                ->map(fn ($t) => ['accion' => $t->tipo, 'motivo' => $t->payload['motivo'] ?? '', 'usuario' => $t->usuario?->name, 'fecha' => $t->ocurrido_at?->toAtomString()])->all()];
    }

    private function revision(Folio $folio, ?UbicacionActual $ubicacion, Collection $tareas): string
    {
        return hash('sha256', json_encode([$folio->getAttributes(), $ubicacion?->getAttributes(),
            $tareas->map(fn ($t) => [$t->id, $t->version, $t->estado->value, $t->maniobraOperacional?->version])->all()], JSON_THROW_ON_ERROR));
    }

    public function intervenir(Folio $folio, User $usuario, array $datos): array
    {
        Gate::authorize('administrar-camaras');
        $tipo = $datos['accion'] === 'corregir_ubicacion' ? self::TIPO_CORRECCION : 'pallet.'.$datos['accion'].'_admin';

        return app(MotorTransicionesOperacionales::class)->ejecutar(new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Planificador, tipo: $tipo, usuario: $usuario,
            payload: ['folio_id' => $folio->id, ...$datos], operacionId: $datos['operacion_id'],
            sujetoTipo: Folio::class, sujetoId: $folio->id, referencia: $folio->numero_folio,
        ), function () use ($folio, $usuario, $datos, $tipo): array {
            $anterior = TransicionOperacional::where('tipo', $tipo)->where('operacion_id', $datos['operacion_id'])->where('estado', EstadoTransicionOperacional::Aplicada)->first();
            if ($anterior) {
                return $anterior->resultado['datos'];
            }
            // Mismo mutex y orden que la toma desde las tablets: todos los pallets antes de sus tareas.
            $previas = $this->tareas($folio);
            $ids = TareaMovimiento::whereIn('maniobra_operacional_id', $previas->pluck('maniobra_operacional_id')->filter())
                ->pluck('folio_id')->push($folio->id)->unique()->sort()->values();
            Folio::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $actual = Folio::findOrFail($folio->id);
            app(GuardiaTemporadaActiva::class)->asegurar($actual);
            $tareas = app(ServicioExclusionPallet::class)->tareasActuales($actual->id)->filter(fn ($t) => ! $t->estado->esFinal())->values();
            $tareas->load(['maniobraOperacional' => fn ($q) => $q->lockForUpdate(), 'planOperacional']);
            $ubicacion = $actual->ubicacionActual()->lockForUpdate()->first();
            if (! hash_equals($this->revision($actual, $ubicacion, $tareas), $datos['revision'])) {
                throw new ConflictoOperacion('El pallet o su tarea cambió. Consulta nuevamente antes de intervenir.');
            }
            if ($actual->tipo_bulto !== TipoBulto::Pallet || ! $actual->activo) {
                throw new ConflictoOperacion('La intervención requiere un pallet completo activo.');
            }
            if (CustodiaTemporalManiobra::whereIn('folio_id', $ids)->where('estado', EstadoCustodiaTemporal::Activa)->lockForUpdate()->exists()) {
                throw new ConflictoOperacion('Existe un pallet bajo custodia temporal. Resuelve primero la discrepancia de la maniobra y su retorno.');
            }
            $correccion = $datos['accion'] === 'corregir_ubicacion';
            if ($correccion && ! ($datos['confirmacion_fisica'] ?? false)) {
                throw new ConflictoOperacion('Confirma la verificación física de la ubicación antes de corregir.');
            }
            foreach ($tareas as $tarea) {
                if ($tarea->maniobra_operacional_id) {
                    $maniobra = ManiobraOperacional::lockForUpdate()->findOrFail($tarea->maniobra_operacional_id);
                    $pasos = $maniobra->pasos()->lockForUpdate()->get();
                    if ($pasos->contains(fn ($p) => ! $ids->contains($p->folio_id))) {
                        throw new ConflictoOperacion('Cambió la composición de la maniobra. Actualiza antes de intervenir.');
                    }
                    if (! $correccion && ($maniobra->secuencia_actual > 1 || $pasos->contains(fn ($p) => $p->iniciada_at !== null || $p->estado === EstadoTareaMovimiento::Completada))) {
                        throw new ConflictoOperacion('La maniobra ya comenzó físicamente. Confirma su destino o corrige la ubicación después de verificar el pallet.');
                    }
                } elseif (! $correccion && ($tarea->iniciada_at || $tarea->estado === EstadoTareaMovimiento::EnProceso)) {
                    throw new ConflictoOperacion('El movimiento ya comenzó físicamente; requiere verificación de ubicación.');
                }
            }
            $destino = null;
            if ($datos['accion'] !== 'liberar_tareas') {
                if (! in_array($actual->estado_operacional, [EstadoOperacionalFolio::Disponible, EstadoOperacionalFolio::PendienteUbicacion], true)) {
                    throw new ConflictoOperacion('El estado del pallet no permite ubicarlo en cámara. Resuelve primero su condición operacional.');
                }
                if ($actual->asignacionCargaActual?->estado->fueraDeCamara()) {
                    throw new ConflictoOperacion('El pallet está en andén o cargado. Registra su retorno por el flujo de despacho antes de ubicarlo en cámara.');
                }
                app(ServicioHabilitacionAlmacenamiento::class)->validarIngresoCamara($actual);
                $destino = Posicion::with('camara')->findOrFail($datos['posicion_destino_id']);
                // Todas las escrituras físicas toman también el mutex de la cámara.
                $camaras = Camara::whereIn('id', array_filter([$ubicacion?->camara_id, $destino->camara_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                Posicion::whereIn('camara_id', $camaras->keys())->orderBy('id')->lockForUpdate()->get(['id']);
                $destino->setRelation('camara', $camaras[$destino->camara_id]);
                foreach ($camaras as $camara) {
                    if ($camara->bloqueo()->lockForUpdate()->exists()) {
                        throw new ConflictoOperacion('Una cámara involucrada mantiene una sesión manual abierta. Cierra esa sesión antes de intervenir.');
                    }
                }
                if ($ubicacion?->posicion_id) {
                    $origen = Posicion::findOrFail($ubicacion->posicion_id);
                    if (ReservaBandaManiobra::where('camara_id', $origen->camara_id)->where('banda', $origen->banda)->where('nivel', $origen->nivel)
                        ->whereNotNull('clave_bloqueo')->whereNotIn('maniobra_operacional_id', $tareas->pluck('maniobra_operacional_id')->filter())->lockForUpdate()->exists()
                        || $origen->reservaPreparacionSagActiva()->lockForUpdate()->exists()) {
                        throw new ConflictoOperacion('El origen está protegido por otra maniobra o inspección. Resuelve ese compromiso antes de intervenir.');
                    }
                }
                if ($destino->camara->version_plano !== (int) $datos['version_plano_destino']) {
                    throw new ConflictoOperacion('El plano de destino cambió. Actualiza las cámaras y vuelve a consultar el pallet.');
                }
                $this->validarDestino($destino, $actual, $tareas->pluck('id')->all(), $tareas->pluck('maniobra_operacional_id')->filter()->all());
                if ($ubicacion?->posicion_id === $destino->id) {
                    throw new ConflictoOperacion('El pallet ya figura en esa posición.');
                }
                if (! $correccion) {
                    app(DetectorAdvertenciasMovimiento::class)->paraUbicacion($destino, []);
                }
            }
            foreach ($tareas as $tarea) {
                if ($tarea->maniobra_operacional_id) {
                    $maniobra = ManiobraOperacional::findOrFail($tarea->maniobra_operacional_id);
                    $ok = app(ServicioManiobrasOperacionales::class)->cancelarReversible($maniobra, $usuario, $datos['motivo'],
                        $correccion ? fn ($p) => $p->folio_id === $actual->id && $p->tipo_paso_maniobra?->value === 'movimiento_permanente' : null);
                    if (! $ok) {
                        throw new ConflictoOperacion('La maniobra mantiene otro movimiento en ejecución o custodia; resuelve su discrepancia.');
                    }
                    $discrepancias = $maniobra->discrepancias()->where('estado', EstadoDiscrepanciaManiobra::Abierta)->lockForUpdate()->get();
                    foreach ($discrepancias as $discrepancia) {
                        $discrepancia->update(['estado' => EstadoDiscrepanciaManiobra::Resuelta, 'accion_resolucion' => 'cancelar_maniobra',
                            'resuelta_por_user_id' => $usuario->id, 'resuelta_at' => now(), 'resolucion' => $datos['motivo']]);
                    }
                } else {
                    $tarea->update(['estado' => EstadoTareaMovimiento::Cancelada, 'cancelada_at' => now(), 'cancelada_por_user_id' => $usuario->id,
                        'motivo_cancelacion' => $datos['motivo'], 'version' => $tarea->version + 1]);
                    app(ServicioReservasTareasMovimiento::class)->liberarPasoObsoleto($tarea, $datos['motivo']);
                }
                app(ServicioSesionEstiba::class)->cerrarDeTarea($tarea->refresh());
            }
            if ($datos['accion'] === 'liberar_tareas' && $tareas->isEmpty()) {
                throw new ConflictoOperacion('El pallet no posee tareas activas que liberar.');
            }
            $resultado = ['accion' => $datos['accion'], 'folio_id' => $actual->id];
            if ($correccion) {
                $resultado['movimiento_id'] = $this->corregir($actual, $ubicacion, $destino, $usuario, $datos)->id;
            } elseif ($destino) {
                $plan = app(ServicioPlanesOperacionales::class)->crear($actual->temporada, TipoPlanOperacional::CorreccionDiscrepancia,
                    'Destino administrativo '.$actual->numero_folio, $usuario, [[
                        'folio_id' => $actual->id, 'tipo_movimiento' => ! $ubicacion ? TipoMovimiento::UbicacionInicial
                            : ($ubicacion->camara_id === $destino->camara_id ? TipoMovimiento::Reubicacion : TipoMovimiento::TrasladoEntreCamaras),
                        'camara_origen_id' => $ubicacion?->camara_id, 'posicion_origen_id' => $ubicacion?->posicion_id,
                        'camara_destino_id' => $destino->camara_id, 'posicion_destino_id' => $destino->id,
                        'instruccion' => 'Ubicar '.$actual->numero_folio.' en '.$destino->camara->nombre.' · '.$destino->etiqueta,
                        'contexto' => ['destino_precalculado_inmutable' => true, 'designado_por_admin' => $usuario->id],
                    ]], PrioridadOperacional::Alta, $datos['motivo']);
                $resultado['tarea_id'] = $plan->tareas->first()->id;
            }
            app(ServicioEstadoArbitrajePlanificador::class)->solicitar($actual->temporada_id, 'intervencion_admin_pallet');

            return $resultado;
        });
    }

    public function validarDestino(Posicion $p, Folio $folio, array $tareasPropias = [], array $maniobrasPropias = []): void
    {
        $c = $p->camara;
        if (app(EstadoEvacuacionEmergencia::class)->activa($c->id)) {
            throw new ConflictoOperacion('La cámara de destino mantiene una evacuación de emergencia activa.');
        }
        if ($c->estado !== EstadoCamara::Activa || $c->contenido !== ContenidoCamara::Productos || $p->estado !== EstadoPosicion::Activa
            || $p->banda > $c->cantidad_bandas || $p->posicion > $c->posiciones_por_banda || $p->nivel > $c->cantidad_niveles) {
            throw new ConflictoOperacion('El destino no es una posición operativa de producto terminado.');
        }
        if ($c->bloqueo()->lockForUpdate()->exists() || $c->sesionesEstiba()->where('estado', 'abierta')->whereNull('tarea_movimiento_id')->lockForUpdate()->exists()) {
            throw new ConflictoOperacion('La cámara está abierta en una sesión manual. Cierra esa sesión antes de intervenir.');
        }
        $banda = $c->bandasOperacionales()->where('numero', $p->banda)->first();
        if (! $banda || $banda->modo !== ModoBandaOperacional::Operativa || ! in_array(UsoBandaOperacional::TransitoProductoTerminado->value, $banda->usos_permitidos ?? [], true)) {
            throw new ConflictoOperacion('La banda no admite almacenamiento de producto terminado.');
        }
        if ($p->ubicacionesActuales()->where('folio_id', '!=', $folio->id)->lockForUpdate()->exists()
            || $p->reservaPreparacionSagActiva()->lockForUpdate()->exists()
            || $p->reservaTareaActiva()->whereNotIn('tarea_movimiento_id', $tareasPropias)->lockForUpdate()->exists()
            || ReservaBandaManiobra::where('camara_id', $c->id)->where('banda', $p->banda)->where('nivel', $p->nivel)
                ->whereNotNull('clave_bloqueo')->whereNotIn('maniobra_operacional_id', $maniobrasPropias)->lockForUpdate()->exists()
            || TareaMovimiento::where('posicion_destino_id', $p->id)->whereNotIn('id', $tareasPropias)
                ->whereIn('estado', [EstadoTareaMovimiento::Pendiente, EstadoTareaMovimiento::Bloqueada, EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::EnProceso])->lockForUpdate()->exists()) {
            throw new ConflictoOperacion('La posición está ocupada o comprometida por otra tarea, maniobra o inspección.');
        }
    }

    private function corregir(Folio $folio, ?UbicacionActual $ubicacion, Posicion $destino, User $usuario, array $datos): Movimiento
    {
        $ahora = now();
        $camaras = Camara::whereIn('id', array_filter([$ubicacion?->camara_id, $destino->camara_id]))->get()->keyBy('id');
        foreach ($camaras as $camara) {
            $camara->increment('version_plano');
        }
        $operacion = OperacionSincronizacion::create(['id' => $datos['operacion_id'], 'user_id' => $usuario->id, 'dispositivo_id' => null,
            'tipo' => TipoMovimiento::CorreccionAdministrativa->value, 'estado' => EstadoOperacionSincronizacion::Aceptada,
            'payload' => $datos, 'payload_hash' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR)),
            'generada_dispositivo_at' => $ahora, 'recibida_servidor_at' => $ahora, 'procesada_at' => $ahora]);
        $movimiento = Movimiento::create(['operacion_id' => $operacion->id, 'folio_id' => $folio->id,
            'tipo_movimiento' => TipoMovimiento::CorreccionAdministrativa, 'user_id' => $usuario->id, 'dispositivo_id' => null,
            'camara_origen_id' => $ubicacion?->camara_id, 'posicion_origen_id' => $ubicacion?->posicion_id,
            'camara_destino_id' => $destino->camara_id, 'posicion_destino_id' => $destino->id,
            'motivo' => $datos['motivo'], 'version_origen_anterior' => $ubicacion ? $camaras[$ubicacion->camara_id]->version_plano - 1 : null,
            'version_origen_resultante' => $ubicacion ? $camaras[$ubicacion->camara_id]->version_plano : null,
            'version_destino_anterior' => $camaras[$destino->camara_id]->version_plano - 1,
            'version_destino_resultante' => $camaras[$destino->camara_id]->version_plano,
            'generado_dispositivo_at' => $ahora, 'recibido_servidor_at' => $ahora]);
        $valores = ['camara_id' => $destino->camara_id, 'posicion_id' => $destino->id, 'movimiento_id' => $movimiento->id, 'ubicado_at' => $ahora];
        $ubicacion ? $ubicacion->update($valores) : UbicacionActual::create(['folio_id' => $folio->id, ...$valores]);
        if ($folio->estado_operacional === EstadoOperacionalFolio::PendienteUbicacion) {
            $folio->update(['estado_operacional' => EstadoOperacionalFolio::Disponible]);
        }

        return $movimiento;
    }
}
