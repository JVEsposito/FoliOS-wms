<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPresenciaCargaAnden;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadCarga;
use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Anden;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\EventoCarga;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\PresenciaCargaAnden;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaTareaMovimiento;
use App\Models\Temporada;
use App\Models\TransicionOperacional;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Cargas\RevisionCargaOperacional;
use App\Services\Cargas\ServicioAcopioCarga;
use App\Services\Cargas\ServicioPlanConcentracionCarga;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Planificador\ServicioRecalculosPendientesPlanificador;
use App\Services\Transiciones\NormalizadorTransicionOperacional;
use DomainException;
use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConcentracionCargaRollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_carga_que_ya_cumple_80_por_ciento_no_genera_movimientos(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 4, fuera: 1);

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertNull($plan);
        $this->assertDatabaseMissing('planes_operacionales', [
            'referencia_tipo' => 'carga_concentracion',
            'referencia_id' => $contexto['carga']->id,
        ]);
        $this->assertDatabaseCount('tareas_movimiento', 0);
    }

    public function test_publica_solo_el_minimo_necesario_para_llegar_al_80_por_ciento(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertNotNull($plan);
        $this->assertSame('concentracion_carga', $plan->tipo->value);
        $this->assertSame('rolling', $plan->contexto['planner_horizon']);
        $this->assertSame(70, $plan->contexto['porcentaje_actual']);
        $this->assertSame(80, $plan->contexto['umbral_porcentaje']);
        $this->assertSame($contexto['camaraObjetivo']->id, $plan->contexto['camara_objetivo_id']);
        $this->assertSame(1, $plan->tareas()->count());
        $this->assertSame(1, $plan->maniobras()->count());

        $tarea = $plan->tareas()->sole();
        $maniobra = $plan->maniobras()->sole();
        $this->assertSame($maniobra->id, $tarea->maniobra_operacional_id);
        $this->assertSame(1, $maniobra->costo_movimientos);
        $this->assertSame('movimiento_permanente', $tarea->tipo_paso_maniobra->value);
        $this->assertSame('concentrar_carga', $tarea->contexto['tipo_decision']);
        $this->assertSame($contexto['camaraObjetivo']->id, $tarea->camara_destino_id);
        $this->assertNull($tarea->posicion_destino_id);
        $this->assertContains($tarea->folio_id, collect($contexto['foliosFuera'])->pluck('id')->all());
    }

    public function test_blocker_sin_destino_util_publica_extraccion_objetivo_y_retorno(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 1, sinUbicacion: 1);
        $objetivo = $contexto['foliosFuera'][0];
        $posicionObjetivo = $objetivo->ubicacionActual->posicion;
        $contexto['camaraFuera']->update(['posiciones_por_banda' => 2]);
        $posicionBlocker = Posicion::create([
            'camara_id' => $contexto['camaraFuera']->id,
            'banda' => $posicionObjetivo->banda,
            'posicion' => 2,
            'nivel' => 1,
            'etiqueta' => 'B01-P02-N1',
        ]);
        $blocker = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'PAL-254-BLOCKER-01',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
            'activo' => true,
            'marca' => 'OTRA',
            'exportadora' => 'Otro cliente',
        ]);
        $this->ubicarSinEventos($blocker, $contexto['camaraFuera'], $posicionBlocker);

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertNotNull($plan);
        $maniobra = $plan->maniobras()->sole();
        $pasos = $maniobra->pasos()->get();
        $this->assertSame(3, $maniobra->costo_movimientos);
        $this->assertSame([
            TipoPasoManiobra::ExtraccionTemporal,
            TipoPasoManiobra::MovimientoPermanente,
            TipoPasoManiobra::RetornoBanda,
        ], $pasos->pluck('tipo_paso_maniobra')->all());
        $this->assertSame([
            EstadoTareaMovimiento::Pendiente,
            EstadoTareaMovimiento::Bloqueada,
            EstadoTareaMovimiento::Bloqueada,
        ], $pasos->pluck('estado')->all());
        $this->assertSame($blocker->id, $pasos[0]->folio_id);
        $this->assertSame($objetivo->id, $pasos[1]->folio_id);
        $this->assertSame($blocker->id, $pasos[2]->folio_id);
        $this->assertSame(
            $posicionObjetivo->posicion,
            $pasos[2]->contexto['profundidad_resultante'],
        );
        $this->assertSame(1, $maniobra->reservasBandas()->count());
        $this->assertTrue($maniobra->contexto['cerrable']);
    }

    public function test_blocker_asignado_a_otra_carga_va_a_destino_util_sin_retorno(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 1, sinUbicacion: 1);
        $objetivo = $contexto['foliosFuera'][0];
        $posicionObjetivo = $objetivo->ubicacionActual->posicion;
        $posicionBlocker = Posicion::create([
            'camara_id' => $contexto['camaraFuera']->id,
            'banda' => $posicionObjetivo->banda,
            'posicion' => 2,
            'nivel' => 1,
            'etiqueta' => 'B01-P02-N1',
        ]);
        $blocker = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'PAL-254-BLOCKER-UTIL',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
            'activo' => true,
            'marca' => 'OTRA',
            'exportadora' => 'Cliente secundario',
        ]);
        $this->ubicarSinEventos($blocker, $contexto['camaraFuera'], $posicionBlocker);

        $camaraSecundaria = $this->crearCamara(
            'CAM-254-C',
            'Cámara carga secundaria',
            1,
            3,
            $contexto['usuario'],
        );
        $posicionesSecundarias = $this->crearPosicionesBanda($camaraSecundaria, 1, 3);
        $ancla = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'PAL-254-ANCLA-UTIL',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now()->subHour(),
            'activo' => true,
            'marca' => 'OTRA',
            'exportadora' => 'Cliente secundario',
        ]);
        $this->ubicarSinEventos($ancla, $camaraSecundaria, $posicionesSecundarias[0]);
        $otraCarga = Carga::create([
            'temporada_id' => $contexto['temporada']->id,
            'codigo' => 'CAR-254-SECUNDARIA',
            'estado' => EstadoCarga::Pendiente,
            'prioridad' => PrioridadCarga::Alta,
            'camara_objetivo_id' => $camaraSecundaria->id,
            'version' => 1,
            'creada_por_user_id' => $contexto['usuario']->id,
            'actualizada_por_user_id' => $contexto['usuario']->id,
            'publicada_por_user_id' => $contexto['usuario']->id,
            'publicada_at' => now(),
        ]);
        foreach ([$ancla, $blocker] as $folio) {
            $asignacion = CargaFolio::create([
                'carga_id' => $otraCarga->id,
                'folio_id' => $folio->id,
                'estado' => EstadoCargaFolio::Pendiente,
                'asignado_por_user_id' => $contexto['usuario']->id,
                'asignado_at' => now(),
            ]);
            ReservaCargaFolio::create([
                'folio_id' => $folio->id,
                'carga_folio_id' => $asignacion->id,
            ]);
        }

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertNotNull($plan);
        $maniobra = $plan->maniobras()->sole();
        $pasos = $maniobra->pasos()->get();
        $this->assertSame(2, $maniobra->costo_movimientos);
        $this->assertSame([
            TipoPasoManiobra::MovimientoPermanente,
            TipoPasoManiobra::MovimientoPermanente,
        ], $pasos->pluck('tipo_paso_maniobra')->all());
        $this->assertSame($blocker->id, $pasos[0]->folio_id);
        $this->assertSame($posicionesSecundarias[1]->id, $pasos[0]->posicion_destino_id);
        $this->assertSame('blocker_destino_util', $pasos[0]->contexto['tipo_decision']);
        $this->assertSame($objetivo->id, $pasos[1]->folio_id);
        $this->assertSame(0, $maniobra->reservasBandas()->count());
        $this->assertSame(1, $maniobra->contexto['blockers_destino_util']);
        $this->assertSame(0, $maniobra->contexto['blockers_retorno']);
        $this->assertSame(2, $maniobra->objetivos()->count());
        $objetivoSecundario = PlanOperacional::query()->where('referencia_tipo', 'carga_concentracion')
            ->where('referencia_id', $otraCarga->id)->sole();
        $this->assertSame(0, $objetivoSecundario->tareas()->count());
        $this->assertSame(1, $objetivoSecundario->maniobrasResolutorias()->count());
        $otraCarga->update(['camara_objetivo_id' => $contexto['camaraFuera']->id]);
        [$operador, $tablet] = $this->crearOperador();
        try {
            app(ServicioPlanesOperacionales::class)->asumir($pasos[0], $operador, $tablet);
            $this->fail('La maniobra usó el acopio anterior de la carga beneficiada.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('acopio', $error->getMessage());
            $this->assertSame(EstadoTareaMovimiento::Pendiente, $pasos[0]->refresh()->estado);
        }
    }

    public static function estadosPreviosRetorno(): array
    {
        return ['sin fallo anterior' => [false], 'UUID con deadlock histórico' => [true], 'destinos del servidor' => [false, 'server']];
    }

    #[DataProvider('estadosPreviosRetorno')]
    public function test_maniobra_temporal_no_cierra_hasta_devolver_el_blocker(bool $falloHistorico, string $compute = 'tablet'): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 1, sinUbicacion: 1);
        config(['planificador.compute' => $compute]);
        $contexto['camaraFuera']->update(['posiciones_por_banda' => 2]);
        $objetivo = $contexto['foliosFuera'][0];
        $posicionObjetivo = $objetivo->ubicacionActual->posicion;
        $posicionBlocker = Posicion::create([
            'camara_id' => $contexto['camaraFuera']->id,
            'banda' => $posicionObjetivo->banda,
            'posicion' => 2,
            'nivel' => 1,
            'etiqueta' => 'B01-P02-N1',
        ]);
        $blocker = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'PAL-254-CUSTODIA',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
            'activo' => true,
            'marca' => 'OTRA',
            'exportadora' => 'Otro cliente',
        ]);
        $this->ubicarSinEventos($blocker, $contexto['camaraFuera'], $posicionBlocker);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $maniobra = $plan->maniobras()->sole();
        [$operador, $dispositivo] = $this->crearOperador();
        $sesiones = app(ServicioSesionEstiba::class);
        $sesionOrigen = $sesiones->abrir($contexto['camaraFuera'], $operador, $dispositivo);
        $sesionDestino = $sesiones->abrir($contexto['camaraObjetivo'], $operador, $dispositivo);
        $planes = app(ServicioPlanesOperacionales::class);
        $movimientos = app(ServicioMovimientoEstiba::class);

        $extraccion = $maniobra->pasos()->where('secuencia_maniobra', 1)->sole();
        $planes->asumir($extraccion, $operador, $dispositivo);
        try {
            app(ServicioReservasTareasMovimiento::class)->validarParaMovimiento(
                null,
                $objetivo,
                TipoMovimiento::Retiro,
                $posicionObjetivo->id,
                null,
                $operador,
                $dispositivo,
            );
            $this->fail('Un movimiento ajeno pudo intervenir un pallet futuro de la maniobra.');
        } catch (ConflictoOperacion $exception) {
            $this->assertStringContainsString('maniobra física asumida', $exception->getMessage());
        }
        $planes->iniciar($extraccion->refresh(), $operador, $dispositivo);
        $movimientos->retirar(
            operacionId: (string) Str::uuid(),
            folio: $blocker,
            sesionOrigen: $sesionOrigen,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionOrigenConocida: $contexto['camaraFuera']->refresh()->version_plano,
            generadoDispositivoAt: now(),
            motivo: 'Extracción temporal de prueba.',
            tareaMovimiento: $extraccion->refresh(),
        );

        $this->assertSame(
            EstadoManiobraOperacional::EnEjecucion,
            $maniobra->refresh()->estado,
        );
        $this->assertDatabaseHas('custodias_temporales_maniobra', [
            'maniobra_operacional_id' => $maniobra->id,
            'folio_id' => $blocker->id,
            'estado' => 'activa',
            'bloqueo_folio_id' => $blocker->id,
        ]);

        $movimientoObjetivo = $maniobra->pasos()->where('secuencia_maniobra', 2)->sole();
        $this->assertSame(EstadoTareaMovimiento::Asumida, $movimientoObjetivo->estado);
        $camaraDestinoAntes = $movimientoObjetivo->camara_destino_id;
        $this->travel(30)->minutes();
        $this->assertSame(
            0,
            app(ServicioReservasTareasMovimiento::class)->expirarVencidas(),
            'Una maniobra con custodia temporal no puede perder su claim por timeout.',
        );
        $this->assertSame(EstadoTareaMovimiento::Asumida, $movimientoObjetivo->refresh()->estado);
        $this->assertSame($camaraDestinoAntes, $movimientoObjetivo->camara_destino_id);
        $this->assertNotNull($movimientoObjetivo->reservaActiva()->first());
        $movimientoObjetivo = $planes->materializarDestino(
            $movimientoObjetivo,
            $contexto['posicionesObjetivo'][3],
            $operador,
            $dispositivo,
        );
        $this->travelBack();
        $planes->iniciar($movimientoObjetivo, $operador, $dispositivo);
        $movimientos->mover(
            operacionId: (string) Str::uuid(),
            folio: $objetivo,
            posicionDestino: $contexto['posicionesObjetivo'][3],
            sesionOrigen: $sesionOrigen,
            sesionDestino: $sesionDestino,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionOrigenConocida: $contexto['camaraFuera']->refresh()->version_plano,
            versionDestinoConocida: $contexto['camaraObjetivo']->refresh()->version_plano,
            generadoDispositivoAt: now(),
            tareaMovimiento: $movimientoObjetivo->refresh(),
        );

        $retorno = $maniobra->pasos()->where('secuencia_maniobra', 3)->sole();
        $this->assertSame(EstadoTareaMovimiento::Asumida, $retorno->estado);
        try {
            $planes->materializarDestino(
                $retorno,
                $posicionBlocker,
                $operador,
                $dispositivo,
            );
            $this->fail('El servidor aceptó una profundidad distinta de la resultante.');
        } catch (ConflictoOperacion $exception) {
            $this->assertStringContainsString($compute === 'server' ? 'precalculado' : 'profundidad resultante', $exception->getMessage());
        }
        $retorno = $planes->materializarDestino(
            $retorno->refresh(),
            $posicionObjetivo,
            $operador,
            $dispositivo,
        );
        $planes->iniciar($retorno, $operador, $dispositivo);
        if ($falloHistorico) {
            $uuidFallido = (string) Str::uuid();
            $fecha = now()->toImmutable();
            $payload = ['numero_folio' => $blocker->numero_folio, 'tipo_bulto' => 'pallet',
                'camara_destino_id' => $contexto['camaraFuera']->id, 'posicion_destino_id' => $posicionObjetivo->id,
                'sesion_destino_id' => $sesionOrigen->id, 'version_destino_conocida' => $contexto['camaraFuera']->refresh()->version_plano,
                'generado_dispositivo_at' => $fecha->format(DATE_ATOM), 'datos_folio' => [], 'datos_material' => [],
                'advertencias_confirmadas' => [], 'tarea_movimiento_id' => $retorno->id];
            $normalizador = app(NormalizadorTransicionOperacional::class);
            $fallida = TransicionOperacional::create(['dominio' => 'estiba', 'tipo' => 'ubicacion_inicial',
                'operacion_id' => $uuidFallido, 'estado' => 'fallida', 'user_id' => $operador->id, 'dispositivo_id' => $dispositivo->id,
                'payload' => $payload, 'payload_hash' => $normalizador->hash($payload), 'cantidad_cambios' => 0,
                'error_tipo' => DeadlockException::class, 'error_codigo' => 'fallo_no_controlado',
                'error_mensaje' => 'SQLSTATE[40001]: Deadlock found when trying to get lock', 'ocurrido_at' => now(), 'finalizado_at' => now()]);
            $token = $operador->crearTokenParaDispositivo($dispositivo, 'recuperar-retorno')->plainTextToken;
            $this->withToken($token)->postJson('/api/movimientos/ubicar', ['operacion_id' => $uuidFallido, ...$payload])
                ->assertConflict()->assertJsonPath('codigo', 'conflicto_operacional');
            $this->assertSame('en_proceso', $retorno->refresh()->estado->value);
            $this->assertNull($blocker->refresh()->ubicacionActual);
            $this->assertDatabaseHas('custodias_temporales_maniobra', ['folio_id' => $blocker->id, 'estado' => 'activa']);
            $this->assertDatabaseHas('operaciones_sincronizacion', ['id' => $uuidFallido, 'estado' => 'rechazada']);
            $this->assertSame('fallida', $fallida->refresh()->estado->value);
            $this->withToken($token)->getJson('/api/tareas-movimiento/'.$retorno->id.'/estado-propio?operacion_id='.$uuidFallido)
                ->assertOk()->assertJsonPath('data.confirmada', false)->assertJsonPath('data.operacion.estado', 'rechazada');
        }
        $movimientos->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $blocker->numero_folio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicionObjetivo,
            sesionDestino: $sesionOrigen,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionDestinoConocida: $contexto['camaraFuera']->refresh()->version_plano,
            generadoDispositivoAt: now(),
            tareaMovimiento: $retorno->refresh(),
        );

        $this->assertSame(EstadoManiobraOperacional::Completada, $maniobra->refresh()->estado);
        $this->assertSame($posicionObjetivo->id, $blocker->refresh()->ubicacionActual->posicion_id);
        $this->assertDatabaseHas('custodias_temporales_maniobra', [
            'maniobra_operacional_id' => $maniobra->id,
            'folio_id' => $blocker->id,
            'estado' => 'resuelta_retorno',
            'bloqueo_folio_id' => null,
        ]);
        $this->assertDatabaseMissing('reservas_bandas_maniobra', [
            'maniobra_operacional_id' => $maniobra->id,
            'liberada_at' => null,
        ]);
    }

    public static function cambiosDuranteManiobra(): array
    {
        return ['secuencia normal' => [false], 'destino suspendido después de iniciar' => [true]];
    }

    #[DataProvider('cambiosDuranteManiobra')]
    public function test_servidor_prepara_y_completa_cinco_pasos_con_reconexion_y_sin_decisiones_tablet(bool $cambioPosterior): void
    {
        [$contexto, $maniobra, $blockers] = $this->maniobraServidorCincoPasos();
        [$operador, $tablet] = $this->crearOperador();
        $planes = app(ServicioPlanesOperacionales::class);
        $sesiones = app(ServicioSesionEstiba::class);
        $movimientos = app(ServicioMovimientoEstiba::class);
        $primera = $planes->asumir($maniobra->pasos()->orderBy('secuencia_maniobra')->first(), $operador, $tablet);
        $this->assertCount(5, $maniobra->pasos);
        $this->assertSame(3, $maniobra->pasos()->whereNotNull('posicion_destino_id')->count());
        $this->assertDatabaseHas('reservas_bandas_maniobra', [
            'maniobra_operacional_id' => $maniobra->id, 'camara_id' => $contexto['camaraObjetivo']->id,
            'banda' => 1, 'nivel' => 1, 'liberada_at' => null,
        ]);
        $token = $operador->crearTokenParaDispositivo($tablet, 'servidor')->plainTextToken;
        foreach (range(1, 5) as $numero) {
            $paso = $maniobra->pasos()->where('secuencia_maniobra', $numero)->sole();
            $this->assertSame(EstadoTareaMovimiento::Asumida, $paso->estado);
            if ($numero === 2 && $cambioPosterior) {
                $contexto['posicionesObjetivo'][3]->update(['estado' => 'bloqueada']);
            }
            if ($numero === 3) {
                if ($cambioPosterior) {
                    $this->assertDatabaseCount('movimientos', 2);
                    $this->assertDatabaseCount('incidencias_destino_planificador', 1);
                    $this->assertNull($paso->reservaActiva->bloqueo_posicion_id);
                    $contexto['posicionesObjetivo'][3]->update(['estado' => 'activa']);
                }
                // La respuesta del servidor puede perderse: repetir resolver no crea otra reserva.
                $this->travel(30)->minutes();
                $this->assertSame(0, app(ServicioReservasTareasMovimiento::class)->expirarVencidas());
                if (! $cambioPosterior) {
                    $sesiones->abrirParaTarea($contexto['camaraFuera'], $operador, $tablet, $paso);
                }
                $reservaId = $paso->reservaActiva->id;
                foreach ([1, 2] as $_) {
                    $this->withToken($token)->postJson('/api/tareas-movimiento/'.$paso->id.'/resolver-destino')
                        ->assertOk()->assertJsonPath('data.reserva.id', $reservaId)
                        ->assertJsonPath('data.plan.compute', 'server');
                }
                try {
                    $planes->liberar($paso, $operador, $tablet);
                    $this->fail('Se liberó una maniobra con dos pallets bajo custodia.');
                } catch (ConflictoOperacion $error) {
                    $this->assertStringContainsString('realidad física', $error->getMessage());
                }
            }
            $sesionOrigen = $paso->camara_origen_id
                ? $sesiones->abrirParaTarea(Camara::findOrFail($paso->camara_origen_id), $operador, $tablet, $paso) : null;
            $sesionDestino = $paso->camara_destino_id
                ? $sesiones->abrirParaTarea(Camara::findOrFail($paso->camara_destino_id), $operador, $tablet, $paso) : null;
            $paso = $planes->iniciar($paso, $operador, $tablet);
            $operacion = (string) Str::uuid();
            if ($paso->tipo_movimiento === TipoMovimiento::Retiro) {
                $movimientos->retirar(
                    operacionId: $operacion, folio: $paso->folio, sesionOrigen: $sesionOrigen,
                    usuario: $operador, dispositivo: $tablet,
                    versionOrigenConocida: $sesionOrigen->camara->refresh()->version_plano,
                    generadoDispositivoAt: now(), motivo: 'Extracción temporal', tareaMovimiento: $paso,
                );
            } elseif ($paso->tipo_movimiento === TipoMovimiento::UbicacionInicial) {
                $movimientos->ubicar(
                    operacionId: $operacion, numeroFolio: $paso->folio->numero_folio, tipoBulto: TipoBulto::Pallet,
                    posicionDestino: $paso->posicionDestino, sesionDestino: $sesionDestino,
                    usuario: $operador, dispositivo: $tablet,
                    versionDestinoConocida: $sesionDestino->camara->refresh()->version_plano,
                    generadoDispositivoAt: now(), tareaMovimiento: $paso,
                );
            } else {
                $movimientos->mover(
                    operacionId: $operacion, folio: $paso->folio, posicionDestino: $paso->posicionDestino,
                    sesionOrigen: $sesionOrigen, sesionDestino: $sesionDestino, usuario: $operador, dispositivo: $tablet,
                    versionOrigenConocida: $sesionOrigen->camara->refresh()->version_plano,
                    versionDestinoConocida: $sesionDestino->camara->refresh()->version_plano,
                    generadoDispositivoAt: now(), tareaMovimiento: $paso,
                );
            }
        }
        $this->travelBack();
        $this->assertSame(EstadoManiobraOperacional::Completada, $maniobra->refresh()->estado);
        $this->assertSame($contexto['camaraObjetivo']->id, $contexto['foliosFuera'][0]->refresh()->ubicacionActual->camara_id);
        foreach ($blockers as $i => $blocker) {
            $this->assertSame($i + 1, $blocker->refresh()->ubicacionActual->posicion->posicion);
        }
        $this->assertDatabaseCount('movimientos', 5);
        $this->assertDatabaseMissing('custodias_temporales_maniobra', ['estado' => 'activa']);
        $this->assertDatabaseMissing('reservas_bandas_maniobra', ['maniobra_operacional_id' => $maniobra->id, 'liberada_at' => null]);
    }

    public function test_servidor_rechaza_antes_del_primer_retiro_si_el_destino_futuro_quedo_ocupado(): void
    {
        [$contexto, $maniobra] = $this->maniobraServidorCincoPasos();
        foreach (array_slice($contexto['posicionesObjetivo'], 3) as $posicion) {
            $ocupante = Folio::create(['temporada_id' => $contexto['temporada']->id,
                'numero_folio' => 'LLENA-'.$posicion->posicion, 'tipo_bulto' => TipoBulto::Pallet, 'fecha_ingreso' => now(), 'activo' => true]);
            $this->ubicarSinEventos($ocupante, $contexto['camaraObjetivo'], $posicion);
        }
        $contexto['camaraObjetivo']->increment('version_plano');
        [$operador, $tablet] = $this->crearOperador();
        try {
            app(ServicioPlanesOperacionales::class)->asumir($maniobra->pasos()->orderBy('secuencia_maniobra')->first(), $operador, $tablet);
            $this->fail('Se autorizó retirar bloqueadores sin destino para el pallet principal.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('destino', $error->getMessage());
        }
        $this->assertDatabaseCount('movimientos', 0);
        $this->assertDatabaseCount('custodias_temporales_maniobra', 0);
        $this->assertDatabaseCount('reservas_tareas_movimiento', 0);
        $this->assertSame(EstadoManiobraOperacional::Pendiente, $maniobra->refresh()->estado);
    }

    private function maniobraServidorCincoPasos(): array
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 1, sinUbicacion: 1);
        config(['planificador.compute' => 'server']);
        $contexto['camaraFuera']->update(['posiciones_por_banda' => 3]);
        $blockers = [];
        foreach ([2, 3] as $profundidad) {
            $posicion = Posicion::create(['camara_id' => $contexto['camaraFuera']->id,
                'banda' => 1, 'posicion' => $profundidad, 'nivel' => 1, 'etiqueta' => 'B01-P0'.$profundidad.'-N1']);
            $blocker = Folio::create(['temporada_id' => $contexto['temporada']->id,
                'numero_folio' => 'BLOCKER-'.$profundidad, 'tipo_bulto' => TipoBulto::Pallet, 'fecha_ingreso' => now(),
                'activo' => true, 'marca' => 'OTRA', 'exportadora' => 'Otro cliente']);
            $this->ubicarSinEventos($blocker, $contexto['camaraFuera'], $posicion);
            $blockers[] = $blocker;
        }
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar($contexto['carga'], $contexto['usuario']);

        return [$contexto, $plan->maniobras()->sole(), $blockers];
    }

    public function test_no_coincide_con_blocker_extraido_rechaza_cancelar_y_reanuda_protegiendo_banda(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 1, sinUbicacion: 1);
        $objetivo = $contexto['foliosFuera'][0];
        $posicionObjetivo = $objetivo->ubicacionActual->posicion;
        $posicionBlocker = Posicion::create([
            'camara_id' => $contexto['camaraFuera']->id,
            'banda' => $posicionObjetivo->banda,
            'posicion' => 2,
            'nivel' => 1,
            'etiqueta' => 'B01-P02-N1',
        ]);
        $blocker = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'PAL-254-DISCREPANCIA',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
            'activo' => true,
            'marca' => 'OTRA',
            'exportadora' => 'Otro cliente',
        ]);
        $this->ubicarSinEventos($blocker, $contexto['camaraFuera'], $posicionBlocker);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $maniobra = $plan->maniobras()->sole();
        [$operador, $dispositivo] = $this->crearOperador();
        $sesionOrigen = app(ServicioSesionEstiba::class)->abrir(
            $contexto['camaraFuera'],
            $operador,
            $dispositivo,
        );
        $planes = app(ServicioPlanesOperacionales::class);
        $extraccion = $maniobra->pasos()->where('secuencia_maniobra', 1)->sole();
        $planes->asumir($extraccion, $operador, $dispositivo);
        $planes->iniciar($extraccion->refresh(), $operador, $dispositivo);
        app(ServicioMovimientoEstiba::class)->retirar(
            operacionId: (string) Str::uuid(),
            folio: $blocker,
            sesionOrigen: $sesionOrigen,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionOrigenConocida: $contexto['camaraFuera']->refresh()->version_plano,
            generadoDispositivoAt: now(),
            motivo: 'Extracción temporal antes de discrepancia.',
            tareaMovimiento: $extraccion->refresh(),
        );

        $siguiente = $maniobra->pasos()->where('secuencia_maniobra', 2)->sole();
        $discrepancia = app(ServicioManiobrasOperacionales::class)->reportarDiscrepancia(
            $siguiente,
            $operador,
            $dispositivo,
            'obstaculo',
            'El destino físico no puede utilizarse.',
        );

        $this->assertSame(
            EstadoManiobraOperacional::PausadaDiscrepancia,
            $maniobra->refresh()->estado,
        );
        $this->assertSame(EstadoTareaMovimiento::Bloqueada, $siguiente->refresh()->estado);
        $this->assertDatabaseHas('custodias_temporales_maniobra', [
            'maniobra_operacional_id' => $maniobra->id,
            'folio_id' => $blocker->id,
            'estado' => 'activa',
        ]);
        $this->assertDatabaseHas('reservas_bandas_maniobra', [
            'maniobra_operacional_id' => $maniobra->id,
            'camara_id' => $contexto['camaraFuera']->id,
            'banda' => $posicionObjetivo->banda,
            'nivel' => $posicionObjetivo->nivel,
            'liberada_at' => null,
        ]);

        $version = $maniobra->refresh()->version;
        $ruta = "/api/discrepancias-maniobra/{$discrepancia->id}/resolver";
        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, [
                'accion' => 'cancelar_maniobra',
                'version_maniobra' => $version,
                'resolucion' => 'El blocker ya está fuera de su ubicación.',
            ])
            ->assertConflict();

        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, [
                'accion' => 'reanudar_maniobra',
                'version_maniobra' => $version,
                'resolucion' => 'Se mantiene la secuencia para devolver el blocker.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'resuelta')
            ->assertJsonPath('data.maniobra.estado', 'en_ejecucion')
            ->assertJsonPath('data.tarea.estado', 'asumida');

        $this->assertSame(EstadoCustodiaTemporal::Activa, $maniobra
            ->custodiasTemporales()
            ->sole()
            ->estado);
        $this->assertSame($operador->id, $siguiente->refresh()->responsable_user_id);
        $this->assertSame($dispositivo->id, $siguiente->dispositivo_id);
        $this->assertNotNull($siguiente->reservaActiva()->first());
        $this->assertDatabaseHas('reservas_bandas_maniobra', [
            'maniobra_operacional_id' => $maniobra->id,
            'liberada_at' => null,
        ]);
    }

    public function test_no_permite_publicar_extraccion_temporal_sin_resolucion(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 1, sinUbicacion: 1);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $folio = $contexto['foliosFuera'][0];
        $posicion = $folio->ubicacionActual->posicion;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('pallets temporales sin retorno');
        app(ServicioManiobrasOperacionales::class)->crearCerrada(
            $plan,
            $contexto['usuario'],
            [
                'candidate_key' => 'maniobra-invalida-sin-retorno',
                'titulo' => 'Maniobra inválida',
                'pasos' => [[
                    'folio_id' => $folio->id,
                    'tipo_movimiento' => TipoMovimiento::Retiro,
                    'tipo_paso_maniobra' => TipoPasoManiobra::ExtraccionTemporal,
                    'prioridad' => PrioridadOperacional::Normal,
                    'camara_origen_id' => $posicion->camara_id,
                    'posicion_origen_id' => $posicion->id,
                ]],
            ],
        );
    }

    public function test_frontier_cuatro_limita_maniobras_y_no_sus_pasos_internos(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 4, fuera: 6);
        $this->habilitarCuatroDestinosVecinos($contexto);

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertNotNull($plan);
        $this->assertSame(4, $plan->maniobras()->count());
        $this->assertSame(4, $plan->tareas()->count());
        $this->assertTrue($plan->maniobras()->get()->every(
            fn ($maniobra): bool => $maniobra->costo_movimientos === 1,
        ));
    }

    public function test_solo_tres_maniobras_pueden_quedar_asumidas_simultaneamente(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 4, fuera: 6);
        $this->habilitarCuatroDestinosVecinos($contexto);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $tareas = $plan->tareas()->orderBy('secuencia')->get();
        $servicio = app(ServicioPlanesOperacionales::class);

        foreach ($tareas->take(3) as $tarea) {
            [$operador, $dispositivo] = $this->crearOperador();
            $servicio->asumir($tarea, $operador, $dispositivo);
        }

        [$cuartoOperador, $cuartaTablet] = $this->crearOperador();
        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('tres maniobras asumidas');
        $servicio->asumir($tareas[3], $cuartoOperador, $cuartaTablet);
    }

    public function test_un_pallet_no_puede_pertenecer_a_dos_maniobras_activas(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $existente = $plan->tareas()->sole();

        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('ya posee otra labor activa');
        app(ServicioManiobrasOperacionales::class)->crearCerrada(
            $plan,
            $contexto['usuario'],
            [
                'candidate_key' => 'duplicada:'.$existente->folio_id,
                'titulo' => 'Maniobra duplicada',
                'pasos' => [[
                    'folio_id' => $existente->folio_id,
                    'tipo_movimiento' => $existente->tipo_movimiento,
                    'tipo_paso_maniobra' => TipoPasoManiobra::MovimientoPermanente,
                    'camara_origen_id' => $existente->camara_origen_id,
                    'posicion_origen_id' => $existente->posicion_origen_id,
                    'camara_destino_id' => $existente->camara_destino_id,
                    'posicion_destino_id' => $existente->posicion_destino_id,
                ]],
            ],
        );
    }

    public function test_dos_camareros_no_pueden_asumir_maniobras_de_la_misma_banda_protegida(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 1, sinUbicacion: 1);
        $objetivo = $contexto['foliosFuera'][0];
        $origen = $objetivo->ubicacionActual->posicion;
        $posicionBlocker = Posicion::create([
            'camara_id' => $contexto['camaraFuera']->id,
            'banda' => $origen->banda,
            'posicion' => 2,
            'nivel' => $origen->nivel,
            'etiqueta' => 'B01-P02-N1-BLOQUEO',
        ]);
        $blocker = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'PAL-254-BANDA-A',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $this->ubicarSinEventos($blocker, $contexto['camaraFuera'], $posicionBlocker);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $primera = $plan->maniobras()->sole();

        $posicionAlterna = Posicion::create([
            'camara_id' => $contexto['camaraFuera']->id,
            'banda' => 2,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B02-P01-N1-ALTERNA',
        ]);
        $alterno = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'PAL-254-BANDA-B',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $this->ubicarSinEventos($alterno, $contexto['camaraFuera'], $posicionAlterna);
        $segunda = app(ServicioManiobrasOperacionales::class)->crearCerrada(
            $plan,
            $contexto['usuario'],
            [
                'candidate_key' => 'banda-incompatible:'.$alterno->id,
                'titulo' => 'Alternativa incompatible',
                'bloqueos_banda' => [[
                    'camara_id' => $origen->camara_id,
                    'banda' => $origen->banda,
                    'nivel' => $origen->nivel,
                ]],
                'pasos' => [[
                    'folio_id' => $alterno->id,
                    'tipo_movimiento' => TipoMovimiento::TrasladoEntreCamaras,
                    'tipo_paso_maniobra' => TipoPasoManiobra::MovimientoPermanente,
                    'camara_origen_id' => $posicionAlterna->camara_id,
                    'posicion_origen_id' => $posicionAlterna->id,
                    'camara_destino_id' => $contexto['camaraObjetivo']->id,
                    'posicion_destino_id' => null,
                ]],
            ],
        );
        [$operadorA, $tabletA] = $this->crearOperador();
        [$operadorB, $tabletB] = $this->crearOperador();
        $servicio = app(ServicioPlanesOperacionales::class);
        $servicio->asumir($primera->pasos()->firstOrFail(), $operadorA, $tabletA);

        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('banda requerida');
        $servicio->asumir($segunda->pasos()->firstOrFail(), $operadorB, $tabletB);
    }

    public function test_una_maniobra_puede_superar_cuatro_movimientos_para_cerrar_fisicamente(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 1, sinUbicacion: 1);
        $objetivo = $contexto['foliosFuera'][0];
        $posicionObjetivo = $objetivo->ubicacionActual->posicion;
        $contexto['camaraFuera']->update(['posiciones_por_banda' => 4]);

        for ($profundidad = 2; $profundidad <= 4; $profundidad++) {
            $posicion = Posicion::create([
                'camara_id' => $contexto['camaraFuera']->id,
                'banda' => $posicionObjetivo->banda,
                'posicion' => $profundidad,
                'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $profundidad),
            ]);
            $blocker = Folio::create([
                'temporada_id' => $contexto['temporada']->id,
                'numero_folio' => sprintf('PAL-254-BLOCKER-%02d', $profundidad),
                'tipo_bulto' => TipoBulto::Pallet,
                'fecha_ingreso' => now(),
                'activo' => true,
                'marca' => 'OTRA',
                'exportadora' => 'Otro cliente',
            ]);
            $this->ubicarSinEventos($blocker, $contexto['camaraFuera'], $posicion);
        }

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertNotNull($plan);
        $this->assertSame(1, $plan->maniobras()->count());
        $maniobra = $plan->maniobras()->sole();
        $pasos = $maniobra->pasos()->get();
        $this->assertSame(7, $maniobra->costo_movimientos);
        $this->assertSame(7, $pasos->count());
        $this->assertSame(range(1, 7), $pasos->pluck('secuencia_maniobra')->all());
        $this->assertSame(
            3,
            $pasos->where('tipo_paso_maniobra', TipoPasoManiobra::ExtraccionTemporal)->count(),
        );
        $this->assertSame(
            3,
            $pasos->where('tipo_paso_maniobra', TipoPasoManiobra::RetornoBanda)->count(),
        );
        $this->assertSame($objetivo->id, $pasos[3]->folio_id);
    }

    public function test_no_coincide_pausa_la_maniobra_antes_del_punto_de_no_retorno(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $tarea = $plan->tareas()->sole();
        [$operador, $dispositivo] = $this->crearOperador();
        app(ServicioPlanesOperacionales::class)->asumir($tarea, $operador, $dispositivo);

        $discrepancia = app(ServicioManiobrasOperacionales::class)->reportarDiscrepancia(
            $tarea->refresh(),
            $operador,
            $dispositivo,
            'pallet_no_coincide',
            'El folio visible no corresponde al patrón.',
        );

        $this->assertSame('abierta', $discrepancia->estado->value);
        $this->assertSame(EstadoTareaMovimiento::Bloqueada, $tarea->refresh()->estado);
        $this->assertSame(
            EstadoManiobraOperacional::PausadaDiscrepancia,
            $tarea->maniobraOperacional->refresh()->estado,
        );
        $this->assertNull($tarea->reservaActiva()->first());
        $this->actingAs($operador, 'sanctum')
            ->getJson('/api/tareas-movimiento?asignacion=mias')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tarea->id)
            ->assertJsonPath('data.0.estado', 'bloqueada')
            ->assertJsonPath('data.0.maniobra.estado', 'pausada_discrepancia');

        $version = $tarea->maniobraOperacional->refresh()->version;
        $ruta = "/api/discrepancias-maniobra/{$discrepancia->id}/resolver";
        $payload = [
            'accion' => 'reanudar_maniobra',
            'version_maniobra' => $version,
            'resolucion' => 'Supervisión verificó el patrón en terreno.',
        ];
        $this->actingAs($operador, 'sanctum')
            ->postJson($ruta, $payload)
            ->assertForbidden();
        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, [...$payload, 'version_maniobra' => $version - 1])
            ->assertConflict();

        $respuesta = $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.estado', 'resuelta')
            ->assertJsonPath('data.accion_resolucion', 'reanudar_maniobra')
            ->assertJsonPath('data.maniobra.estado', 'pendiente')
            ->assertJsonPath('data.tarea.estado', 'pendiente');
        $resueltaAt = $respuesta->json('data.resuelta_at');

        $this->assertNull($tarea->refresh()->responsable_user_id);
        $this->assertNull($tarea->dispositivo_id);
        $this->assertNull($tarea->reservaActiva()->first());
        $this->assertNull($tarea->maniobraOperacional->refresh()->responsable_user_id);
        $this->assertNull($tarea->maniobraOperacional->dispositivo_id);
        $this->actingAs($operador, 'sanctum')
            ->getJson('/api/tareas-movimiento?asignacion=mias')
            ->assertOk()
            ->assertJsonMissing(['id' => $tarea->id]);
        $this->assertDatabaseHas('discrepancias_maniobra', [
            'id' => $discrepancia->id,
            'accion_resolucion' => 'reanudar_maniobra',
            'resuelta_por_user_id' => $contexto['usuario']->id,
        ]);
        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.resuelta_at', $resueltaAt);
    }

    public function test_supervision_cancela_una_maniobra_reversible_tras_no_coincide(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $tarea = $plan->tareas()->sole();
        [$operador, $dispositivo] = $this->crearOperador();
        app(ServicioPlanesOperacionales::class)->asumir($tarea, $operador, $dispositivo);
        $discrepancia = app(ServicioManiobrasOperacionales::class)->reportarDiscrepancia(
            $tarea->refresh(),
            $operador,
            $dispositivo,
            'posicion_vacia',
        );
        $maniobra = $tarea->maniobraOperacional->refresh();

        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson("/api/discrepancias-maniobra/{$discrepancia->id}/resolver", [
                'accion' => 'cancelar_maniobra',
                'version_maniobra' => $maniobra->version,
                'resolucion' => 'El estado físico cambió y requiere un cálculo nuevo.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'resuelta')
            ->assertJsonPath('data.accion_resolucion', 'cancelar_maniobra')
            ->assertJsonPath('data.maniobra.estado', 'cancelada')
            ->assertJsonPath('data.tarea.estado', 'cancelada');

        $this->assertSame(EstadoManiobraOperacional::Cancelada, $maniobra->refresh()->estado);
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $tarea->refresh()->estado);
    }

    public function test_no_coincide_en_proceso_solo_reanuda_con_el_mismo_actor_y_reserva(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $tarea = $plan->tareas()->sole();
        [$operador, $dispositivo] = $this->crearOperador();
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->asumir($tarea, $operador, $dispositivo);
        $planes->materializarDestino(
            $tarea->refresh(),
            $contexto['posicionesObjetivo'][7],
            $operador,
            $dispositivo,
        );
        $planes->iniciar($tarea->refresh(), $operador, $dispositivo);
        $reservaId = $tarea->reservaActiva()->sole()->id;
        $discrepancia = app(ServicioManiobrasOperacionales::class)->reportarDiscrepancia(
            $tarea->refresh(),
            $operador,
            $dispositivo,
            'posicion_no_coincide',
        );
        $maniobra = $tarea->maniobraOperacional->refresh();
        $ruta = "/api/discrepancias-maniobra/{$discrepancia->id}/resolver";

        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, [
                'accion' => 'cancelar_maniobra',
                'version_maniobra' => $maniobra->version,
                'resolucion' => 'No se debe poder cancelar este movimiento.',
            ])
            ->assertConflict();
        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, [
                'accion' => 'replanificar_sufijo',
                'version_maniobra' => $maniobra->version,
                'resolucion' => 'No se debe poder recalcular con un pallet en movimiento.',
            ])
            ->assertConflict();
        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, [
                'accion' => 'retorno_seguro',
                'version_maniobra' => $maniobra->version,
                'resolucion' => 'No se debe poder iniciar otra ruta con un pallet en movimiento.',
            ])
            ->assertConflict();
        $this->actingAs($contexto['usuario'], 'sanctum')
            ->postJson($ruta, [
                'accion' => 'reanudar_maniobra',
                'version_maniobra' => $maniobra->version,
                'resolucion' => 'El camarero debe terminar el movimiento iniciado.',
            ])
            ->assertOk()
            ->assertJsonPath('data.maniobra.estado', 'en_ejecucion')
            ->assertJsonPath('data.tarea.estado', 'en_proceso');

        $this->assertSame($operador->id, $maniobra->refresh()->responsable_user_id);
        $this->assertSame($dispositivo->id, $maniobra->dispositivo_id);
        $this->assertSame($reservaId, $tarea->reservaActiva()->sole()->id);
    }

    public function test_camion_en_anden_cancela_concentracion_reversible(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $maniobra = $plan?->maniobras()->sole();
        $this->assertNotNull($maniobra);
        $this->crearPresenciaAnden($contexto);

        app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertSame(EstadoManiobraOperacional::Cancelada, $maniobra->refresh()->estado);
        $this->assertTrue($plan->refresh()->contexto['suspendido_por_anden']);
    }

    public function test_camion_en_anden_no_reescribe_un_movimiento_ya_iniciado(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $tarea = $plan->tareas()->sole();
        [$operador, $dispositivo] = $this->crearOperador();
        $servicio = app(ServicioPlanesOperacionales::class);
        $tarea = $servicio->asumir($tarea, $operador, $dispositivo);
        $tarea = $servicio->materializarDestino(
            $tarea,
            $contexto['posicionesObjetivo'][7],
            $operador,
            $dispositivo,
        );
        $destinoId = $tarea->posicion_destino_id;
        $servicio->iniciar($tarea, $operador, $dispositivo);
        $this->crearPresenciaAnden($contexto);

        app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertSame(EstadoTareaMovimiento::EnProceso, $tarea->refresh()->estado);
        $this->assertSame($destinoId, $tarea->posicion_destino_id);
        $this->assertSame(
            EstadoManiobraOperacional::EnEjecucion,
            $tarea->maniobraOperacional->refresh()->estado,
        );
        $this->assertTrue($plan->refresh()->contexto['suspendido_por_anden']);
    }

    public function test_pallets_sin_ubicacion_siguen_contando_en_el_denominador_del_umbral(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 0, sinUbicacion: 2);

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertNotNull($plan);
        $this->assertSame(5, $plan->contexto['total']);
        $this->assertSame(3, $plan->contexto['concentrados']);
        $this->assertSame(60, $plan->contexto['porcentaje_actual']);
        $this->assertFalse($plan->estado->esFinal());
        $this->assertSame(0, $plan->tareas()->count());
    }

    public function test_recepcion_comparte_acopio_y_objetivo_sin_duplicar_la_tarea_fisica(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 0, sinUbicacion: 2);
        $recepcion = $this->crearRecepcion($contexto);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar($contexto['carga'], $contexto['usuario']);

        $this->assertSame(0, $plan->tareas()->count());
        $this->assertSame(2, $recepcion->tareas()->count());
        $this->assertSame(2, $plan->contexto['recepciones_pendientes']);
        $this->assertSame(2, $plan->maniobrasResolutorias()->count());
        foreach ($recepcion->tareas()->get() as $tarea) {
            $this->assertSame($contexto['camaraObjetivo']->id, $tarea->camara_destino_id);
            $this->assertSame($contexto['carga']->id, $tarea->contexto['acopio_carga']['carga_id']);
            $this->assertCount(3, $tarea->contexto['acopio_carga']['puntos']);
            $this->assertSame(1, $tarea->maniobraOperacional->costo_movimientos);
            $this->assertSame(2, $tarea->maniobraOperacional->objetivos()->count());
        }
        app(ServicioPlanConcentracionCarga::class)->sincronizar($contexto['carga'], $contexto['usuario']);
        $this->assertSame(2, $plan->maniobrasResolutorias()->count());
        $this->assertDatabaseCount('tareas_movimiento', 2);
    }

    public function test_dos_tablets_extienden_el_acopio_comprometido_y_rechazan_otra_camara_o_un_hueco(): void
    {
        $contexto = $this->crearContexto(total: 5, concentrados: 3, fuera: 0, sinUbicacion: 2);
        $recepcion = $this->crearRecepcion($contexto);
        $planes = app(ServicioPlanesOperacionales::class);
        [$a, $tabletA] = $this->crearOperador();
        [$b, $tabletB] = $this->crearOperadorAdicional();
        [$primera, $segunda] = $recepcion->tareas()->get()->all();
        $primera = $planes->asumir($primera, $a, $tabletA);
        $segunda = $planes->asumir($segunda, $b, $tabletB);
        $planes->materializarDestino($primera, $contexto['posicionesObjetivo'][3], $a, $tabletA);

        foreach ([$contexto['posicionesFuera'][0], $contexto['posicionesObjetivo'][8]] as $invalida) {
            try {
                $planes->materializarDestino($segunda, $invalida, $b, $tabletB);
                $this->fail('La recepción aceptó un destino ajeno al acopio de la carga.');
            } catch (ConflictoOperacion $error) {
                $this->assertStringContainsString('acopio', $error->getMessage());
            }
        }
        // P5 ya es vecina de P4 reservada por A, aunque A aún no la haya recibido.
        $materializada = $planes->materializarDestino($segunda, $contexto['posicionesObjetivo'][4], $b, $tabletB);
        $this->assertSame($contexto['posicionesObjetivo'][4]->id, $materializada->posicion_destino_id);
        $this->assertSame(3, UbicacionActual::query()->count());
        $this->assertSame(2, ReservaTareaMovimiento::query()->whereNotNull('bloqueo_posicion_id')->count());
    }

    public function test_primera_recepcion_establece_un_solo_acopio_aun_sin_camara_objetivo(): void
    {
        $contexto = $this->crearContexto(total: 2, concentrados: 0, fuera: 0, sinUbicacion: 2);
        $contexto['carga']->update(['camara_objetivo_id' => null]);
        $recepcion = $this->crearRecepcion($contexto);
        $concentracion = app(ServicioPlanConcentracionCarga::class)->sincronizar($contexto['carga'], $contexto['usuario']);
        $this->assertNotNull($concentracion);
        // Sin cámara objetivo, el acopio se estima antes del primer pallet en la cámara que
        // tiene bandas libres para la carga completa (la de pallets dispersos tiene una posición).
        $this->assertSame($contexto['camaraObjetivo']->id, $concentracion->contexto['camara_objetivo_id']);
        [$a, $tabletA] = $this->crearOperador();
        [$b, $tabletB] = $this->crearOperadorAdicional();
        $planes = app(ServicioPlanesOperacionales::class);
        [$primera, $segunda] = $recepcion->tareas()->get()->all();
        $primera = $planes->asumir($primera, $a, $tabletA);
        $segunda = $planes->asumir($segunda, $b, $tabletB);
        $planes->materializarDestino($primera, $contexto['posicionesObjetivo'][0], $a, $tabletA);
        $objetivo = app(ServicioAcopioCarga::class)->paraFolio($segunda->folio_id, $segunda->id);
        $this->assertSame($contexto['camaraObjetivo']->id, $objetivo['camara_objetivo_id']);
        $this->assertCount(1, $objetivo['puntos']);
        $this->expectException(ConflictoOperacion::class);
        $planes->materializarDestino($segunda, $contexto['posicionesFuera'][0], $b, $tabletB);
    }

    public function test_entrega_de_recepcion_avanza_ambos_objetivos_con_un_solo_movimiento(): void
    {
        $contexto = $this->crearContexto(total: 4, concentrados: 3, fuera: 0, sinUbicacion: 1);
        $folio = $contexto['folios'][3];
        $folio->update([
            'estado_operacional' => 'pendiente_ubicacion',
            'condicion_termica' => 'prefrio_aprobado',
            'habilitacion_almacenamiento' => 'habilitado',
        ]);
        $recepcion = $this->crearRecepcion($contexto);
        $carga = app(ServicioPlanConcentracionCarga::class)->sincronizar($contexto['carga'], $contexto['usuario']);
        [$operador, $tablet] = $this->crearOperador();
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($recepcion->tareas()->sole(), $operador, $tablet);
        $tarea = $planes->materializarDestino($tarea, $contexto['posicionesObjetivo'][3], $operador, $tablet);
        $tarea = $planes->iniciar($tarea, $operador, $tablet);
        $sesion = app(ServicioSesionEstiba::class)->abrir($contexto['camaraObjetivo'], $operador, $tablet);
        $proyector = app(ServicioPlanConcentracionCarga::class);
        $doble = \Mockery::mock(ServicioPlanConcentracionCarga::class);
        $doble->shouldNotReceive('sincronizar');
        app()->instance(ServicioPlanConcentracionCarga::class, $doble);
        app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(), numeroFolio: $folio->numero_folio, tipoBulto: TipoBulto::Pallet,
            posicionDestino: $contexto['posicionesObjetivo'][3], sesionDestino: $sesion,
            usuario: $operador, dispositivo: $tablet,
            versionDestinoConocida: $contexto['camaraObjetivo']->refresh()->version_plano,
            generadoDispositivoAt: now(), tareaMovimiento: $tarea,
        );
        app()->instance(ServicioPlanConcentracionCarga::class, $proyector);
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'tipo' => ServicioRecalculosPendientesPlanificador::CARGA,
            'fuente_id' => $contexto['carga']->id, 'pendiente' => true,
        ]);
        app(ServicioPlanConcentracionCarga::class)->sincronizar($contexto['carga'], $contexto['usuario']);
        $this->assertSame('completado', $recepcion->refresh()->estado->value);
        $this->assertSame('completado', $carga->refresh()->estado->value);
        $this->assertSame(100, $carga->contexto['porcentaje_actual']);
        $this->assertSame(0, $carga->contexto['recepciones_pendientes']);
        $this->assertDatabaseCount('movimientos', 1);
        $this->assertDatabaseCount('tareas_movimiento', 1);
    }

    public function test_cambio_de_camara_antes_de_iniciar_rechaza_la_reserva_antigua_pero_respeta_movimiento_iniciado(): void
    {
        $contexto = $this->crearContexto(total: 4, concentrados: 3, fuera: 0, sinUbicacion: 1);
        $recepcion = $this->crearRecepcion($contexto);
        [$operador, $tablet] = $this->crearOperador();
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($recepcion->tareas()->sole(), $operador, $tablet);
        $tarea = $planes->materializarDestino($tarea, $contexto['posicionesObjetivo'][3], $operador, $tablet);
        $contexto['carga']->update(['camara_objetivo_id' => $contexto['camaraFuera']->id]);
        try {
            $planes->iniciar($tarea, $operador, $tablet);
            $this->fail('Se inició una entrega hacia una cámara que ya no corresponde.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('acopio', $error->getMessage());
        }
        $contexto['carga']->update(['camara_objetivo_id' => $contexto['camaraObjetivo']->id]);
        $tarea = $planes->iniciar($tarea, $operador, $tablet);
        $contexto['carga']->update(['camara_objetivo_id' => $contexto['camaraFuera']->id]);
        app(ServicioPlanConcentracionCarga::class)->sincronizar($contexto['carga'], $contexto['usuario']);
        $this->assertSame(EstadoTareaMovimiento::EnProceso, $tarea->refresh()->estado);
        $this->assertSame($contexto['posicionesObjetivo'][3]->id, $tarea->posicion_destino_id);
        $this->assertSame($operador->id, $tarea->responsable_user_id);
    }

    public function test_packing_asignado_despues_de_generar_recepcion_incorpora_la_carga_al_asumir(): void
    {
        $contexto = $this->crearContexto(total: 4, concentrados: 3, fuera: 0, sinUbicacion: 1);
        $folio = $contexto['folios'][3];
        $folio->update(['origen_sistema' => 'recepcion_externa']);
        ReservaCargaFolio::query()->where('folio_id', $folio->id)->delete();
        $recepcion = $this->crearRecepcion($contexto, TipoPlanOperacional::AlmacenamientoPallet);
        $this->assertNull($recepcion->tareas()->sole()->camara_destino_id);
        $asignacion = CargaFolio::query()->where('folio_id', $folio->id)->sole();
        ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);
        [$operador, $tablet] = $this->crearOperador();
        $tarea = app(ServicioPlanesOperacionales::class)->asumir($recepcion->tareas()->sole(), $operador, $tablet);
        $this->assertSame($contexto['camaraObjetivo']->id, $tarea->camara_destino_id);
        $this->assertSame($contexto['carga']->id, $tarea->contexto['acopio_carga']['carga_id']);
        $this->assertSame(TipoPlanOperacional::AlmacenamientoPallet, $tarea->planOperacional->tipo);
        $this->assertSame(2, $tarea->maniobraOperacional->objetivos()->count());
        $this->assertDatabaseCount('tareas_movimiento', 1);
    }

    public function test_carga_reasignada_antes_del_claim_sustituye_solo_el_objetivo_secundario(): void
    {
        $contexto = $this->crearContexto(total: 4, concentrados: 3, fuera: 0, sinUbicacion: 1);
        $recepcion = $this->crearRecepcion($contexto);
        app(ServicioPlanConcentracionCarga::class)->sincronizar($contexto['carga'], $contexto['usuario']);
        $folio = $contexto['folios'][3];
        $otra = Carga::create([
            'temporada_id' => $contexto['temporada']->id, 'codigo' => 'CAR-COORD-OTRA',
            'estado' => EstadoCarga::Pendiente, 'prioridad' => PrioridadCarga::Normal,
            'camara_objetivo_id' => $contexto['camaraFuera']->id, 'creada_por_user_id' => $contexto['usuario']->id,
            'actualizada_por_user_id' => $contexto['usuario']->id,
        ]);
        $asignacion = CargaFolio::create([
            'carga_id' => $otra->id, 'folio_id' => $folio->id, 'estado' => EstadoCargaFolio::Pendiente,
            'asignado_por_user_id' => $contexto['usuario']->id, 'asignado_at' => now(),
        ]);
        ReservaCargaFolio::query()->where('folio_id', $folio->id)->update(['carga_folio_id' => $asignacion->id]);
        [$operador, $tablet] = $this->crearOperador();
        $tarea = app(ServicioPlanesOperacionales::class)->asumir($recepcion->tareas()->sole(), $operador, $tablet);
        $objetivos = $tarea->maniobraOperacional->objetivos()->get();
        $this->assertSame($otra->id, $tarea->contexto['acopio_carga']['carga_id']);
        $this->assertSame($contexto['camaraFuera']->id, $tarea->camara_destino_id);
        $this->assertSame(2, $objetivos->count());
        $this->assertTrue($objetivos->contains('id', $recepcion->id));
        $this->assertFalse($objetivos->contains('referencia_id', $contexto['carga']->id));
        $this->assertTrue($objetivos->contains('referencia_id', $otra->id));
    }

    public function test_un_destino_de_carga_no_habilita_almacenar_un_pallet_sin_aprobacion_termica(): void
    {
        $contexto = $this->crearContexto(total: 4, concentrados: 3, fuera: 0, sinUbicacion: 1);
        $folio = $contexto['folios'][3];
        $folio->update(['condicion_termica' => 'pendiente_prefrio', 'habilitacion_almacenamiento' => 'no_habilitado']);
        $recepcion = $this->crearRecepcion($contexto);
        [$operador, $tablet] = $this->crearOperador();
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($recepcion->tareas()->sole(), $operador, $tablet);
        $tarea = $planes->materializarDestino($tarea, $contexto['posicionesObjetivo'][3], $operador, $tablet);
        $sesion = app(ServicioSesionEstiba::class)->abrir($contexto['camaraObjetivo'], $operador, $tablet);
        try {
            app(ServicioMovimientoEstiba::class)->ubicar(
                operacionId: (string) Str::uuid(), numeroFolio: $folio->numero_folio, tipoBulto: TipoBulto::Pallet,
                posicionDestino: $contexto['posicionesObjetivo'][3], sesionDestino: $sesion,
                usuario: $operador, dispositivo: $tablet,
                versionDestinoConocida: $contexto['camaraObjetivo']->refresh()->version_plano,
                generadoDispositivoAt: now(), tareaMovimiento: $tarea,
            );
            $this->fail('La previsión de la carga habilitó almacenamiento sin autorización térmica.');
        } catch (DomainException) {
            $this->assertDatabaseCount('movimientos', 0);
            $this->assertSame(EstadoTareaMovimiento::Asumida, $tarea->refresh()->estado);
        }
    }

    public function test_asignacion_retirada_despues_del_claim_se_puede_liberar_y_retomar_sin_objetivo_obsoleto(): void
    {
        $contexto = $this->crearContexto(total: 4, concentrados: 3, fuera: 0, sinUbicacion: 1);
        $recepcion = $this->crearRecepcion($contexto);
        [$operador, $tablet] = $this->crearOperador();
        $revision = app(RevisionCargaOperacional::class);
        $antes = $revision->calcular();
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($recepcion->tareas()->sole(), $operador, $tablet);
        $this->assertNotSame($antes, $revision->calcular());
        ReservaCargaFolio::query()->where('folio_id', $tarea->folio_id)->delete();
        try {
            $planes->materializarDestino($tarea, $contexto['posicionesObjetivo'][3], $operador, $tablet);
            $this->fail('El claim siguió asociado a una carga retirada.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('libere y vuelva a tomar', $error->getMessage());
        }
        $tarea = $planes->liberar($tarea, $operador, $tablet);
        $tarea = $planes->asumir($tarea, $operador, $tablet);
        $this->assertNull($tarea->contexto['acopio_carga']);
        $this->assertSame(1, $tarea->maniobraOperacional->objetivos()->count());
        $tarea = $planes->materializarDestino($tarea, $contexto['posicionesFuera'][0], $operador, $tablet);
        $this->assertSame($contexto['posicionesFuera'][0]->id, $tarea->posicion_destino_id);
    }

    public function test_snapshot_de_25_recepciones_comparte_consultas_y_conserva_el_acopio_individual(): void
    {
        $contexto = $this->crearContexto(total: 26, concentrados: 1, fuera: 0, sinUbicacion: 25);
        $recepcion = $this->crearRecepcion($contexto);
        $tareas = $recepcion->tareas()->get();
        $acopio = app(ServicioAcopioCarga::class);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $acopio->paraTareas($tareas->take(1));
            $consultasUna = count(DB::getQueryLog());
            $tareas->each->unsetRelation('planOperacional');
            DB::flushQueryLog();
            $resultado = $acopio->paraTareas($tareas);
            $consultasTodas = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(25, $resultado);
        $this->assertSame($consultasUna, $consultasTodas, 'El número de consultas no debe crecer por pallet de la misma carga.');
        $this->assertLessThanOrEqual(15, $consultasTodas);
        foreach ($tareas as $tarea) {
            $this->assertSame($acopio->paraFolio($tarea->folio_id, $tarea->id), $resultado[$tarea->id]);
        }
    }

    private function crearRecepcion(array $contexto, TipoPlanOperacional $tipo = TipoPlanOperacional::RecepcionTunel): PlanOperacional
    {
        $folios = collect($contexto['folios'])->filter(fn (Folio $folio): bool => ! $folio->ubicacionActual()->exists());

        return app(ServicioPlanesOperacionales::class)->crear(
            temporada: $contexto['temporada'], tipo: $tipo,
            titulo: 'Recibir túnel coordinado', creadoPor: $contexto['usuario'],
            tareas: $folios->map(fn (Folio $folio): array => [
                'folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
            ])->values()->all(), contexto: ['planner_horizon' => 'rolling'],
        );
    }

    private function crearOperadorAdicional(): array
    {
        return [User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]),
            Dispositivo::create(['codigo' => 'TABLET-COORD-B', 'nombre' => 'Tablet B', 'activo' => true])];
    }

    public function test_servidor_rechaza_destino_que_no_amplia_el_grupo_y_acepta_un_vecino(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        $tarea = $plan->tareas()->sole();
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-254',
            'nombre' => 'Tablet concentración 254',
            'activo' => true,
        ]);
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $servicio = app(ServicioPlanesOperacionales::class);
        $tarea = $servicio->asumir($tarea, $operador, $dispositivo);
        $lejana = $contexto['posicionesObjetivo'][9];
        $vecina = $contexto['posicionesObjetivo'][7];

        try {
            $servicio->materializarDestino(
                $tarea,
                $lejana,
                $operador,
                $dispositivo,
            );
            $this->fail('El servidor aceptó una posición que no amplía el grupo principal.');
        } catch (ConflictoOperacion $exception) {
            $this->assertStringContainsString('no amplía físicamente', $exception->getMessage());
        }

        $materializada = $servicio->materializarDestino(
            $tarea->refresh(),
            $vecina,
            $operador,
            $dispositivo,
        );

        $this->assertSame($vecina->id, $materializada->posicion_destino_id);
        $this->assertSame($contexto['camaraObjetivo']->id, $materializada->camara_destino_id);
        $this->assertSame('asumida', $materializada->estado->value);
    }

    public function test_al_llegar_al_80_por_ciento_no_publica_movimientos_adicionales(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        [$operador, $dispositivo] = $this->crearOperador();
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($plan->tareas()->sole(), $operador, $dispositivo);
        $tarea = $planes->materializarDestino(
            $tarea,
            $contexto['posicionesObjetivo'][7],
            $operador,
            $dispositivo,
        );
        $planes->iniciar($tarea, $operador, $dispositivo);
        $sesiones = app(ServicioSesionEstiba::class);
        $sesionOrigen = $sesiones->abrir($tarea->camaraOrigen, $operador, $dispositivo);
        $sesionDestino = $sesiones->abrir($tarea->camaraDestino, $operador, $dispositivo);

        app(ServicioMovimientoEstiba::class)->mover(
            operacionId: (string) Str::uuid(),
            folio: $tarea->folio,
            posicionDestino: $tarea->posicionDestino,
            sesionOrigen: $sesionOrigen,
            sesionDestino: $sesionDestino,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionOrigenConocida: $tarea->camaraOrigen->refresh()->version_plano,
            versionDestinoConocida: $tarea->camaraDestino->refresh()->version_plano,
            generadoDispositivoAt: now(),
            tareaMovimiento: $tarea->refresh(),
        );
        app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga']->refresh(),
            $contexto['usuario'],
        );

        $this->assertSame('completado', $plan->refresh()->estado->value);
        $this->assertSame(1, $plan->maniobras()->count());
        $this->assertSame(1, $plan->tareas()->count());
    }

    public function test_una_solucion_directa_vence_a_otra_que_requiere_despeje(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $objetivoBloqueado = $contexto['foliosFuera'][0];
        $origen = $objetivoBloqueado->ubicacionActual->posicion;
        $posicionBlocker = Posicion::create([
            'camara_id' => $origen->camara_id,
            'banda' => $origen->banda,
            'posicion' => 2,
            'nivel' => $origen->nivel,
            'etiqueta' => 'B01-P02-N1-COSTO',
        ]);
        $blocker = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'PAL-254-COSTO-BLOCKER',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $this->ubicarSinEventos($blocker, $contexto['camaraFuera'], $posicionBlocker);

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $this->assertNotNull($plan);
        $maniobra = $plan->maniobras()->sole();
        $this->assertSame(1, $maniobra->costo_movimientos);
        $this->assertNotSame($objetivoBloqueado->id, $maniobra->pasos()->sole()->folio_id);
    }

    public function test_off_retira_trabajo_reversible_y_shadow_solo_audita(): void
    {
        $contexto = $this->crearContexto(total: 10, concentrados: 7, fuera: 3);
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);

        config(['planificador.mode' => 'off']);
        app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );
        $this->assertSame(0, $plan->maniobras()->whereIn('estado', [
            EstadoManiobraOperacional::Pendiente->value,
            EstadoManiobraOperacional::EnEjecucion->value,
        ])->count());

        config(['planificador.mode' => 'shadow']);
        app(ServicioPlanConcentracionCarga::class)->sincronizar(
            $contexto['carga'],
            $contexto['usuario'],
        );

        $evento = EventoCarga::query()
            ->where('carga_id', $contexto['carga']->id)
            ->where('tipo', 'tareas_generadas')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame('shadow', $evento->datos['planner_mode']);
        $this->assertSame(0, $plan->maniobras()->whereIn('estado', [
            EstadoManiobraOperacional::Pendiente->value,
            EstadoManiobraOperacional::EnEjecucion->value,
        ])->count());
    }

    /** @return array<string, mixed> */
    private function crearContexto(
        int $total,
        int $concentrados,
        int $fuera,
        int $sinUbicacion = 0,
    ): array {
        config([
            'planificador.mode' => 'guided',
            'planificador.generacion_automatica' => true,
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
            'planificador.frontier_max' => 4,
        ]);
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-254',
            'nombre' => 'Temporada concentración 254',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $usuario = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $camaraObjetivo = $this->crearCamara(
            'CAM-254-A',
            'Cámara objetivo concentración',
            1,
            10,
            $usuario,
        );
        $camaraFuera = $this->crearCamara(
            'CAM-254-B',
            'Cámara pallets dispersos',
            max(1, $fuera),
            1,
            $usuario,
        );
        $posicionesObjetivo = $this->crearPosicionesBanda(
            $camaraObjetivo,
            banda: 1,
            cantidad: 10,
        );
        $posicionesFuera = [];
        for ($indice = 1; $indice <= max(1, $fuera); $indice++) {
            $posicionesFuera[] = Posicion::create([
                'camara_id' => $camaraFuera->id,
                'banda' => $indice,
                'posicion' => 1,
                'nivel' => 1,
                'etiqueta' => sprintf('B%02d-P01-N1', $indice),
            ]);
        }
        $carga = Carga::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'CAR-254-000001',
            'estado' => EstadoCarga::Pendiente,
            'prioridad' => PrioridadCarga::Alta,
            'camara_objetivo_id' => $camaraObjetivo->id,
            'version' => 1,
            'creada_por_user_id' => $usuario->id,
            'actualizada_por_user_id' => $usuario->id,
            'publicada_por_user_id' => $usuario->id,
            'publicada_at' => now(),
        ]);

        $folios = [];
        $foliosFuera = [];
        for ($indice = 1; $indice <= $total; $indice++) {
            $folio = Folio::create([
                'temporada_id' => $temporada->id,
                'numero_folio' => sprintf('PAL-254-%03d', $indice),
                'tipo_bulto' => TipoBulto::Pallet,
                'fecha_ingreso' => now()->subDay()->addMinutes($indice),
                'activo' => true,
                'marca' => 'MACE',
                'exportadora' => 'Exportadora 254',
            ]);
            $asignacion = CargaFolio::create([
                'carga_id' => $carga->id,
                'folio_id' => $folio->id,
                'estado' => EstadoCargaFolio::Pendiente,
                'asignado_por_user_id' => $usuario->id,
                'asignado_at' => now()->addSeconds($indice),
            ]);
            ReservaCargaFolio::create([
                'folio_id' => $folio->id,
                'carga_folio_id' => $asignacion->id,
            ]);

            if ($indice <= $concentrados) {
                $this->ubicarSinEventos($folio, $camaraObjetivo, $posicionesObjetivo[$indice - 1]);
            } elseif ($indice <= $concentrados + $fuera) {
                $posicion = $posicionesFuera[$indice - $concentrados - 1];
                $this->ubicarSinEventos($folio, $camaraFuera, $posicion);
                $foliosFuera[] = $folio;
            }

            $folios[] = $folio;
        }

        $this->assertSame($total, $concentrados + $fuera + $sinUbicacion);

        return compact(
            'temporada',
            'usuario',
            'camaraObjetivo',
            'camaraFuera',
            'posicionesObjetivo',
            'posicionesFuera',
            'carga',
            'folios',
            'foliosFuera',
        );
    }

    private function crearCamara(
        string $codigo,
        string $nombre,
        int $bandas,
        int $posiciones,
        User $usuario,
    ): Camara {
        $camara = Camara::create([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'contenido' => ContenidoCamara::Productos,
            'estado' => 'activa',
            'cantidad_bandas' => $bandas,
            'posiciones_por_banda' => $posiciones,
            'cantidad_niveles' => 1,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $usuario);

        return $camara;
    }

    /** @param array<string, mixed> $contexto */
    private function habilitarCuatroDestinosVecinos(array $contexto): void
    {
        $contexto['camaraObjetivo']->update(['cantidad_bandas' => 2]);
        app(ServicioBandasOperacionales::class)->sincronizar(
            $contexto['camaraObjetivo'],
            $contexto['usuario'],
        );
        $this->crearPosicionesBanda($contexto['camaraObjetivo'], banda: 2, cantidad: 4);
    }

    /** @return array<int, Posicion> */
    private function crearPosicionesBanda(Camara $camara, int $banda, int $cantidad): array
    {
        $posiciones = [];
        for ($indice = 1; $indice <= $cantidad; $indice++) {
            $posiciones[] = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => $banda,
                'posicion' => $indice,
                'nivel' => 1,
                'etiqueta' => sprintf('B%02d-P%02d-N1', $banda, $indice),
            ]);
        }

        return $posiciones;
    }

    private function ubicarSinEventos(Folio $folio, Camara $camara, Posicion $posicion): void
    {
        UbicacionActual::withoutEvents(fn (): UbicacionActual => UbicacionActual::create([
            'folio_id' => $folio->id,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion->id,
            'ubicado_at' => now(),
        ]));
    }

    /** @return array{User, Dispositivo} */
    private function crearOperador(): array
    {
        return [
            User::factory()->create([
                'rol' => RolUsuario::CamareroFrio,
                'activo' => true,
            ]),
            Dispositivo::create([
                'codigo' => 'TABLET-254-'.Str::upper(Str::random(6)),
                'nombre' => 'Tablet maniobras 254',
                'activo' => true,
            ]),
        ];
    }

    /** @param array<string, mixed> $contexto */
    private function crearPresenciaAnden(array $contexto): PresenciaCargaAnden
    {
        $anden = Anden::create([
            'codigo' => 'AND-254-'.Str::upper(Str::random(6)),
            'nombre' => 'Andén concentración 254',
            'activo' => true,
            'creado_por_user_id' => $contexto['usuario']->id,
            'actualizado_por_user_id' => $contexto['usuario']->id,
        ]);

        return PresenciaCargaAnden::create([
            'carga_id' => $contexto['carga']->id,
            'anden_id' => $anden->id,
            'bloqueo_carga_id' => $contexto['carga']->id,
            'bloqueo_anden_id' => $anden->id,
            'estado' => EstadoPresenciaCargaAnden::Activa,
            'operacion_ingreso_id' => (string) Str::uuid(),
            'ingreso_payload_hash' => hash('sha256', (string) Str::uuid()),
            'patente' => 'PR2540',
            'ingresada_por_user_id' => $contexto['usuario']->id,
            'ingresada_at' => now(),
        ]);
    }
}
