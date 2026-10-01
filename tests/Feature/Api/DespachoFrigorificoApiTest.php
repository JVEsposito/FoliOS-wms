<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Anden;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\IncidenciaCargaFolio;
use App\Models\Posicion;
use App\Models\User;
use App\Services\Cargas\ServicioCarga;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioSesionEstiba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DespachoFrigorificoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_publicar_genera_tareas_y_el_reporte_de_incidencia_es_idempotente(): void
    {
        $contexto = $this->crearContextoFrio(2);
        $carga = $this->crearCargaPublicada(
            $contexto['despachador'],
            $contexto['anden'],
            $contexto['folios'],
        );
        $asignacion = $carga->asignacionesActuales()->firstOrFail();

        $this->assertDatabaseHas('tareas_carga', [
            'carga_id' => $carga->id,
            'camara_origen_id' => $contexto['camara']->id,
            'estado' => 'pendiente',
        ]);

        $operacion = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacion,
            'tipo' => 'zuncho_roto',
            'descripcion' => 'Zuncho lateral cortado.',
            'sesion_estiba_id' => $contexto['sesion']->id,
        ];
        $ruta = "/api/cargas/asignaciones/{$asignacion->id}/incidencias";

        $incidenciaId = $this->conToken($contexto['token'])
            ->postJson($ruta, $payload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'abierta')
            ->assertJsonPath('data.tipo', 'zuncho_roto')
            ->json('data.id');

        $this->conToken($contexto['token'])
            ->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $incidenciaId);

        $this->assertSame(1, IncidenciaCargaFolio::query()->count());
        $this->assertSame('con_incidencia', $asignacion->refresh()->estado->value);
        $this->assertSame('en_preparacion', $carga->refresh()->estado->value);

        $this->conToken($contexto['tokenOficina'])
            ->getJson("/api/cargas/incidencias?carga_id={$carga->id}&estado=abierta")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.carga.codigo', $carga->codigo)
            ->assertJsonPath('data.0.folio.numero_folio', $asignacion->folio->numero_folio)
            ->assertJsonPath('data.0.ubicacion_reportada.camara.codigo', 'CAM-01')
            ->assertJsonPath('data.0.reportado_por.nombre', $contexto['operador']->name)
            ->assertJsonPath('data.0.dispositivo.codigo', 'TABLET-01');

        $this->conToken($contexto['tokenOficina'])
            ->getJson('/api/cargas?solo_con_incidencias=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.incidencias_abiertas', 1);

        $this->conToken($contexto['token'])
            ->postJson($ruta, [
                ...$payload,
                'descripcion' => 'Mismo UUID con otro contenido.',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');
    }

    public function test_resuelve_incidencias_como_reparacion_despacho_parcial_o_reemplazo(): void
    {
        $contexto = $this->crearContextoFrio(3);
        $carga = $this->crearCargaPublicada(
            $contexto['despachador'],
            $contexto['anden'],
            array_slice($contexto['folios'], 0, 2),
        );
        $asignacion = $carga->asignacionesActuales()->firstOrFail();
        $incidencia = $this->reportarIncidencia($contexto, $asignacion);

        $this->conToken($contexto['tokenOficina'])
            ->postJson("/api/cargas/incidencias/{$incidencia->id}/resolver", [
                'operacion_id' => (string) Str::uuid(),
                'resolucion' => 'reparado',
                'observacion' => 'Pallet reparado en piso.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'resuelta')
            ->assertJsonPath('data.tipo_resolucion', 'reparado');

        $this->assertSame('pendiente', $asignacion->refresh()->estado->value);
        $segundaIncidencia = $this->reportarIncidencia($contexto, $asignacion);

        $this->conToken($contexto['tokenOficina'])
            ->postJson("/api/cargas/incidencias/{$segundaIncidencia->id}/resolver", [
                'operacion_id' => (string) Str::uuid(),
                'resolucion' => 'reemplazo',
                'folio_reemplazo_id' => $contexto['folios'][2]->id,
                'observacion' => 'Reemplazo autorizado por exportadora.',
            ])
            ->assertOk()
            ->assertJsonPath('data.tipo_resolucion', 'reemplazo');

        $this->assertDatabaseHas('carga_folios', [
            'id' => $asignacion->id,
            'estado' => 'reemplazado',
        ]);
        $this->assertDatabaseHas('carga_folios', [
            'carga_id' => $carga->id,
            'folio_id' => $contexto['folios'][2]->id,
            'estado' => 'pendiente',
            'reemplaza_a_carga_folio_id' => $asignacion->id,
        ]);
        $this->assertDatabaseHas('reservas_carga_folio', [
            'folio_id' => $contexto['folios'][2]->id,
        ]);
        $this->assertDatabaseMissing('reservas_carga_folio', [
            'folio_id' => $asignacion->folio_id,
        ]);

        $nuevaAsignacion = CargaFolio::query()
            ->where('folio_id', $contexto['folios'][2]->id)
            ->firstOrFail();
        $terceraIncidencia = $this->reportarIncidencia($contexto, $nuevaAsignacion);

        $this->conToken($contexto['token'])
            ->postJson("/api/cargas/incidencias/{$terceraIncidencia->id}/resolver", [
                'operacion_id' => (string) Str::uuid(),
                'resolucion' => 'despacho_parcial',
            ])
            ->assertForbidden();

        $this->conToken($contexto['tokenOficina'])
            ->postJson("/api/cargas/incidencias/{$terceraIncidencia->id}/resolver", [
                'operacion_id' => (string) Str::uuid(),
                'resolucion' => 'despacho_parcial',
                'observacion' => 'Cliente acepta salida parcial.',
            ])
            ->assertOk()
            ->assertJsonPath('data.tipo_resolucion', 'despacho_parcial');

        $this->assertDatabaseHas('carga_folios', [
            'id' => $nuevaAsignacion->id,
            'estado' => 'descartado',
        ]);
    }

    public function test_envia_a_anden_y_cierra_la_salida_del_camion_con_trazabilidad(): void
    {
        $contexto = $this->crearContextoFrio(1);
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada(
            $contexto['despachador'],
            $contexto['anden'],
            [$folio],
        );
        $asignacion = $carga->asignacionesActuales()->firstOrFail();
        $operacionRetiro = (string) Str::uuid();
        $payloadRetiro = [
            'operacion_id' => $operacionRetiro,
            'anden_id' => $contexto['anden']->id,
            'sesion_estiba_id' => $contexto['sesion']->id,
            'version_camara_conocida' => 1,
            'generado_dispositivo_at' => now()->toAtomString(),
        ];
        $rutaRetiro = "/api/cargas/asignaciones/{$asignacion->id}/enviar-anden";

        $this->conToken($contexto['token'])
            ->postJson($rutaRetiro, $payloadRetiro)
            ->assertOk()
            ->assertJsonPath('data.estado', 'despachada')
            ->assertJsonPath('data.folios.0.estado_carga', 'en_anden')
            ->assertJsonPath('data.folios.0.anden.codigo', 'AND-01');

        $this->conToken($contexto['token'])
            ->postJson($rutaRetiro, $payloadRetiro)
            ->assertOk()
            ->assertJsonPath('data.estado', 'despachada');

        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folio->id]);
        $this->assertDatabaseHas('movimientos', [
            'folio_id' => $folio->id,
            'tipo_movimiento' => 'retiro',
        ]);
        $this->assertSame(1, $folio->movimientos()->where('tipo_movimiento', 'retiro')->count());

        $salidaFisica = now()->toImmutable()->addHour()->startOfMinute();
        $this->travelTo($salidaFisica);
        $operacionCierre = (string) Str::uuid();
        $payloadCierre = [
            'operacion_id' => $operacionCierre,
            'patente' => 'ab-cd-12',
            'conductor' => 'María Pérez',
            'ocurrido_at' => $salidaFisica->toAtomString(),
        ];
        $rutaCierre = "/api/cargas/{$carga->id}/cerrar-despacho";

        $this->conToken($contexto['tokenOficina'])
            ->postJson($rutaCierre, $payloadCierre)
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrada')
            ->assertJsonPath('data.cierre.patente', 'AB-CD-12')
            ->assertJsonPath('data.cierre.conductor', 'María Pérez')
            ->assertJsonPath('data.cierre.cerrada_at', $salidaFisica->toAtomString())
            ->assertJsonPath('data.total_folios', 1)
            ->assertJsonPath('data.folios.0.numero_folio', $folio->numero_folio)
            ->assertJsonPath('data.folios.0.estado_carga', 'despachado')
            ->assertJsonPath('data.progreso.porcentaje', 100);

        $this->conToken($contexto['tokenOficina'])
            ->postJson($rutaCierre, $payloadCierre)
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrada');

        $this->assertDatabaseHas('folios', [
            'id' => $folio->id,
            'estado_operacional' => EstadoOperacionalFolio::Despachado->value,
            'activo' => false,
        ]);
        $this->assertDatabaseMissing('reservas_carga_folio', ['folio_id' => $folio->id]);
        $this->assertDatabaseHas('carga_folios', ['folio_id' => $folio->id, 'estado' => 'despachado']);
        $this->assertDatabaseHas('eventos_carga', [
            'carga_id' => $carga->id,
            'folio_id' => $folio->id,
            'tipo' => 'folio_despachado',
        ]);
        $this->assertDatabaseHas('cargas', [
            'id' => $carga->id,
            'cerrada_at' => $salidaFisica->format('Y-m-d H:i:s'),
        ]);
        $this->assertNotNull($carga->refresh()->cierre_registrado_at);

        $this->conToken($contexto['tokenOficina'])
            ->postJson($rutaCierre, [
                ...$payloadCierre,
                'conductor' => 'Otra persona',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->travelBack();
    }

    public function test_planifica_la_extraccion_vertical_y_salta_a_otra_banda_ante_una_incidencia(): void
    {
        $contexto = $this->crearContextoFrio(4);
        $posicionOtraBanda = Posicion::create([
            'camara_id' => $contexto['camara']->id,
            'banda' => 2,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B02-P01-N1',
        ]);
        $contexto['folios'][3]
            ->ubicacionActual()
            ->update(['posicion_id' => $posicionOtraBanda->id]);
        $carga = $this->crearCargaPublicada(
            $contexto['despachador'],
            $contexto['anden'],
            $contexto['folios'],
        );

        $this->conToken($contexto['token'])
            ->getJson("/api/cargas/{$carga->id}/plan-extraccion")
            ->assertOk()
            ->assertJsonPath('data.siguiente.folio.numero_folio', 'FOLIO-DESP-03')
            ->assertJsonPath('data.items.0.orden', 1)
            ->assertJsonPath('data.items.1.folio.numero_folio', 'FOLIO-DESP-02')
            ->assertJsonPath('data.items.2.folio.numero_folio', 'FOLIO-DESP-01');

        $asignacionConIncidencia = $carga->asignacionesActuales()
            ->where('folio_id', $contexto['folios'][2]->id)
            ->firstOrFail();
        $this->reportarIncidencia($contexto, $asignacionConIncidencia);

        $this->conToken($contexto['token'])
            ->getJson("/api/cargas/{$carga->id}/plan-extraccion")
            ->assertOk()
            ->assertJsonPath('data.siguiente.folio.numero_folio', 'FOLIO-DESP-04')
            ->assertJsonPath('data.resumen.planificables', 1)
            ->assertJsonPath('data.resumen.bloqueados', 2)
            ->assertJsonPath('data.resumen.con_incidencia', 1)
            ->assertJsonPath('data.items.3.folio.numero_folio', 'FOLIO-DESP-03')
            ->assertJsonPath('data.items.3.estado_ruta', 'incidencia');
    }

    public function test_plan_de_extraccion_usa_etag_y_no_recalcula_la_ruta_si_no_cambia(): void
    {
        $contexto = $this->crearContextoFrio(3);
        $carga = $this->crearCargaPublicada(
            $contexto['despachador'],
            $contexto['anden'],
            $contexto['folios'],
        );
        $ruta = "/api/cargas/{$carga->id}/plan-extraccion";

        $inicial = $this->conToken($contexto['token'])
            ->getJson($ruta)
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'ETag')
            ->assertJsonCount(3, 'data.items');
        $etagInicial = $inicial->headers->get('ETag');

        $this->assertNotNull($etagInicial);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->conToken($contexto['token'])
            ->withHeader('If-None-Match', $etagInicial)
            ->get($ruta)
            ->assertStatus(304)
            ->assertHeader('ETag', $etagInicial);

        $recalculoRuta = collect(DB::getQueryLog())->contains(function (array $consulta): bool {
            $sql = Str::lower(Str::squish(str_replace(['`', '"'], '', $consulta['query'])));

            return str_starts_with($sql, 'select * from carga_folios ')
                || str_starts_with($sql, 'select * from ubicaciones_actuales ');
        });
        $this->assertFalse($recalculoRuta);
        DB::disableQueryLog();

        $contexto['camara']->increment('version_plano');
        $actualizado = $this->conToken($contexto['token'])
            ->withHeader('If-None-Match', $etagInicial)
            ->getJson($ruta)
            ->assertOk();

        $this->assertNotSame($etagInicial, $actualizado->headers->get('ETag'));
    }

    public function test_la_oficina_calcula_concentracion_y_filtra_reemplazos_equivalentes(): void
    {
        $contexto = $this->crearContextoFrio(7);
        $carga = $this->crearCargaPublicada(
            $contexto['despachador'],
            $contexto['anden'],
            array_slice($contexto['folios'], 0, 5),
        );
        $aislada = Posicion::create([
            'camara_id' => $contexto['camara']->id,
            'banda' => 5,
            'posicion' => 20,
            'nivel' => 1,
            'etiqueta' => 'B05-P20-N1',
        ]);
        $contexto['folios'][4]
            ->ubicacionActual()
            ->update(['posicion_id' => $aislada->id]);

        $this->conToken($contexto['tokenOficina'])
            ->getJson("/api/cargas/{$carga->id}")
            ->assertOk()
            ->assertJsonPath('data.progreso.porcentaje', 80)
            ->assertJsonPath('data.progreso.cumple_umbral', true)
            ->assertJsonPath('data.progreso.concentrados', 4)
            ->assertJsonPath('data.progreso.faltantes', 1)
            ->assertJsonPath('data.progreso.grupo_principal.camara.codigo', 'CAM-01')
            ->assertJsonPath('data.progreso.grupo_principal.banda_desde', 1)
            ->assertJsonPath('data.progreso.grupo_principal.banda_hasta', 1);

        $equivalente = $contexto['folios'][5];
        $noEquivalente = $contexto['folios'][6];
        $noEquivalente->update(['calibre' => '3J']);
        $original = $contexto['folios'][0];

        $this->conToken($contexto['tokenOficina'])
            ->getJson("/api/cargas/folios-disponibles?equivalente_a={$original->id}&per_page=25")
            ->assertOk()
            ->assertJsonFragment(['numero_folio' => $equivalente->numero_folio])
            ->assertJsonMissing(['numero_folio' => $noEquivalente->numero_folio]);
    }

    public function test_el_acceso_de_oficina_expone_capacidades_del_despacho_frigorifico(): void
    {
        $despachador = User::factory()->create([
            'rol' => RolUsuario::Despachador,
            'activo' => true,
        ]);

        $this->postJson('/api/acceso-oficina', [
            'email' => $despachador->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.puede_resolver_comercialmente_carga', true)
            ->assertJsonPath('usuario.puede_resolver_reparacion_carga', true)
            ->assertJsonPath('usuario.puede_cerrar_despacho_frigorifico', true);
    }

    /** @return array<string, mixed> */
    private function crearContextoFrio(int $cantidadFolios): array
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $despachador = User::factory()->create([
            'rol' => RolUsuario::Despachador,
            'activo' => true,
        ]);
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-01',
            'nombre' => 'Tablet cámara 01',
        ]);
        $token = $operador
            ->crearTokenParaDispositivo($dispositivo, 'tablet-01')
            ->plainTextToken;
        $tokenOficina = $despachador
            ->createToken('oficina-despachos', ['oficina'])
            ->plainTextToken;
        $camara = Camara::create([
            'codigo' => 'CAM-01',
            'nombre' => 'Cámara de tránsito 01',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => $cantidadFolios,
            'cantidad_niveles' => 1,
        ]);
        $sesion = app(ServicioSesionEstiba::class)->abrir(
            $camara,
            $operador,
            $dispositivo,
        );
        $folios = [];

        for ($indice = 1; $indice <= $cantidadFolios; $indice++) {
            $posicion = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $indice,
                'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $indice),
            ]);
            $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
                operacionId: (string) Str::uuid(),
                numeroFolio: sprintf('FOLIO-DESP-%02d', $indice),
                tipoBulto: TipoBulto::Pallet,
                posicionDestino: $posicion,
                sesionDestino: $sesion,
                usuario: $operador,
                dispositivo: $dispositivo,
                versionDestinoConocida: $indice - 1,
                generadoDispositivoAt: now(),
                datosFolio: [
                    'variedad' => 'Santina',
                    'calibre' => '2J',
                    'marca' => 'Marca prueba',
                    'exportadora' => 'Exportadora prueba',
                ],
            );
            $folios[] = $movimiento->folio;
        }

        $anden = Anden::create([
            'codigo' => 'AND-01',
            'nombre' => 'Andén 01',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);

        return compact(
            'administrador',
            'despachador',
            'operador',
            'dispositivo',
            'token',
            'tokenOficina',
            'camara',
            'sesion',
            'anden',
            'folios',
        );
    }

    /**
     * @param  array<int, Folio>  $folios
     */
    private function crearCargaPublicada(User $despachador, Anden $anden, array $folios): Carga
    {
        $servicio = app(ServicioCarga::class);
        $carga = $servicio->crear(['anden_previsto_id' => $anden->id], $despachador);
        $carga = $servicio->agregarFolios(
            $carga,
            collect($folios)->pluck('numero_folio')->all(),
            $despachador,
            1,
        );

        return $servicio->publicar($carga, $despachador, $carga->version);
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function reportarIncidencia(array $contexto, CargaFolio $asignacion): IncidenciaCargaFolio
    {
        $id = $this->conToken($contexto['token'])
            ->postJson("/api/cargas/asignaciones/{$asignacion->id}/incidencias", [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'caja_aplastada',
                'sesion_estiba_id' => $contexto['sesion']->id,
            ])
            ->assertCreated()
            ->json('data.id');

        return IncidenciaCargaFolio::query()->findOrFail($id);
    }

    private function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
