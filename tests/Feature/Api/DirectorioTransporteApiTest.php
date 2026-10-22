<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\ConductorTransporte;
use App\Models\EventoDirectorioTransporte;
use App\Models\ItemMaterial;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Services\Materiales\ServicioRecepcionMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectorioTransporteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_primer_ingreso_guarda_directorio_normaliza_identidades_y_reintento_no_duplica(): void
    {
        [$actor, $token, $datos] = $this->preparar();
        $datos['transporte'] = $this->viaje();
        $respuesta = $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)
            ->assertCreated()->assertJsonPath('data.transporte.conductor.rut', '11111111-1')
            ->assertJsonPath('data.patente', 'ABCD12')->json('data');
        $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)
            ->assertCreated()->assertJsonPath('data.id', $respuesta['id']);
        $this->assertDatabaseCount('conductores_transporte', 1);
        $this->assertDatabaseCount('empresas_transporte', 1);
        $this->assertDatabaseCount('vehiculos_transporte', 1);
        $this->assertDatabaseCount('eventos_directorio_transporte', 3);
        $this->assertDatabaseHas('eventos_directorio_transporte', ['user_id' => $actor->id, 'usuario' => $actor->name]);
        $this->withToken($token)->getJson('/api/materiales/transporte/conductor?rut=11.111.111-1')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.nombre', 'Juan Pérez')->assertJsonPath('ultimo_viaje.transporte.vehiculo.patente', 'ABCD12');
        $this->withToken($token)->getJson('/api/materiales/transporte/empresa?rut=76.123.456-0')
            ->assertOk()->assertJsonPath('data.nombre', 'Transportes de prueba');
        $this->withToken($token)->getJson('/api/materiales/transporte/vehiculo?patente=ab-cd.12')
            ->assertOk()->assertJsonPath('data.descripcion', 'Camión azul');
    }

    public function test_mismo_conductor_y_empresa_sirven_a_otro_cliente_en_otro_camion_sin_cambiar_viaje_confirmado(): void
    {
        [$actor, $token, $datos] = $this->preparar();
        $datos['transporte'] = $this->viaje();
        $original = $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)->assertCreated()->json('data');
        $confirmada = app(ServicioRecepcionMaterial::class)->confirmar(
            RecepcionMaterial::findOrFail($original['id']), (string) Str::uuid(), 1, $actor, exigirFotoDocumento: false,
        );
        [, $otroToken, $otro] = $this->preparar('OTRO');
        $otro['transporte'] = $this->viaje('WXYZ34');
        $otro['transporte']['conductor'] = [...$otro['transporte']['conductor'],
            'telefono' => '+56999999999', 'actualizar_directorio' => true, 'version_conocida' => 1];
        $this->withToken($otroToken)->postJson('/api/materiales/recepciones', $otro)->assertCreated()
            ->assertJsonPath('data.transporte.conductor.version', 2)->assertJsonPath('data.patente', 'WXYZ34');
        $this->assertDatabaseCount('conductores_transporte', 1);
        $this->assertDatabaseCount('empresas_transporte', 1);
        $this->assertDatabaseCount('vehiculos_transporte', 2);
        $this->assertSame('+56999999999', ConductorTransporte::firstOrFail()->telefono);
        $confirmada->refresh();
        $this->assertEquals($original['transporte'], $confirmada->transporte_snapshot);
        $this->assertEquals($original['transporte'], $confirmada->snapshot_confirmacion['transporte']);
        $this->assertSame('ABCD12', $confirmada->patente);
        $evento = EventoDirectorioTransporte::where('tipo', 'conductor')->whereNotNull('antes')->firstOrFail();
        $this->assertSame('+56911111111', $evento->antes['telefono']);
        $this->assertSame('+56999999999', $evento->despues['telefono']);
    }

    public function test_datos_del_viaje_pueden_corregirse_sin_sobrescribir_directorio(): void
    {
        [, $token, $datos] = $this->preparar();
        $datos['transporte'] = $this->viaje();
        $original = $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)->assertCreated()->json('data');
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['transporte']['conductor']['nombre'] = 'Juan Pérez (dato del viaje)';
        $datos['version_conocida'] = 1;
        $this->withToken($token)->putJson('/api/materiales/recepciones/'.$original['id'], $datos)->assertOk()
            ->assertJsonPath('data.transporte.conductor.nombre', 'Juan Pérez (dato del viaje)');
        $this->assertSame('Juan Pérez', ConductorTransporte::firstOrFail()->nombre);
        $this->assertDatabaseCount('eventos_directorio_transporte', 3);
        $this->assertDatabaseHas('eventos_recepciones_materiales', ['recepcion_material_id' => $original['id'], 'tipo' => 'actualizada']);
    }

    public function test_actualizacion_con_version_obsoleta_revierte_recepcion_y_directorio(): void
    {
        [, $token, $datos] = $this->preparar();
        $datos['transporte'] = $this->viaje();
        $original = $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)->assertCreated()->json('data');
        ConductorTransporte::firstOrFail()->update(['version' => 2, 'nombre' => 'Nombre actualizado']);
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['transporte']['conductor'] = [...$datos['transporte']['conductor'],
            'actualizar_directorio' => true, 'version_conocida' => 1];
        $datos['transporte']['vehiculo']['patente'] = 'ZZZZ99';
        $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)->assertStatus(409);
        $this->assertDatabaseCount('recepciones_materiales', 1);
        $this->assertDatabaseCount('vehiculos_transporte', 1);
        $this->assertSame('Nombre actualizado', ConductorTransporte::firstOrFail()->nombre);

        // Corregir otro antecedente del viaje original conserva también su versión
        // histórica; no adopta el maestro actualizado por otra operación.
        $datos['version_conocida'] = 1;
        $datos['observacion'] = 'Solo se corrige una observación';
        $datos['transporte'] = $this->viaje();
        $this->withToken($token)->putJson('/api/materiales/recepciones/'.$original['id'], $datos)->assertOk()
            ->assertJsonPath('data.transporte.conductor.version', 1);
    }

    public function test_error_en_las_cantidades_no_deja_fichas_huerfanas_y_entradas_invalidas_se_rechazan(): void
    {
        [, $token, $datos] = $this->preparar();
        $datos['transporte'] = $this->viaje();
        $datos['detalles'][0]['bultos'][0]['cantidad'] = 11;
        $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)->assertUnprocessable();
        $this->assertDatabaseCount('conductores_transporte', 0);
        $this->assertDatabaseCount('empresas_transporte', 0);
        $this->assertDatabaseCount('vehiculos_transporte', 0);
        $this->assertDatabaseCount('eventos_directorio_transporte', 0);
        $datos['transporte']['conductor']['rut'] = '11.111.111-2';
        $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)->assertUnprocessable()
            ->assertJsonValidationErrors('transporte.conductor.rut');
        $datos['transporte']['conductor']['rut'] = ['11111111-1'];
        $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)->assertUnprocessable();
        $this->withToken($token)->getJson('/api/materiales/transporte/conductor?rut=11111111-2')->assertUnprocessable();
    }

    public function test_cliente_anterior_conserva_textos_y_empresa_sin_rut_no_crea_ficha_ambigua(): void
    {
        [, $token, $datos] = $this->preparar();
        $datos['patente'] = 'AB-CD 12';
        $datos['transportista'] = 'Transportista histórico';
        $original = $this->withToken($token)->postJson('/api/materiales/recepciones', $datos)->assertCreated()
            ->assertJsonPath('data.transporte', null)->assertJsonPath('data.patente', 'AB-CD 12')->json('data');
        $this->assertDatabaseCount('vehiculos_transporte', 0);
        $datos['transporte'] = $this->viaje();
        $datos['transporte']['empresa']['rut'] = null;
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['version_conocida'] = 1;
        $nuevo = $this->withToken($token)->putJson('/api/materiales/recepciones/'.$original['id'], $datos)->assertOk()
            ->assertJsonPath('data.transporte.empresa.id', null)->json('data');
        $this->assertDatabaseCount('empresas_transporte', 0);

        // Una aplicación anterior puede cambiar camión/empresa sin borrar al conductor.
        unset($datos['transporte']);
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['version_conocida'] = $nuevo['version'];
        $datos['patente'] = 'WXYZ34';
        $datos['transportista'] = 'Otro transportista';
        $this->withToken($token)->putJson('/api/materiales/recepciones/'.$original['id'], $datos)->assertOk()
            ->assertJsonPath('data.transporte.conductor.rut', '11111111-1')
            ->assertJsonPath('data.transporte.empresa.nombre', 'Otro transportista')
            ->assertJsonPath('data.transporte.vehiculo.patente', 'WXYZ34');
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['version_conocida']++;
        $datos['patente'] = 'CAMION-SIN-PATENTE';
        $this->withToken($token)->putJson('/api/materiales/recepciones/'.$original['id'], $datos)->assertOk()
            ->assertJsonPath('data.transporte.vehiculo.id', null)
            ->assertJsonPath('data.patente', 'CAMION-SIN-PATENTE');
    }

    public function test_directorio_exige_permiso_y_la_busqueda_no_crea_registros(): void
    {
        [, $token] = $this->preparar();
        $this->withToken($token)->getJson('/api/materiales/transporte/conductor?rut=11111111-1')
            ->assertOk()->assertJsonPath('data', null)->assertJsonPath('ultimo_viaje', null);
        $this->assertDatabaseCount('conductores_transporte', 0);
        $validador = User::factory()->create(['rol' => RolUsuario::Validador, 'activo' => true]);
        $sinPermiso = $validador->createToken('transporte-sin-acceso', ['oficina'])->plainTextToken;
        foreach (['conductor?rut=11111111-1', 'empresa?rut=76123456-0', 'vehiculo?patente=ABCD12'] as $consulta) {
            $this->withToken($sinPermiso)->getJson('/api/materiales/transporte/'.$consulta)->assertForbidden();
        }
    }

    private function viaje(string $patente = 'AB-CD 12'): array
    {
        return [
            'conductor' => ['rut' => '11.111.111-1', 'nombre' => 'Juan Pérez', 'telefono' => '+56911111111'],
            'empresa' => ['rut' => '76.123.456-0', 'nombre' => 'Transportes de prueba', 'telefono' => '+56222222222'],
            'vehiculo' => ['patente' => $patente, 'descripcion' => 'Camión azul'],
            'patente_carro' => 'XY-1234',
        ];
    }

    private function preparar(string $sufijo = 'GENERAL'): array
    {
        $actor = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $token = $actor->createToken('transporte-oficina', ['oficina'])->plainTextToken;
        $catalogo = ClienteMaterial::with('cliente')->where('codigo', 'GENERAL')->firstOrFail();
        if ($sufijo !== 'GENERAL') {
            $cliente = Cliente::create(['codigo' => $sufijo, 'nombre' => 'Cliente '.$sufijo, 'activo' => true,
                'creado_por_user_id' => $actor->id, 'actualizado_por_user_id' => $actor->id]);
            $catalogo = ClienteMaterial::create(['temporada_material_id' => $catalogo->temporada_material_id, 'cliente_id' => $cliente->id,
                'codigo' => $sufijo, 'nombre' => 'Cliente '.$sufijo, 'activo' => true,
                'creado_por_user_id' => $actor->id, 'actualizado_por_user_id' => $actor->id]);
        }
        $catalogo->cliente->update(['codigo_folio_materiales' => $sufijo === 'GENERAL' ? 'GE' : 'OT']);
        $proveedor = ProveedorMaterial::create(['codigo' => 'TR-'.$sufijo, 'nombre' => 'Proveedor transporte', 'activo' => true,
            'creado_por_user_id' => $actor->id, 'actualizado_por_user_id' => $actor->id]);
        DB::table('clientes_proveedores_materiales')->insert(['id' => (string) Str::uuid(), 'cliente_id' => $catalogo->cliente_id,
            'proveedor_material_id' => $proveedor->id, 'activo' => true, 'categorias' => json_encode(['Embalaje']),
            'creado_por_user_id' => $actor->id, 'actualizado_por_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $item = ItemMaterial::create(['cliente_material_id' => $catalogo->id, 'codigo' => 'TR-'.$sufijo, 'nombre' => 'Material transporte',
            'categoria' => 'Embalaje', 'categoria_operacional' => 'insumo', 'unidad_medida' => 'unidades', 'origen_sistema' => 'manual',
            'activo' => true, 'creado_por_user_id' => $actor->id, 'actualizado_por_user_id' => $actor->id]);

        return [$actor, $token, [
            'operacion_id' => (string) Str::uuid(), 'cliente_id' => $catalogo->cliente_id, 'proveedor_material_id' => $proveedor->id,
            'numero_guia_despacho' => 'GD-TRANSPORTE-'.$sufijo,
            'detalles' => [['item_material_id' => $item->id, 'cantidad_documental' => 10, 'cantidad_recibida' => 10,
                'bultos' => [['cantidad' => 10]]]],
        ]];
    }
}
