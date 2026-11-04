<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\MedicionPuntoRocio;
use App\Models\NotificacionOperacional;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PuntoRocioPrefrioApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 12:30 en Santiago (UTC−3).
        $this->travelTo(CarbonImmutable::parse('2026-11-04 15:30:00', 'UTC'));
    }

    public function test_la_tablet_registra_una_medicion_y_calcula_el_punto_de_rocio_como_la_calculadora(): void
    {
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $sala = $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/prefrio/punto-rocio/salas', ['nombre' => ' Sala  de sellado '])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Sala de sellado')
            ->json('data');
        [$operador, $token] = $this->tablet(RolUsuario::OperadorPrefrio, 'PR-01');

        $operacion = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacion,
            'etapa' => 'sellado',
            'punto_tipo' => 'sala',
            'punto_id' => $sala['id'],
            'referencia' => 'Línea 2',
            'temperatura_ambiente_c' => 15,
            'humedad_relativa' => 62.9,
            'temperatura_superficie_c' => 7.1,
            'gotas_visibles' => 'pendiente',
            'medido_at' => '2026-11-04T12:10:00-03:00',
        ];
        $respuesta = $this->conToken($token)->postJson('/api/prefrio/punto-rocio', $payload)
            ->assertCreated()
            ->assertJsonPath('data.punto', 'Sala de sellado')
            ->assertJsonPath('data.punto_rocio_c', 7.99)
            ->assertJsonPath('data.margen_c', -0.89)
            ->assertJsonPath('data.vapor_g_m3', 8.05)
            ->assertJsonPath('data.estado', 'condensacion_posible')
            ->assertJsonPath('data.estado_etiqueta', 'Condensación posible')
            ->assertJsonPath('data.alerta_pendiente', true)
            ->assertJsonPath('data.fecha', '2026-11-04')
            ->assertJsonPath('data.dispositivo', 'PDA PR-01')
            ->assertJsonPath('data.registrado_por', $operador->name);

        // Repetir la misma operación no duplica ni vuelve a avisar.
        $this->conToken($token)->postJson('/api/prefrio/punto-rocio', $payload)
            ->assertOk()->assertJsonPath('data.id', $respuesta->json('data.id'));
        $this->conToken($token)->postJson('/api/prefrio/punto-rocio', [...$payload, 'humedad_relativa' => 60])
            ->assertConflict();
        $this->assertSame(1, MedicionPuntoRocio::query()->count());

        $aviso = NotificacionOperacional::query()->sole();
        $this->assertSame('condensacion_prefrio', $aviso->tipo->value);
        $this->assertSame('supervisor_frio', $aviso->audiencia_valor);
        $this->assertSame('critica', $aviso->severidad->value);
        $this->assertSame('Condensación posible · Sellado · Sala de sellado', $aviso->titulo);
        $this->assertSame('Rocío 8,0 °C, superficie 7,1 °C, margen -0,9 °C. Registra una acción correctiva en Prefrío → Punto de rocío.', $aviso->mensaje);
        $this->assertSame('/oficina/prefrio/punto-rocio?fecha=2026-11-04', $aviso->datos['href']);
        $this->actingAs($supervisor, 'sanctum')->getJson('/api/notificaciones-operacionales')
            ->assertOk()->assertJsonPath('data.0.id', $aviso->id);
    }

    public function test_el_resumen_del_dia_cuenta_alertas_y_la_supervision_registra_la_accion(): void
    {
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $camara = Camara::create(['codigo' => 'CAM-09', 'nombre' => 'Cámara 9', 'contenido' => 'productos']);
        $sala = $this->actingAs($supervisor, 'sanctum')->postJson('/api/prefrio/punto-rocio/salas', ['nombre' => 'Paletizado'])->json('data.id');
        $medir = fn (array $datos) => $this->actingAs($supervisor, 'sanctum')->postJson('/api/prefrio/punto-rocio', [
            'operacion_id' => (string) Str::uuid(), 'etapa' => 'paletizado', 'punto_tipo' => 'sala', 'punto_id' => $sala,
            'temperatura_ambiente_c' => 6, 'humedad_relativa' => 80, 'gotas_visibles' => 'no', 'medido_at' => '2026-11-04T08:00:00-03:00', ...$datos,
        ]);

        $favorable = $medir(['temperatura_superficie_c' => 8])->assertCreated()->assertJsonPath('data.estado', 'margen_favorable')->json('data');
        $this->assertFalse($favorable['requiere_accion']);
        $bajo = $medir(['temperatura_superficie_c' => 3.5, 'medido_at' => '2026-11-04T09:00:00-03:00'])->assertJsonPath('data.estado', 'margen_bajo')->json('data');
        $medir(['medido_at' => '2026-11-04T10:00:00-03:00'])->assertJsonPath('data.estado', 'sin_superficie')->assertJsonPath('data.requiere_accion', false);
        $gotas = $medir(['etapa' => 'almacenamiento', 'punto_tipo' => 'camara', 'punto_id' => $camara->id, 'temperatura_ambiente_c' => 0,
            'humedad_relativa' => 92, 'temperatura_superficie_c' => 2, 'gotas_visibles' => 'si', 'medido_at' => '2026-11-04T11:00:00-03:00'])
            ->assertJsonPath('data.punto', 'CAM-09 · Cámara 9')
            ->assertJsonPath('data.estado', 'margen_favorable')
            ->assertJsonPath('data.requiere_accion', true)
            ->json('data');
        // 23:30 del día anterior en Santiago: es otro día operacional aunque en UTC sea el 4.
        $medir(['medido_at' => '2026-11-03T23:30:00-03:00', 'temperatura_superficie_c' => 8])->assertJsonPath('data.fecha', '2026-11-03');
        $this->assertSame(['advertencia', 'critica'], NotificacionOperacional::query()->orderBy('severidad')->pluck('severidad')->map->value->all());

        $dia = $this->actingAs($supervisor, 'sanctum')->getJson('/api/prefrio/punto-rocio?fecha=2026-11-04')
            ->assertOk()
            ->assertJsonPath('resumen.registros', 4)
            ->assertJsonPath('resumen.alertas', 2)
            ->assertJsonPath('resumen.margen_bajo', 1)
            ->assertJsonPath('resumen.gotas_visibles', 1)
            ->assertJsonPath('resumen.alertas_pendientes', 2)
            ->assertJsonPath('mediciones.0.id', $favorable['id'])
            ->assertJsonPath('puntos.salas.0.nombre', 'Paletizado')
            ->assertJsonPath('puntos.camaras.0.nombre', 'CAM-09 · Cámara 9')
            ->assertJsonPath('parametros.margen_minimo_c', 2)
            ->assertJsonPath('permisos.supervisar', true)
            ->json();
        $this->assertSame(['paletizado', 'almacenamiento'], array_column($dia['por_etapa'], 'etapa'));
        $this->assertCount(14, $dia['historial']);
        $this->assertSame(['fecha' => '2026-11-03', 'registros' => 1], array_intersect_key($dia['historial'][12], ['fecha' => 1, 'registros' => 1]));

        // Operación ahora muestra lo pendiente de atender.
        $pendientes = $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->getJson('/api/operacion-ahora')->assertOk()->json('data.prefrio.condensacion.alertas_pendientes');
        $this->assertEqualsCanonicalizing([$bajo['id'], $gotas['id']], array_column($pendientes, 'id'));

        $this->actingAs($supervisor, 'sanctum')->postJson("/api/prefrio/punto-rocio/{$favorable['id']}/accion", ['texto' => 'Nada que hacer'])
            ->assertConflict()->assertJsonPath('message', 'La medición no tiene una alerta que atender.');
        $this->actingAs($supervisor, 'sanctum')->postJson("/api/prefrio/punto-rocio/{$bajo['id']}/accion", ['texto' => 'Se bajó la HR de la sala a 60 %.'])
            ->assertOk()
            ->assertJsonPath('data.alerta_pendiente', false)
            ->assertJsonPath('data.accion_registrada_por', $supervisor->name);
        $this->actingAs($supervisor, 'sanctum')->postJson("/api/prefrio/punto-rocio/{$gotas['id']}/anular", ['texto' => 'Lectura de otro termómetro'])
            ->assertOk()->assertJsonPath('data.anulada', true);
        $this->actingAs($supervisor, 'sanctum')->postJson("/api/prefrio/punto-rocio/{$gotas['id']}/accion", ['texto' => 'Revisar evaporador'])
            ->assertConflict();

        $this->actingAs($supervisor, 'sanctum')->getJson('/api/prefrio/punto-rocio?fecha=2026-11-04&etapa=paletizado')
            ->assertOk()
            ->assertJsonPath('resumen.registros', 3)
            ->assertJsonPath('resumen.alertas_pendientes', 0)
            ->assertJsonCount(3, 'mediciones');
        $this->actingAs($supervisor, 'sanctum')->getJson('/api/prefrio/punto-rocio?fecha=2026-11-04')
            ->assertJsonPath('resumen.registros', 3)
            ->assertJsonCount(4, 'mediciones');
    }

    public function test_valida_permisos_puntos_y_horas(): void
    {
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $sala = $this->actingAs($supervisor, 'sanctum')->postJson('/api/prefrio/punto-rocio/salas', ['nombre' => 'Andén'])->json('data');
        $this->actingAs($supervisor, 'sanctum')->postJson('/api/prefrio/punto-rocio/salas', ['nombre' => 'Andén'])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');
        [, $tokenOperador] = $this->tablet(RolUsuario::OperadorPrefrio, 'PR-02');
        $base = ['etapa' => 'ingreso_prefrio', 'punto_tipo' => 'sala', 'punto_id' => $sala['id'], 'temperatura_ambiente_c' => 4,
            'humedad_relativa' => 85, 'gotas_visibles' => 'no', 'medido_at' => '2026-11-04T12:00:00-03:00'];

        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/prefrio/punto-rocio')->assertOk()->assertJsonPath('permisos.registrar', false);
        $this->actingAs($consulta, 'sanctum')->postJson('/api/prefrio/punto-rocio', [...$base, 'operacion_id' => (string) Str::uuid()])->assertForbidden();
        $this->conToken($tokenOperador)->postJson('/api/prefrio/punto-rocio/salas', ['nombre' => 'Otra'])->assertForbidden();

        $this->conToken($tokenOperador)->postJson('/api/prefrio/punto-rocio', [...$base, 'operacion_id' => (string) Str::uuid(), 'humedad_relativa' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('humedad_relativa');
        $this->conToken($tokenOperador)->postJson('/api/prefrio/punto-rocio', [...$base, 'operacion_id' => (string) Str::uuid(), 'medido_at' => '2026-11-04T13:00:00-03:00'])
            ->assertUnprocessable()->assertJsonPath('errors.medido_at.0', 'La hora de medición no puede estar en el futuro.');
        $this->conToken($tokenOperador)->postJson('/api/prefrio/punto-rocio', [...$base, 'operacion_id' => (string) Str::uuid(), 'medido_at' => '2026-10-20T12:00:00-03:00'])
            ->assertUnprocessable()->assertJsonPath('errors.medido_at.0', 'Solo se registran mediciones de los últimos 7 días.');

        $this->actingAs($supervisor, 'sanctum')->putJson("/api/prefrio/punto-rocio/salas/{$sala['id']}", ['nombre' => 'Andén', 'activa' => false, 'version_conocida' => 1])
            ->assertOk()->assertJsonPath('data.activa', false);
        $this->actingAs($supervisor, 'sanctum')->putJson("/api/prefrio/punto-rocio/salas/{$sala['id']}", ['nombre' => 'Andén 2', 'version_conocida' => 1])
            ->assertConflict();
        $this->conToken($tokenOperador)->postJson('/api/prefrio/punto-rocio', [...$base, 'operacion_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('errors.punto_id.0', 'La sala no existe o está inactiva.');
        $this->assertSame(0, MedicionPuntoRocio::query()->count());
    }

    /** @return array{User, string} */
    private function tablet(RolUsuario $rol, string $codigo): array
    {
        $usuario = User::factory()->create(['rol' => $rol]);
        $dispositivo = Dispositivo::create(['codigo' => $codigo, 'nombre' => "PDA {$codigo}", 'plataforma' => 'android', 'activo' => true]);

        return [$usuario, $usuario->crearTokenParaDispositivo($dispositivo, "test-{$codigo}")->plainTextToken];
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
