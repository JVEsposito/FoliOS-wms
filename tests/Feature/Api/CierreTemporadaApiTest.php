<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaPendienteCierre;
use App\Enums\RolUsuario;
use App\Models\BloqueoCamara;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Cliente;
use App\Models\Dispositivo;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\NotificacionOperacional;
use App\Models\Posicion;
use App\Models\ProcesoPrefrio;
use App\Models\RegularizacionCierreTemporada;
use App\Models\SesionEstiba;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Temporadas\Cierre\ServicioDiagnosticoCierreTemporada;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CierreTemporadaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_diagnostico_lista_los_pendientes_por_categoria_y_omite_materiales(): void
    {
        $administrador = $this->administrador();
        $activa = $this->activa();
        $recepcion = $this->recepcionAbierta($activa, 'GUIA-CIERRE-01');
        $carga = $this->carga($activa, 'CAR-CIERRE-01', $administrador);
        [$camara] = $this->camaraConPosicion('CAM-CIERRE');
        $pallet = $this->folio($activa, 'PT-CIERRE-001', 'pallet');
        $this->ubicar($pallet, $camara);
        $this->folio($activa, 'PT-DESPACHADO', 'pallet', ['estado_operacional' => 'despachado', 'activo' => false]);
        $this->folio($activa, 'MAT-CIERRE-001', 'material');

        $datos = $this->actingAs($administrador, 'sanctum')
            ->getJson("/api/administracion/temporadas/{$activa->id}/cierre")
            ->assertOk()
            ->assertJsonPath('data.temporada.codigo', $activa->codigo)
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.folios_en_camaras', 1)
            ->json('data');
        $categorias = collect($datos['categorias'])->keyBy('categoria');

        $this->assertSame(1, $categorias['recepciones_romana']['total']);
        $this->assertSame($recepcion['numero_recepcion'], $categorias['recepciones_romana']['items'][0]['referencia']);
        $this->assertSame(1, $categorias['validacion_mp']['total']);
        $this->assertSame(1, $categorias['cargas']['total']);
        $this->assertSame($carga->codigo, $categorias['cargas']['items'][0]['referencia']);
        $this->assertSame(1, $categorias['folios_pt']['total'], 'Ni el folio despachado ni el de Materiales son pendientes.');
        $this->assertSame('PT-CIERRE-001', $categorias['folios_pt']['items'][0]['referencia']);
        $this->assertSame('CAM-CIERRE', $categorias['folios_pt']['items'][0]['ubicacion']['camara']);
        $this->assertStringContainsString('cargas sin cerrar', (string) $categorias['folios_pt']['bloqueo_regularizacion']);
        $this->assertCount(4, $datos['motivos']);

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum')
            ->getJson("/api/administracion/temporadas/{$activa->id}/cierre")
            ->assertForbidden();

        // Cada consulta de detalle es válida aunque su categoría no tenga registros.
        foreach (CategoriaPendienteCierre::cases() as $categoria) {
            $this->assertIsIterable(app(ServicioDiagnosticoCierreTemporada::class)->pendientes($activa, $categoria));
        }

        $this->artisan('temporadas:diagnosticar')->assertExitCode(1);
        $this->artisan('temporadas:diagnosticar', ['temporada' => 'NO-EXISTE'])->assertExitCode(1);
    }

    public function test_no_se_activa_otra_temporada_mientras_la_vigente_tenga_pendientes(): void
    {
        $administrador = $this->administrador();
        $activa = $this->activa();
        $carga = $this->carga($activa, 'CAR-BLOQUEA', $administrador);
        $siguiente = $this->temporada('SIGUIENTE');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$siguiente->id}/activar")
            ->assertConflict()
            ->assertJsonPath('codigo', 'temporada_con_pendientes')
            ->assertJsonPath('pendientes.0.temporada.codigo', $activa->codigo)
            ->assertJsonPath('pendientes.0.categoria', 'cargas')
            ->assertJsonPath('pendientes.0.cantidad', 1);

        $this->putJson("/api/administracion/temporadas/{$siguiente->id}", [
            'codigo' => $siguiente->codigo,
            'nombre' => $siguiente->nombre,
            'fecha_inicio' => $siguiente->fecha_inicio->toDateString(),
            'fecha_fin' => $siguiente->fecha_fin->toDateString(),
            'prefijo_documental' => $siguiente->prefijo_documental,
            'activa' => true,
        ])->assertConflict()->assertJsonPath('codigo', 'temporada_con_pendientes');

        $this->postJson('/api/administracion/temporadas', [
            'codigo' => 'NUEVA-ACTIVA',
            'nombre' => 'Nueva activa',
            ...$this->vigenciaProductiva(),
            'activa' => true,
        ])->assertConflict()->assertJsonPath('codigo', 'temporada_con_pendientes');
        $this->assertDatabaseMissing('temporadas', ['codigo' => 'NUEVA-ACTIVA']);

        $this->postJson("/api/administracion/temporadas/{$siguiente->id}/migrar", [
            'temporada_origen_id' => $activa->id,
            'copiar_catalogo_validacion' => false,
            'copiar_catalogo_materiales' => true,
            'migrar_inventario_materiales' => false,
            'activar_destino' => true,
        ])->assertConflict()->assertJsonPath('codigo', 'temporada_con_pendientes');
        $this->assertDatabaseCount('migraciones_temporadas', 0);
        $this->assertTrue($activa->refresh()->activa);

        // Editar la vigente sin cambiar la activación no se bloquea.
        $this->putJson("/api/administracion/temporadas/{$activa->id}", [
            'codigo' => $activa->codigo,
            'nombre' => 'Nombre corregido',
            'fecha_inicio' => $activa->fecha_inicio->toDateString(),
            'fecha_fin' => $activa->fecha_fin->toDateString(),
            'prefijo_documental' => $activa->prefijo_documental,
        ])->assertOk();

        $this->regularizar($activa, 'cargas', [$carga->id], 'error_digitacion')->assertOk();

        $this->postJson("/api/administracion/temporadas/{$siguiente->id}/activar")
            ->assertOk()
            ->assertJsonPath('data.activa', true);
        $this->assertFalse($activa->refresh()->activa);
    }

    public function test_regularizar_prefrio_cancela_el_proceso_y_libera_el_tunel(): void
    {
        $administrador = $this->administrador();
        $activa = $this->activa();
        $tunel = TunelPrefrio::create([
            'codigo' => 'T-CIERRE',
            'nombre' => 'Túnel cierre',
            'capacidad_posiciones' => 20,
            'creado_por_user_id' => $administrador->id,
        ]);
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $activa->id,
            'codigo' => 'PRE-CIERRE',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'prefrio-cierre'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => 'borrador',
            'setpoint' => -0.5,
            'creado_por_user_id' => $administrador->id,
        ]);

        $this->actingAs($administrador, 'sanctum');
        $this->regularizar($activa, 'prefrio', [$proceso->id], 'dato_prueba')->assertOk();
        $this->assertSame('cancelado', $proceso->refresh()->estado->value);
        $this->assertDatabaseHas('eventos_prefrio', ['proceso_prefrio_id' => $proceso->id, 'tipo' => 'cancelacion']);
        $this->assertDatabaseHas('regularizaciones_cierre_temporada', [
            'categoria' => 'prefrio', 'entidad_id' => $proceso->id,
        ]);
        $this->assertFalse($tunel->procesos()->whereIn('estado', ['borrador', 'cargando', 'listo_para_iniciar', 'en_proceso', 'pendiente_verificacion'])->exists());
    }

    public function test_se_puede_cancelar_un_prefrio_heredado_de_una_temporada_inactiva(): void
    {
        $administrador = $this->administrador();
        $anterior = $this->activa();
        $tunel = TunelPrefrio::create([
            'codigo' => 'T-HEREDADO', 'nombre' => 'Túnel heredado',
            'capacidad_posiciones' => 12, 'creado_por_user_id' => $administrador->id,
        ]);
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $anterior->id,
            'codigo' => 'PRE-HEREDADO',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'prefrio-heredado'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => 'borrador', 'setpoint' => -0.5,
            'creado_por_user_id' => $administrador->id,
        ]);
        $anterior->update(['activa' => false]);
        $this->temporada('AHORA')->update(['activa' => true]);

        $this->actingAs($administrador, 'sanctum');
        $this->regularizar($anterior, 'prefrio', [$proceso->id], 'dato_prueba')->assertOk();
        $this->assertSame('cancelado', $proceso->refresh()->estado->value);
        $this->assertNull($tunel->procesoActivo()->first());
    }

    public function test_una_carga_con_folios_no_puede_ocultarse_ni_liberar_el_folio_por_regularizacion(): void
    {
        $administrador = $this->administrador();
        $activa = $this->activa();
        $carga = $this->carga($activa, 'CAR-CON-FOLIO', $administrador);
        $folio = $this->folio($activa, 'PT-CON-CARGA', 'pallet');
        CargaFolio::create([
            'carga_id' => $carga->id,
            'folio_id' => $folio->id,
            'asignado_por_user_id' => $administrador->id,
            'asignado_at' => now(),
        ]);

        $this->actingAs($administrador, 'sanctum');
        $this->regularizar($activa, 'cargas', [$carga->id], 'error_digitacion')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La carga conserva folios, tareas o un camión en andén. Cancélala o ciérrala en Cargas antes de regularizarla.');
        $this->assertDatabaseMissing('regularizaciones_cierre_temporada', ['entidad_id' => $carga->id]);
    }

    public function test_destino_con_folios_de_su_temporada_en_camaras_no_se_reactiva(): void
    {
        $administrador = $this->administrador();
        $this->activa();
        $destino = $this->temporada('DESTINO-OCUPADO');
        [$camara, $posicion] = $this->camaraConPosicion('CAM-DESTINO');
        $folio = $this->folio($destino, 'PT-DESTINO-OLVIDADO', 'pallet');
        $this->ubicar($folio, $camara, $posicion);

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$destino->id}/activar")
            ->assertConflict()
            ->assertJsonPath('pendientes.0.temporada.codigo', $destino->codigo)
            ->assertJsonPath('pendientes.0.categoria', 'folios_pt');
        $this->assertFalse($destino->refresh()->activa);
    }

    public function test_regularizar_un_folio_lo_retira_libera_la_posicion_y_queda_auditado(): void
    {
        $administrador = $this->administrador();
        $activa = $this->activa();
        $carga = $this->carga($activa, 'CAR-OLVIDADA', $administrador);
        [$camara, $posicion] = $this->camaraConPosicion('CAM-REG');
        $folio = $this->folio($activa, 'PT-OLVIDADO', 'pallet');
        $this->ubicar($folio, $camara, $posicion);
        $version = $camara->refresh()->version_plano;

        $this->actingAs($administrador, 'sanctum');
        $this->regularizar($activa, 'folios_pt', [$folio->id], 'despachado_sin_registro')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Antes de regularizar folios PT, cierra o regulariza: cargas sin cerrar.');
        $this->assertDatabaseHas('ubicaciones_actuales', ['folio_id' => $folio->id]);

        $this->regularizar($activa, 'cargas', [$carga->id], 'despachado_sin_registro')
            ->assertOk()
            ->assertJsonPath('data.regularizados', 1);
        $this->regularizar($activa, 'folios_pt', [$folio->id], 'despachado_sin_registro', 'corto')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');
        $this->regularizar($activa, 'folios_pt', [$folio->id], 'olvido')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo_categoria');

        $this->regularizar($activa, 'folios_pt', [$folio->id], 'despachado_sin_registro')
            ->assertOk()
            ->assertJsonPath('data.regularizados', 1);

        $folio->refresh();
        $this->assertSame('retirado_definitivo', $folio->estado_operacional->value);
        $this->assertFalse($folio->activo);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folio->id]);
        $this->assertSame($version + 1, $camara->refresh()->version_plano);

        $registro = RegularizacionCierreTemporada::query()->where('entidad_id', $folio->id)->firstOrFail();
        $this->assertSame('folios_pt', $registro->categoria->value);
        $this->assertSame('disponible', $registro->estado_anterior);
        $this->assertSame('despachado_sin_registro', $registro->motivo_categoria->value);
        $this->assertSame($administrador->id, $registro->regularizado_por_user_id);
        $this->assertSame('CAM-REG', $registro->snapshot['ubicacion']['camara']);
        $this->assertSame('B01-P01-N1', $registro->snapshot['ubicacion']['posicion']);

        // Ya no está pendiente: una segunda regularización se rechaza.
        $this->regularizar($activa, 'folios_pt', [$folio->id], 'despachado_sin_registro')
            ->assertUnprocessable();

        $diagnostico = $this->getJson("/api/administracion/temporadas/{$activa->id}/cierre")
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.regularizados', 2)
            ->json('data');
        $this->assertCount(2, $diagnostico['regularizaciones_recientes']);
        $this->artisan('temporadas:diagnosticar', ['temporada' => $activa->codigo])->assertExitCode(0);

        $this->expectException(DomainException::class);
        $registro->update(['motivo' => 'Intento de reescribir la auditoría.']);
    }

    public function test_el_plano_marca_registros_sin_cerrar_de_otra_temporada_y_bloquean_la_activacion(): void
    {
        $administrador = $this->administrador();
        $activa = $this->activa();
        $ensayo = $this->temporada('ENSAYO-CIERRE', ['tipo' => 'prueba']);
        $siguiente = $this->temporada('SIGUIENTE-2');
        [$camara, $posicion] = $this->camaraConPosicion('CAM-FANTASMA', bandas: 2);
        $fantasma = $this->folio($ensayo, 'PT-FANTASMA', 'pallet');
        $this->ubicar($fantasma, $camara, $posicion);
        $vigente = $this->folio($activa, 'PT-VIGENTE', 'pallet');
        $this->ubicar($vigente, $camara, Posicion::query()->create([
            'camara_id' => $camara->id, 'banda' => 2, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B02-P01-N1',
        ]));

        $plano = $this->actingAs($administrador, 'sanctum')
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.registros_sin_cerrar', 1)
            ->json('data.posiciones');
        $porFolio = collect($plano)->pluck('folio')->filter()->keyBy('numero_folio');
        $this->assertSame('ENSAYO-CIERRE', $porFolio['PT-FANTASMA']['registro_sin_cerrar']['temporada']['codigo']);
        $this->assertSame('prueba', $porFolio['PT-FANTASMA']['registro_sin_cerrar']['temporada']['tipo']);
        $this->assertNull($porFolio['PT-VIGENTE']['registro_sin_cerrar']);

        // La vigente también tiene un folio pendiente, pero el fantasma de la
        // temporada de prueba se informa por separado.
        $this->postJson("/api/administracion/temporadas/{$siguiente->id}/activar")
            ->assertConflict()
            ->assertJsonFragment(['codigo' => 'ENSAYO-CIERRE'])
            ->assertJsonFragment(['etiqueta' => 'Folios PT que aún figuran en cámaras']);

        $this->regularizar($ensayo, 'folios_pt', [$fantasma->id], 'dato_prueba')->assertOk();
        $this->regularizar($activa, 'folios_pt', [$vigente->id], 'merma_anulacion')->assertOk();

        $this->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.registros_sin_cerrar', 0);
        $this->postJson("/api/administracion/temporadas/{$siguiente->id}/activar")->assertOk();
    }

    public function test_avisa_a_cada_responsable_una_vez_y_la_oficina_muestra_el_aviso(): void
    {
        $administrador = $this->administrador();
        $activa = $this->activa();
        $recepcion = $this->recepcionAbierta($activa, 'GUIA-AVISO-01');
        $planificador = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $this->carga($activa, 'CAR-AVISO', $planificador);
        $this->folio($activa, 'PT-SIN-RESPONSABLE', 'pallet');

        $resultado = $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$activa->id}/cierre/avisar")
            ->assertOk()
            ->assertJsonPath('data.sin_responsable', 1)
            ->json('data.avisados');
        $this->assertCount(2, $resultado);
        $this->assertTrue(collect($resultado)->every(fn (array $aviso): bool => $aviso['nuevo']));

        $operador = User::query()->findOrFail($recepcion['creado_por_user_id']);
        $aviso = NotificacionOperacional::query()
            ->where('audiencia_tipo', 'usuario')
            ->where('audiencia_valor', (string) $operador->id)
            ->firstOrFail();
        $this->assertSame('cierre_temporada_pendiente', $aviso->tipo->value);
        $this->assertStringContainsString('2 registros sin cerrar', $aviso->mensaje);
        $this->assertSame($activa->codigo, $aviso->datos['temporada']['codigo']);

        // Sin cambios en sus pendientes, el aviso no se repite.
        $this->postJson("/api/administracion/temporadas/{$activa->id}/cierre/avisar")
            ->assertOk()
            ->assertJsonPath('data.avisados.0.nuevo', false);
        $this->assertSame(2, NotificacionOperacional::query()->where('tipo', 'cierre_temporada_pendiente')->count());

        $this->actingAs($planificador, 'sanctum')
            ->getJson('/api/oficina/contexto')
            ->assertOk()
            ->assertJsonCount(1, 'data.avisos_cierre')
            ->assertJsonPath('data.avisos_cierre.0.titulo', "Cierre de temporada {$activa->codigo}");
        $avisoPlanificador = NotificacionOperacional::query()
            ->where('audiencia_valor', (string) $planificador->id)
            ->firstOrFail();
        $this->postJson("/api/notificaciones-operacionales/{$avisoPlanificador->id}/leer")->assertOk();
        $this->getJson('/api/oficina/contexto')->assertOk()->assertJsonCount(0, 'data.avisos_cierre');
    }

    public function test_regularizar_una_sesion_de_estiba_la_cierra_y_libera_la_camara(): void
    {
        $administrador = $this->administrador();
        $activa = $this->activa();
        [$camara] = $this->camaraConPosicion('CAM-SESION');
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $dispositivo = Dispositivo::create(['codigo' => 'TAB-CIERRE', 'nombre' => 'Tablet cierre']);
        $sesion = SesionEstiba::create([
            'camara_id' => $camara->id,
            'user_id' => $camarero->id,
            'dispositivo_id' => $dispositivo->id,
            'estado' => 'abierta',
            'version_inicial' => 0,
            'iniciada_at' => now(),
            'ultima_actividad_at' => now(),
        ]);
        BloqueoCamara::create([
            'camara_id' => $camara->id,
            'sesion_estiba_id' => $sesion->id,
            'adquirido_at' => now(),
        ]);
        // Una sesión abierta en una cámara de Materiales no es un pendiente.
        $bodega = Camara::create(['codigo' => 'MAT-CIERRE', 'nombre' => 'Bodega', 'contenido' => 'materiales']);
        SesionEstiba::create([
            'camara_id' => $bodega->id,
            'user_id' => User::factory()->create(['rol' => RolUsuario::CamareroMateriales])->id,
            'dispositivo_id' => Dispositivo::create(['codigo' => 'TAB-MAT', 'nombre' => 'Tablet bodega'])->id,
            'estado' => 'abierta',
            'version_inicial' => 0,
            'iniciada_at' => now(),
            'ultima_actividad_at' => now(),
        ]);
        $ensayo = $this->temporada('ENSAYO-SESION', ['tipo' => 'prueba']);

        $this->actingAs($administrador, 'sanctum')
            ->getJson("/api/administracion/temporadas/{$ensayo->id}/cierre")
            ->assertOk()
            ->assertJsonPath('data.categorias.9.categoria', 'sesiones_estiba')
            ->assertJsonPath('data.categorias.9.aplica', false)
            ->assertJsonPath('data.categorias.9.total', 0);

        $this->getJson("/api/administracion/temporadas/{$activa->id}/cierre")
            ->assertOk()
            ->assertJsonPath('data.categorias.9.total', 1)
            ->assertJsonPath('data.categorias.9.items.0.referencia', 'CAM-SESION');

        $this->regularizar($activa, 'sesiones_estiba', [$sesion->id], 'error_digitacion')
            ->assertOk();

        $sesion->refresh();
        $this->assertSame('cierre_forzado', $sesion->estado->value);
        $this->assertStringStartsWith('Cierre de temporada (Error de digitación):', (string) $sesion->motivo_cierre);
        $this->assertDatabaseMissing('bloqueos_camara', ['camara_id' => $camara->id]);
        $this->assertDatabaseHas('regularizaciones_cierre_temporada', [
            'categoria' => 'sesiones_estiba',
            'entidad_id' => $sesion->id,
            'referencia' => 'CAM-SESION',
        ]);
    }

    private function regularizar(
        Temporada $temporada,
        string $categoria,
        array $ids,
        string $motivoCategoria,
        string $motivo = 'Registro revisado en el cierre de la temporada.',
    ) {
        return $this->postJson("/api/administracion/temporadas/{$temporada->id}/cierre/regularizar", [
            'categoria' => $categoria,
            'ids' => $ids,
            'motivo_categoria' => $motivoCategoria,
            'motivo' => $motivo,
        ]);
    }

    private function administrador(): User
    {
        return User::factory()->create(['rol' => RolUsuario::Administrador]);
    }

    private function activa(): Temporada
    {
        $activa = Temporada::query()->where('activa', true)->firstOrFail();
        if ($activa->prefijo_documental === null || $activa->fecha_inicio === null) {
            $activa->update($this->vigenciaProductiva());
        }

        return $activa->refresh();
    }

    /** @param  array<string, mixed>  $atributos */
    private function temporada(string $codigo, array $atributos = []): Temporada
    {
        return app(ServicioTemporadaGlobal::class)->guardar([
            'codigo' => $codigo,
            'nombre' => "Temporada {$codigo}",
            ...$this->vigenciaProductiva(),
            ...$atributos,
        ]);
    }

    /** @param  array<string, mixed>  $atributos */
    private function folio(Temporada $temporada, string $numero, string $tipo, array $atributos = []): Folio
    {
        return Folio::query()->create([
            'temporada_id' => $temporada->id,
            'numero_folio' => $numero,
            'tipo_bulto' => $tipo,
            'estado_operacional' => 'disponible',
            'fecha_ingreso' => now(),
            'activo' => true,
            ...$atributos,
        ]);
    }

    private function carga(Temporada $temporada, string $codigo, User $usuario): Carga
    {
        return Carga::query()->create([
            'temporada_id' => $temporada->id,
            'codigo' => $codigo,
            'estado' => 'borrador',
            'creada_por_user_id' => $usuario->id,
            'actualizada_por_user_id' => $usuario->id,
        ]);
    }

    /** @return array{Camara, Posicion} */
    private function camaraConPosicion(string $codigo, int $bandas = 1): array
    {
        $camara = Camara::create([
            'codigo' => $codigo,
            'nombre' => "Cámara {$codigo}",
            'cantidad_bandas' => $bandas,
        ]);
        $posicion = Posicion::query()->create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);

        return [$camara, $posicion];
    }

    private function ubicar(Folio $folio, Camara $camara, ?Posicion $posicion = null): void
    {
        UbicacionActual::query()->create([
            'folio_id' => $folio->id,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion?->id,
            'ubicado_at' => now(),
        ]);
    }

    /** @return array<string, mixed> Recepción en báscula de ingreso, sin validar. */
    private function recepcionAbierta(Temporada $temporada, string $guia): array
    {
        $cliente = Cliente::query()->firstOrCreate(
            ['codigo' => 'CLI-CIERRE'],
            ['nombre' => 'Cliente cierre', 'activo' => true],
        );
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', [
                'operacion_id' => (string) Str::uuid(),
                'temporada_id' => $temporada->id,
                'cliente_id' => $cliente->id,
                'tipo_recepcion' => 'fruta_con_envases',
                'especie_validacion_id' => EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true])->id,
                'tipo_servicio' => 'proceso',
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 24]],
                'numero_guia_despacho' => $guia,
                'patente_camion' => 'ABCD12',
                'tipo_camion' => 'termo',
                'rut_conductor' => '12.345.678-5',
                'nombre_conductor' => 'Transportista de prueba',
                'peso_bruto' => 18000,
            ])
            ->assertCreated()
            ->json('data');

        return [...$recepcion, 'creado_por_user_id' => $operador->id];
    }
}
