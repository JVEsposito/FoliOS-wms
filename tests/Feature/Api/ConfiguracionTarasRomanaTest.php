<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConfiguracionTarasRomanaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bins_y_totes_tienen_taras_oficiales_y_solo_administracion_puede_modificarlas(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);

        $catalogo = $this->actingAs($operador, 'sanctum')->getJson('/api/romana/catalogos')->assertOk()->json();
        $this->assertEqualsWithDelta(43.000, $catalogo['taras_romana']['bins'], 0.0001);
        $this->assertEqualsWithDelta(0.890, $catalogo['taras_romana']['totes'], 0.0001);
        $this->assertFalse($catalogo['puede_configurar_taras_romana']);

        $ruta = '/api/romana/taras/bins';
        $datos = ['tara_unitaria' => 44, 'motivo' => 'Nuevo estándar aprobado por planta'];
        $this->putJson($ruta, $datos)->assertForbidden();
        $this->actingAs($admin, 'sanctum')->putJson($ruta, $datos)->assertOk()
            ->assertJsonPath('data.tara_unitaria', 44);
        $this->assertDatabaseHas('configuracion_taras_romana', ['tipo_envase' => 'bins', 'tara_unitaria' => 44]);
        $this->assertDatabaseHas('historial_configuracion_taras_romana', [
            'tipo_envase' => 'bins',
            'tara_anterior' => 43,
            'tara_nueva' => 44,
            'motivo' => $datos['motivo'],
            'modificado_por_user_id' => $admin->id,
        ]);
        $this->putJson('/api/romana/taras/esponjas', $datos)->assertNotFound();
        $this->putJson('/api/romana/taras/totes', ['tara_unitaria' => 0.9, 'motivo' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo');
    }

    public function test_operador_no_puede_enviar_tara_incorrecta_y_las_recepciones_conservan_su_snapshot(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'TAR-01', 'nombre' => 'Cliente Taras', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $datos = [
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'fruta_pesaje_envases', 'especie_validacion_id' => $especie->id,
            'tipo_servicio' => 'proceso',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2, 'propiedad' => 'cliente', 'tara_unitaria' => 40]],
            'numero_guia_despacho' => 'GD-TAR-01', 'patente_camion' => 'ABCD12',
            'tipo_camion' => 'plano', 'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Conductor',
        ];
        $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('envases.0.tara_unitaria');

        unset($datos['envases'][0]['tara_unitaria']);
        $recepcion = $this->postJson('/api/romana/recepciones', $datos)->assertCreated()
            ->assertJsonPath('data.taras_romana_snapshot.bins', 43)
            ->json('data');
        $this->assertDatabaseHas('detalles_envases_recepcion_romana', [
            'recepcion_romana_id' => $recepcion['id'], 'tipo_envase' => 'bins', 'tara_unitaria_salida' => 43,
        ]);

        $this->actingAs($admin, 'sanctum')->putJson('/api/romana/taras/bins', [
            'tara_unitaria' => 44, 'motivo' => 'Nueva calibración de bins',
        ])->assertOk();
        $this->actingAs($operador, 'sanctum')->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => (string) Str::uuid(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2]],
            'peso_bruto' => 500,
        ])->assertOk()->assertJsonPath('data.peso_tara', 86)
            ->assertJsonPath('data.peso_neto', 414);

        $datos['operacion_id'] = (string) Str::uuid();
        $datos['numero_guia_despacho'] = 'GD-TAR-02';
        $datos['envases'][0]['tara_unitaria'] = 43;
        $this->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('envases.0.tara_unitaria');
    }

    public function test_esponjas_permanecen_variables_en_cada_camion(): void
    {
        $this->assertNull(app(\App\Services\Romana\ServicioTarasRomana::class)->paraRecepcion(null, 'esponjas'));
        $this->assertNull(app(\App\Services\Romana\ServicioTarasRomana::class)->paraRecepcion(null, 'esponja_tote'));
        app(\App\Services\Romana\ServicioTarasRomana::class)->asegurarValor('esponjas', 7.4);
        app(\App\Services\Romana\ServicioTarasRomana::class)->asegurarValor('esponjas', 6.8);
        $this->assertDatabaseCount('historial_configuracion_taras_romana', 0);
    }
}
