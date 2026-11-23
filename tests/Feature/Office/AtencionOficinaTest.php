<?php

namespace Tests\Feature\Office;

use App\Enums\EstadoAuditoriaIntegridadOperacional;
use App\Enums\OrigenAuditoriaIntegridadOperacional;
use App\Enums\RolUsuario;
use App\Models\AuditoriaIntegridadOperacional;
use App\Models\IngresoPorteria;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Gerencia\ServicioPanelGerencial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtencionOficinaTest extends TestCase
{
    use RefreshDatabase;

    public function test_exige_sesion_y_acceso_a_oficina(): void
    {
        $this->getJson('/api/oficina/atencion')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => false]), 'sanctum')
            ->getJson('/api/oficina/atencion')->assertForbidden();
    }

    public function test_romana_cuenta_solo_ingresos_pendientes_y_fuentes_habilitadas(): void
    {
        $this->crearTemporadaActivaPrueba(['codigo' => 'PRUEBA', 'nombre' => 'Prueba', 'fecha_inicio' => '2026-01-01']);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        foreach ([[], ['revisado_at' => now()], ['anulado_at' => now()]] as $cambios) {
            IngresoPorteria::create([
                'operacion_id' => Str::uuid()->toString(),
                'payload_hash' => str_repeat('a', 64),
                'cliente_nombre' => 'Cliente prueba',
                'cliente_es_otro' => true,
                'motivo' => 'carga',
                'patente' => 'ABCD12',
                'tipo_camion' => 'termo',
                'nombre_conductor' => 'Conductor prueba',
                'guias' => ['000123'],
                'ingreso_at' => now()->subDay(),
                'creado_por_user_id' => $operador->id,
                'creado_por_nombre' => $operador->name,
                ...$cambios,
            ]);
        }

        $this->actingAs($operador, 'sanctum')->getJson('/api/oficina/atencion')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.parcial', false)
            ->assertJsonPath('data.areas.materia-prima.fuentes.0.cantidad', 1)
            ->assertJsonPath('data.areas.materia-prima.fuentes.1.cantidad', 0)
            ->assertJsonMissingPath('data.areas.frigorifico')
            ->assertJsonMissingPath('data.areas.materiales')
            ->assertJsonMissingPath('data.areas.gerencia')
            ->assertJsonMissingPath('data.areas.administracion');
    }

    public function test_cero_lectura_parcial_y_consultas_son_distintos(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba(['codigo' => 'PRUEBA', 'nombre' => 'Prueba', 'fecha_inicio' => '2026-01-01']);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->getJson('/api/oficina/atencion')->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.parcial', true)
            ->assertJsonPath('data.areas.frigorifico.cantidad', 0)
            ->assertJsonPath('data.areas.materiales.cantidad', 0)
            ->assertJsonPath('data.areas.gerencia.cantidad', null)
            ->assertJsonPath('data.areas.administracion.cantidad', null)
            ->assertJsonPath('data.areas.consultas.referencia', '/oficina/consultas')
            ->assertJsonMissingPath('data.areas.consultas.cantidad');

        Cache::put(app(ServicioPanelGerencial::class)->claveCache($temporada->id), ['alertas' => [[], []]], 60);
        $auditoria = AuditoriaIntegridadOperacional::create([
            'origen' => OrigenAuditoriaIntegridadOperacional::Programada,
            'estado' => EstadoAuditoriaIntegridadOperacional::Completada,
            'iniciada_at' => now(),
            'hallazgos_activos' => 3,
        ]);

        $this->getJson('/api/oficina/atencion')->assertOk()
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.parcial', false)
            ->assertJsonPath('data.areas.gerencia.cantidad', 2)
            ->assertJsonPath('data.areas.administracion.cantidad', 3);

        $auditoria->update(['estado' => EstadoAuditoriaIntegridadOperacional::Fallida]);
        $this->getJson('/api/oficina/atencion')->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.parcial', true)
            ->assertJsonPath('data.areas.administracion.cantidad', null);
    }

    public function test_sin_temporada_no_hay_un_cero_falso_en_fuentes_estacionales(): void
    {
        Temporada::query()->update(['activa' => false]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->getJson('/api/oficina/atencion')->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.parcial', true)
            ->assertJsonPath('data.areas.materia-prima.fuentes.1.cantidad', null);
        $this->assertDatabaseMissing('temporadas', ['activa' => true]);
    }
}
