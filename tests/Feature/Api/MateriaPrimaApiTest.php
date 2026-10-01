<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaPendienteCierre;
use App\Enums\ContenidoCamara;
use App\Enums\MotivoRegularizacionCierre;
use App\Enums\RolUsuario;
use App\Models\CalibreValidacion;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EntregaFrutaProceso;
use App\Models\EspecieValidacion;
use App\Models\Temporada;
use App\Models\TipoResultadoPacking;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\MateriaPrima\RellenoEnvasesLotes;
use App\Services\MateriaPrima\ServicioCompletarEnvasesSegmentos;
use App\Services\Temporadas\Cierre\ServicioRegularizacionCierreTemporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class MateriaPrimaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ultimo_lote_concilia_milesimas_por_contenedor_sin_repartir_por_esponjas(): void
    {
        $contexto = $this->prepararRecepcionValidada(tresTipos: true);
        DB::table('recepciones_romana')->where('id', $contexto['recepcion_id'])->update(['peso_neto' => 18000.020]);
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $this->actingAs($digitador, 'sanctum');
        $primero = $this->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
            'numero_lote' => 'REPARTO-1', 'cuartel' => null,
        ]))->assertCreated()->assertJsonPath('data.pesos.kilos_netos_calculados', 7500)->json('data');
        $segundo = $this->postJson('/api/materia-prima/lotes', $this->payloadLote(
            [...$contexto, 'segmento_id' => $contexto['segundo_segmento_id']],
            ['numero_lote' => 'REPARTO-2', 'cuartel' => null],
        ))->assertCreated()->assertJsonPath('data.pesos.kilos_netos_calculados', 10500.02)->json('data');
        $this->assertEqualsWithDelta(18000.020,
            $primero['pesos']['kilos_netos_calculados'] + $segundo['pesos']['kilos_netos_calculados'], 0.00001);
        $this->assertEqualsWithDelta(11634.02, $segundo['pesos']['kilos_brutos'], 0.00001);
    }

    public function test_comando_previsualiza_completa_audita_y_es_idempotente(): void
    {
        $contexto = $this->prepararRecepcionValidada(tresTipos: true);
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, ['cuartel' => null]))
            ->assertCreated()->json('data');
        DB::table('lotes_materia_prima_envases')->where('lote_materia_prima_id', $lote['id'])
            ->where('tipo_envase', 'esponjas')->delete();
        DB::table('segmentos_validacion_mp')->where('id', $contexto['segmento_id'])
            ->update(['estado' => 'lotizacion_parcial']);
        DB::table('lotes_materia_prima')->where('id', $lote['id'])->update(['kilos_brutos' => 8000]);
        $antes = DB::table('eventos_lote_materia_prima')->count();
        $this->artisan('materia-prima:completar-envases-segmentos')->assertSuccessful();
        $this->assertDatabaseMissing('lotes_materia_prima_envases', [
            'lote_materia_prima_id' => $lote['id'], 'tipo_envase' => 'esponjas',
        ]);
        $this->assertDatabaseHas('lotes_materia_prima', ['id' => $lote['id'], 'kilos_brutos' => 8000]);
        $this->assertDatabaseHas('segmentos_validacion_mp', [
            'id' => $contexto['segmento_id'], 'estado' => 'lotizacion_parcial',
        ]);
        $this->assertSame($antes, DB::table('eventos_lote_materia_prima')->count());

        $this->artisan('materia-prima:completar-envases-segmentos --aplicar')->assertSuccessful();
        $this->assertDatabaseHas('lotes_materia_prima_envases', [
            'lote_materia_prima_id' => $lote['id'], 'tipo_envase' => 'esponjas',
            'cantidad' => 3, 'tara_unitaria' => 1,
        ]);
        $this->assertDatabaseHas('lotes_materia_prima', [
            'id' => $lote['id'], 'kilos_brutos' => $lote['pesos']['kilos_brutos'],
        ]);
        $this->assertDatabaseHas('segmentos_validacion_mp', [
            'id' => $contexto['segmento_id'], 'estado' => 'lotizado',
        ]);
        $evento = DB::table('eventos_lote_materia_prima')->where('lote_materia_prima_id', $lote['id'])
            ->where('tipo', 'envases_segmento_completados')->first();
        $this->assertNotNull($evento);
        $this->assertNull($evento->user_id);
        $datos = json_decode($evento->datos, true);
        $this->assertSame(8000, $datos['bruto_anterior']);
        $this->assertSame('esponjas', $datos['envases_agregados'][0]['tipo_envase']);
        $this->assertSame($antes + 1, DB::table('eventos_lote_materia_prima')->count());
        $this->artisan('materia-prima:completar-envases-segmentos --aplicar')->assertSuccessful();
        $this->assertSame($antes + 1, DB::table('eventos_lote_materia_prima')->count());
    }

    public function test_migracion_solo_copia_resumen_sin_cambiar_pesos_cantidades_ni_segmentos(): void
    {
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto))
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('segmentos_validacion_mp', ['id' => $contexto['segmento_id'], 'estado' => 'lotizado']);
        DB::table('lotes_materia_prima_envases')->where('lote_materia_prima_id', $lote['id'])->delete();
        DB::table('lotes_materia_prima')->where('id', $lote['id'])->update([
            'cantidad_envases_primarios' => 20, 'cantidad_envases_secundarios' => 4,
            'kilos_brutos' => 12345,
        ]);
        DB::table('segmentos_validacion_mp')->where('id', $contexto['segmento_id'])->update(['estado' => 'lotizacion_parcial']);

        // Otro segmento histórico con dos lotes: el relleno no modifica sus pesos ni su estado.
        $original = (array) DB::table('segmentos_validacion_mp')->where('id', $contexto['segmento_id'])->first();
        $otroSegmentoId = (string) Str::uuid();
        DB::table('segmentos_validacion_mp')->insert([
            ...$original, 'id' => $otroSegmentoId, 'secuencia' => 2, 'estado' => 'lotizacion_parcial',
        ]);
        foreach (DB::table('segmentos_envases_validacion_mp')->where('segmento_validacion_mp_id', $contexto['segmento_id'])->get() as $envase) {
            DB::table('segmentos_envases_validacion_mp')->insert([
                ...(array) $envase, 'id' => (string) Str::uuid(), 'segmento_validacion_mp_id' => $otroSegmentoId,
            ]);
        }
        $base = (array) DB::table('lotes_materia_prima')->where('id', $lote['id'])->first();
        foreach (['HIST-A', 'HIST-B'] as $numero) {
            DB::table('lotes_materia_prima')->insert([
                ...$base, 'id' => (string) Str::uuid(), 'operacion_id' => (string) Str::uuid(),
                'segmento_validacion_mp_id' => $otroSegmentoId, 'numero_lote' => $numero,
                'clave_numero_vigente' => hash('sha256', $numero),
            ]);
        }
        $relleno = app(RellenoEnvasesLotes::class);
        $relleno->copiarResumenExistente();
        $this->assertDatabaseHas('lotes_materia_prima', [
            'id' => $lote['id'], 'cantidad_envases_primarios' => 20,
            'cantidad_envases_secundarios' => 4, 'kilos_brutos' => 12345,
        ]);
        $this->assertDatabaseHas('segmentos_validacion_mp', ['id' => $contexto['segmento_id'], 'estado' => 'lotizacion_parcial']);
        $this->assertDatabaseHas('segmentos_validacion_mp', ['id' => $otroSegmentoId, 'estado' => 'lotizacion_parcial']);
        $this->assertDatabaseCount('lotes_materia_prima_envases', 6);
        $antes = DB::table('lotes_materia_prima_envases')->orderBy('lote_materia_prima_id')->orderBy('tipo_envase')->get()->toArray();
        $relleno->copiarResumenExistente();
        $this->assertEquals($antes, DB::table('lotes_materia_prima_envases')->orderBy('lote_materia_prima_id')->orderBy('tipo_envase')->get()->toArray());
        $this->assertSame(1, app(ServicioCompletarEnvasesSegmentos::class)->ejecutar()['resumen']['segmentos_divididos']);
        $this->artisan('materia-prima:completar-envases-segmentos --aplicar')->assertSuccessful();
        $this->assertDatabaseHas('segmentos_validacion_mp', ['id' => $otroSegmentoId, 'estado' => 'lotizacion_parcial']);
    }

    public function test_comando_omite_lotes_entregados_y_parcialmente_entregados(): void
    {
        $contexto = $this->prepararRecepcionValidada(tresTipos: true);
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $this->actingAs($digitador, 'sanctum');
        $primero = $this->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
            'numero_lote' => 'ENTREGADO-1', 'cuartel' => null,
        ]))->assertCreated()->json('data');
        $segundo = $this->postJson('/api/materia-prima/lotes', $this->payloadLote(
            [...$contexto, 'segmento_id' => $contexto['segundo_segmento_id']],
            ['numero_lote' => 'PARCIAL-2', 'cuartel' => null],
        ))->assertCreated()->json('data');
        foreach ([[$primero, $contexto['segmento_id']], [$segundo, $contexto['segundo_segmento_id']]] as [$lote, $segmentoId]) {
            DB::table('lotes_materia_prima_envases')->where('lote_materia_prima_id', $lote['id'])
                ->where('tipo_envase', 'esponjas')->delete();
            DB::table('segmentos_validacion_mp')->where('id', $segmentoId)
                ->update(['estado' => 'lotizacion_parcial']);
        }
        DB::table('lotes_materia_prima')->where('id', $primero['id'])->update(['estado' => 'entregado_proceso']);
        DB::table('lotes_materia_prima')->where('id', $segundo['id'])->update(['estado' => 'entrega_parcial_proceso']);
        $eventosAntes = DB::table('eventos_lote_materia_prima')->count();
        $resultado = app(ServicioCompletarEnvasesSegmentos::class)->ejecutar(true);
        $this->assertSame(2, $resultado['resumen']['omitidos_entregados']);
        $this->assertSame(0, $resultado['resumen']['lotes_completados']);
        foreach ([$primero, $segundo] as $lote) {
            $this->assertDatabaseMissing('lotes_materia_prima_envases', [
                'lote_materia_prima_id' => $lote['id'], 'tipo_envase' => 'esponjas',
            ]);
        }
        $this->assertDatabaseCount('eventos_lote_materia_prima', $eventosAntes);
    }

    public function test_comando_no_modifica_otra_temporada_y_sin_temporada_activa_no_hace_nada(): void
    {
        $contexto = $this->prepararRecepcionValidada(tresTipos: true);
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, ['cuartel' => null]))
            ->assertCreated()->json('data');
        DB::table('lotes_materia_prima_envases')->where('lote_materia_prima_id', $lote['id'])
            ->where('tipo_envase', 'esponjas')->delete();
        $contexto['temporada']->update(['activa' => false]);
        app(ServicioTemporadaActiva::class)->olvidar();
        $this->assertNull(app(ServicioCompletarEnvasesSegmentos::class)->ejecutar(true)['temporada']);
        $nueva = Temporada::create(['codigo' => 'MP-NUEVA', 'nombre' => 'Temporada nueva', 'activa' => true]);
        $resultado = app(ServicioCompletarEnvasesSegmentos::class)->ejecutar(true);
        $this->assertSame(1, $resultado['resumen']['omitidos_otras_temporadas']);
        $this->assertSame([], $resultado['lotes']);
        $this->assertDatabaseMissing('lotes_materia_prima_envases', [
            'lote_materia_prima_id' => $lote['id'], 'tipo_envase' => 'esponjas',
        ]);
        $nueva->update(['activa' => false]);
    }

    public function test_cancelar_hidrocooler_en_cierre_libera_equipo_sin_inventar_mediciones(): void
    {
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, ['requiere_hidrocooler' => true]))
            ->assertCreated()
            ->assertJsonPath('data.pesos.kilos_brutos', 19940)
            ->json('data');
        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/iniciar", [
            'operacion_id' => (string) Str::uuid(),
            'equipo' => 'HIDRO-CIERRE',
            'turno' => 'A',
            'cantidad_bombas_funcionando' => 3,
            'inicio_at' => now()->subMinutes(30)->toAtomString(),
            'temperatura_inicial_c' => 18,
            'temperatura_objetivo_c' => 4,
            'cloro_libre_ppm' => 95,
            'ph_agua' => 6.5,
            'control_inicial_conforme' => true,
            'condicion_visual_agua' => 'conforme',
            'dosificador_operativo' => true,
            'manejo_agua' => 'sin_novedad',
        ])->assertOk()->assertJsonPath('data.estado', 'hidrocooler_en_curso');

        $proceso = DB::table('procesos_hidrocooler_materia_prima')->where('lote_materia_prima_id', $lote['id'])->first();
        $this->assertNotNull($proceso->equipo_activo_clave);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        app(ServicioRegularizacionCierreTemporada::class)->regularizar(
            $contexto['temporada'],
            CategoriaPendienteCierre::Hidrocooler,
            [$proceso->id],
            MotivoRegularizacionCierre::DatoPrueba,
            'Ciclo de ensayo sin fruta real al cerrar temporada.',
            $administrador,
        );

        $this->assertDatabaseHas('procesos_hidrocooler_materia_prima', [
            'id' => $proceso->id, 'estado' => 'cancelado', 'equipo_activo_clave' => null,
            'temperatura_c' => null, 'completado_por_user_id' => null,
        ]);
        $this->assertDatabaseHas('lotes_materia_prima', ['id' => $lote['id'], 'estado' => 'pendiente_hidrocooler']);
        $this->assertDatabaseHas('regularizaciones_cierre_temporada', ['entidad_id' => $proceso->id]);
    }

    public function test_lotes_y_resumen_no_muestran_temporadas_anteriores_ni_operacion_sin_temporada_activa(): void
    {
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto))
            ->assertCreated()
            ->json('data');

        $this->getJson('/api/materia-prima/resumen')
            ->assertOk()->assertJsonPath('lotes.borradores', 1);
        $this->getJson('/api/materia-prima/lotes')
            ->assertOk()->assertJsonPath('data.0.id', $lote['id']);

        $contexto['temporada']->update(['activa' => false]);
        $otraTemporada = Temporada::create([
            'codigo' => 'TEMP-TRAZA-MP',
            'nombre' => 'Temporada siguiente',
            'activa' => true,
        ]);
        $this->getJson('/api/materia-prima/resumen')
            ->assertOk()->assertJsonPath('temporada.id', $otraTemporada->id)
            ->assertJsonPath('lotes.borradores', 0);
        $this->getJson('/api/materia-prima/lotes')->assertOk()->assertJsonCount(0, 'data');

        $otraTemporada->update(['activa' => false]);
        $this->getJson('/api/materia-prima/resumen')
            ->assertOk()->assertJsonPath('temporada', null)
            ->assertJsonPath('lotes.borradores', 0);
        $this->getJson('/api/materia-prima/lotes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_descarga_registro_hidrocooler_en_blanco_sin_ciclos(): void
    {
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);

        $xlsx = $this->actingAs($digitador, 'sanctum')
            ->get('/api/materia-prima/hidrocooler/registro/en-blanco?formato=xlsx')
            ->assertOk()
            ->assertDownload('registro-hidrocooler-en-blanco.xlsx')
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($xlsx->baseResponse->getFile()->getPathname()) === true);
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertIsString($hoja);
        $this->assertStringContainsString('REGISTRO DE CONTROL DE HIDROCOOLER', $hoja);
        $this->assertStringContainsString('Bombas', $hoja);
        $this->assertStringContainsString('Cloro ppm', $hoja);
        $this->assertStringContainsString('A17', $hoja);

        $pdf = $this->get('/api/materia-prima/hidrocooler/registro/en-blanco?formato=pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdf->getContent());
        $this->assertStringContainsString('REGISTRO DE CONTROL DE HIDROCOOLER', $pdf->getContent());
        $this->assertStringContainsString('Bombas', $pdf->getContent());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/materia-prima/hidrocooler/registro/en-blanco')
            ->assertUnauthorized();
    }

    public function test_resumen_fruta_proceso_agrega_en_base_de_datos_sin_materializar_el_inventario(): void
    {
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/materia-prima/fruta-proceso/resumen')
            ->assertOk();

        $consultas = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $consulta): string => strtolower(trim($consulta)));
        DB::disableQueryLog();

        $this->assertFalse($consultas->contains(
            fn (string $consulta): bool => preg_match(
                '/^select\s+[`"]?lotes_materia_prima[`"]?\.\*/',
                $consulta,
            ) === 1,
        ));
        $this->assertFalse($consultas->contains(
            fn (string $consulta): bool => preg_match(
                '/^select\s+\*\s+from\s+[`"]?sublotes_retorno_packing[`"]?/',
                $consulta,
            ) === 1,
        ));
        $this->assertTrue($consultas->contains(
            fn (string $consulta): bool => str_contains($consulta, 'sum(')
                && str_contains($consulta, 'lotes_materia_prima'),
        ));
    }

    public function test_catalogos_usan_etag_y_omiten_relaciones_pesadas_si_no_cambian(): void
    {
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $camara = Camara::create([
            'codigo' => 'MP-ETAG',
            'nombre' => 'Cámara ETag',
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);

        $inicial = $this->actingAs($digitador, 'sanctum')
            ->getJson('/api/materia-prima/catalogos')
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'ETag')
            ->assertJsonPath('temporada.id', $temporada->id)
            ->assertJsonPath('camaras.0.id', $camara->id);
        $etagInicial = $inicial->headers->get('ETag');

        $this->assertNotNull($etagInicial);
        $this->assertStringContainsString('private', (string) $inicial->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $inicial->headers->get('Cache-Control'));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withHeader('If-None-Match', $etagInicial)
            ->get('/api/materia-prima/catalogos')
            ->assertStatus(304)
            ->assertHeader('ETag', $etagInicial);

        $consultoCatalogosPesados = collect(DB::getQueryLog())
            ->contains(fn (array $consulta): bool => preg_match(
                '/from\s+[`"]?(especies_validacion|variedades_validacion|calibres_validacion|csg_validacion)[`"]?/i',
                $consulta['query'],
            ) === 1);
        DB::disableQueryLog();
        $this->assertFalse($consultoCatalogosPesados);

        $temporada->increment('version_catalogo');
        $catalogoActualizado = $this->withHeader('If-None-Match', $etagInicial)
            ->getJson('/api/materia-prima/catalogos')
            ->assertOk();
        $etagCatalogoActualizado = $catalogoActualizado->headers->get('ETag');
        $this->assertNotSame($etagInicial, $etagCatalogoActualizado);

        $camara->update(['nombre' => 'Cámara ETag actualizada']);
        $camaraActualizada = $this->withHeader('If-None-Match', $etagCatalogoActualizado)
            ->getJson('/api/materia-prima/catalogos')
            ->assertOk()
            ->assertJsonPath('camaras.0.nombre', 'Cámara ETag actualizada');
        $this->assertNotSame(
            $etagCatalogoActualizado,
            $camaraActualizada->headers->get('ETag'),
        );
    }

    public function test_lotiza_dos_segmentos_con_tres_envases_y_completa_hidrocooler_y_camara(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00'));
        $contexto = $this->prepararRecepcionValidada(tresTipos: true);
        $segundoSegmentoId = $contexto['segundo_segmento_id'];
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $camara = Camara::create([
            'codigo' => 'MP-01',
            'nombre' => 'Cámara materia prima',
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);

        $this->actingAs($digitador, 'sanctum')
            ->getJson('/api/materia-prima/segmentos-pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.id', $contexto['segmento_id'])
            ->assertJsonPath('data.0.recepcion.peso_neto', 18000)
            ->assertJsonPath('data.0.recepcion.tipo_envase_calculo_neto', 'bins')
            ->assertJsonPath('data.0.recepcion.peso_neto_por_envase', 375)
            ->assertJsonPath('data.0.envases.0.cantidad_disponible', 20)
            ->assertJsonPath('data.0.neto_estimado', 7500);

        $primerPayload = $this->payloadLote($contexto, [
            'numero_lote' => 'EXP-2026-001-A',
            'cantidad_envases_primarios' => 20,
            'cantidad_envases_secundarios' => 4,
            'kilos_brutos' => 8000,
            'kilos_netos_confirmados' => 7495,
            'requiere_hidrocooler' => true,
        ]);
        unset($primerPayload['csg_validacion_id'], $primerPayload['especie_validacion_id'], $primerPayload['variedad_validacion_id']);
        unset($primerPayload['cuartel']); // se hereda el cuartel A del segmento
        $primerPayload['ggn'] = '';
        $primerLote = $this->postJson('/api/materia-prima/lotes', $primerPayload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.pesos.kilos_netos_calculados', 7500)
            ->assertJsonPath('data.pesos.kilos_brutos', 8311)
            ->assertJsonPath('data.trazabilidad.ggn', null)
            ->assertJsonPath('data.trazabilidad.csg_id', $contexto['csg_id'])
            ->assertJsonPath('data.trazabilidad.variedad_id', $contexto['variedad_id'])
            ->assertJsonPath('data.pesos.kilos_netos_confirmados', 7500)
            ->assertJsonPath('data.pesos.corregido_por_digitador', false)
            ->json('data');
        $this->assertDatabaseHas('segmentos_validacion_mp', ['id' => $contexto['segmento_id'], 'estado' => 'lotizado']);
        $this->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
            'numero_lote' => 'DUPLICADO-SEGMENTO', 'cuartel' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors('segmento_validacion_mp_id');

        $primerLote = $this->postJson(
            "/api/materia-prima/lotes/{$primerLote['id']}/confirmar",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $primerLote['version'],
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.estado', 'pendiente_hidrocooler')
            ->json('data');

        $this->assertDatabaseHas('segmentos_validacion_mp', [
            'id' => $contexto['segmento_id'],
            'estado' => 'lotizado',
        ]);

        $segundoPayload = $this->payloadLote([...$contexto, 'segmento_id' => $segundoSegmentoId], [
            'numero_lote' => 'EXP-2026-001-B',
            'cantidad_envases_primarios' => 28,
            'cantidad_envases_secundarios' => 6,
            'kilos_brutos' => 11000,
            'kilos_netos_confirmados' => 10500,
            'requiere_hidrocooler' => false,
        ]);
        unset($segundoPayload['cuartel']); // se hereda el cuartel B
        $segundoLote = $this->postJson('/api/materia-prima/lotes', $segundoPayload)
            ->assertCreated()
            ->assertJsonPath('data.pesos.kilos_brutos', 11634)
            ->json('data');
        $this->assertSame(18000.0, (float) ($primerLote['pesos']['kilos_netos_calculados'] + $segundoLote['pesos']['kilos_netos_calculados']));
        $this->assertEqualsWithDelta(19945, $primerLote['pesos']['kilos_brutos'] + $segundoLote['pesos']['kilos_brutos'], 0.0001);
        $this->assertDatabaseHas('lotes_materia_prima_envases', ['lote_materia_prima_id' => $primerLote['id'], 'tipo_envase' => 'esponjas', 'cantidad' => 3]);
        $segundoLote = $this->postJson(
            "/api/materia-prima/lotes/{$segundoLote['id']}/confirmar",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $segundoLote['version'],
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.estado', 'pendiente_asignacion')
            ->json('data');

        $this->assertDatabaseHas('segmentos_validacion_mp', [
            'id' => $contexto['segmento_id'],
            'estado' => 'lotizado',
        ]);
        $this->getJson('/api/materia-prima/segmentos-pendientes')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $inicio = now()->subMinutes(90);
        $this->postJson(
            "/api/materia-prima/lotes/{$primerLote['id']}/hidrocooler/iniciar",
            [
                'operacion_id' => (string) Str::uuid(),
                'equipo' => 'HIDRO-02',
                'turno' => 'A',
                'cantidad_bombas_funcionando' => 3,
                'inicio_at' => $inicio->toAtomString(),
                'temperatura_inicial_c' => 18.25,
                'temperatura_objetivo_c' => 4,
                'temperatura_agua_inicial_c' => 1.8,
                'cloro_libre_ppm' => 95,
                'ph_agua' => 6.5,
                'control_inicial_conforme' => true,
                'condicion_visual_agua' => 'conforme',
                'dosificador_operativo' => true,
                'manejo_agua' => 'sin_novedad',
                'observacion_inicio' => 'Agua y lote verificados.',
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.estado', 'hidrocooler_en_curso')
            ->assertJsonPath('data.hidrocooler.equipo', 'HIDRO-02')
            ->assertJsonPath('data.hidrocooler.turno', 'A')
            ->assertJsonPath('data.hidrocooler.cantidad_bombas_funcionando', 3)
            ->assertJsonPath('data.hidrocooler.operador', $digitador->name)
            ->assertJsonPath('data.hidrocooler.cantidad_envases', 20)
            ->assertJsonPath('data.hidrocooler.kilos_netos', 7500)
            ->assertJsonPath('data.hidrocooler.temperatura_inicial_c', 18.25)
            ->assertJsonPath('data.hidrocooler.temperatura_objetivo_c', 4)
            ->assertJsonPath('data.hidrocooler.cloro_libre_ppm', 95)
            ->assertJsonPath('data.hidrocooler.ph_agua', 6.5)
            ->assertJsonPath('data.hidrocooler.condicion_visual_agua', 'conforme')
            ->assertJsonPath('data.hidrocooler.dosificador_operativo', true)
            ->assertJsonPath('data.hidrocooler.manejo_agua', 'sin_novedad')
            ->assertJsonPath('data.hidrocooler.iniciado_por', $digitador->name);

        $this->postJson(
            "/api/materia-prima/lotes/{$primerLote['id']}/hidrocooler/completar",
            [
                'operacion_id' => (string) Str::uuid(),
                'termino_at' => now()->toAtomString(),
                'temperatura_c' => 3.75,
                'temperatura_agua_final_c' => 1.65,
                'cloro_libre_final_ppm' => 95,
                'ph_agua_final' => 6.5,
                'condicion_visual_agua_final' => 'conforme',
                'dosificador_operativo_final' => true,
                'control_final_conforme' => true,
                'destino_salida' => 'camara',
                'observacion' => 'Pulpa dentro del rango.',
                'accion_correctiva' => null,
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.estado', 'pendiente_asignacion')
            ->assertJsonPath('data.hidrocooler.duracion_minutos', 90)
            ->assertJsonPath('data.hidrocooler.temperatura_c', 3.75)
            ->assertJsonPath('data.hidrocooler.temperatura_agua_final_c', 1.65)
            ->assertJsonPath('data.hidrocooler.destino_salida', 'camara')
            ->assertJsonPath('data.hidrocooler.observacion', 'Pulpa dentro del rango.')
            ->assertJsonPath('data.hidrocooler.completado_por', $digitador->name);

        $registro = $this->get('/api/materia-prima/hidrocooler/registro?formato=pdf&equipo=HIDRO-02&turno=A')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('HIDRO-02', $registro->getContent());
        $this->assertStringContainsString('Turno A', $registro->getContent());

        foreach ([$primerLote['id'], $segundoLote['id']] as $loteId) {
            $this->postJson("/api/materia-prima/lotes/{$loteId}/asignar-camara", [
                'operacion_id' => (string) Str::uuid(),
                'camara_id' => $camara->id,
                'observacion' => 'Disponible para proceso.',
            ])
                ->assertOk()
                ->assertJsonPath('data.estado', 'asignado_camara')
                ->assertJsonPath('data.asignacion_camara.camara.codigo', 'MP-01');
        }

        $this->getJson('/api/materia-prima/resumen')
            ->assertOk()
            ->assertJsonPath('lotes.borradores', 0)
            ->assertJsonPath('lotes.pendientes_hidrocooler', 0)
            ->assertJsonPath('lotes.pendientes_asignacion', 0)
            ->assertJsonPath('lotes.asignados_camara', 2);
        $this->assertDatabaseCount('lotes_materia_prima', 2);
        $this->assertDatabaseCount('procesos_hidrocooler_materia_prima', 1);
        $this->assertDatabaseCount('asignaciones_camara_lote_materia_prima', 2);
    }

    public function test_hidrocooler_libera_un_lote_directo_a_proceso_sin_camara_ficticia(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-17 10:00:00'));
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
                'numero_lote' => 'LOTE-HIDRO-DIRECTO',
                'requiere_hidrocooler' => true,
            ]))
            ->assertCreated()
            ->json('data');
        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');

        $inicioId = (string) Str::uuid();
        $inicio = [
            'operacion_id' => $inicioId,
            'equipo' => 'HIDRO-01',
            'turno' => 'B',
            'cantidad_bombas_funcionando' => 2,
            'inicio_at' => now()->subMinutes(75)->toAtomString(),
            'temperatura_inicial_c' => 19.4,
            'temperatura_objetivo_c' => 4,
            'temperatura_agua_inicial_c' => 1.9,
            'cloro_libre_ppm' => 105,
            'ph_agua' => 6.7,
            'control_inicial_conforme' => true,
            'condicion_visual_agua' => 'conforme',
            'dosificador_operativo' => true,
            'manejo_agua' => 'filtrado',
            'observacion_inicio' => 'Carga completa verificada.',
        ];
        $inicioSinBombas = $inicio;
        $inicioSinBombas['cantidad_bombas_funcionando'] = 0;
        $this->postJson(
            "/api/materia-prima/lotes/{$lote['id']}/hidrocooler/iniciar",
            $inicioSinBombas,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cantidad_bombas_funcionando');

        $this->postJson(
            "/api/materia-prima/lotes/{$lote['id']}/hidrocooler/iniciar",
            $inicio,
        )
            ->assertOk()
            ->assertJsonPath('data.estado', 'hidrocooler_en_curso')
            ->assertJsonPath('data.hidrocooler.codigo', fn ($codigo) => str_starts_with($codigo, 'HC-20260817-'));

        $inicioAlterado = $inicio;
        $inicioAlterado['temperatura_inicial_c'] = 20;
        $this->postJson(
            "/api/materia-prima/lotes/{$lote['id']}/hidrocooler/iniciar",
            $inicioAlterado,
        )->assertConflict();

        $this->postJson(
            "/api/materia-prima/lotes/{$lote['id']}/hidrocooler/completar",
            [
                'operacion_id' => (string) Str::uuid(),
                'termino_at' => now()->toAtomString(),
                'temperatura_c' => 3.8,
                'temperatura_agua_final_c' => 1.7,
                'cloro_libre_final_ppm' => 105,
                'ph_agua_final' => 6.7,
                'condicion_visual_agua_final' => 'conforme',
                'dosificador_operativo_final' => true,
                'control_final_conforme' => true,
                'destino_salida' => 'proceso',
                'observacion' => 'Liberado directamente a Packing.',
                'accion_correctiva' => 'Se verificó el filtro antes de liberar.',
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.estado', 'disponible_proceso')
            ->assertJsonPath('data.hidrocooler.duracion_minutos', 75)
            ->assertJsonPath('data.hidrocooler.destino_salida', 'proceso')
            ->assertJsonPath('data.hidrocooler.accion_correctiva', 'Se verificó el filtro antes de liberar.')
            ->assertJsonPath('data.asignacion_camara', null);

        $this->getJson('/api/materia-prima/hidrocooler/resumen')
            ->assertOk()
            ->assertJsonPath('pendientes', 0)
            ->assertJsonPath('en_curso', 0)
            ->assertJsonPath('completados_hoy', 1)
            ->assertJsonPath('duracion_promedio_hoy', 75);
        $this->getJson('/api/materia-prima/hidrocooler/lotes?bandeja=historial')
            ->assertOk()
            ->assertJsonPath('data.0.numero_lote', 'LOTE-HIDRO-DIRECTO')
            ->assertJsonPath('data.0.hidrocooler.destino_salida', 'proceso');

        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/materia-prima/fruta-proceso/lotes?estado=abiertos')
            ->assertOk()
            ->assertJsonPath('data.0.numero_lote', 'LOTE-HIDRO-DIRECTO')
            ->assertJsonPath('data.0.origen_operacional', 'hidrocooler_directo')
            ->assertJsonPath('data.0.camara', null);
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 12,
            'kilos_enviados' => 4500,
            'linea_proceso' => 'Línea 1',
            'turno' => 'A',
            'numero_orden' => 'OP-HIDRO-DIRECTO',
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'entrega_parcial_proceso')
            ->assertJsonPath('data.camara', null);

        $this->assertDatabaseHas('entregas_fruta_proceso', [
            'lote_materia_prima_id' => $lote['id'],
            'asignacion_camara_lote_id' => null,
            'camara_id' => null,
        ]);
        $this->assertDatabaseCount('procesos_hidrocooler_materia_prima', 1);
        $this->assertDatabaseCount('asignaciones_camara_lote_materia_prima', 0);
    }

    public function test_hidrocooler_retiene_desviaciones_y_solo_supervision_libera_tras_evaluar_producto(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00'));
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
                'numero_lote' => 'LOTE-HIDRO-RETENIDO',
                'requiere_hidrocooler' => true,
            ]))->assertCreated()->json('data');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk();

        $inicio = [
            'operacion_id' => (string) Str::uuid(),
            'equipo' => 'HIDRO-01',
            'turno' => 'A',
            'cantidad_bombas_funcionando' => 2,
            'inicio_at' => now()->subMinutes(30)->toAtomString(),
            'temperatura_inicial_c' => 18,
            'temperatura_objetivo_c' => 4,
            'cloro_libre_ppm' => 95,
            'ph_agua' => 6.5,
            'control_inicial_conforme' => true,
            'condicion_visual_agua' => 'conforme',
            'dosificador_operativo' => true,
            'manejo_agua' => 'sin_novedad',
        ];
        $inicioNoConforme = $inicio;
        $inicioNoConforme['control_inicial_conforme'] = false;
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/iniciar", $inicioNoConforme)
            ->assertUnprocessable()->assertJsonValidationErrors('control_inicial_conforme');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/iniciar", $inicio)
            ->assertOk();
        $termino = [
            'operacion_id' => (string) Str::uuid(),
            'termino_at' => now()->toAtomString(),
            'temperatura_c' => 6,
            'cloro_libre_final_ppm' => 40,
            'ph_agua_final' => 6.5,
            'condicion_visual_agua_final' => 'no_conforme',
            'dosificador_operativo_final' => false,
            'control_final_conforme' => false,
            'destino_salida' => 'proceso',
            'accion_correctiva' => 'Agua renovada; fruta apartada para evaluación.',
        ];
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/completar", $termino)
            ->assertOk()
            ->assertJsonPath('data.estado', 'hidrocooler_retenido')
            ->assertJsonPath('data.hidrocooler.cloro_libre_final_ppm', 40)
            ->assertJsonPath('data.hidrocooler.control_final_conforme', false)
            ->assertJsonPath('data.hidrocooler.destino_salida', 'proceso')
            ->assertJsonPath('data.hidrocooler.liberado_at', null);
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/completar", $termino)
            ->assertJsonPath('data.estado', 'hidrocooler_retenido');
        $this->getJson('/api/materia-prima/hidrocooler/resumen')
            ->assertJsonPath('retenidos', 1)
            ->assertJsonPath('en_curso', 0);
        $this->getJson('/api/materia-prima/hidrocooler/lotes?bandeja=retenidos')
            ->assertJsonPath('data.0.id', $lote['id']);
        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/materia-prima/fruta-proceso/lotes?estado=abiertos')
            ->assertOk()->assertJsonCount(0, 'data');

        $liberacion = [
            'operacion_id' => (string) Str::uuid(),
            'temperatura_verificacion_c' => 5,
            'cloro_libre_verificacion_ppm' => 95,
            'ph_agua_verificacion' => 6.5,
            'control_verificacion_conforme' => true,
            'evaluacion_producto' => 'Se segregó el lote y se evaluó la fruta expuesta.',
            'verificacion_liberacion' => 'Se renovó el agua y se verificó el control sanitario.',
        ];
        $this->actingAs($digitador, 'sanctum')
            ->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/liberar", $liberacion)
            ->assertForbidden();
        $this->actingAs($supervisor, 'sanctum');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/liberar", $liberacion)
            ->assertUnprocessable()->assertJsonValidationErrors('temperatura_verificacion_c');
        $this->assertDatabaseHas('lotes_materia_prima', [
            'id' => $lote['id'], 'estado' => 'hidrocooler_retenido',
        ]);
        $liberacion['temperatura_verificacion_c'] = 3.8;
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/liberar", $liberacion)
            ->assertOk()->assertJsonPath('data.estado', 'disponible_proceso')
            ->assertJsonPath('data.hidrocooler.liberado_por', $supervisor->name)
            ->assertJsonPath('data.hidrocooler.temperatura_verificacion_c', 3.8);
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/liberar", $liberacion)
            ->assertOk()->assertJsonPath('data.estado', 'disponible_proceso');
        $liberacion['operacion_id'] = (string) Str::uuid();
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/hidrocooler/liberar", $liberacion)
            ->assertConflict();
        $this->assertDatabaseCount('procesos_hidrocooler_materia_prima', 1);
    }

    public function test_controla_identificadores_disponibilidad_y_correccion_supervisada(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00'));
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create([
            'rol' => RolUsuario::DigitadorMateriaPrima,
            'email' => 'digitador.prueba@estiba.local',
            'password' => 'password123',
        ]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);

        $this->postJson('/api/acceso-oficina', [
            'email' => $digitador->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.puede_consultar_materia_prima', true)
            ->assertJsonPath('usuario.puede_gestionar_lotes_materia_prima', true)
            ->assertJsonPath('usuario.puede_supervisar_lotes_materia_prima', false)
            ->assertJsonPath('usuario.puede_consultar_hidrocooler_materia_prima', true)
            ->assertJsonPath('usuario.puede_operar_hidrocooler_materia_prima', true)
            ->assertJsonPath('usuario.ambito_camaras', 'materia_prima');

        $this->actingAs($digitador, 'sanctum');
        $payload = $this->payloadLote($contexto, [
            'numero_lote' => 'LOTE-CORREGIBLE',
            'cantidad_envases_primarios' => 48,
            'cantidad_envases_secundarios' => 10,
            'kilos_brutos' => 19000,
            'kilos_netos_confirmados' => 18000,
            'requiere_hidrocooler' => false,
        ]);

        $invalido = $payload;
        $invalido['ggn'] = '1234';
        $this->postJson('/api/materia-prima/lotes', $invalido)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ggn');

        $lote = $this->postJson('/api/materia-prima/lotes', $payload)
            ->assertCreated()
            ->json('data');

        $excedente = $this->payloadLote($contexto, [
            'numero_lote' => 'LOTE-SIN-SALDO',
            'cantidad_envases_primarios' => 1,
            'cantidad_envases_secundarios' => 0,
            'envase_secundario' => null,
            'kilos_brutos' => 500,
            'kilos_netos_confirmados' => 375,
            'requiere_hidrocooler' => false,
        ]);
        $this->postJson('/api/materia-prima/lotes', $excedente)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('segmento_validacion_mp_id');

        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/materia-prima/lotes/{$lote['id']}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'El número fue informado con antecedentes incorrectos.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'anulado')
            ->assertJsonPath(
                'data.motivo_anulacion',
                'El número fue informado con antecedentes incorrectos.',
            );

        $this->assertDatabaseHas('segmentos_validacion_mp', [
            'id' => $contexto['segmento_id'],
            'estado' => 'pendiente_lote',
        ]);
        $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', [
                ...$payload,
                'operacion_id' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.numero_lote', 'LOTE-CORREGIBLE')
            ->assertJsonPath('data.estado', 'borrador');

        $this->assertDatabaseCount('lotes_materia_prima', 2);
        $this->assertDatabaseHas('lotes_materia_prima', [
            'id' => $lote['id'],
            'estado' => 'anulado',
            'clave_numero_vigente' => null,
        ]);
    }

    public function test_calibre_no_se_exige_cuartel_es_opcional_y_el_origen_confirmado_se_corrige(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27 15:00:00'));
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $this->actingAs($digitador, 'sanctum');

        $lote = $this->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
            'numero_lote' => 'LOTE-SIN-CALIBRE',
            'cuartel' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.trazabilidad.calibre_id', null)
            ->assertJsonPath('data.trazabilidad.calibre', null)
            ->assertJsonPath('data.trazabilidad.cuartel', null)
            ->json('data');

        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');

        DB::table('lotes_materia_prima')->where('id', $lote['id'])->update([
            'calibre_validacion_id' => $contexto['calibre_id'],
            'calibre_snapshot' => '28 mm',
            'cuartel' => 'CUARTEL-ANTIGUO',
        ]);
        $operacionId = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacionId,
            'version_conocida' => $lote['version'],
            'cuartel' => null,
            'retirar_calibre' => true,
            'kilos_brutos' => 1,
        ];

        $corregido = $this->putJson(
            "/api/materia-prima/lotes/{$lote['id']}/corregir-origen",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.estado', $lote['estado'])
            ->assertJsonPath('data.trazabilidad.calibre_id', null)
            ->assertJsonPath('data.trazabilidad.calibre', null)
            ->assertJsonPath('data.trazabilidad.cuartel', null)
            ->assertJsonPath('data.pesos.kilos_brutos', 19940)
            ->json('data');

        $this->putJson(
            "/api/materia-prima/lotes/{$lote['id']}/corregir-origen",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.version', $corregido['version']);

        $this->assertDatabaseHas('eventos_lote_materia_prima', [
            'lote_materia_prima_id' => $lote['id'],
            'operacion_id' => $operacionId,
            'tipo' => 'origen_corregido',
        ]);
        $this->assertDatabaseHas('lotes_materia_prima', [
            'id' => $lote['id'],
            'calibre_validacion_id' => null,
            'calibre_snapshot' => null,
            'cuartel' => null,
            'kilos_brutos' => 19940,
        ]);
    }

    public function test_entrega_bins_a_proceso_por_viajes_parciales_sin_sobrepasar_el_saldo(): void
    {
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $camara = Camara::create([
            'codigo' => 'MP-PROCESO',
            'nombre' => 'Cámara fruta a proceso',
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);

        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
                'numero_lote' => 'LOTE-PROCESO-01',
                'requiere_hidrocooler' => false,
            ]))
            ->assertCreated()
            ->json('data');
        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/asignar-camara", [
            'operacion_id' => (string) Str::uuid(),
            'camara_id' => $camara->id,
        ])->assertOk();

        $operacionPrimerViaje = (string) Str::uuid();
        $primerViaje = [
            'operacion_id' => $operacionPrimerViaje,
            'cantidad_envases' => 20,
            'linea_proceso' => 'Línea 2',
            'turno' => 'A',
            'numero_orden' => 'OP-2026-0088',
            'observacion' => 'Primer viaje físico.',
        ];
        $this->actingAs($camarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $primerViaje)
            ->assertOk()
            ->assertJsonPath('data.estado', 'entrega_parcial_proceso')
            ->assertJsonPath('data.progreso.total', 48)
            ->assertJsonPath('data.progreso.entregados', 20)
            ->assertJsonPath('data.progreso.disponibles', 28)
            ->assertJsonPath('data.entregas.0.linea_proceso', 'Línea 2')
            ->assertJsonPath('data.entregas.0.turno', 'A')
            ->assertJsonPath('data.entregas.0.numero_orden', 'OP-2026-0088');

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $primerViaje)
            ->assertOk()
            ->assertJsonPath('data.progreso.entregados', 20);
        $this->assertDatabaseCount('entregas_fruta_proceso', 1);

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
            ...$primerViaje,
            'cantidad_envases' => 21,
        ])->assertConflict();

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
            ...$primerViaje,
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 29,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cantidad_envases');

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
            ...$primerViaje,
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 28,
            'turno' => 'B',
            'numero_orden' => 'OP-2026-0089',
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'entregado_proceso')
            ->assertJsonPath('data.progreso.entregados', 48)
            ->assertJsonPath('data.progreso.disponibles', 0);

        $this->getJson('/api/materia-prima/fruta-proceso/resumen')
            ->assertOk()
            ->assertJsonPath('lotes_abiertos', 0)
            ->assertJsonPath('lotes_completados', 1)
            ->assertJsonPath('bins_entregados', 48)
            ->assertJsonPath('bins_disponibles', 0);
    }

    public function test_camarero_anula_solo_su_ultimo_viaje_abierto_y_supervisor_corrige_un_lote_completo(): void
    {
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $otroCamarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $camara = Camara::create([
            'codigo' => 'MP-CORRECCION',
            'nombre' => 'Cámara corrección',
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);

        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
                'numero_lote' => 'LOTE-CORRECCION-PROCESO',
                'requiere_hidrocooler' => false,
            ]))->assertCreated()->json('data');
        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/asignar-camara", [
            'operacion_id' => (string) Str::uuid(),
            'camara_id' => $camara->id,
        ])->assertOk();

        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 20,
            'linea_proceso' => 'Línea 1',
            'turno' => 'A',
            'numero_orden' => 'ORD-100',
        ];
        $entrega = $this->actingAs($camarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", $payload)
            ->assertOk()
            ->assertJsonPath('data.entregas.0.puede_anular', true)
            ->json('data.entregas.0');

        $this->actingAs($otroCamarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'No corresponde al operador.',
            ])->assertForbidden();

        $anulacion = [
            'operacion_id' => (string) Str::uuid(),
            'motivo' => 'Cantidad digitada incorrectamente.',
        ];
        $this->actingAs($camarero, 'sanctum')
            ->postJson(
                "/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/anular",
                $anulacion,
            )
            ->assertOk()
            ->assertJsonPath('data.estado', 'asignado_camara')
            ->assertJsonPath('data.progreso.entregados', 0)
            ->assertJsonPath('data.entregas.0.anulado', true);
        $this->postJson(
            "/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/anular",
            $anulacion,
        )
            ->assertOk()
            ->assertJsonPath('data.progreso.entregados', 0);

        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
            ...$payload,
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk();
        $primeraVigente = EntregaFrutaProceso::query()
            ->where('lote_materia_prima_id', $lote['id'])
            ->whereNull('anulado_at')
            ->firstOrFail();
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
            ...$payload,
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 28,
        ])->assertOk()->assertJsonPath('data.estado', 'entregado_proceso');

        $this->postJson("/api/materia-prima/fruta-proceso/entregas/{$primeraVigente->id}/anular", [
            'operacion_id' => (string) Str::uuid(),
            'motivo' => 'Intento después del cierre.',
        ])->assertForbidden();

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/entregas/{$primeraVigente->id}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Corrección supervisada por error documental.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'entrega_parcial_proceso')
            ->assertJsonPath('data.progreso.entregados', 28)
            ->assertJsonPath('data.progreso.disponibles', 20);
    }

    public function test_registra_retorno_de_packing_crea_sublotes_y_los_ubica_en_camara(): void
    {
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $camaraOrigen = Camara::create([
            'codigo' => 'MP-ORIGEN-PACK',
            'nombre' => 'Cámara origen Packing',
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $camaraRetorno = Camara::create([
            'codigo' => 'MP-RETORNO-PACK',
            'nombre' => 'Cámara retorno Packing',
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);

        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
                'numero_lote' => 'LOTE-RETORNO-PACKING',
                'requiere_hidrocooler' => false,
            ]))->assertCreated()->json('data');
        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/asignar-camara", [
            'operacion_id' => (string) Str::uuid(),
            'camara_id' => $camaraOrigen->id,
        ])->assertOk();

        $entrega = $this->actingAs($camarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
                'operacion_id' => (string) Str::uuid(),
                'cantidad_envases' => 20,
                'kilos_enviados' => 7500,
                'linea_proceso' => 'Línea 2',
                'turno' => 'A',
                'numero_orden' => 'OP-RET-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.entregas.0.kilos_enviados', 7500)
            ->json('data.entregas.0');

        $catalogos = $this->getJson('/api/materia-prima/fruta-proceso/catalogos')
            ->assertOk()
            ->assertJsonFragment(['codigo' => 'MP-ORIGEN-PACK'])
            ->assertJsonFragment(['codigo' => 'MP-RETORNO-PACK'])
            ->assertJsonCount(4, 'tipos_resultado')
            ->json();
        $tipos = collect($catalogos['tipos_resultado'])->keyBy('codigo');

        $retornoParcial = $this->postJson(
            "/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/retornos",
            [
                'operacion_id' => (string) Str::uuid(),
                'cierra_entrega' => false,
                'resultados' => [[
                    'tipo_resultado_packing_id' => $tipos['precalibre']['id'],
                    'cantidad_bins' => 1,
                    'kilos_netos' => 350,
                ]],
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.entregas.0.retorno.estado', 'parcial')
            ->assertJsonPath('data.entregas.0.retorno.bins_retornados', 1)
            ->json('data.entregas.0.retorno.movimientos.0');
        $this->postJson("/api/materia-prima/fruta-proceso/retornos/{$retornoParcial['id']}/anular", [
            'operacion_id' => (string) Str::uuid(),
            'motivo' => 'Packing corrigió la clasificación informada.',
        ])
            ->assertOk()
            ->assertJsonPath('data.entregas.0.retorno.estado', 'pendiente')
            ->assertJsonPath('data.entregas.0.retorno.bins_retornados', 0)
            ->assertJsonPath('data.entregas.0.retorno.movimientos.0.anulado', true);

        $operacionRetorno = (string) Str::uuid();
        $retornoPayload = [
            'operacion_id' => $operacionRetorno,
            'cierra_entrega' => true,
            'observacion' => 'Packing cerró el viaje físico.',
            'resultados' => [
                [
                    'tipo_resultado_packing_id' => $tipos['precalibre']['id'],
                    'cantidad_bins' => 12,
                    'kilos_netos' => 4500,
                ],
                [
                    'tipo_resultado_packing_id' => $tipos['comercial']['id'],
                    'cantidad_bins' => 6,
                    'kilos_netos' => 2200,
                ],
                [
                    'tipo_resultado_packing_id' => $tipos['descarte']['id'],
                    'cantidad_bins' => 2,
                    'kilos_netos' => 500,
                ],
            ],
        ];

        $respuesta = $this->postJson(
            "/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/retornos",
            $retornoPayload,
        )
            ->assertOk()
            ->assertJsonPath('data.entregas.0.retorno.estado', 'completado')
            ->assertJsonPath('data.entregas.0.retorno.bins_retornados', 20)
            ->assertJsonPath('data.entregas.0.retorno.kilos_recuperados', 7200)
            ->assertJsonPath('data.entregas.0.retorno.merma_kilos', 300)
            ->assertJsonPath('data.entregas.0.retorno.puede_registrar', false)
            ->assertJsonPath('data.entregas.0.puede_anular', false)
            ->assertJsonCount(3, 'data.entregas.0.retorno.movimientos.0.resultados')
            ->json('data.entregas.0.retorno.movimientos.0');

        $this->assertMatchesRegularExpression('/^RP-\d{6}$/', $respuesta['numero']);
        $resultadoPrecalibre = collect($respuesta['resultados'])->first(
            fn (array $resultado): bool => $resultado['tipo']['codigo'] === 'precalibre',
        );
        $this->assertNotNull($resultadoPrecalibre);
        $this->assertMatchesRegularExpression(
            '/^PC-\d{6}$/',
            $resultadoPrecalibre['numero_sublote'],
        );
        $this->assertDatabaseCount('retornos_packing', 2);
        $this->assertDatabaseCount('retorno_packing_entregas', 2);
        $this->assertDatabaseHas('retorno_packing_entregas', [
            'retorno_packing_id' => $respuesta['id'],
            'entrega_fruta_proceso_id' => $entrega['id'],
            'cierra_entrega' => true,
        ]);
        $this->assertDatabaseCount('sublotes_retorno_packing', 4);

        $this->postJson(
            "/api/materia-prima/fruta-proceso/entregas/{$entrega['id']}/retornos",
            $retornoPayload,
        )
            ->assertOk()
            ->assertJsonCount(2, 'data.entregas.0.retorno.movimientos');
        $this->assertDatabaseCount('retornos_packing', 2);

        $sublote = $respuesta['resultados'][0];
        $this->postJson("/api/materia-prima/fruta-proceso/sublotes/{$sublote['id']}/ubicar", [
            'operacion_id' => (string) Str::uuid(),
            'camara_id' => $camaraRetorno->id,
            'observacion' => 'Retorno ubicado por el camarero.',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.entregas.0.retorno.movimientos.0.resultados.0.estado',
                'ubicado_camara',
            )
            ->assertJsonPath(
                'data.entregas.0.retorno.movimientos.0.resultados.0.camara.codigo',
                'MP-RETORNO-PACK',
            );

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/retornos/{$respuesta['id']}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Intento posterior a la ubicación.',
            ])->assertForbidden();

        $this->getJson('/api/materia-prima/fruta-proceso/resumen')
            ->assertOk()
            ->assertJsonPath('entregas_pendientes_retorno', 0)
            ->assertJsonPath('bins_retornados', 20)
            ->assertJsonPath('kilos_recuperados', 7200)
            ->assertJsonPath('sublotes_pendientes_ubicacion', 2);
    }

    public function test_retorno_multiorigen_cierra_cada_viaje_por_separado_y_no_duplica_el_resumen(): void
    {
        $contexto = $this->prepararRecepcionValidada();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $camara = Camara::create([
            'codigo' => 'MP-MULTIORIGEN',
            'nombre' => 'Cámara multiorigen',
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);

        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
                'numero_lote' => 'LOTE-MULTIORIGEN',
                'requiere_hidrocooler' => false,
            ]))->assertCreated()->json('data');
        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/asignar-camara", [
            'operacion_id' => (string) Str::uuid(),
            'camara_id' => $camara->id,
        ])->assertOk();

        $primera = $this->actingAs($camarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
                'operacion_id' => (string) Str::uuid(),
                'cantidad_envases' => 20,
                'kilos_enviados' => 7500,
                'linea_proceso' => 'Línea 1',
                'turno' => 'A',
                'numero_orden' => 'OP-MULTI-001',
            ])->assertOk()->json('data.entregas.0');
        $segunda = $this->postJson(
            "/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas",
            [
                'operacion_id' => (string) Str::uuid(),
                'cantidad_envases' => 10,
                'kilos_enviados' => 3750,
                'linea_proceso' => 'Línea 2',
                'turno' => 'A',
                'numero_orden' => 'OP-MULTI-002',
            ],
        )->assertOk()->json('data.entregas.0');
        $tipo = TipoResultadoPacking::query()->where('codigo', 'comercial')->firstOrFail();

        $respuesta = $this->postJson(
            "/api/materia-prima/fruta-proceso/entregas/{$primera['id']}/retornos",
            [
                'operacion_id' => (string) Str::uuid(),
                'entregas' => [
                    [
                        'entrega_fruta_proceso_id' => $primera['id'],
                        'cierra_entrega' => true,
                    ],
                    [
                        'entrega_fruta_proceso_id' => $segunda['id'],
                        'cierra_entrega' => false,
                    ],
                ],
                'resultados' => [[
                    'tipo_resultado_packing_id' => $tipo->id,
                    'cantidad_bins' => 8,
                    'kilos_netos' => 3000,
                ]],
            ],
        )->assertOk()->json('data');

        $primeraActual = collect($respuesta['entregas'])->firstWhere('id', $primera['id']);
        $segundaActual = collect($respuesta['entregas'])->firstWhere('id', $segunda['id']);
        $this->assertSame('completado', $primeraActual['retorno']['estado']);
        $this->assertSame('parcial', $segundaActual['retorno']['estado']);
        $this->assertCount(2, $primeraActual['retorno']['movimientos'][0]['origenes']);
        $this->assertDatabaseCount('retorno_packing_entregas', 2);
        $this->assertDatabaseHas('retorno_packing_entregas', [
            'entrega_fruta_proceso_id' => $primera['id'],
            'cierra_entrega' => true,
        ]);
        $this->assertDatabaseHas('retorno_packing_entregas', [
            'entrega_fruta_proceso_id' => $segunda['id'],
            'cierra_entrega' => false,
        ]);

        $this->getJson('/api/materia-prima/fruta-proceso/resumen')
            ->assertOk()
            ->assertJsonPath('entregas_pendientes_retorno', 1)
            ->assertJsonPath('retornos_registrados', 1)
            ->assertJsonPath('bins_retornados', 8)
            ->assertJsonPath('kilos_recuperados', 3000)
            ->assertJsonPath('desglose_resultados.0.tipo.codigo', 'comercial')
            ->assertJsonPath('desglose_resultados.0.bins', 8);
    }

    /** @return array<string, mixed> */
    private function prepararRecepcionValidada(bool $tresTipos = false): array
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = Cliente::create([
            'codigo' => 'EXP-MP',
            'nombre' => 'Exportadora Materia Prima',
            'activo' => true,
        ]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', [
                'operacion_id' => (string) Str::uuid(),
                'temporada_id' => $temporada->id,
                'cliente_id' => $cliente->id,
                'tipo_recepcion' => 'fruta_con_envases',
                'especie_validacion_id' => EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true])->id,
                'tipo_servicio' => 'proceso',
                'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad' => 48],
                    ['tipo_envase' => 'totes', 'cantidad' => 10],
                    ...($tresTipos ? [['tipo_envase' => 'esponjas', 'cantidad' => 5]] : []),
                ],
                'numero_guia_despacho' => 'GD-MP-100',
                'patente_camion' => 'ABCD12',
                'tipo_camion' => 'plano',
                'rut_conductor' => '12.345.678-5',
                'nombre_conductor' => 'Transportista MP',
                'peso_bruto' => 28000,
            ])
            ->assertCreated()
            ->json('data');
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2],
                ...($tresTipos ? [['tipo_envase' => 'esponjas', 'tara_unitaria' => 1]] : [])],
            'peso_tara' => 10000,
            'tipo_envase_calculo_neto' => 'bins',
        ])
            ->assertOk()
            ->assertJsonPath('data.peso_neto', 18000)
            ->assertJsonPath('data.cantidad_envase_calculo_neto', 48)
            ->assertJsonPath('data.peso_neto_por_envase', 375);

        $especie = EspecieValidacion::firstOrCreate([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cereza',
            'activo' => true,
        ]);
        $variedad = VariedadValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'Santina',
            'activo' => true,
        ]);
        $calibre = CalibreValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => '28 mm',
            'activo' => true,
        ]);
        $csg = CsgValidacion::create([
            'temporada_id' => $temporada->id,
            'codigo' => '12345678',
            'predio' => 'Fundo El Maitén',
            'activo' => true,
        ]);

        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", [
                'operacion_id' => (string) Str::uuid(),
            ])
            ->assertOk()
            ->json('data');
        $segmentos = $tresTipos ? [
            ['motivos' => ['cuartel'], 'cuartel' => 'A', 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 20], ['tipo_envase' => 'totes', 'cantidad' => 4], ['tipo_envase' => 'esponjas', 'cantidad' => 3]]],
            ['motivos' => ['cuartel'], 'cuartel' => 'B', 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 28], ['tipo_envase' => 'totes', 'cantidad' => 6], ['tipo_envase' => 'esponjas', 'cantidad' => 2]]],
        ] : null;
        $respuestaValidacion = $this->postJson(
            "/api/validacion-mp/validaciones/{$validacion['id']}/confirmar",
            [
                'operacion_id' => (string) Str::uuid(),
                'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad_validada' => 48],
                    ['tipo_envase' => 'totes', 'cantidad_validada' => 10],
                    ...($tresTipos ? [['tipo_envase' => 'esponjas', 'cantidad_validada' => 5]] : []),
                ],
                'tarjas_verificadas' => true,
                'requiere_segregacion' => $tresTipos,
                'segmentos' => $segmentos,
                'csg_validacion_id' => $csg->id,
                'variedad_validacion_id' => $variedad->id,
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.estado', 'validada')->json('data');
        $segmentoId = $respuestaValidacion['segmentos'][0]['id'];

        return [
            'temporada' => $temporada,
            'cliente' => $cliente,
            'recepcion_id' => $recepcion['id'],
            'segmento_id' => $segmentoId,
            'segundo_segmento_id' => $respuestaValidacion['segmentos'][1]['id'] ?? null,
            'csg_id' => $csg->id,
            'especie_id' => $especie->id,
            'variedad_id' => $variedad->id,
            'calibre_id' => $calibre->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @param  array<string, mixed>  $reemplazos
     * @return array<string, mixed>
     */
    private function payloadLote(array $contexto, array $reemplazos = []): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'segmento_validacion_mp_id' => $contexto['segmento_id'],
            'numero_lote' => 'EXP-2026-001',
            'csg_validacion_id' => $contexto['csg_id'],
            'sdp' => '987654321',
            'ggn' => '1234567890123',
            'fecha_cosecha' => '2026-07-26',
            'predio' => 'Fundo El Maitén',
            'especie_validacion_id' => $contexto['especie_id'],
            'variedad_validacion_id' => $contexto['variedad_id'],
            'cuartel' => 'C-12',
            'tipo_producto' => 'materia_prima',
            'envase_primario' => 'bins',
            'envase_secundario' => 'totes',
            'cantidad_envases_primarios' => 48,
            'cantidad_envases_secundarios' => 10,
            'kilos_brutos' => 19000,
            'kilos_netos_confirmados' => 18000,
            'requiere_hidrocooler' => false,
            'observacion' => 'Antecedentes informados por exportadora.',
            ...$reemplazos,
        ];
    }
}
