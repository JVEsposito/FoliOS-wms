<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\EstadoRecepcionMaterial;
use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\EliminacionRecepcionMaterial;
use App\Models\FotoRecepcionMaterial;
use App\Models\ItemMaterial;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Services\Materiales\ServicioRecepcionMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class FotosRecepcionMaterialApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private RecepcionMaterial $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $catalogo = ClienteMaterial::where('codigo', 'GENERAL')->whereHas('temporada', fn ($q) => $q->where('activa', true))->firstOrFail();
        $catalogo->cliente->update(['codigo_folio_materiales' => 'GE']);
        $proveedor = ProveedorMaterial::create(['codigo' => 'FOTOS', 'nombre' => 'Proveedor fotos', 'activo' => true, 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        DB::table('clientes_proveedores_materiales')->insert([
            'id' => (string) Str::uuid(), 'cliente_id' => $catalogo->cliente_id, 'proveedor_material_id' => $proveedor->id,
            'activo' => true, 'categorias' => json_encode(['Embalaje']), 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = ItemMaterial::create(['cliente_material_id' => $catalogo->id, 'codigo' => 'FOTO', 'nombre' => 'Film',
            'categoria' => 'Embalaje', 'categoria_operacional' => CategoriaOperacionalMaterial::Insumo, 'unidad_medida' => 'rollos', 'activo' => true, 'origen_sistema' => 'manual', 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        $this->recepcion = app(ServicioRecepcionMaterial::class)->crear([
            'operacion_id' => (string) Str::uuid(), 'cliente_id' => $catalogo->cliente_id, 'proveedor_material_id' => $proveedor->id,
            'numero_guia_despacho' => 'GUIA-123', 'detalles' => [['item_material_id' => $item->id, 'cantidad_documental' => 10,
                'cantidad_contada' => 10, 'cantidad_aceptada' => 10, 'cantidad_recibida' => 10, 'cantidad_rechazada' => 0, 'bultos' => [['cantidad' => 10]]]],
        ], $this->admin);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_confirmacion_exige_documento_y_snapshot_guarda_evidencia_sin_cambiar_version_al_subir(): void
    {
        $this->confirmar()->assertUnprocessable()->assertJsonPath('codigo', 'recepcion_sin_foto_documento');
        $this->assertSame(EstadoRecepcionMaterial::Borrador, $this->recepcion->fresh()->estado);
        $this->subir('referencial')->assertCreated();
        $this->confirmar()->assertUnprocessable();
        $foto = $this->subir()->assertCreated()->json('data');
        $this->assertSame(1, $this->recepcion->fresh()->version);
        $op = (string) Str::uuid();
        $confirmada = $this->confirmar($op)->assertOk()->json('data');
        $this->assertArrayNotHasKey('sin_foto_documento', $confirmada['snapshot_confirmacion']);
        $this->assertSame($foto['sha256'], collect($confirmada['snapshot_confirmacion']['fotos'])->firstWhere('id', $foto['id'])['sha256']);
        // Simula evidencia retirada por una vía externa: el reintento histórico no vuelve a validar fotos.
        FotoRecepcionMaterial::where('recepcion_material_id', $this->recepcion->id)->delete();
        $this->confirmar($op)->assertOk()->assertJsonPath('data.snapshot_confirmacion', $confirmada['snapshot_confirmacion']);
    }

    public function test_corregir_confirmada_legada_sin_fotos_reutiliza_folios_y_registra_ausencia_de_documento(): void
    {
        $this->subir()->assertCreated();
        $confirmada = $this->confirmar()->assertOk()->json('data');
        // Una recepción anterior al requisito no conserva fotos ni sus referencias en el snapshot.
        FotoRecepcionMaterial::where('recepcion_material_id', $this->recepcion->id)->delete();
        $snapshot = $confirmada['snapshot_confirmacion'];
        unset($snapshot['fotos']);
        $this->recepcion->update(['snapshot_confirmacion' => $snapshot]);
        $datos = [
            'operacion_id' => (string) Str::uuid(),
            'confirmacion_operacion_id' => (string) Str::uuid(),
            'version_conocida' => $confirmada['version'],
            'motivo_correccion' => 'Se corrige el número de guía de una recepción legada.',
            'cliente_id' => $this->recepcion->cliente_id,
            'proveedor_material_id' => $this->recepcion->proveedor_material_id,
            'numero_guia_despacho' => 'GUIA-CORREGIDA',
            'detalles' => [[
                'item_material_id' => $this->recepcion->detalles->first()->item_material_id,
                'cantidad_documental' => 10, 'cantidad_contada' => 10,
                'cantidad_aceptada' => 10, 'cantidad_recibida' => 10, 'cantidad_rechazada' => 0,
                'bultos' => [['cantidad' => 10]],
            ]],
        ];

        $corregida = $this->putJson($this->base().'/administrar', $datos)
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.numero_guia_despacho', 'GUIA-CORREGIDA')
            ->assertJsonPath('data.snapshot_confirmacion.sin_foto_documento', true)
            ->assertJsonCount(0, 'data.snapshot_confirmacion.fotos')
            ->json('data');

        $this->assertSame(
            array_column($confirmada['snapshot_confirmacion']['folios'], 'numero_folio'),
            array_column($corregida['snapshot_confirmacion']['folios'], 'numero_folio'),
        );
        $this->assertDatabaseCount('folios_materiales', 1);
        $this->putJson($this->base().'/administrar', $datos)->assertOk()
            ->assertJsonPath('data.snapshot_confirmacion', $corregida['snapshot_confirmacion']);
        $this->assertDatabaseCount('folios_materiales', 1);
    }

    public function test_confirmar_anulada_sin_fotos_prioriza_el_error_de_estado(): void
    {
        $this->recepcion->update(['estado' => EstadoRecepcionMaterial::Anulada]);

        $this->confirmar()->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio')
            ->assertJsonPath('message', 'La recepción ya no se encuentra en borrador.');
    }

    public function test_confirmar_sin_fotos_con_version_obsoleta_prioriza_el_conflicto(): void
    {
        $this->recepcion->update(['version' => 2]);

        $this->confirmar()->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional')
            ->assertJsonPath('message', 'La recepción cambió desde la última lectura.');
    }

    public function test_limites_por_tipo_y_operacion_id_no_duplica_y_rechaza_otro_archivo(): void
    {
        $archivo = UploadedFile::fake()->image('foto.jpg', 80, 40);
        $op = (string) Str::uuid();
        $primera = $this->subir('documento', $archivo, $op)->assertCreated()->json('data');
        $this->subir('documento', $archivo, $op)->assertOk()->assertJsonPath('data.id', $primera['id']);
        $this->subir('documento', UploadedFile::fake()->image('otra.jpg', 40, 80), $op)->assertConflict();
        $this->assertCount(2, Storage::disk('local')->allFiles());
        foreach (['documento', 'referencial'] as $tipo) {
            for ($i = $tipo === 'documento' ? 1 : 0; $i < 5; $i++) {
                $this->subir($tipo)->assertCreated();
            }
            $this->subir($tipo)->assertUnprocessable();
        }
        $this->assertSame(10, $this->recepcion->fotos()->count());
        $this->assertCount(20, Storage::disk('local')->allFiles());
    }

    public function test_tipo_real_tamano_y_resolucion_se_validan_antes_de_guardar(): void
    {
        $this->subir('documento', UploadedFile::fake()->createWithContent('falso.jpg', 'no es una imagen'))->assertUnprocessable();
        $this->subir('documento', UploadedFile::fake()->image('grande.png')->size(5121))->assertUnprocessable();
        $this->subir('documento', UploadedFile::fake()->createWithContent('vector.jpg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))->assertUnprocessable();
        $this->assertSame(0, $this->recepcion->fotos()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_borrador_borra_fisicamente_y_confirmada_exige_admin_motivo_y_documento_restante(): void
    {
        $foto = $this->subir()->assertCreated()->json('data');
        $modelo = FotoRecepcionMaterial::findOrFail($foto['id']);
        $this->deleteJson($this->base().'/fotos/'.$foto['id'])->assertNoContent();
        $this->assertDatabaseMissing('fotos_recepciones_materiales', ['id' => $foto['id']]);
        Storage::disk('local')->assertMissing($modelo->ruta_original);
        $primera = $this->subir()->assertCreated()->json('data');
        $this->confirmar()->assertOk();
        $url = $this->base().'/fotos/'.$primera['id'];
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroMateriales]);
        $this->actingAs($operador, 'sanctum')->deleteJson($url, ['motivo' => 'Error'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($url)->assertUnprocessable();
        $this->deleteJson($url, ['motivo' => 'Error'])->assertUnprocessable()->assertJsonPath('codigo', 'recepcion_requiere_foto_documento');
        $this->subir()->assertCreated();
        $this->deleteJson($url, ['motivo' => 'Documento repetido'])->assertNoContent();
        $eliminada = FotoRecepcionMaterial::withTrashed()->findOrFail($primera['id']);
        $this->assertNotNull($eliminada->eliminada_at);
        $this->assertSame($this->admin->id, $eliminada->eliminada_por_user_id);
        $this->assertSame('Documento repetido', $eliminada->motivo_eliminacion);
        Storage::disk('local')->assertExists($eliminada->ruta_original);
    }

    public function test_anulada_conserva_fotos_y_rechaza_subida_y_borrado(): void
    {
        $foto = $this->subir()->assertCreated()->json('data');
        $this->confirmar()->assertOk();
        $this->postJson($this->base().'/anular', ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Recepción anulada'])->assertOk();
        $this->subir()->assertUnprocessable();
        $this->deleteJson($this->base().'/fotos/'.$foto['id'])->assertUnprocessable();
        $this->getJson($foto['url'])->assertOk();
    }

    public function test_privacidad_pertenencia_encabezados_y_filtro_de_recepciones_antiguas(): void
    {
        $foto = $this->subir()->assertCreated()->json('data');
        foreach ([$foto['url'], $foto['miniatura_url']] as $url) {
            $r = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $r->headers->get('Cache-Control'));
        }
        $otra = $this->recepcion->replicate();
        $otra->operacion_id = (string) Str::uuid();
        $otra->save();
        $this->getJson('/api/materiales/recepciones/'.$otra->id.'/fotos/'.$foto['id'].'/original')->assertNotFound();
        $this->getJson('/api/materiales/recepciones')->assertOk()->assertJsonMissingPath('data.0.fotos');
        $this->getJson($this->base())->assertOk()->assertJsonCount(1, 'data.fotos')->assertJsonPath('data.puede_confirmar_por_fotos', true);
        $otra->update(['estado' => EstadoRecepcionMaterial::Confirmada]);
        $this->getJson('/api/materiales/recepciones?sin_foto_documento=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otra->id)->assertJsonPath('data.0.fotos_documento', 0);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')->getJson($foto['url'])->assertForbidden();
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->getJson($foto['url'])->assertUnauthorized();
    }

    public function test_zip_nombres_y_sin_fotos_no_encontrado(): void
    {
        $this->getJson($this->base().'/fotos.zip')->assertNotFound();
        $this->subir()->assertCreated();
        $this->subir('referencial')->assertCreated();
        $respuesta = $this->get($this->base().'/fotos.zip')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($ruta));
            $this->assertSame('GUIA-123_documento_1.jpg', $zip->getNameIndex(0));
            $this->assertSame('GUIA-123_referencial_1.jpg', $zip->getNameIndex(1));
            $zip->close();
        } finally {
            @unlink($ruta);
        }
    }

    public function test_eliminacion_administrativa_archiva_evidencia_y_permiso_de_descarga(): void
    {
        $foto = $this->subir()->assertCreated()->json('data');
        $original = FotoRecepcionMaterial::findOrFail($foto['id'])->ruta_original;
        $this->confirmar()->assertOk();
        $this->deleteJson($this->base(), ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 2, 'motivo' => 'Registro duplicado'])->assertOk();
        $eliminacion = EliminacionRecepcionMaterial::latest()->firstOrFail();
        $evidencia = $eliminacion->snapshot['fotos'][0];
        $this->assertSame($foto['sha256'], $evidencia['sha256']);
        $this->assertDatabaseMissing('fotos_recepciones_materiales', ['id' => $foto['id']]);
        Storage::disk('local')->assertExists($evidencia['ruta']);
        Storage::disk('local')->assertMissing($original);
        $url = '/api/materiales/recepciones/eliminaciones/'.$eliminacion->id.'/fotos/0';
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/materiales/recepciones/eliminaciones')->assertOk()->assertJsonPath('data.0.fotos.0.url', $url);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum')->getJson($url)->assertForbidden();
        $this->getJson('/api/materiales/recepciones/eliminaciones')->assertForbidden();
    }

    public function test_fallo_de_traslado_se_registra_y_la_evidencia_original_sigue_descargable(): void
    {
        $foto = $this->subir()->assertCreated()->json('data');
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('move')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        Log::shouldReceive('error')->twice();
        $this->deleteJson($this->base(), ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1, 'motivo' => 'Registro duplicado'])->assertOk();
        $eliminacion = EliminacionRecepcionMaterial::latest()->firstOrFail();
        $this->assertSame($foto['sha256'], $eliminacion->snapshot['fotos'][0]['sha256']);
        $this->get('/api/materiales/recepciones/eliminaciones/'.$eliminacion->id.'/fotos/0')->assertOk();
    }

    private function subir(string $tipo = 'documento', ?UploadedFile $archivo = null, ?string $operacion = null)
    {
        return $this->post($this->base().'/fotos', ['archivo' => $archivo ?? UploadedFile::fake()->image('foto.jpg', 80, 40), 'tipo' => $tipo, 'operacion_id' => $operacion ?? (string) Str::uuid()], ['Accept' => 'application/json']);
    }

    private function confirmar(?string $operacion = null)
    {
        return $this->postJson($this->base().'/confirmar', ['operacion_id' => $operacion ?? (string) Str::uuid(), 'version_conocida' => 1]);
    }

    private function base(): string
    {
        return '/api/materiales/recepciones/'.$this->recepcion->id;
    }
}
