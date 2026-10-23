<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\EliminacionRecepcionMaterial;
use App\Models\EventoRecepcionMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\FotoRecepcionMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\PerfilAcceso;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegularizacionRecepcionMaterialApiTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\PreparaFotoDocumentoRecepcionMaterial;

    private string $token;

    private User $admin;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-23T18:00:00Z'));
        [$this->admin, $this->token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $this->withToken($this->token);
        $this->payload = ['operacion_id' => (string) Str::uuid(), 'cliente_id' => $cliente->id, 'proveedor_material_id' => $proveedor->id,
            'provisional' => true, 'fecha_recepcion' => '2026-10-20T10:30:00-03:00', 'motivo_registro_tardio' => 'Guía pendiente durante descarga de temporada',
            'numero_guia_despacho' => null, 'detalles' => [['item_material_id' => $item->id, 'cantidad_documental' => null,
                'cantidad_contada' => 12, 'cantidad_aceptada' => 10, 'cantidad_rechazada' => 2, 'bultos' => [['cantidad' => 10]]]]];
    }

    private function crear(array $cambios = []): array
    {
        return $this->postJson('/api/materiales/recepciones', [...$this->payload, 'operacion_id' => (string) Str::uuid(), ...$cambios])->assertCreated()->json('data');
    }

    private function confirmar(array $recepcion): array
    {
        return $this->postJson('/api/materiales/recepciones/'.$recepcion['id'].'/confirmar', ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion['version'], 'motivo_autorizacion' => 'Descarga contada; autorizo ingreso provisional'])->assertOk()->json('data');
    }

    private function regularizacion(array $recepcion): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion['version'], 'numero_guia_despacho' => 'GD-FINAL-891',
            'fecha_documento' => '2026-10-20', 'motivo' => 'Guía recibida y contrastada con el conteo físico', 'detalles' => [['id' => $recepcion['detalles'][0]['id'], 'cantidad_documental' => 12]]];
    }

    public function test_provisional_sin_documento_acepta_stock_una_sola_vez_y_conserva_ambas_fechas(): void
    {
        $recepcion = $this->crear();
        $this->assertSame('por_autorizar', $recepcion['registro']['estado']);
        $datos = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1, 'motivo_autorizacion' => 'Conteo supervisado, documentación pendiente'];
        $url = '/api/materiales/recepciones/'.$recepcion['id'].'/confirmar';
        $confirmada = $this->postJson($url, $datos)->assertOk()->assertJsonPath('data.estado', 'confirmada')->assertJsonPath('data.registro.estado', 'pendiente')->assertJsonPath('data.numero_guia_despacho', null)->json('data');
        $this->postJson($url, $datos)->assertOk();
        $this->assertDatabaseCount('folios_materiales', 1);
        $this->assertDatabaseCount('movimientos_inventario_materiales', 1);
        $this->assertSame(2, $confirmada['version']);
        $this->assertSame('2026-10-20 13:30:00', Folio::firstOrFail()->fecha_ingreso->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 18:00:00', MovimientoInventarioMaterial::firstOrFail()->ocurrido_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 18:00:00', RecepcionMaterial::firstOrFail()->confirmado_at->format('Y-m-d H:i:s'));
        $this->postJson($url, [...$datos, 'motivo_autorizacion' => 'Otro motivo diferente'])->assertConflict();
        $this->assertNotEmpty($confirmada['snapshot_confirmacion']['registro']['motivo_autorizacion']);
    }

    public function test_confirmador_sin_permiso_especifico_no_autoriza_ni_regulariza(): void
    {
        $perfil = PerfilAcceso::create(['codigo' => 'SIN-TARDIOS', 'nombre' => 'Solo gestión y confirmación', 'rol_base' => 'supervisor_materiales', 'modulos' => ['materiales.etiquetas'], 'modulos_tablet' => [], 'permisos_materiales' => ['recepciones.gestionar', 'recepciones.confirmar'], 'activo' => true]);
        $usuario = User::factory()->create(['rol' => RolUsuario::SupervisorMateriales, 'activo' => true, 'perfil_acceso_id' => $perfil->id]);
        $this->withToken($usuario->createToken('oficina', ['oficina'])->plainTextToken);
        $recepcion = $this->crear();
        $this->postJson('/api/materiales/recepciones/'.$recepcion['id'].'/confirmar', ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1, 'motivo_autorizacion' => 'Intento sin permiso'])->assertForbidden();
        $this->postJson('/api/materiales/recepciones/'.$recepcion['id'].'/regularizar', [])->assertForbidden();
        $this->assertDatabaseCount('folios_materiales', 0);
        $this->assertNull(RecepcionMaterial::firstOrFail()->autorizado_at);
    }

    public function test_normal_anterior_sigue_exigiendo_guia_cantidad_y_foto_y_repite_el_uuid_original(): void
    {
        $normal = $this->payload;
        unset($normal['provisional'], $normal['fecha_recepcion'], $normal['motivo_registro_tardio']);
        $this->postJson('/api/materiales/recepciones', $normal)->assertUnprocessable()->assertJsonValidationErrors(['numero_guia_despacho', 'detalles.0.cantidad_documental']);
        $normal['numero_guia_despacho'] = 'GD-NORMAL';
        $normal['detalles'][0]['cantidad_documental'] = 12;
        $recepcion = $this->postJson('/api/materiales/recepciones', $normal)->assertCreated()->json('data');
        $this->postJson('/api/materiales/recepciones', $normal)->assertCreated()->assertJsonPath('data.id', $recepcion['id']);
        $confirmacion = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1];
        $this->postJson('/api/materiales/recepciones/'.$recepcion['id'].'/confirmar', $confirmacion)->assertUnprocessable()->assertJsonPath('codigo', 'recepcion_sin_foto_documento');
        $this->postJsonConDocumento('/api/materiales/recepciones/'.$recepcion['id'].'/confirmar', $confirmacion)->assertOk();
        $this->assertNull(RecepcionMaterial::firstOrFail()->fecha_recepcion);
        $this->assertEquals(now(), Folio::firstOrFail()->fecha_ingreso);
    }

    public function test_registro_retroactivo_normal_requiere_autorizacion_pero_no_regularizacion_posterior(): void
    {
        $payload = $this->payload;
        $payload['detalles'][0]['cantidad_documental'] = 12;
        $recepcion = $this->crear([...$payload, 'provisional' => false, 'numero_guia_despacho' => 'GD-RETRO']);
        $url = '/api/materiales/recepciones/'.$recepcion['id'].'/confirmar';
        $datos = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1];
        $this->postJsonConDocumento($url, $datos)->assertUnprocessable();
        $this->postJson($url, [...$datos, 'motivo_autorizacion' => 'Ingreso físico validado contra guía'])->assertOk()->assertJsonPath('data.registro.retroactivo', true)->assertJsonPath('data.registro.estado', 'no_requerida');
    }

    public function test_registro_futuro_y_provisional_sin_motivo_no_crean_inventario(): void
    {
        $this->postJson('/api/materiales/recepciones', [...$this->payload, 'fecha_recepcion' => '2026-10-24T10:00:00-03:00'])->assertUnprocessable();
        $this->postJson('/api/materiales/recepciones', [...$this->payload, 'motivo_registro_tardio' => null])->assertUnprocessable();
        $this->assertDatabaseCount('recepciones_materiales', 0);
        $this->assertDatabaseCount('folios_materiales', 0);
    }

    public function test_regularizar_despues_de_consumos_y_reservas_no_toca_folios_saldos_ni_ledger(): void
    {
        $recepcion = $this->confirmar($this->crear());
        $url = '/api/materiales/recepciones/'.$recepcion['id'].'/regularizar';
        $datos = $this->regularizacion($recepcion);
        $this->postJson($url, $datos)->assertUnprocessable()->assertJsonPath('codigo', 'recepcion_sin_foto_documento');
        $this->documento($recepcion);
        $material = FolioMaterial::firstOrFail();
        $material->update(['cantidad_actual' => 7, 'cantidad_reservada' => 2]);
        $material->folio->update(['estado_operacional' => 'disponible']);
        MovimientoInventarioMaterial::create(['folio_id' => $material->folio_id, 'item_material_id' => $material->item_material_id, 'tipo' => 'consumo_centro_costo', 'cantidad' => 3, 'cantidad_anterior' => 10, 'cantidad_resultante' => 7, 'user_id' => $this->admin->id, 'motivo' => 'Consumo posterior al ingreso provisional', 'ocurrido_at' => now()]);
        $antes = $this->inventario();
        $snapshot = RecepcionMaterial::firstOrFail()->snapshot_confirmacion;
        $this->postJson($url, $datos)->assertOk()->assertJsonPath('data.registro.estado', 'regularizada')->assertJsonPath('data.version', 3)->assertJsonPath('data.detalles.0.cantidad_documental', '12.000');
        $this->postJson($url, $datos)->assertOk();
        $this->assertSame($antes, $this->inventario());
        $this->assertSame($snapshot, RecepcionMaterial::firstOrFail()->snapshot_confirmacion);
        $this->assertDatabaseCount('eventos_recepciones_materiales', 3);
        $this->postJson($url, [...$datos, 'numero_guia_despacho' => 'GUIA-OTRA'])->assertConflict();
        $evento = EventoRecepcionMaterial::where('tipo', 'regularizada')->firstOrFail();
        $this->getJson('/api/materiales/supervision/evidencia/evento/'.$evento->id)->assertOk()
            ->assertJsonPath('data.evento.documento_anterior.detalles.0.cantidad_documental', null)
            ->assertJsonPath('data.evento.documento_resultante.detalles.0.cantidad_documental', '12.000')
            ->assertJsonPath('data.evento.documento_resultante.detalles.0.item.nombre', 'Film para recepción')
            ->assertDontSee('payload_hash')->assertDontSee('ruta_original');
        $this->assertSame('regularizada', EventoRecepcionMaterial::latest('ocurrido_at')->where('tipo', 'regularizada')->firstOrFail()->tipo->value);
    }

    private function inventario(): array
    {
        return [DB::table('folios')->orderBy('id')->get()->toJson(), DB::table('folios_materiales')->orderBy('folio_id')->get()->toJson(), DB::table('bultos_recepciones_materiales')->orderBy('id')->get()->toJson(), DB::table('movimientos_inventario_materiales')->orderBy('id')->get()->toJson()];
    }

    public function test_regularizacion_exige_version_documento_y_todos_los_detalles_originales(): void
    {
        $recepcion = $this->confirmar($this->crear());
        $url = '/api/materiales/recepciones/'.$recepcion['id'].'/regularizar';
        $datos = $this->regularizacion($recepcion);
        $this->postJson($url, [...$datos, 'version_conocida' => 1])->assertConflict();
        $this->documento($recepcion);
        $this->postJson($url, [...$datos, 'detalles' => [['id' => (string) Str::uuid(), 'cantidad_documental' => 12]]])->assertUnprocessable();
        $this->postJson($url, [...$datos, 'detalles' => [$datos['detalles'][0], $datos['detalles'][0]]])->assertUnprocessable();
        $this->postJson($url, [...$datos, 'detalles' => []])->assertUnprocessable();
        $this->postJson($url, [...$datos, 'fecha_documento' => '2026-10-24'])->assertUnprocessable();
        $this->assertNull(RecepcionMaterial::firstOrFail()->regularizado_at);
        $this->assertDatabaseCount('eventos_recepciones_materiales', 2);
    }

    public function test_vencimiento_es_visible_y_regularizar_tarde_exige_reconocimiento_sin_mover_el_plazo(): void
    {
        $recepcion = $this->confirmar($this->crear());
        $limite = $recepcion['registro']['fecha_limite'];
        $this->travelTo(Carbon::parse('2026-10-26T03:00:00Z'));
        $this->getJson('/api/materiales/recepciones?regularizacion=vencida')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.registro.estado', 'vencida')->assertJsonPath('regularizacion.vencidas', 1);
        $this->documento($recepcion);
        $datos = $this->regularizacion($recepcion);
        $url = '/api/materiales/recepciones/'.$recepcion['id'].'/regularizar';
        $this->postJson($url, $datos)->assertUnprocessable();
        $this->postJson($url, [...$datos, 'aceptar_fuera_plazo' => true])->assertOk()->assertJsonPath('data.registro.regularizada_fuera_plazo', true)->assertJsonPath('data.registro.fecha_limite', $limite);
        $this->assertTrue(EventoRecepcionMaterial::where('tipo', 'regularizada')->firstOrFail()->datos['fuera_plazo']);
        $this->getJson('/api/materiales/recepciones?regularizacion=vencida')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertEquals(10, FolioMaterial::firstOrFail()->cantidad_actual);
    }

    public function test_autorizacion_fuera_de_cinco_dias_exige_reconocimiento_y_no_reinicia_el_plazo(): void
    {
        $recepcion = $this->crear(['fecha_recepcion' => '2026-10-10T10:00:00-03:00']);
        $url = '/api/materiales/recepciones/'.$recepcion['id'].'/confirmar';
        $datos = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1, 'motivo_autorizacion' => 'Excepción supervisada por atraso de respaldo'];
        $this->postJson($url, $datos)->assertUnprocessable();
        $this->postJson($url, [...$datos, 'aceptar_fuera_plazo' => true])->assertOk()->assertJsonPath('data.registro.estado', 'vencida')->assertJsonPath('data.registro.fecha_limite', $recepcion['registro']['fecha_limite']);
    }

    public function test_correccion_administrativa_no_reconstruye_provisionales(): void
    {
        $recepcion = $this->confirmar($this->crear());
        $datos = [...$this->payload, 'operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion['version'], 'motivo_correccion' => 'No debe reconstruir inventario', 'confirmacion_operacion_id' => (string) Str::uuid()];
        $antes = $this->inventario();
        $this->putJson('/api/materiales/recepciones/'.$recepcion['id'].'/administrar', $datos)->assertUnprocessable();
        $this->assertSame($antes, $this->inventario());
    }

    public function test_editar_borrador_sin_campos_nuevos_conserva_la_fecha_y_el_modo_provisional(): void
    {
        $recepcion = $this->crear();
        $datos = $this->payload;
        unset($datos['provisional'], $datos['fecha_recepcion'], $datos['motivo_registro_tardio']);
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['version_conocida'] = 1;
        $this->putJson('/api/materiales/recepciones/'.$recepcion['id'], $datos)->assertOk()->assertJsonPath('data.registro.provisional', true)->assertJsonPath('data.registro.fecha_recepcion', $recepcion['registro']['fecha_recepcion']);
    }

    private function documento(array $recepcion): void
    {
        FotoRecepcionMaterial::create([
            'recepcion_material_id' => $recepcion['id'], 'tipo' => 'documento', 'orden' => 1,
            'ruta_original' => 'fixture/documento.jpg', 'ruta_miniatura' => 'fixture/documento-mini.jpg',
            'mime' => 'image/jpeg', 'bytes' => 100, 'ancho' => 100, 'alto' => 100,
            'sha256' => hash('sha256', 'guía recibida'), 'operacion_id' => (string) Str::uuid(), 'subida_por_user_id' => $this->admin->id,
        ]);
    }

    public function test_no_regulariza_una_temporada_cerrada_y_no_cambia_documentos(): void
    {
        $recepcion = $this->confirmar($this->crear());
        $this->documento($recepcion);
        DB::table('temporadas')->update(['activa' => false]);
        app(ServicioTemporadaActiva::class)->olvidar();
        $this->postJson('/api/materiales/recepciones/'.$recepcion['id'].'/regularizar', $this->regularizacion($recepcion))->assertUnprocessable();
        $this->assertNull(RecepcionMaterial::firstOrFail()->regularizado_at);
        $this->assertNull(RecepcionMaterial::firstOrFail()->numero_guia_despacho);
    }

    public function test_guia_de_provisional_eliminada_y_su_autorizacion_quedan_en_auditoria(): void
    {
        $recepcion = $this->confirmar($this->crear());
        $this->deleteJson('/api/materiales/recepciones/'.$recepcion['id'], ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion['version'], 'motivo' => 'Registro duplicado; se conserva respaldo'])->assertOk();
        $eliminacion = EliminacionRecepcionMaterial::firstOrFail();
        $this->assertNull($eliminacion->numero_guia_despacho);
        $this->assertTrue(data_get($eliminacion->snapshot, 'cabecera.registro.provisional'));
        $this->assertNotEmpty(data_get($eliminacion->snapshot, 'cabecera.registro.motivo_autorizacion'));
    }

    public function test_cantidad_documental_desconocida_con_conversion_se_regulariza_en_unidad_base_sin_reconvertir_stock(): void
    {
        $itemId = $this->payload['detalles'][0]['item_material_id'];
        $this->putJson('/api/administracion/materiales/items/'.$itemId.'/unidades', ['unidad_origen' => 'cajas', 'factor' => '10', 'activa' => true, 'version_conocida' => 0, 'motivo' => 'Diez rollos por caja'])->assertOk();
        $datos = $this->payload;
        $datos['detalles'][0] = [...$datos['detalles'][0], 'unidad_ingreso' => 'cajas', 'conversion_version' => 1, 'cantidad_contada' => 2, 'cantidad_aceptada' => 2, 'cantidad_rechazada' => 0, 'bultos' => [['cantidad' => 2]]];
        $recepcion = $this->confirmar($this->crear($datos));
        $this->assertNull($recepcion['detalles'][0]['cantidad_documental']);
        $this->assertEquals(20, FolioMaterial::firstOrFail()->cantidad_actual);
        $conversion = $recepcion['detalles'][0]['conversion_unidad'];
        $this->documento($recepcion);
        $regularizacion = $this->regularizacion($recepcion);
        $regularizacion['detalles'][0]['cantidad_documental'] = 20;
        $this->postJson('/api/materiales/recepciones/'.$recepcion['id'].'/regularizar', $regularizacion)->assertOk()->assertJsonPath('data.detalles.0.cantidad_documental', '20.000')->assertJsonPath('data.detalles.0.conversion_unidad', $conversion);
        $this->assertEquals(20, FolioMaterial::firstOrFail()->cantidad_actual);
        $this->assertDatabaseCount('movimientos_inventario_materiales', 1);
    }

    private function prepararCatalogo(): array
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $token = $administrador->createToken('oficina-test', ['oficina'])->plainTextToken;
        $catalogo = ClienteMaterial::query()
            ->with(['cliente', 'temporada'])
            ->where('codigo', 'GENERAL')
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))
            ->firstOrFail();
        $cliente = $catalogo->cliente;
        $cliente->update(['codigo_folio_materiales' => 'GE']);
        $item = ItemMaterial::create([
            'cliente_material_id' => $catalogo->id,
            'codigo' => 'FILM-REC',
            'nombre' => 'Film para recepción',
            'categoria' => 'Embalaje',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'rollos',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        $proveedor = ProveedorMaterial::create([
            'codigo' => 'PROV-REC',
            'nombre' => 'Proveedor recepción',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        DB::table('clientes_proveedores_materiales')->insert([
            'id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'activo' => true,
            'categorias' => json_encode(['Embalaje'], JSON_UNESCAPED_UNICODE),
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$administrador, $token, $cliente, $proveedor, $item];
    }
}
