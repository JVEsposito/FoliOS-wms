<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoHidrocoolerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogo_solo_administra_con_permiso_y_audita_cada_version(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $datos = ['nombre' => 'Producto de prueba', 'unidad_dosis' => 'ml/L', 'activo' => true];
        $producto = $this->actingAs($admin, 'sanctum')->postJson('/api/administracion/productos-hidrocooler', $datos)
            ->assertCreated()->assertJsonPath('data.actualizado_por.name', $admin->name)->json('data');
        $url = '/api/administracion/productos-hidrocooler/'.$producto['id'];
        $this->putJson($url, [...$datos, 'unidad_dosis' => 'g/L', 'version_conocida' => 1])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($url, [...$datos, 'version_conocida' => 1])->assertConflict();
        $this->getJson($url.'/eventos')->assertOk()->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.usuario.id', $admin->id);
        $this->assertDatabaseCount('eventos_producto_hidrocooler', 2);
        $this->putJson($url, [...$datos, 'activo' => false, 'version_conocida' => 2])->assertOk();
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $this->actingAs($digitador, 'sanctum')->getJson('/api/materia-prima/hidrocooler/productos')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/administracion/productos-hidrocooler')->assertForbidden();
        $this->postJson('/api/administracion/productos-hidrocooler', $datos)->assertForbidden();
        $this->putJson($url, $datos)->assertForbidden();
        $this->getJson($url.'/eventos')->assertForbidden();
    }
}
