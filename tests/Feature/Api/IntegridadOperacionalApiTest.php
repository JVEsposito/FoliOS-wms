<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\OrigenAuditoriaIntegridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Jobs\EjecutarAuditoriaIntegridadOperacional;
use App\Models\Camara;
use App\Models\ClienteMaterial;
use App\Models\Folio;
use App\Models\ItemMaterial;
use App\Models\PerfilAcceso;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use App\Services\IntegridadOperacional\ServicioAuditoriaIntegridadOperacional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegridadOperacionalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_detecta_contradicciones_sin_modificar_operacion_y_marca_su_resolucion(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();

        [$folioPrefrio, $procesoPrefrio] = $this->prefrioInconsistente(
            $temporada,
            $administrador,
        );
        [$folioUbicado, $camara] = $this->ubicacionInconsistente();
        [$folioMaterial, $item] = $this->materialInconsistente($administrador);
        [$repaletizajeId, $resultadoRepaletizajeId] = $this->repaletizajeInconsistente(
            $administrador,
        );
        [$folioCarga, $reservaCargaId] = $this->reservaCargaInconsistente(
            $temporada,
            $administrador,
        );

        $snapshotPrefrio = DB::table('folios')->where('id', $folioPrefrio->id)->first();
        $snapshotMaterial = DB::table('folios_materiales')->where('folio_id', $folioMaterial->id)->first();
        $snapshotRepa = DB::table('repaletizajes')->where('id', $repaletizajeId)->first();

        $servicio = app(ServicioAuditoriaIntegridadOperacional::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $auditoria = $servicio->ejecutar(
            OrigenAuditoriaIntegridadOperacional::Manual,
            $administrador,
        );
        $consultasPersistencia = collect(DB::getQueryLog())->filter(
            fn (array $consulta): bool => str_contains(
                Str::lower($consulta['query']),
                'hallazgos_integridad',
            ),
        )->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $consultasPersistencia);
        $this->assertSame('completada', $auditoria->estado->value);
        $this->assertSame(5, $auditoria->hallazgos_activos);
        $this->assertSame(5, $auditoria->hallazgos_criticos);
        $this->assertSame(5, $auditoria->hallazgos_nuevos);
        $this->assertSame(0, $auditoria->hallazgos_resueltos);

        foreach ([
            'prefrio_aprobado_no_proyectado',
            'ubicacion_folio_inconsistente',
            'saldo_material_fuera_de_rango',
            'repaletizaje_desbalanceado',
            'reserva_carga_inconsistente',
        ] as $regla) {
            $this->assertDatabaseHas('hallazgos_integridad', [
                'regla_codigo' => $regla,
                'activo' => true,
            ]);
        }

        $this->assertEquals(
            $snapshotPrefrio,
            DB::table('folios')->where('id', $folioPrefrio->id)->first(),
        );
        $this->assertEquals(
            $snapshotMaterial,
            DB::table('folios_materiales')->where('folio_id', $folioMaterial->id)->first(),
        );
        $this->assertEquals(
            $snapshotRepa,
            DB::table('repaletizajes')->where('id', $repaletizajeId)->first(),
        );
        $this->assertDatabaseHas('ubicaciones_actuales', [
            'folio_id' => $folioUbicado->id,
            'camara_id' => $camara->id,
        ]);
        $this->assertDatabaseHas('reservas_carga_folio', [
            'folio_id' => $folioCarga->id,
            'carga_folio_id' => $reservaCargaId,
        ]);

        $auditoriaRepetida = $servicio->ejecutar(
            OrigenAuditoriaIntegridadOperacional::Manual,
            $administrador,
        );
        $this->assertSame(5, $auditoriaRepetida->hallazgos_activos);
        $this->assertSame(0, $auditoriaRepetida->hallazgos_nuevos);
        $this->assertSame(
            5,
            DB::table('hallazgos_integridad')->where('ocurrencias', 2)->count(),
        );

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/integridad-operacional?modulo=prefrio&severidad=critico')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.referencia', $folioPrefrio->numero_folio)
            ->assertJsonPath('resumen.activos', 5)
            ->assertJsonCount(5, 'catalogo.reglas');

        DB::table('folios')->where('id', $folioPrefrio->id)->update([
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion->value,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado->value,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado->value,
        ]);
        DB::table('ubicaciones_actuales')->where('folio_id', $folioUbicado->id)->delete();
        DB::table('folios_materiales')->where('folio_id', $folioMaterial->id)->update([
            'cantidad_reservada' => 100,
        ]);
        DB::table('repaletizaje_resultados')->where('id', $resultadoRepaletizajeId)->update([
            'cantidad_resultante' => 10,
        ]);
        DB::table('reservas_carga_folio')->where('folio_id', $folioCarga->id)->delete();

        $auditoriaResuelta = $servicio->ejecutar(
            OrigenAuditoriaIntegridadOperacional::Manual,
            $administrador,
        );
        $this->assertSame(0, $auditoriaResuelta->hallazgos_activos);
        $this->assertSame(0, $auditoriaResuelta->hallazgos_nuevos);
        $this->assertSame(5, $auditoriaResuelta->hallazgos_resueltos);

        $this->assertDatabaseCount('auditorias_integridad', 3);
        $this->assertSame(0, DB::table('hallazgos_integridad')->where('activo', true)->count());
        $this->assertSame(5, DB::table('hallazgos_integridad')->whereNotNull('resuelto_at')->count());
        $this->assertDatabaseHas('procesos_prefrio', [
            'id' => $procesoPrefrio->id,
            'estado' => EstadoProcesoPrefrio::Aprobado->value,
        ]);
        $this->assertDatabaseHas('items_materiales', [
            'id' => $item->id,
            'activo' => true,
        ]);
    }

    public function test_consulta_puede_ver_pero_solo_administracion_ejecuta_auditorias(): void
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'CONSULTA_INTEGRIDAD',
            'nombre' => 'Consulta de integridad',
            'rol_base' => RolUsuario::Consulta,
            'modulos' => [CatalogoModulosAcceso::OFICINA_INTEGRIDAD_OPERACIONAL],
            'modulos_tablet' => [],
            'activo' => true,
            'predeterminado' => false,
            'protegido' => false,
        ]);
        $consulta = User::factory()->create([
            'rol' => RolUsuario::Consulta,
            'perfil_acceso_id' => $perfil->id,
            'activo' => true,
        ]);
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);

        $this->getJson('/api/administracion/integridad-operacional')->assertUnauthorized();

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/administracion/integridad-operacional')
            ->assertForbidden();

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/administracion/integridad-operacional')
            ->assertOk()
            ->assertJsonPath('resumen.activos', 0);

        $this->postJson('/api/administracion/integridad-operacional/auditar')
            ->assertForbidden();
        $this->getJson('/api/administracion/integridad-operacional/purgar-despachados')->assertForbidden();
        $this->postJson('/api/administracion/integridad-operacional/purgar-despachados')->assertForbidden();

        $this->get('/oficina/administracion/integridad-operacional')
            ->assertOk()
            ->assertSee('Salud operacional')
            ->assertSee('Purgar pallets despachados');
    }

    public function test_purga_confirmada_solo_libera_despachados_y_la_auditoria_resuelve_su_hallazgo(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->actingAs($administrador, 'sanctum');
        [$despachado, $camara] = $this->ubicacionInconsistente();
        $ubicacion = $despachado->ubicacionActual;
        $activo = $this->folio('PAL-EN-CAMARA', TipoBulto::Pallet, EstadoOperacionalFolio::Disponible, true,
            CondicionTermicaFolio::PrefrioAprobado, HabilitacionAlmacenamientoFolio::Habilitado);
        DB::table('ubicaciones_actuales')->insert([
            ...$ubicacion->getAttributes(), 'id' => (string) Str::uuid(), 'folio_id' => $activo->id,
        ]);
        $auditoria = app(ServicioAuditoriaIntegridadOperacional::class);
        $auditoria->ejecutar(OrigenAuditoriaIntegridadOperacional::Manual, $administrador);
        $ruta = '/api/administracion/integridad-operacional/purgar-despachados';
        $revision = $this->getJson($ruta)->assertOk()->assertJsonPath('cantidad', 1)
            ->assertJsonPath('folios.0', $despachado->numero_folio)->json('revision');

        $this->postJson($ruta, ['confirmacion' => false, 'revision' => $revision])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
        $this->assertDatabaseHas('ubicaciones_actuales', ['id' => $ubicacion->id]);

        // Un retorno entre la vista previa y la confirmación invalida la autorización.
        $despachado->update(['activo' => true, 'estado_operacional' => EstadoOperacionalFolio::Disponible]);
        $this->postJson($ruta, ['confirmacion' => true, 'revision' => $revision])->assertConflict();
        $this->assertDatabaseHas('ubicaciones_actuales', ['id' => $ubicacion->id]);
        $this->assertSame(0, $camara->fresh()->version_plano);
        $despachado->update(['activo' => false, 'estado_operacional' => EstadoOperacionalFolio::Despachado]);

        $this->postJson($ruta, ['confirmacion' => true, 'revision' => $revision])
            ->assertOk()->assertJsonPath('purgadas', 1);
        $this->postJson($ruta, ['confirmacion' => true, 'revision' => $revision])->assertConflict();
        $this->getJson($ruta)->assertOk()->assertJsonPath('cantidad', 0);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $despachado->id]);
        $this->assertDatabaseHas('ubicaciones_actuales', ['folio_id' => $activo->id]);
        $this->assertDatabaseHas('folios', ['id' => $despachado->id, 'estado_operacional' => 'despachado', 'activo' => false]);
        $this->assertSame(1, $camara->fresh()->version_plano);
        $this->assertDatabaseHas('cambios_transiciones_operacionales', [
            'modelo_tipo' => UbicacionActual::class, 'modelo_id' => $ubicacion->id, 'tipo' => 'eliminacion',
        ]);
        $this->assertSame(1, $auditoria->ejecutar(OrigenAuditoriaIntegridadOperacional::Manual, $administrador)->hallazgos_resueltos);
    }

    public function test_administracion_programa_la_auditoria_manual_en_la_cola(): void
    {
        Queue::fake();
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/integridad-operacional/auditar')
            ->assertStatus(202)
            ->assertJsonPath(
                'message',
                'Auditoría programada. El panel se actualizará cuando finalice.',
            );

        Queue::assertPushed(
            EjecutarAuditoriaIntegridadOperacional::class,
            fn (EjecutarAuditoriaIntegridadOperacional $job): bool => $job->actorId === $administrador->id,
        );
        $this->assertDatabaseCount('auditorias_integridad', 0);
    }

    public function test_consulta_reutiliza_el_etag_mientras_no_hay_una_nueva_auditoria(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $inicial = $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/integridad-operacional')
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'ETag');
        $etagInicial = (string) $inicial->headers->get('ETag');
        $this->assertNotSame('', $etagInicial);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withHeader('If-None-Match', $etagInicial)
            ->getJson('/api/administracion/integridad-operacional')
            ->assertStatus(304)
            ->assertHeader('ETag', $etagInicial);
        $consultoHallazgos = collect(DB::getQueryLog())->contains(
            fn (array $consulta): bool => str_contains(
                Str::lower($consulta['query']),
                'hallazgos_integridad',
            ),
        );
        $this->assertFalse($consultoHallazgos);
        DB::disableQueryLog();

        app(ServicioAuditoriaIntegridadOperacional::class)->ejecutar(
            OrigenAuditoriaIntegridadOperacional::Manual,
            $administrador,
        );

        $actualizada = $this->withHeader('If-None-Match', $etagInicial)
            ->getJson('/api/administracion/integridad-operacional')
            ->assertOk();
        $this->assertNotSame($etagInicial, (string) $actualizada->headers->get('ETag'));
    }

    public function test_no_reporta_como_inconsistente_un_folio_aprobado_pendiente_de_ubicacion(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        [$folio] = $this->prefrioInconsistente($temporada, $administrador);

        $folio->update([
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
        ]);
        $this->assertSame(
            EstadoOperacionalFolio::PendientePrefrio,
            $folio->estado_operacional,
        );

        $auditoria = app(ServicioAuditoriaIntegridadOperacional::class)->ejecutar(
            OrigenAuditoriaIntegridadOperacional::Manual,
            $administrador,
        );
        $this->assertSame(0, $auditoria->hallazgos_activos);
        $this->assertSame(0, $auditoria->hallazgos_criticos);

        $this->assertDatabaseMissing('hallazgos_integridad', [
            'regla_codigo' => 'prefrio_aprobado_no_proyectado',
            'entidad_id' => $folio->id,
            'activo' => true,
        ]);
    }

    public function test_comando_programado_registra_una_auditoria_sin_hallazgos(): void
    {
        $codigo = Artisan::call('folios:auditar-integridad', [
            '--origen' => 'programada',
        ]);

        $this->assertSame(0, $codigo);
        $this->assertDatabaseHas('auditorias_integridad', [
            'origen' => 'programada',
            'estado' => 'completada',
            'hallazgos_activos' => 0,
        ]);
        $this->assertStringContainsString(
            'completada',
            Artisan::output(),
        );
    }

    /** @return array{Folio, ProcesoPrefrio} */
    private function prefrioInconsistente(
        Temporada $temporada,
        User $administrador,
    ): array {
        $folio = $this->folio(
            'PAL-INTEGRIDAD-PF',
            TipoBulto::Pallet,
            EstadoOperacionalFolio::PendientePrefrio,
            true,
            CondicionTermicaFolio::PendientePrefrio,
            HabilitacionAlmacenamientoFolio::NoHabilitado,
        );
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-AUD-01',
            'nombre' => 'Túnel auditoría',
            'capacidad_posiciones' => 20,
            'setpoint_habitual' => -1.5,
            'estado_administrativo' => 'activo',
            'estado_tecnico' => 'operativo',
            'version_configuracion' => 1,
            'creado_por_user_id' => $administrador->id,
        ]);
        $posicion = PosicionTunelPrefrio::create([
            'tunel_prefrio_id' => $tunel->id,
            'numero' => 1,
            'etiqueta' => 'TUN-AUD-01-P01',
            'activa' => true,
        ]);
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'PF-AUD-000001',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'proceso-integridad'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => EstadoProcesoPrefrio::Aprobado,
            'setpoint' => -1.5,
            'version' => 5,
            'creado_por_user_id' => $administrador->id,
            'finalizado_por_user_id' => $administrador->id,
            'finalizado_at' => now(),
        ]);
        ProcesoPrefrioFolio::create([
            'proceso_prefrio_id' => $proceso->id,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'estado' => EstadoFolioProcesoPrefrio::Aprobado,
            'cargado_at' => now()->subHours(12),
            'retirado_at' => now(),
            'cargado_por_user_id' => $administrador->id,
            'retirado_por_user_id' => $administrador->id,
        ]);

        return [$folio, $proceso];
    }

    /** @return array{Folio, Camara} */
    private function ubicacionInconsistente(): array
    {
        $folio = $this->folio(
            'PAL-INTEGRIDAD-UB',
            TipoBulto::Pallet,
            EstadoOperacionalFolio::Despachado,
            false,
            CondicionTermicaFolio::PrefrioAprobado,
            HabilitacionAlmacenamientoFolio::Habilitado,
        );
        $camara = Camara::create([
            'codigo' => 'CAM-AUD-01',
            'nombre' => 'Cámara auditoría',
            'tipo' => 'almacenaje',
            'contenido' => 'productos',
            'estado' => 'activa',
            'version_plano' => 0,
        ]);
        DB::table('ubicaciones_actuales')->insert([
            'id' => (string) Str::uuid(),
            'folio_id' => $folio->id,
            'camara_id' => $camara->id,
            'posicion_id' => null,
            'movimiento_id' => null,
            'ubicado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$folio, $camara];
    }

    /** @return array{Folio, ItemMaterial} */
    private function materialInconsistente(User $administrador): array
    {
        $cliente = ClienteMaterial::query()->firstOrFail();
        $item = ItemMaterial::create([
            'cliente_material_id' => $cliente->id,
            'codigo' => 'MAT-AUD-001',
            'nombre' => 'Material auditoría',
            'categoria' => 'Prueba',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'unidad',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        $folio = $this->folio(
            'FMA-INTEGRIDAD-01',
            TipoBulto::Material,
            EstadoOperacionalFolio::Disponible,
        );
        DB::table('folios_materiales')->insert([
            'folio_id' => $folio->id,
            'item_material_id' => $item->id,
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo->value,
            'cantidad_inicial' => 100,
            'cantidad_actual' => 100,
            'cantidad_reservada' => 120,
            'unidad_medida' => 'unidad',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$folio, $item];
    }

    /** @return array{string, string} */
    private function repaletizajeInconsistente(User $administrador): array
    {
        $origen = $this->folio(
            'SAL-INTEGRIDAD-ORIGEN',
            TipoBulto::Saldo,
            EstadoOperacionalFolio::Agotado,
            false,
            CondicionTermicaFolio::PrefrioAprobado,
            HabilitacionAlmacenamientoFolio::Habilitado,
        );
        $resultado = $this->folio(
            'SAL-INTEGRIDAD-RESULTADO',
            TipoBulto::Saldo,
            EstadoOperacionalFolio::PendienteUbicacion,
            true,
            CondicionTermicaFolio::PrefrioAprobado,
            HabilitacionAlmacenamientoFolio::Habilitado,
        );
        $repaId = (string) Str::uuid();
        $resultadoId = (string) Str::uuid();
        DB::table('repaletizajes')->insert([
            'id' => $repaId,
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'repa-integridad'),
            'codigo' => 'RP-AUD-000001',
            'modalidad' => 'cambio_folio',
            'tipo_resultado' => 'saldo',
            'estrategia_folio' => 'nuevo',
            'folio_resultante_id' => $resultado->id,
            'folio_conservado_id' => null,
            'cantidad_objetivo' => 120,
            'cantidad_resultante' => 9,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado->value,
            'campos_mix' => json_encode([], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'estado' => 'confirmado',
            'user_id' => $administrador->id,
            'dispositivo_id' => null,
            'confirmado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('repaletizaje_detalles')->insert([
            'id' => (string) Str::uuid(),
            'repaletizaje_id' => $repaId,
            'folio_origen_id' => $origen->id,
            'orden' => 1,
            'es_folio_conservado' => false,
            'cajas_antes' => 10,
            'cajas_aportadas' => 10,
            'cajas_despues' => 0,
            'tipo_bulto_antes' => TipoBulto::Saldo->value,
            'tipo_bulto_despues' => null,
            'estado_antes' => EstadoOperacionalFolio::Disponible->value,
            'estado_despues' => EstadoOperacionalFolio::Agotado->value,
            'snapshot_antes' => json_encode([], JSON_THROW_ON_ERROR),
            'snapshot_despues' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('repaletizaje_resultados')->insert([
            'id' => $resultadoId,
            'repaletizaje_id' => $repaId,
            'folio_id' => $resultado->id,
            'orden' => 1,
            'tipo_resultado' => 'saldo',
            'cantidad_objetivo' => 120,
            'cantidad_resultante' => 9,
            'hereda_ubicacion' => false,
            'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$repaId, $resultadoId];
    }

    /** @return array{Folio, string} */
    private function reservaCargaInconsistente(
        Temporada $temporada,
        User $administrador,
    ): array {
        $folio = $this->folio(
            'PAL-INTEGRIDAD-CARGA',
            TipoBulto::Pallet,
            EstadoOperacionalFolio::Disponible,
            true,
            CondicionTermicaFolio::PrefrioAprobado,
            HabilitacionAlmacenamientoFolio::Habilitado,
        );
        $cargaId = (string) Str::uuid();
        $asignacionId = (string) Str::uuid();
        DB::table('cargas')->insert([
            'id' => $cargaId,
            'temporada_id' => $temporada->id,
            'codigo' => 'CAR-AUD-000001',
            'estado' => 'cancelada',
            'prioridad' => 'normal',
            'version' => 2,
            'creada_por_user_id' => $administrador->id,
            'actualizada_por_user_id' => $administrador->id,
            'cancelada_por_user_id' => $administrador->id,
            'cancelada_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('carga_folios')->insert([
            'id' => $asignacionId,
            'carga_id' => $cargaId,
            'folio_id' => $folio->id,
            'estado' => 'descartado',
            'asignado_por_user_id' => $administrador->id,
            'asignado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('reservas_carga_folio')->insert([
            'folio_id' => $folio->id,
            'carga_folio_id' => $asignacionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$folio, $asignacionId];
    }

    private function folio(
        string $numero,
        TipoBulto $tipo,
        EstadoOperacionalFolio $estado,
        bool $activo = true,
        ?CondicionTermicaFolio $condicion = null,
        ?HabilitacionAlmacenamientoFolio $habilitacion = null,
    ): Folio {
        return Folio::create([
            'numero_folio' => $numero,
            'tipo_bulto' => $tipo,
            'estado_operacional' => $estado,
            'condicion_termica' => $condicion,
            'habilitacion_almacenamiento' => $habilitacion,
            'fecha_ingreso' => now(),
            'activo' => $activo,
        ]);
    }
}
