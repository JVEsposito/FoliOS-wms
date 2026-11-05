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

    public function test_vincula_el_aire_del_sellado_y_la_fruta_que_llega_con_el_margen_de_cada_etapa(): void
    {
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $camara = Camara::create(['codigo' => 'CAM-01', 'nombre' => 'Cámara 1', 'contenido' => 'productos']);
        $salas = collect(['Sala de sellado', 'Sala de paletizado', 'Salida túneles'])
            ->mapWithKeys(fn (string $nombre) => [$nombre => $this->actingAs($supervisor, 'sanctum')->postJson('/api/prefrio/punto-rocio/salas', ['nombre' => $nombre])->json('data.id')]);
        [, $token] = $this->tablet(RolUsuario::OperadorPrefrio, 'PR-03');
        $medir = fn (array $datos) => $this->conToken($token)->postJson('/api/prefrio/punto-rocio', [
            'operacion_id' => (string) Str::uuid(), 'punto_tipo' => 'sala', 'gotas_visibles' => 'no', ...$datos,
        ]);

        // En mantención la humedad alta es la deseada: supervisión baja el margen de cámara.
        $this->actingAs($supervisor, 'sanctum')->putJson('/api/prefrio/punto-rocio/margenes', ['margenes' => [
            ['etapa' => 'almacenamiento', 'margen_minimo_c' => 0.5, 'version_conocida' => 0],
        ]])->assertOk()->assertJsonPath('data.6.etapa', 'almacenamiento')->assertJsonPath('data.6.margen_minimo_c', 0.5)
            ->assertJsonPath('data.6.ajustado', true)->assertJsonPath('data.1.etapa', 'sellado')->assertJsonPath('data.1.margen_minimo_c', 2);
        $this->actingAs($supervisor, 'sanctum')->putJson('/api/prefrio/punto-rocio/margenes', ['margenes' => [
            ['etapa' => 'almacenamiento', 'margen_minimo_c' => 0.8, 'version_conocida' => 0],
        ]])->assertConflict();
        $this->conToken($token)->putJson('/api/prefrio/punto-rocio/margenes', ['margenes' => [['etapa' => 'sellado', 'margen_minimo_c' => 1]]])->assertForbidden();

        // Sellado a 12 °C y 60 %: el aire que queda en la bolsa tiene rocío 4,47 °C.
        $sellado = $medir(['etapa' => 'sellado', 'punto_id' => $salas['Sala de sellado'], 'temperatura_ambiente_c' => 12, 'humedad_relativa' => 60,
            'temperatura_superficie_c' => 10, 'medido_at' => '2026-11-04T09:30:00-03:00'])
            ->assertCreated()->assertJsonPath('data.punto_rocio_c', 4.47)->assertJsonPath('data.estado', 'margen_favorable')->json('data');

        // Paletizado seco (rocío 0,05 °C): la sala cumple, pero la fruta a 3 °C ya está bajo el rocío del aire sellado.
        $paletizadoDatos = ['etapa' => 'paletizado', 'punto_tipo' => 'sala', 'gotas_visibles' => 'no', 'punto_id' => $salas['Sala de paletizado'], 'temperatura_ambiente_c' => 10, 'humedad_relativa' => 50,
            'temperatura_superficie_c' => 3, 'medido_at' => '2026-11-04T10:00:00-03:00', 'sellado_referencia_id' => $sellado['id'],
            'operacion_id' => (string) Str::uuid()];
        $paletizado = $medir($paletizadoDatos)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'margen_favorable')
            ->assertJsonPath('data.margen_c', 2.95)
            ->assertJsonPath('data.envase.punto', 'Sala de sellado')
            ->assertJsonPath('data.envase.punto_rocio_c', 4.47)
            ->assertJsonPath('data.envase.margen_c', -1.47)
            ->assertJsonPath('data.envase.estado', 'condensacion_posible')
            ->assertJsonPath('data.condicion', 'Condensación posible en el envase')
            ->assertJsonPath('data.critica', true)
            ->assertJsonPath('data.requiere_accion', true)
            ->assertJsonPath('data.origen', null)
            ->json('data');
        $this->conToken($token)->postJson('/api/prefrio/punto-rocio', $paletizadoDatos)->assertOk()->assertJsonPath('data.id', $paletizado['id']);
        $this->conToken($token)->postJson('/api/prefrio/punto-rocio', [...$paletizadoDatos, 'sellado_referencia_id' => null])->assertConflict();
        $aviso = NotificacionOperacional::query()->sole();
        $this->assertSame('critica', $aviso->severidad->value);
        $this->assertSame('Condensación posible en el envase · Paletizado · Sala de paletizado', $aviso->titulo);
        $this->assertSame('Dentro del envase: rocío del sellado 4,5 °C (09:30, Sala de sellado), superficie 3,0 °C, margen -1,5 °C. Registra una acción correctiva en Prefrío → Punto de rocío.', $aviso->mensaje);

        // La fruta sale del túnel a 1 °C y entra a una cámara a 0 °C y 92 % (rocío −1,14 °C).
        $salida = $medir(['etapa' => 'salida_prefrio', 'punto_id' => $salas['Salida túneles'], 'temperatura_ambiente_c' => 2, 'humedad_relativa' => 80,
            'temperatura_superficie_c' => 1, 'medido_at' => '2026-11-04T11:00:00-03:00'])->assertJsonPath('data.estado', 'margen_favorable')->json('data');
        $camaraMedicion = $medir(['etapa' => 'almacenamiento', 'punto_tipo' => 'camara', 'punto_id' => $camara->id, 'temperatura_ambiente_c' => 0,
            'humedad_relativa' => 92, 'temperatura_superficie_c' => 0.2, 'medido_at' => '2026-11-04T12:00:00-03:00', 'origen_referencia_id' => $salida['id']])
            ->assertCreated()
            ->assertJsonPath('data.punto_rocio_c', -1.14)
            ->assertJsonPath('data.margen_minimo_c', 0.5)
            ->assertJsonPath('data.margen_c', 1.34)
            ->assertJsonPath('data.estado', 'margen_favorable')
            ->assertJsonPath('data.origen.etapa_etiqueta', 'Salida prefrío')
            ->assertJsonPath('data.origen.temperatura_superficie_c', 1)
            ->assertJsonPath('data.origen.margen_c', 2.14)
            ->assertJsonPath('data.origen.estado', 'margen_favorable')
            ->assertJsonPath('data.requiere_accion', false)
            ->json('data');

        // Reglas de los vínculos.
        $sinSuperficie = $medir(['etapa' => 'otro', 'punto_id' => $salas['Sala de paletizado'], 'temperatura_ambiente_c' => 8, 'humedad_relativa' => 70,
            'medido_at' => '2026-11-04T11:30:00-03:00'])->json('data');
        $base = ['etapa' => 'almacenamiento', 'punto_tipo' => 'camara', 'punto_id' => $camara->id, 'temperatura_ambiente_c' => 0, 'humedad_relativa' => 90,
            'temperatura_superficie_c' => 0.5, 'medido_at' => '2026-11-04T12:15:00-03:00'];
        $medir([...$base, 'sellado_referencia_id' => $paletizado['id']])
            ->assertUnprocessable()->assertJsonPath('errors.sellado_referencia_id.0', 'El aire del envase debe venir de una medición de sellado.');
        $medir([...$base, 'etapa' => 'sellado', 'punto_tipo' => 'sala', 'punto_id' => $salas['Sala de sellado'], 'sellado_referencia_id' => $sellado['id']])
            ->assertUnprocessable()->assertJsonPath('errors.sellado_referencia_id.0', 'Una medición de sellado ya evalúa su propio aire: no necesita otro sellado.');
        $medir([...$base, 'origen_referencia_id' => $sinSuperficie['id']])
            ->assertUnprocessable()->assertJsonPath('errors.origen_referencia_id.0', 'La medición de origen no tiene T° superficial de la fruta.');
        $medir([...$base, 'medido_at' => '2026-11-04T09:00:00-03:00', 'sellado_referencia_id' => $sellado['id']])
            ->assertUnprocessable()->assertJsonPath('errors.sellado_referencia_id.0', 'La medición vinculada debe ser anterior a esta.');
        $antiguo = $medir(['etapa' => 'sellado', 'punto_id' => $salas['Sala de sellado'], 'temperatura_ambiente_c' => 12, 'humedad_relativa' => 50,
            'medido_at' => '2026-10-31T09:00:00-03:00'])->assertCreated()->json('data');
        $medir([...$base, 'sellado_referencia_id' => $antiguo['id']])
            ->assertUnprocessable()->assertJsonPath('errors.sellado_referencia_id.0', 'Solo se vinculan mediciones de las 72 horas anteriores.');

        // La oficina recibe los candidatos, los márgenes por etapa y las alertas vinculadas.
        $dia = $this->actingAs($supervisor, 'sanctum')->getJson('/api/prefrio/punto-rocio?fecha=2026-11-04')
            ->assertOk()
            ->assertJsonPath('parametros.horas_referencia', 72)
            ->assertJsonPath('parametros.margenes_por_etapa.6.margen_minimo_c', 0.5)
            ->json();
        $this->assertSame(
            [$camaraMedicion['id'], $salida['id'], $paletizado['id'], $sellado['id']],
            array_column($dia['referencias'], 'id'),
            'Solo sellados y mediciones con T° superficial, de la más reciente a la más antigua',
        );
        $pendientes = $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->getJson('/api/operacion-ahora')->json('data.prefrio.condensacion.alertas_pendientes');
        $this->assertSame([[$paletizado['id'], 'Condensación posible en el envase', true]], array_map(fn (array $alerta) => [$alerta['id'], $alerta['condicion'], $alerta['critica']], $pendientes));

        // Anular el sellado deja la comparación guardada y avisa que su referencia se anuló.
        $this->actingAs($supervisor, 'sanctum')->postJson("/api/prefrio/punto-rocio/{$sellado['id']}/anular", ['texto' => 'Higrómetro sin calibrar'])->assertOk();
        $this->actingAs($supervisor, 'sanctum')->getJson('/api/prefrio/punto-rocio?fecha=2026-11-04')
            ->assertJsonPath('mediciones.1.id', $paletizado['id'])
            ->assertJsonPath('mediciones.1.envase.margen_c', -1.47)
            ->assertJsonPath('mediciones.1.envase.referencia_anulada', true);
        $medir([...$base, 'sellado_referencia_id' => $sellado['id']])
            ->assertUnprocessable()->assertJsonPath('errors.sellado_referencia_id.0', 'La medición vinculada fue anulada.');
    }

    public function test_el_plan_de_cadena_de_frio_calcula_el_rocio_y_la_humedad_maxima_de_cada_etapa(): void
    {
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $actuar = fn () => $this->actingAs($supervisor, 'sanctum');
        // En las etapas frías la fruta queda a la T° del aire o sobre ella: se trabaja con 0,5 °C de margen.
        $actuar()->putJson('/api/prefrio/punto-rocio/margenes', ['margenes' => collect(['salida_prefrio', 'traslado', 'almacenamiento', 'despacho', 'carga_contenedor'])
            ->map(fn (string $etapa) => ['etapa' => $etapa, 'margen_minimo_c' => 0.5])->all()])->assertOk();

        $plan = $actuar()->putJson('/api/prefrio/punto-rocio/plan', ['etapas' => [
            ['etapa' => 'packing', 'temperatura_aire_c' => 12, 'temperatura_fruta_c' => 4, 'version_conocida' => 0],
            ['etapa' => 'sellado', 'temperatura_aire_c' => 12, 'temperatura_fruta_c' => 4],
            ['etapa' => 'ingreso_prefrio', 'temperatura_aire_c' => -1.5, 'temperatura_fruta_c' => 4],
            ['etapa' => 'salida_prefrio', 'temperatura_aire_c' => -1, 'temperatura_fruta_c' => -1],
            ['etapa' => 'traslado', 'temperatura_aire_c' => 4, 'temperatura_fruta_c' => -1],
            ['etapa' => 'almacenamiento', 'temperatura_aire_c' => -1, 'temperatura_fruta_c' => -0.5],
            ['etapa' => 'despacho', 'temperatura_aire_c' => 4, 'temperatura_fruta_c' => -0.5],
            ['etapa' => 'carga_contenedor', 'temperatura_aire_c' => 0, 'temperatura_fruta_c' => -0.5],
        ]])->assertOk()->assertJsonPath('data.temperatura_congelacion_c', -1.7)->json('data.etapas');
        $porEtapa = collect($plan)->keyBy('etapa');
        $this->assertSame(['packing', 'sellado', 'paletizado', 'ingreso_prefrio', 'salida_prefrio', 'traslado', 'almacenamiento', 'despacho', 'carga_contenedor'], array_column($plan, 'etapa'));
        $resumen = fn (string $etapa) => array_intersect_key($porEtapa[$etapa], array_flip(['rocio_maximo_c', 'limitante', 'humedad_maxima', 'cerca_congelacion']));

        $this->assertEquals(['rocio_maximo_c' => 2.0, 'limitante' => 'fruta', 'humedad_maxima' => 50.4, 'cerca_congelacion' => false], $resumen('packing'));
        // El aire que queda en la bolsa debe aguantar la fruta más fría de lo que sigue: −1 °C en salida y traslado, menos 0,5.
        $this->assertEquals(['rocio_maximo_c' => -1.5, 'limitante' => 'envase', 'humedad_maxima' => 39.1, 'cerca_congelacion' => false], $resumen('sellado'));
        $this->assertEquals(2.0, $porEtapa['sellado']['rocio_fruta_c']);
        $this->assertEquals(-1.5, $porEtapa['sellado']['rocio_envase_c']);
        $this->assertFalse($porEtapa['paletizado']['planificada']);
        $this->assertNull($porEtapa['paletizado']['humedad_maxima']);
        // En el túnel la fruta está más caliente que el aire: no hay límite de humedad, pero el aire se acerca a la congelación.
        $this->assertEquals(['rocio_maximo_c' => 2.0, 'limitante' => 'fruta', 'humedad_maxima' => 100.0, 'cerca_congelacion' => true], $resumen('ingreso_prefrio'));
        $this->assertEquals(96.4, $porEtapa['salida_prefrio']['humedad_maxima']);
        $this->assertEquals(67.4, $porEtapa['traslado']['humedad_maxima']);
        $this->assertEquals(100.0, $porEtapa['almacenamiento']['humedad_maxima']);
        $this->assertEquals(69.9, $porEtapa['despacho']['humedad_maxima']);
        $this->assertEquals(93.0, $porEtapa['carga_contenedor']['humedad_maxima']);

        // Versiones, etapas válidas y permisos.
        $actuar()->putJson('/api/prefrio/punto-rocio/plan', ['etapas' => [['etapa' => 'packing', 'temperatura_aire_c' => 14, 'version_conocida' => 0]]])->assertConflict();
        $actuar()->putJson('/api/prefrio/punto-rocio/plan', ['etapas' => [['etapa' => 'otro', 'temperatura_fruta_c' => 3]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['etapas.0.etapa' => 'Esa etapa no forma parte de la cadena de frío.']);
        [, $token] = $this->tablet(RolUsuario::OperadorPrefrio, 'PR-04');
        $this->conToken($token)->putJson('/api/prefrio/punto-rocio/plan', ['etapas' => [['etapa' => 'packing', 'temperatura_fruta_c' => 3]]])->assertForbidden();

        // Cada medición guarda el rocío máximo del plan y queda fuera si lo supera.
        $sala = $actuar()->postJson('/api/prefrio/punto-rocio/salas', ['nombre' => 'Sala de sellado'])->json('data.id');
        $fuera = $this->conToken($token)->postJson('/api/prefrio/punto-rocio', [
            'operacion_id' => (string) Str::uuid(), 'etapa' => 'sellado', 'punto_tipo' => 'sala', 'punto_id' => $sala, 'temperatura_ambiente_c' => 12,
            'humedad_relativa' => 60, 'temperatura_superficie_c' => 10, 'gotas_visibles' => 'no', 'medido_at' => '2026-11-04T09:00:00-03:00',
        ])->assertCreated()
            ->assertJsonPath('data.estado', 'margen_favorable')
            ->assertJsonPath('data.requiere_accion', false)
            ->assertJsonPath('data.plan.rocio_maximo_c', -1.5)
            ->assertJsonPath('data.plan.humedad_maxima', 39.1)
            ->assertJsonPath('data.plan.fuera', true)
            ->json('data');
        $this->assertSame(0, NotificacionOperacional::query()->count(), 'Quedar fuera del plan se informa, pero no notifica');
        $this->conToken($token)->postJson('/api/prefrio/punto-rocio', [
            'operacion_id' => (string) Str::uuid(), 'etapa' => 'otro', 'punto_tipo' => 'sala', 'punto_id' => $sala, 'temperatura_ambiente_c' => 12,
            'humedad_relativa' => 60, 'gotas_visibles' => 'no', 'medido_at' => '2026-11-04T09:30:00-03:00',
        ])->assertCreated()->assertJsonPath('data.plan', null);

        // Cambiar el plan no reinterpreta lo ya medido.
        $actuar()->putJson('/api/prefrio/punto-rocio/plan', ['etapas' => [
            ['etapa' => 'salida_prefrio', 'temperatura_aire_c' => -1, 'temperatura_fruta_c' => 0, 'version_conocida' => 1],
            ['etapa' => 'traslado', 'temperatura_aire_c' => 4, 'temperatura_fruta_c' => 2, 'version_conocida' => 1],
        ]])->assertOk();
        $dia = $actuar()->getJson('/api/prefrio/punto-rocio?fecha=2026-11-04')
            ->assertOk()
            ->assertJsonPath('resumen.fuera_de_plan', 1)
            ->assertJsonPath('mediciones.0.id', $fuera['id'])
            ->assertJsonPath('mediciones.0.plan.rocio_maximo_c', -1.5)
            ->json();
        $this->assertEquals(-1.0, collect($dia['plan']['etapas'])->firstWhere('etapa', 'sellado')['rocio_maximo_c'], 'El nuevo límite del envase viene de cámara, despacho y carga');
        $this->assertSame('packing', $dia['etapas'][0]['valor']);
        $this->assertSame('otro', $dia['etapas'][9]['valor']);
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
