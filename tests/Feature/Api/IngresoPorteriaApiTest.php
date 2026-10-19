<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\IngresoPorteria;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\IdentidadOficina;
use App\Services\Autorizacion\InicioOficina;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngresoPorteriaApiTest extends TestCase
{
    use RefreshDatabase;

    private function guardia(): User
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::GuardiaPorteria]);
        $this->actingAs($usuario, 'sanctum');

        return $usuario;
    }

    private function datos(array $cambios = []): array
    {
        return [...[
            'operacion_id' => (string) Str::uuid(), 'cliente_otro' => 'Servicio de transporte',
            'motivo' => 'carga', 'patente' => 'ab-cd 12', 'tipo_camion' => 'termo',
            'nombre_conductor' => 'Conductor de prueba', 'guias' => ['000123', '000124'],
        ], ...$cambios];
    }

    public function test_el_guardia_tiene_inicio_propio_y_no_accede_a_romana_ni_administracion(): void
    {
        $guardia = User::factory()->create(['rol' => RolUsuario::GuardiaPorteria, 'password' => Hash::make('secreto-seguro')]);
        $this->postJson('/api/acceso-oficina', ['email' => $guardia->email, 'password' => 'secreto-seguro'])
            ->assertOk()->assertJsonPath('usuario.inicio', '/oficina/porteria')
            ->assertJsonPath('usuario.puede_registrar_porteria', true);
        $this->actingAs($guardia, 'sanctum');
        $this->getJson('/api/romana/catalogos')->assertForbidden();
        $this->postJson('/api/administracion/clientes', ['codigo' => 'NO', 'nombre' => 'No autorizado'])->assertForbidden();
        $this->get('/oficina/porteria')->assertOk()->assertSee('Ingresos Portería');
    }

    public function test_registra_varias_guias_del_cliente_del_catalogo_y_conserva_el_nombre(): void
    {
        $guardia = $this->guardia();
        $cliente = Cliente::query()->create(['codigo' => 'FALCO', 'nombre' => 'Falco Fruit', 'activo' => true]);
        $this->getJson('/api/porteria/catalogos')->assertOk()->assertJsonPath('clientes.0.id', $cliente->id);
        $id = $this->postJson('/api/porteria/ingresos', $this->datos(['cliente_otro' => null, 'cliente_id' => $cliente->id, 'cantidad' => 24, 'unidad' => 'bins']))
            ->assertCreated()->assertJsonPath('data.patente', 'ABCD12')->assertJsonPath('data.cliente_nombre', 'Falco Fruit')
            ->assertJsonPath('data.guias', ['000123', '000124'])->assertJsonPath('data.cantidad', 24)->json('data.id');
        $cliente->update(['nombre' => 'Nombre posterior']);
        $this->getJson('/api/porteria/ingresos/'.$id)->assertOk()->assertJsonPath('data.cliente_nombre', 'Falco Fruit')
            ->assertJsonPath('eventos.0.accion', 'registrado')->assertJsonPath('eventos.0.user_nombre', $guardia->name);
        $this->assertDatabaseCount('recepciones_romana', 0);
    }

    public function test_varios_camiones_comparten_guia_y_una_patente_puede_volver(): void
    {
        $this->guardia();
        foreach (['ABCD12', 'EFGH34', 'ABCD12'] as $patente) {
            $this->postJson('/api/porteria/ingresos', $this->datos(['patente' => $patente, 'guias' => ['000123']]))->assertCreated();
        }
        $this->assertDatabaseCount('ingresos_porteria', 3);
        $this->assertSame(3, IngresoPorteria::query()->get()->map->codigo()->unique()->count());
    }

    public function test_el_reintento_es_idempotente_y_un_uuid_reutilizado_con_otros_datos_es_conflicto(): void
    {
        $this->guardia();
        $datos = $this->datos();
        $id = $this->postJson('/api/porteria/ingresos', $datos)->assertCreated()->json('data.id');
        $this->postJson('/api/porteria/ingresos', $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/porteria/ingresos', [...$datos, 'patente' => 'ZZZZ99'])->assertConflict();
        $this->guardia();
        $this->postJson('/api/porteria/ingresos', $datos)->assertConflict();
        $this->assertDatabaseCount('ingresos_porteria', 1);
        $this->assertDatabaseCount('eventos_ingreso_porteria', 1);
    }

    public function test_otro_no_crea_clientes_y_vacio_y_servicio_admiten_sin_guia(): void
    {
        $this->guardia();
        $antes = Cliente::query()->count();
        foreach (['vacio', 'servicio'] as $motivo) {
            $this->postJson('/api/porteria/ingresos', $this->datos(['motivo' => $motivo, 'guias' => []]))
                ->assertCreated()->assertJsonPath('data.cliente_es_otro', true)->assertJsonPath('data.guias', []);
        }
        $this->assertSame($antes, Cliente::query()->count());
        $this->postJson('/api/porteria/ingresos', $this->datos(['guias' => []]))->assertUnprocessable()->assertJsonValidationErrors('guias');
    }

    public function test_rechaza_guia_repetida_dentro_del_ingreso_cliente_inactivo_y_cantidad_sin_unidad(): void
    {
        $this->guardia();
        $this->postJson('/api/porteria/ingresos', $this->datos(['guias' => ['123', ' 123 ']]))->assertUnprocessable()->assertJsonValidationErrors('guias.0');
        $this->postJson('/api/porteria/ingresos', $this->datos(['cantidad' => 20]))->assertUnprocessable()->assertJsonValidationErrors('unidad');
        $cliente = Cliente::query()->create(['codigo' => 'INACTIVO', 'nombre' => 'Inactivo', 'activo' => false]);
        $this->postJson('/api/porteria/ingresos', $this->datos(['cliente_otro' => null, 'cliente_id' => $cliente->id]))->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
        $this->assertDatabaseCount('ingresos_porteria', 0);
    }

    public function test_solo_administracion_corrige_y_anula_con_motivo_historial_y_control_de_version(): void
    {
        $this->guardia();
        $datos = $this->datos();
        $id = $this->postJson('/api/porteria/ingresos', $datos)->assertCreated()->json('data.id');
        $correccion = [...$datos, 'nombre_conductor' => 'Nombre corregido', 'version' => 1, 'motivo_correccion' => 'Error al escribir el conductor'];
        $this->putJson('/api/porteria/ingresos/'.$id, $correccion)->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->putJson('/api/porteria/ingresos/'.$id, $correccion)->assertOk()->assertJsonPath('data.nombre_conductor', 'Nombre corregido')->assertJsonPath('data.version', 2);
        $this->putJson('/api/porteria/ingresos/'.$id, $correccion)->assertConflict();
        $this->postJson('/api/porteria/ingresos/'.$id.'/anular', ['version' => 2, 'motivo' => 'xx'])->assertUnprocessable();
        $this->postJson('/api/porteria/ingresos/'.$id.'/anular', ['version' => 2, 'motivo' => 'El camión finalmente no ingresó'])->assertOk();
        $this->getJson('/api/porteria/ingresos/'.$id)->assertOk()->assertJsonCount(3, 'eventos')->assertJsonPath('eventos.0.accion', 'anulado');
        $this->assertDatabaseCount('ingresos_porteria', 1);
    }

    public function test_la_bitacora_corta_por_dia_de_chile_y_funciona_sin_temporada_activa(): void
    {
        $this->guardia();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 02:30:00', 'UTC'));
        $this->postJson('/api/porteria/ingresos', $this->datos())->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 03:30:00', 'UTC'));
        $this->postJson('/api/porteria/ingresos', $this->datos())->assertCreated();
        $this->getJson('/api/porteria/ingresos?fecha=2026-10-19')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/porteria/ingresos?fecha=2026-10-20')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_un_perfil_restringido_o_inactivo_no_registra_y_romana_consulta_sin_registrar(): void
    {
        $perfil = PerfilAcceso::query()->create(['codigo' => 'GUARDIA_SIN_MODULO', 'nombre' => 'Sin módulo', 'rol_base' => RolUsuario::GuardiaPorteria, 'modulos' => [], 'modulos_tablet' => [], 'activo' => true]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::GuardiaPorteria, 'perfil_acceso_id' => $perfil->id]), 'sanctum');
        $this->postJson('/api/porteria/ingresos', $this->datos())->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::GuardiaPorteria, 'activo' => false]), 'sanctum');
        $this->getJson('/api/porteria/ingresos')->assertForbidden();
        $romana = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $this->actingAs($romana, 'sanctum');
        $identidad = app(IdentidadOficina::class)->para($romana);
        $this->assertSame('/oficina/porteria', app(InicioOficina::class)->resolver($identidad, '/oficina/porteria'));
        $this->getJson('/api/porteria/ingresos')->assertOk();
        $this->postJson('/api/porteria/ingresos', $this->datos())->assertForbidden();
    }
}
