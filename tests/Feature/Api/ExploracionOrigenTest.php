<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ConexionExterna;
use App\Models\User;
use App\Services\Integraciones\ExploradorMySql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

class ExploracionOrigenTest extends TestCase
{
    use RefreshDatabase;

    private ConexionExterna $conexion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->conexion = ConexionExterna::create(['nombre' => 'Origen fixture', 'activa' => true, 'version' => 1, 'credenciales' => ['password' => 'clave_fixture']]);
    }

    public function test_lista_y_muestra_sin_guardar_los_datos_del_origen_en_la_auditoria(): void
    {
        $mock = $this->mock(ExploradorMySql::class);
        $mock->shouldReceive('objetos')->once()->andReturn([['nombre' => 'vista', 'tipo' => 'VIEW']]);
        $mock->shouldReceive('muestra')->once()->andReturn(['columnas' => ['folio'], 'filas' => [['folio' => 'FOLIO-PRIVADO']], 'limite' => 10]);
        $this->postJson($this->ruta('estructura'), ['version_conocida' => 1])->assertOk()->assertJsonPath('data.0.nombre', 'vista')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($this->ruta('muestra'), ['version_conocida' => 1, 'tabla' => 'vista', 'columnas' => ['folio'], 'filtros' => [], 'limite' => 10])->assertOk()->assertJsonPath('data.filas.0.folio', 'FOLIO-PRIVADO');
        $historial = $this->conexion->eventos()->get()->toJson();
        $this->assertStringNotContainsString('FOLIO-PRIVADO', $historial);
        $this->assertStringNotContainsString('clave_fixture', $historial);
        $this->assertDatabaseCount('eventos_conexion_externa', 2);
    }

    public function test_rechaza_permisos_versiones_antiguas_y_respuestas_tardias(): void
    {
        $mock = $this->mock(ExploradorMySql::class);
        $this->postJson($this->ruta('estructura'), ['version_conocida' => 2])->assertConflict();
        $mock->shouldReceive('objetos')->once()->andReturnUsing(function () {
            $this->conexion->update(['version' => 2]);

            return [['nombre' => 'vista', 'tipo' => 'VIEW']];
        });
        $this->postJson($this->ruta('estructura'), ['version_conocida' => 1])->assertConflict()->assertJsonMissingPath('data');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum');
        foreach (['estructura', 'columnas', 'muestra'] as $accion) {
            $this->postJson($this->ruta($accion), ['version_conocida' => 2])->assertForbidden();
        }
        $this->assertDatabaseCount('eventos_conexion_externa', 0);
    }

    public function test_un_error_de_origen_no_expone_credenciales_y_la_muestra_tiene_limite(): void
    {
        Log::spy();
        $this->mock(ExploradorMySql::class)->shouldReceive('objetos')->once()->andThrow(new PDOException('servidor_privado clave_fixture'));
        $respuesta = $this->postJson($this->ruta('estructura'), ['version_conocida' => 1])->assertUnprocessable();
        $this->assertStringNotContainsString('servidor_privado', $respuesta->getContent());
        $this->assertStringNotContainsString('clave_fixture', $respuesta->getContent());
        Log::shouldNotHaveReceived('error');
        $this->postJson($this->ruta('muestra'), ['version_conocida' => 1, 'tabla' => 'vista', 'columnas' => ['folio'], 'filtros' => [], 'limite' => 1000])->assertUnprocessable()->assertJsonValidationErrors('limite');
    }

    private function ruta(string $accion): string
    {
        return '/api/administracion/conexiones-externas/'.$this->conexion->id.'/'.$accion;
    }
}
