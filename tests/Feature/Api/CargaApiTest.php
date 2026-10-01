<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\ClienteMaterial;
use App\Models\Dispositivo;
use App\Models\EventoCarga;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\User;
use App\Services\Cargas\ServicioCarga;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CargaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_despachador_crea_publica_y_expone_la_carga_en_tablet_y_plano(): void
    {
        $despachador = $this->despachador();
        [$camara, $folio] = $this->crearFolioUbicado('FOLIO-CARGA-01');

        $creada = $this->actingAs($despachador, 'sanctum')
            ->postJson('/api/cargas', [
                'numero_orden_externa' => 'OE-1001',
                'prioridad' => 'alta',
                'camara_objetivo_id' => $camara->id,
                'observacion' => 'Preparar para embarque.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'CAR-000001')
            ->assertJsonPath('data.temporada.activa', true)
            ->assertJsonPath('data.estado', 'borrador');

        $cargaId = $creada->json('data.id');

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$cargaId}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.total_folios', 1)
            ->assertJsonPath('data.folios.0.ubicacion.camara.codigo', 'CAM-01');

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/pendientes')
            ->assertJsonMissing(['codigo' => 'CAR-000001']);

        $this->actingAs($despachador, 'sanctum')
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath(
                'data.posiciones.0.folio.carga_actual',
                null,
            );

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$cargaId}/publicar", [
                'version_esperada' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.total_folios', 1);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.codigo', 'CAR-000001')
            ->assertJsonPath('data.0.distribucion.0.cantidad', 1);

        $this->actingAs($despachador, 'sanctum')
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath(
                'data.posiciones.0.folio.carga_actual.codigo',
                'CAR-000001',
            );
    }

    public function test_bandeja_operacional_usa_etag_y_evade_el_detalle_si_no_cambia(): void
    {
        $despachador = $this->despachador();
        [$camara, $folio] = $this->crearFolioUbicado('FOLIO-CARGA-ETAG');
        $carga = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk();
        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/publicar", [
                'version_esperada' => 2,
            ])
            ->assertOk();

        $inicial = $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/pendientes')
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'ETag')
            ->assertJsonPath('data.0.codigo', $carga->codigo)
            ->assertJsonPath('data.0.total_folios', 1);
        $etagInicial = $inicial->headers->get('ETag');

        $this->assertNotNull($etagInicial);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($despachador, 'sanctum')
            ->withHeader('If-None-Match', $etagInicial)
            ->get('/api/cargas/pendientes')
            ->assertStatus(304)
            ->assertHeader('ETag', $etagInicial);

        $consultoBandeja = collect(DB::getQueryLog())->contains(function (array $consulta): bool {
            $sql = Str::lower(Str::squish(str_replace(['`', '"'], '', $consulta['query'])));

            return str_contains($sql, 'from cargas')
                || str_contains($sql, 'from carga_folios')
                || str_contains($sql, 'from reservas_carga_folio')
                || str_contains($sql, 'from incidencias_carga_folio')
                || str_contains($sql, 'from ubicaciones_actuales')
                || str_contains($sql, 'from posiciones')
                || str_contains($sql, 'from camaras');
        });
        $this->assertFalse(
            $consultoBandeja,
            'Un 304 de cargas no debe consultar ni reconstruir la bandeja operacional.',
        );
        DB::disableQueryLog();
        DB::flushQueryLog();

        $camara->increment('version_plano');
        $actualizada = $this->actingAs($despachador, 'sanctum')
            ->withHeader('If-None-Match', $etagInicial)
            ->getJson('/api/cargas/pendientes')
            ->assertOk();

        $this->assertNotSame($etagInicial, $actualizada->headers->get('ETag'));
    }

    public function test_un_folio_no_puede_pertenecer_a_dos_cargas_activas(): void
    {
        $despachador = $this->despachador();
        [, $folio] = $this->crearFolioUbicado('FOLIO-UNICO');
        $primera = $this->crearCarga($despachador);
        $segunda = $this->crearCarga($despachador);

        $this->assertSame('CAR-000001', $primera->codigo);
        $this->assertSame('CAR-000002', $segunda->codigo);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$primera->id}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk();

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$segunda->id}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'folios_no_asignables')
            ->assertJsonPath('errores.0.codigo', 'asignado_otra_carga');
    }

    public function test_bandejas_de_carga_no_mezclan_ordenes_de_temporadas_anteriores(): void
    {
        $despachador = $this->despachador();
        $cargaAnterior = $this->crearCarga($despachador);
        $cargaAnterior->update(['estado' => 'pendiente']);

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'CARGA-NUEVA',
            'nombre' => 'Temporada nueva de cargas',
            'activa' => true,
        ], usuarioId: $despachador->id);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['id' => $cargaAnterior->id]);

        $this->getJson('/api/cargas/pendientes')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_cancelar_libera_los_folios_y_conserva_eventos(): void
    {
        $despachador = $this->despachador();
        [, $folio] = $this->crearFolioUbicado('FOLIO-LIBERADO');
        $carga = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk();

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/publicar", [
                'version_esperada' => 2,
            ])
            ->assertOk();

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/cancelar", [
                'version_esperada' => 3,
                'motivo' => 'Orden reemplazada por el despacho.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada')
            ->assertJsonPath('data.version', 4);

        $this->assertDatabaseHas('carga_folios', [
            'folio_id' => $folio->id,
            'estado' => 'descartado',
        ]);
        $this->assertDatabaseMissing('reservas_carga_folio', [
            'folio_id' => $folio->id,
        ]);
        $this->assertTrue(
            EventoCarga::query()
                ->where('carga_id', $carga->id)
                ->where('tipo', 'cancelada')
                ->exists(),
        );
        $this->assertTrue(
            EventoCarga::query()
                ->where('carga_id', $carga->id)
                ->where('folio_id', $folio->id)
                ->where('tipo', 'folio_desasignado')
                ->exists(),
        );

        $nueva = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$nueva->id}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk();
    }

    public function test_rechaza_folios_no_disponibles_o_sin_ubicacion(): void
    {
        $despachador = $this->despachador();
        $carga = $this->crearCarga($despachador);
        $bloqueado = Folio::create([
            'numero_folio' => 'FOLIO-BLOQUEADO',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Bloqueado,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [$bloqueado->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'folios_no_asignables')
            ->assertJsonPath('errores.0.codigo', 'estado_no_disponible');

        $sinUbicacion = Folio::create([
            'numero_folio' => 'FOLIO-SIN-UBICACION',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [$sinUbicacion->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'folios_no_asignables')
            ->assertJsonPath('errores.0.codigo', 'sin_ubicacion');
    }

    public function test_asigna_pallet_en_tunel_sin_posicion_pero_rechaza_saldo_y_reserva_de_otra_carga(): void
    {
        $despachador = $this->despachador();
        $primera = $this->crearCarga($despachador);
        $segunda = $this->crearCarga($despachador);
        $pallet = Folio::create([
            'numero_folio' => 'PAL-TUNEL-SIN-POSICION',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $saldo = Folio::create([
            'numero_folio' => 'SALDO-TUNEL',
            'tipo_bulto' => TipoBulto::Saldo,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles?q=PAL-TUNEL-SIN-POSICION')
            ->assertOk()
            ->assertJsonPath('data.0.ubicacion', null);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$primera->id}/folios", [
                'folios' => [$pallet->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.folios.0.ubicacion', null);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$segunda->id}/folios", [
                'folios' => [$pallet->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errores.0.codigo', 'asignado_otra_carga');

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$segunda->id}/folios", [
                'folios' => [$saldo->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errores.0.codigo', 'tipo_bulto_no_permitido');
    }

    public function test_operador_no_gestiona_cargas_y_el_lote_no_supera_26_folios(): void
    {
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);

        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/cargas', [])
            ->assertForbidden();

        $despachador = $this->despachador();
        $carga = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => collect(range(1, 27))
                    ->map(fn (int $numero): string => "FOLIO-{$numero}")
                    ->all(),
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folios');
    }

    public function test_el_lote_es_atomico_y_entrega_errores_por_folio(): void
    {
        $despachador = $this->despachador();
        [, $folio] = $this->crearFolioUbicado('FOLIO-VALIDO');
        $carga = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [$folio->numero_folio, 'FOLIO-INEXISTENTE'],
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'folios_no_asignables')
            ->assertJsonPath('errores.0.folio', 'FOLIO-INEXISTENTE')
            ->assertJsonPath('errores.0.codigo', 'no_existe');

        $this->assertDatabaseMissing('carga_folios', [
            'carga_id' => $carga->id,
        ]);
        $this->assertSame(1, $carga->refresh()->version);
    }

    public function test_una_carga_pendiente_admite_cambios_con_control_de_version(): void
    {
        $despachador = $this->despachador();
        [, $folio] = $this->crearFolioUbicado('FOLIO-VERSIONADO');
        $carga = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/publicar", [
                'version_esperada' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 3);

        $this->actingAs($despachador, 'sanctum')
            ->putJson("/api/cargas/{$carga->id}", [
                'prioridad' => 'urgente',
                'version_esperada' => 2,
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->actingAs($despachador, 'sanctum')
            ->putJson("/api/cargas/{$carga->id}", [
                'prioridad' => 'urgente',
                'version_esperada' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.prioridad', 'urgente')
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.actualizada_por.id', $despachador->id);

        $this->actingAs($despachador, 'sanctum')
            ->deleteJson("/api/cargas/{$carga->id}/folios/{$folio->id}", [
                'version_esperada' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertSame(4, $carga->refresh()->version);
    }

    public function test_publicar_revalida_el_estado_actual_de_todos_los_folios(): void
    {
        $despachador = $this->despachador();
        [, $folio] = $this->crearFolioUbicado('FOLIO-CAMBIO-ESTADO');
        $carga = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk();

        $folio->update(['estado_operacional' => EstadoOperacionalFolio::Bloqueado]);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/publicar", [
                'version_esperada' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'folios_no_asignables')
            ->assertJsonPath('errores.0.codigo', 'estado_no_disponible');

        $this->assertSame('borrador', $carga->refresh()->estado->value);
        $this->assertSame(2, $carga->version);
    }

    public function test_el_despachador_accede_a_oficina_sin_administrar_camaras(): void
    {
        $despachador = $this->despachador();

        $this->postJson('/api/acceso-oficina', [
            'email' => $despachador->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.puede_gestionar_cargas', true)
            ->assertJsonPath('usuario.puede_configurar_camaras', false)
            ->assertJsonPath('usuario.puede_administrar_camaras', false);

        $this->actingAs($despachador, 'sanctum')
            ->postJson('/api/configuracion/camaras', [])
            ->assertForbidden();
    }

    public function test_los_duplicados_se_detectan_despues_de_normalizar_el_folio(): void
    {
        $despachador = $this->despachador();
        $carga = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [' FOLIO-DUPLICADO ', 'FOLIO-DUPLICADO'],
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folios.1');
    }

    public function test_los_materiales_no_aparecen_entre_los_folios_disponibles_para_cargas(): void
    {
        $despachador = $this->despachador();
        [, $producto] = $this->crearFolioUbicado('FOLIO-PRODUCTO');
        [, $material] = $this->crearFolioMaterialUbicado('MAT-OCULTO');

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles')
            ->assertOk()
            ->assertJsonFragment(['numero_folio' => $producto->numero_folio])
            ->assertJsonMissing(['numero_folio' => $material->numero_folio]);
    }

    public function test_rechaza_incorporar_un_material_directamente_a_una_carga(): void
    {
        $despachador = $this->despachador();
        [, $material] = $this->crearFolioMaterialUbicado('MAT-NO-CARGA');
        $carga = $this->crearCarga($despachador);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/folios", [
                'folios' => [$material->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'folios_no_asignables')
            ->assertJsonPath('errores.0.codigo', 'tipo_bulto_no_permitido');

        $this->assertDatabaseMissing('carga_folios', [
            'carga_id' => $carga->id,
            'folio_id' => $material->id,
        ]);
    }

    public function test_publicar_rechaza_una_carga_previamente_contaminada_con_materiales(): void
    {
        $despachador = $this->despachador();
        [, $material] = $this->crearFolioMaterialUbicado('MAT-CONTAMINADO');
        $carga = $this->crearCarga($despachador);
        $asignacion = CargaFolio::create([
            'carga_id' => $carga->id,
            'folio_id' => $material->id,
            'asignado_por_user_id' => $despachador->id,
            'asignado_at' => now(),
        ]);
        ReservaCargaFolio::create([
            'folio_id' => $material->id,
            'carga_folio_id' => $asignacion->id,
        ]);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/publicar", [
                'version_esperada' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'folios_no_asignables')
            ->assertJsonPath('errores.0.codigo', 'tipo_bulto_no_permitido');

        $this->assertSame('borrador', $carga->refresh()->estado->value);
    }

    public function test_rechaza_una_camara_de_materiales_como_objetivo_en_api_y_servicio(): void
    {
        $despachador = $this->despachador();
        [$camaraMateriales] = $this->crearFolioMaterialUbicado('MAT-CAMARA-OBJETIVO');

        $this->actingAs($despachador, 'sanctum')
            ->postJson('/api/cargas', [
                'camara_objetivo_id' => $camaraMateriales->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('camara_objetivo_id');

        try {
            app(ServicioCarga::class)->crear([
                'camara_objetivo_id' => $camaraMateriales->id,
            ], $despachador);
            $this->fail('El servicio aceptó una cámara de materiales como objetivo.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cámara de productos', $exception->getMessage());
        }
    }

    public function test_no_modifica_ni_cancela_una_carga_de_temporada_inactiva(): void
    {
        $despachador = $this->despachador();
        $carga = $this->crearCarga($despachador);

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'CARGA-CERRADA',
            'nombre' => 'Temporada que reemplaza a la de la carga',
            'activa' => true,
        ], usuarioId: $despachador->id);

        $this->actingAs($despachador, 'sanctum')
            ->putJson("/api/cargas/{$carga->id}", [
                'version_esperada' => 1,
                'prioridad' => 'alta',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'temporada_no_activa');

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/cancelar", [
                'version_esperada' => 1,
                'motivo' => 'Cancelación fuera de la temporada activa.',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'temporada_no_activa');

        $this->assertDatabaseHas('cargas', ['id' => $carga->id, 'version' => 1, 'estado' => $carga->estado->value]);
    }

    private function despachador(): User
    {
        return User::factory()->create([
            'rol' => RolUsuario::Despachador,
            'activo' => true,
        ]);
    }

    private function crearCarga(User $usuario): Carga
    {
        $id = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/cargas', [])
            ->assertCreated()
            ->json('data.id');

        return Carga::query()->findOrFail($id);
    }

    /**
     * @return array{Camara, Folio}
     */
    private function crearFolioUbicado(string $numeroFolio): array
    {
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-'.Str::upper(Str::random(6)),
            'nombre' => 'Tablet de prueba',
        ]);
        $camara = Camara::create([
            'codigo' => 'CAM-01',
            'nombre' => 'Cámara 01',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        $sesion = app(ServicioSesionEstiba::class)
            ->abrir($camara, $operador, $dispositivo);
        $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $numeroFolio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicion,
            sesionDestino: $sesion,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionDestinoConocida: 0,
            generadoDispositivoAt: now(),
        );

        return [$camara, $movimiento->folio];
    }

    /**
     * @return array{Camara, Folio}
     */
    private function crearFolioMaterialUbicado(string $numeroFolio): array
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroMateriales,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-MAT-'.Str::upper(Str::random(6)),
            'nombre' => 'Tablet de materiales',
        ]);
        $item = ItemMaterial::create([
            'cliente_material_id' => ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail()->id,
            'codigo' => 'ITEM-'.Str::upper(Str::random(6)),
            'nombre' => 'Material de prueba',
            'unidad_medida' => 'unidades',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        $camara = Camara::create([
            'codigo' => 'MAT-'.Str::upper(Str::random(5)),
            'nombre' => 'Cámara de materiales',
            'contenido' => ContenidoCamara::Materiales,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        $sesion = app(ServicioSesionEstiba::class)
            ->abrir($camara, $operador, $dispositivo);
        $folio = Folio::create([
            'numero_folio' => $numeroFolio,
            'tipo_bulto' => TipoBulto::Material,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'fecha_ingreso' => now(),
            'activo' => true,
            'origen_sistema' => 'recepcion_materiales',
        ]);
        FolioMaterial::create([
            'folio_id' => $folio->id,
            'item_material_id' => $item->id,
            'cantidad_inicial' => 10,
            'cantidad_actual' => 10,
            'cantidad_reservada' => 0,
            'unidad_medida' => $item->unidad_medida,
        ]);
        $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $folio->numero_folio,
            tipoBulto: TipoBulto::Material,
            posicionDestino: $posicion,
            sesionDestino: $sesion,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionDestinoConocida: 0,
            generadoDispositivoAt: now(),
        );

        return [$camara, $movimiento->folio];
    }
}
