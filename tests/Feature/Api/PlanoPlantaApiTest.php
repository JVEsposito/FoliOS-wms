<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\RolUsuario;
use App\Enums\TipoAlmacenMaterial;
use App\Models\AlmacenMaterial;
use App\Models\Anden;
use App\Models\Camara;
use App\Models\PlanoPlanta;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanoPlantaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_vista_incluye_catalogo_operacional_y_solo_el_administrador_puede_editar(): void
    {
        $this->crearTemporadaActivaPrueba([
            'codigo' => 'ACTUAL',
            'nombre' => 'Temporada actual',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2027-03-31',
            'activa' => true,
        ]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $camara = $this->crearCamara();
        $anden = $this->crearAnden($administrador);

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.planta.configurado', false)
            ->assertJsonPath('data.planta.version', 0)
            ->assertJsonPath('data.planta.puede_editar', false)
            ->assertJsonFragment(['tipo' => 'camara', 'id' => $camara->id])
            ->assertJsonFragment(['tipo' => 'anden', 'id' => $anden->id]);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.planta.puede_editar', true);
    }

    public function test_el_administrador_guarda_un_plano_con_recintos_reales_y_areas_dibujadas(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $camara = $this->crearCamara();
        $tunel = $this->crearTunel($administrador);
        $anden = $this->crearAnden($administrador);
        $almacen = $this->crearAlmacen($administrador);
        $elementos = [
            $this->elemento('camara', $camara->id, 'Cámara uno', 200, 200),
            $this->elemento('tunel', $tunel->id, 'Túnel uno', 2300, 200),
            $this->elemento('anden', $anden->id, 'Andén uno', 4400, 200),
            $this->elemento('almacen', $almacen->id, 'Bodega uno', 6500, 200),
            $this->elemento('zona', null, 'Patio de recepción', 200, 2500, 'patio'),
        ];

        $this->actingAs($consulta, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 0,
                'nombre' => 'Planta frigorífica',
                'elementos' => $elementos,
            ])
            ->assertForbidden();

        $this->actingAs($administrador, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 0,
                'nombre' => 'Planta frigorífica',
                'elementos' => $elementos,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.nombre', 'Planta frigorífica')
            ->assertJsonCount(5, 'data.elementos');

        $this->assertDatabaseHas('planos_planta', [
            'codigo' => 'principal',
            'nombre' => 'Planta frigorífica',
            'version' => 1,
            'actualizado_por_user_id' => $administrador->id,
        ]);
    }

    public function test_rechaza_sobrescrituras_con_una_version_antigua(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        PlanoPlanta::create([
            'codigo' => 'principal',
            'nombre' => 'Plano vigente',
            'version' => 3,
            'elementos' => [],
            'actualizado_por_user_id' => $administrador->id,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 2,
                'nombre' => 'Plano atrasado',
                'elementos' => [],
            ])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->assertDatabaseHas('planos_planta', ['version' => 3, 'nombre' => 'Plano vigente']);
    }

    public function test_valida_limites_duplicados_y_referencias_inexistentes(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $camara = $this->crearCamara();
        $duplicado = $this->elemento('camara', $camara->id, 'Duplicada', 9200, 100);

        $this->actingAs($administrador, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 0,
                'nombre' => 'Plano inválido',
                'elementos' => [
                    $duplicado,
                    [...$duplicado, 'id' => (string) Str::uuid(), 'x' => 200],
                    $this->elemento('anden', (string) Str::uuid(), 'No existe', 3000, 3000),
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['elementos.camara', 'elementos.0', 'elementos.anden']);
    }

    /** @return array<string, mixed> */
    public function test_el_plano_publica_indicadores_vivos_y_recintos_fuera_de_servicio(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'VIVO',
            'nombre' => 'Temporada viva',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2027-03-31',
            'activa' => true,
        ]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $activa = $this->crearCamara();
        $inactiva = Camara::create([
            'codigo' => 'CAM-PLANO-02',
            'nombre' => 'Cámara en mantención',
            'contenido' => ContenidoCamara::Productos,
            'estado' => EstadoCamara::Inactiva,
        ]);
        $anden = $this->crearAnden($administrador);

        $respuesta = $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.planta.indicadores.repa.pallets_pendientes', 0)
            ->assertJsonPath('data.planta.indicadores.repa.maximo', config('planificador.repa_buffer_max_pallets'))
            ->assertJsonPath('data.planta.indicadores.repa.prioridad', 'normal')
            ->assertJsonPath('data.planta.indicadores.recepcion_mp.en_romana', 0)
            ->assertJsonPath('data.planta.indicadores.recepcion_mp.pendientes_validacion', 0)
            ->assertJsonPath('data.planta.indicadores.recepcion_mp.en_validacion', 0);

        $catalogo = collect($respuesta->json('data.planta.catalogo'))->keyBy('id');
        $this->assertSame('operativa', $catalogo[$activa->id]['estado']);
        $this->assertSame('fuera_servicio', $catalogo[$inactiva->id]['estado']);
        $this->assertSame(1, $catalogo->where('id', $activa->id)->count());
        $this->assertFalse($catalogo[$anden->id]['ocupado']);
        $this->assertNull($catalogo[$anden->id]['patente']);
        $this->assertSame($temporada->id, $respuesta->json('data.temporada.id'));
    }

    public function test_acepta_areas_de_proceso_y_rechaza_categorias_desconocidas(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $elementos = [
            $this->elemento('zona', null, 'REPA', 200, 200, 'repa'),
            $this->elemento('zona', null, 'Recepción MP', 2300, 200, 'recepcion_mp'),
            $this->elemento('zona', null, 'Materiales', 4400, 200, 'materiales'),
        ];

        $this->actingAs($administrador, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 0,
                'nombre' => 'Planta frigorífica',
                'elementos' => $elementos,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        $elementos[0]['categoria'] = 'invernadero';
        $this->actingAs($administrador, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 1,
                'nombre' => 'Planta frigorífica',
                'elementos' => $elementos,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['elementos.0.categoria']);
    }

    public function test_guarda_pasillos_y_conexiones_y_publica_la_red_con_recorridos(): void
    {
        $this->crearTemporadaActivaPrueba([
            'codigo' => 'RED',
            'nombre' => 'Temporada red',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2027-03-31',
            'activa' => true,
        ]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $camara = $this->crearCamara();
        $tunel = $this->crearTunel($administrador);
        $camaraElemento = $this->elemento('camara', $camara->id, 'Cámara uno', 200, 200);
        $tunelElemento = $this->elemento('tunel', $tunel->id, 'Túnel uno', 2600, 200);
        $pasillo = [...$this->elemento('pasillo', null, 'Pasillo norte', 200, 1100), 'ancho' => 3600, 'alto' => 200];
        $sala = $this->elemento('zona', null, 'Sala de máquinas', 5000, 200, 'no_operativo');
        $conexiones = [
            ['id' => (string) Str::uuid(), 'desde' => $camaraElemento['id'], 'hacia' => $pasillo['id']],
            ['id' => (string) Str::uuid(), 'desde' => $tunelElemento['id'], 'hacia' => $pasillo['id']],
        ];

        $this->actingAs($administrador, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 0,
                'nombre' => 'Planta frigorífica',
                'elementos' => [$camaraElemento, $tunelElemento, $pasillo, $sala],
                'conexiones' => $conexiones,
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.conexiones')
            ->assertJsonPath('data.red.pasillos', 1)
            ->assertJsonPath('data.red.recintos_conectados', 2)
            ->assertJsonPath('data.red.recintos_sin_acceso', []);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonCount(2, 'data.planta.conexiones')
            ->assertJsonPath('data.planta.red.redes', 1);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/operacion-ahora/plano/recorrido?desde='.$camaraElemento['id'].'&hacia='.$tunelElemento['id'])
            ->assertOk()
            ->assertJsonPath('data.elementos', [$camaraElemento['id'], $pasillo['id'], $tunelElemento['id']])
            ->assertJsonPath('data.version_plano', 1);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/operacion-ahora/plano/recorrido?desde='.$camaraElemento['id'].'&hacia='.$sala['id'])
            ->assertNotFound();
    }

    public function test_rechaza_conexiones_sin_borde_compartido_y_recintos_demasiado_angostos(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $camara = $this->crearCamara();
        $tunel = $this->crearTunel($administrador);
        $camaraElemento = $this->elemento('camara', $camara->id, 'Cámara uno', 200, 200);
        $tunelLejano = $this->elemento('tunel', $tunel->id, 'Túnel uno', 6000, 6000);
        $sala = $this->elemento('zona', null, 'Sala de máquinas', 1400, 200, 'no_operativo');

        $this->actingAs($administrador, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 0,
                'nombre' => 'Planta frigorífica',
                'elementos' => [$camaraElemento, $tunelLejano, $sala],
                'conexiones' => [
                    ['id' => (string) Str::uuid(), 'desde' => $camaraElemento['id'], 'hacia' => $tunelLejano['id']],
                    ['id' => (string) Str::uuid(), 'desde' => $camaraElemento['id'], 'hacia' => $sala['id']],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conexiones.0', 'conexiones.1']);

        $this->actingAs($administrador, 'sanctum')
            ->putJson('/api/administracion/operacion-ahora/plano', [
                'version_esperada' => 0,
                'nombre' => 'Planta frigorífica',
                'elementos' => [[...$camaraElemento, 'alto' => 200]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['elementos.0']);

        $this->assertDatabaseCount('planos_planta', 0);
    }

    private function elemento(string $tipo, ?string $referencia, string $nombre, int $x, int $y, ?string $categoria = null): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tipo' => $tipo,
            'referencia_id' => $referencia,
            'nombre' => $nombre,
            'categoria' => $categoria,
            'x' => $x,
            'y' => $y,
            'ancho' => 1200,
            'alto' => 900,
            'rotacion' => 0,
        ];
    }

    private function crearCamara(): Camara
    {
        return Camara::create([
            'codigo' => 'CAM-PLANO-01',
            'nombre' => 'Cámara plano',
            'contenido' => ContenidoCamara::Productos,
        ]);
    }

    private function crearTunel(User $usuario): TunelPrefrio
    {
        return TunelPrefrio::create([
            'codigo' => 'TUN-PLANO-01',
            'nombre' => 'Túnel plano',
            'capacidad_posiciones' => 10,
            'creado_por_user_id' => $usuario->id,
        ]);
    }

    private function crearAnden(User $usuario): Anden
    {
        return Anden::create([
            'codigo' => 'AND-PLANO-01',
            'nombre' => 'Andén plano',
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
    }

    private function crearAlmacen(User $usuario): AlmacenMaterial
    {
        return AlmacenMaterial::create([
            'codigo' => 'BOD-PLANO-01',
            'nombre' => 'Bodega plano',
            'tipo' => TipoAlmacenMaterial::Fisica,
            'centro_costo' => 'FRIO',
            'requiere_ubicacion_fisica' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
    }
}
