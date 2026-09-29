<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\CategoriaValidacion;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\CsgValidacion;
use App\Models\Dispositivo;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\ImportacionValidacion;
use App\Models\OrigenValidacion;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministracionValidacionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pt_rechaza_variedad_desasociada_del_csg_aunque_la_combinacion_proyectada_siga_activa(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba(['codigo' => 'TEMP-PT-CSG', 'nombre' => 'PT CSG', 'activa' => true]);
        $especie = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Cereza', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina', 'activo' => true]);
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-PT', 'activo' => true]);
        $articulo = ArticuloValidacion::create([
            'temporada_id' => $temporada->id, 'especie_validacion_id' => $especie->id,
            'variedad_validacion_id' => $variedad->id, 'especie' => 'Cereza', 'variedad' => 'Santina',
            'calibre' => '2J', 'envase' => 'Caja', 'activo' => true,
        ]);
        $origen = OrigenValidacion::create([
            'temporada_id' => $temporada->id, 'csg_validacion_id' => $csg->id,
            'cliente' => 'DIS', 'marca' => 'Atlas', 'csg' => 'CSG-PT', 'activo' => true,
        ]);
        $categoria = CategoriaValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Exportación', 'activo' => true]);
        CombinacionValidacion::create([
            'temporada_id' => $temporada->id, 'articulo_validacion_id' => $articulo->id,
            'origen_validacion_id' => $origen->id, 'activo' => true,
        ]);
        $validador = User::factory()->create(['rol' => RolUsuario::Validador]);
        $dispositivo = Dispositivo::create(['codigo' => 'VAL-PT-CSG', 'nombre' => 'PDA PT', 'activo' => true]);
        $token = $validador->crearTokenParaDispositivo($dispositivo, 'validacion-pt')->plainTextToken;

        $this->withToken($token)->postJson('/api/validacion/pallets', [
            'operacion_id' => (string) Str::uuid(), 'numero_folio' => 'PAL-CSG-VAR',
            'tipo_bulto' => 'pallet', 'cantidad_cajas' => 10, 'linea_proceso' => 1,
            'turno' => 'A', 'temporada_id' => $temporada->id, 'catalogo_version' => $temporada->version_catalogo,
            'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id,
            'categoria_validacion_id' => $categoria->id, 'resultado' => 'aprobado',
            'composicion' => [[
                'origen_validacion_id' => $origen->id, 'cantidad_cajas' => 10,
                'lote_materia_prima' => 'LOTE-PT-CSG', 'proceso_packing' => 'PROC-PT-CSG',
            ]],
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertUnprocessable()->assertJsonPath('message', 'La variedad seleccionada no está asociada al CSG elegido.');
    }

    public function test_administrador_configura_temporada_articulo_origen_y_combinacion(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);

        $temporadaId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', [
                ...$this->vigenciaProductiva(),
                'codigo' => ' 2026-2027 ',
                'nombre' => ' Temporada cerezas ',
                'activa' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.codigo', '2026-2027')
            ->assertJsonPath('data.activa', true)
            ->json('data.id');

        $this->postJson('/api/administracion/clientes', [
            'codigo' => 'DIS',
            'nombre' => 'DIS',
            'activo' => true,
        ])->assertCreated();

        $articuloId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/validacion/articulos', [
                'temporada_id' => $temporadaId,
                'especie' => 'Cereza',
                'variedad' => 'Santina',
                'calibre' => '2j',
                'envase' => 'Caja 5 kg',
                'codigo_externo' => 'cer-san-2j',
                'activo' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.calibre', '2J')
            ->json('data.id');

        $origenId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/validacion/origenes', [
                'temporada_id' => $temporadaId,
                'cliente' => 'DIS',
                'marca' => 'Atlas',
                'csg' => '105410',
                'predio' => 'OLM',
                'activo' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $combinacionId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/validacion/combinaciones', [
                'temporada_id' => $temporadaId,
                'articulo_validacion_id' => $articuloId,
                'origen_validacion_id' => $origenId,
                'codigo_externo' => 'VAL-001',
                'activo' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.articulo.id', $articuloId)
            ->assertJsonPath('data.origen.id', $origenId)
            ->json('data.id');

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/validacion')
            ->assertOk()
            ->assertJsonPath('temporada.id', $temporadaId)
            ->assertJsonPath('combinaciones.0.id', $combinacionId);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertJsonPath('combinaciones.0.id', $combinacionId)
            ->assertJsonPath('temporada.version_catalogo', 4);
    }

    public function test_activar_una_temporada_desactiva_la_anterior(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $anterior = $this->crearTemporadaActivaPrueba([
            'codigo' => '2025-2026',
            'nombre' => 'Temporada anterior',
            'activa' => true,
        ]);

        $nuevaId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', [
                ...$this->vigenciaProductiva(),
                'codigo' => '2026-2027',
                'nombre' => 'Temporada nueva',
                'activa' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.activa', true)
            ->json('data.id');

        $this->assertFalse($anterior->refresh()->activa);
        $this->assertTrue(Temporada::query()->findOrFail($nuevaId)->activa);
        $this->assertSame(1, Temporada::query()->where('activa', true)->count());
    }

    public function test_administra_una_categoria_independiente_para_todas_las_especies_y_marcas(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        Temporada::query()->where('activa', true)->update(['activa' => false]);
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'CAT-2026',
            'nombre' => 'Temporada categorías',
            'activa' => true,
        ]);

        $categoriaId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/validacion/categorias', [
                'temporada_id' => $temporada->id,
                'nombre' => ' Exportación ',
                'codigo_externo' => 'cat-exp',
                'activo' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Exportación')
            ->assertJsonPath('data.codigo_externo', 'CAT-EXP')
            ->json('data.id');

        $this->actingAs($administrador, 'sanctum')
            ->getJson("/api/administracion/validacion/temporadas/{$temporada->id}/catalogo")
            ->assertOk()
            ->assertJsonPath('categorias.0.id', $categoriaId)
            ->assertJsonPath('categorias.0.nombre', 'Exportación');

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertJsonPath('categorias.0.id', $categoriaId);

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/validacion/categorias/{$categoriaId}", [
                'temporada_id' => $temporada->id,
                'nombre' => 'Mercado nacional',
                'codigo_externo' => null,
                'activo' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Mercado nacional');
    }

    public function test_crea_un_envase_asociado_a_un_cliente_de_la_temporada(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'ENV-2026',
            'nombre' => 'Temporada envases',
            'activa' => true,
        ]);
        $cliente = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Disfruta',
            'codigo_externo' => 'DIS',
            'activo' => true,
        ]);
        $especie = EspecieValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cereza',
            'activo' => true,
        ]);
        $datos = [
            'especie_validacion_id' => $especie->id,
            'nombre' => 'CC2.5GM',
            'codigo_externo' => 'CC25',
            'activo' => true,
        ];

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/validacion/envases', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliente_validacion_id');

        $envaseId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/validacion/envases', [
                ...$datos,
                'cliente_validacion_id' => $cliente->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.cliente.id', $cliente->id)
            ->json('data.id');

        $this->assertSame(
            $cliente->id,
            EnvaseValidacion::query()->findOrFail($envaseId)->cliente_validacion_id,
        );
    }

    public function test_importador_previsualiza_y_confirma_csv_sin_desactivar_ausencias(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        Temporada::query()->update(['activa' => false]);
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
            'version_catalogo' => 1,
        ]);
        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/clientes', [
                'codigo' => 'DIS',
                'nombre' => 'DIS',
                'activo' => true,
            ])
            ->assertCreated();
        $articuloAnterior = ArticuloValidacion::create([
            'temporada_id' => $temporada->id,
            'especie' => 'Cereza',
            'variedad' => 'Lapins',
            'calibre' => 'J',
            'envase' => 'Caja 5 kg',
            'activo' => true,
        ]);

        $archivo = UploadedFile::fake()->createWithContent(
            'temporada.csv',
            "especie;variedad;calibre;envase;cliente;marca;csg;predio;codigo_articulo;codigo_origen;codigo_combinacion\n".
            "Cereza;Santina;2J;Caja 5 kg;DIS;Atlas;105410;OLM;CER-SAN-2J;ORI-01;VAL-01\n".
            "Cereza;Regina;3J;Caja 2,5 kg;DIS;Premium;105411;RIO;CER-REG-3J;ORI-02;VAL-02\n",
        );

        $importacionId = $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/validacion/importaciones/previsualizar', [
                'temporada_id' => $temporada->id,
                'archivo' => $archivo,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.resumen.filas_validas', 2)
            ->assertJsonPath('data.resumen.filas_con_error', 0)
            ->json('data.id');

        $this->assertDatabaseCount('articulos_validacion', 1);

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/validacion/importaciones/{$importacionId}/confirmar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.resumen.creados.articulos', 8)
            ->assertJsonPath('data.resumen.creados.combinaciones', 16)
            ->assertJsonPath('data.resumen.version_catalogo_resultante', 2);

        $this->assertDatabaseHas('articulos_validacion', [
            'id' => $articuloAnterior->id,
            'activo' => true,
        ]);
        $this->assertDatabaseHas('origenes_validacion', [
            'cliente' => 'DIS',
            'marca' => 'Atlas',
            'csg' => '105410',
        ]);
        $this->assertDatabaseCount('combinaciones_validacion', 16);
    }

    public function test_importacion_con_error_no_puede_confirmarse(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
        ]);
        $archivo = UploadedFile::fake()->createWithContent(
            'incompleta.csv',
            "especie;variedad;calibre;envase;cliente;marca;csg\nCereza;Santina;2J;Caja 5 kg;DIS;Atlas;\n",
        );

        $importacionId = $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/validacion/importaciones/previsualizar', [
                'temporada_id' => $temporada->id,
                'archivo' => $archivo,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'con_errores')
            ->assertJsonPath('data.resumen.filas_con_error', 1)
            ->json('data.id');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/validacion/importaciones/{$importacionId}/confirmar")
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
    }

    public function test_importacion_vacia_se_rechaza_sin_crear_borrador(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2028-2029',
            'nombre' => 'Temporada 2028-2029',
            'activa' => true,
        ]);
        $archivo = UploadedFile::fake()->createWithContent(
            'sin-datos.csv',
            "especie;variedad;calibre;envase;cliente;marca;csg\n",
        );

        $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/validacion/importaciones/previsualizar', [
                'temporada_id' => $temporada->id,
                'archivo' => $archivo,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio')
            ->assertJsonPath('message', 'La planilla no contiene filas de datos para importar.');

        $this->assertDatabaseCount('importaciones_validacion', 0);
    }

    public function test_confirmacion_rechaza_defensivamente_un_borrador_sin_filas(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2029-2030',
            'nombre' => 'Temporada 2029-2030',
            'activa' => true,
            'version_catalogo' => 3,
        ]);
        $importacion = ImportacionValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre_archivo' => 'vacia.csv',
            'tipo_archivo' => 'csv',
            'checksum' => hash('sha256', 'vacia'),
            'estado' => 'borrador',
            'resumen' => ['filas_validas' => 0, 'filas_con_error' => 0],
            'filas' => [],
            'errores' => null,
            'creado_por_user_id' => $administrador->id,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/validacion/importaciones/{$importacion->id}/confirmar")
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio')
            ->assertJsonPath('message', 'La importación no contiene filas de datos y no puede confirmarse.');

        $this->assertSame('borrador', $importacion->refresh()->estado);
        $this->assertSame(3, $temporada->refresh()->version_catalogo);
    }

    public function test_validador_no_administra_catalogos_y_combinacion_no_habilitada_se_rechaza(): void
    {
        $validador = User::factory()->create(['rol' => RolUsuario::Validador]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'VAL-ADMIN-01',
            'nombre' => 'PDA validación',
            'activo' => true,
        ]);
        $token = $validador->crearTokenParaDispositivo($dispositivo, 'validacion-test')->plainTextToken;
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
        ]);
        $articulo = ArticuloValidacion::create([
            'temporada_id' => $temporada->id,
            'especie' => 'Cereza',
            'variedad' => 'Santina',
            'calibre' => '2J',
            'envase' => 'Caja 5 kg',
            'activo' => true,
        ]);
        $categoria = CategoriaValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Exportación',
            'activo' => true,
        ]);
        $origenHabilitado = OrigenValidacion::create([
            'temporada_id' => $temporada->id,
            'cliente' => 'DIS',
            'marca' => 'Atlas',
            'csg' => '105410',
            'activo' => true,
        ]);
        $origenNoHabilitado = OrigenValidacion::create([
            'temporada_id' => $temporada->id,
            'cliente' => 'OTRO',
            'marca' => 'Otra marca',
            'csg' => '999999',
            'activo' => true,
        ]);
        CombinacionValidacion::create([
            'temporada_id' => $temporada->id,
            'articulo_validacion_id' => $articulo->id,
            'origen_validacion_id' => $origenHabilitado->id,
            'activo' => true,
        ]);

        $this->withToken($token)
            ->getJson('/api/administracion/validacion')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/validacion/pallets', [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => 'PAL-NO-HABILITADO',
                'tipo_bulto' => 'pallet',
                'cantidad_cajas' => 120,
                'linea_proceso' => 1,
                'turno' => 'A',
                'temporada_id' => $temporada->id,
                'catalogo_version' => 1,
                'articulo_validacion_id' => $articulo->id,
                'origen_validacion_id' => $origenNoHabilitado->id,
                'categoria_validacion_id' => $categoria->id,
                'resultado' => 'aprobado',
                'generado_dispositivo_at' => now()->toAtomString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
    }
}
