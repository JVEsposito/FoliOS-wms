<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPosicion;
use App\Enums\RolUsuario;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Posicion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreaFolioParaUbicar;
use Tests\TestCase;

class BandasOperacionalesApiTest extends TestCase
{
    use CreaFolioParaUbicar;
    use RefreshDatabase;

    public function test_el_plano_expone_capacidad_y_estado_calculado_por_banda(): void
    {
        [$operador, $token] = $this->identidadOperacional();
        [$camara, $posiciones, $banda] = $this->crearCamaraConBanda();
        $posiciones[2]->update(['estado' => EstadoPosicion::FueraDeServicio]);
        $sesionId = $this->withToken($token)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');
        $this->folioParaUbicar('PALLET-BANDA-001');

        $this->withToken($token)
            ->postJson('/api/movimientos/ubicar', [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => 'PALLET-BANDA-001',
                'tipo_bulto' => 'pallet',
                'posicion_destino_id' => $posiciones[0]->id,
                'sesion_destino_id' => $sesionId,
                'version_destino_conocida' => 0,
                'generado_dispositivo_at' => now()->toAtomString(),
            ])
            ->assertOk();

        $this->withToken($token)
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonCount(1, 'data.bandas_operacionales')
            ->assertJsonPath('data.bandas_operacionales.0.id', $banda->id)
            ->assertJsonPath('data.bandas_operacionales.0.numero', 1)
            ->assertJsonPath('data.bandas_operacionales.0.usos_permitidos', [
                'transito_pt',
                'inspeccion',
                'retenidos',
            ])
            ->assertJsonPath('data.bandas_operacionales.0.estado', 'parcial')
            ->assertJsonPath('data.bandas_operacionales.0.acepta_nuevos_ingresos', true)
            ->assertJsonPath('data.bandas_operacionales.0.capacidad.fisica', 3)
            ->assertJsonPath('data.bandas_operacionales.0.capacidad.efectiva', 2)
            ->assertJsonPath('data.bandas_operacionales.0.capacidad.ocupadas', 1)
            ->assertJsonPath('data.bandas_operacionales.0.capacidad.disponibles', 1)
            ->assertJsonPath('data.bandas_operacionales.0.capacidad.porcentaje', 50);

        $this->assertSame(RolUsuario::CamareroFrio, $operador->rol);
    }

    public function test_administrador_configura_usos_y_modo_con_version_optimista(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        [$camara, , $banda] = $this->crearCamaraConBanda();

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}/bandas/{$banda->id}", [
                'usos_permitidos' => ['retenidos', 'inspeccion'],
                'modo' => 'bloqueada',
                'motivo_estado' => 'Mantención de puerta lateral',
                'version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.usos_permitidos', ['inspeccion', 'retenidos'])
            ->assertJsonPath('data.modo', 'bloqueada')
            ->assertJsonPath('data.estado', 'bloqueada')
            ->assertJsonPath('data.acepta_nuevos_ingresos', false)
            ->assertJsonPath('data.motivo_estado', 'Mantención de puerta lateral')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.actualizado_por.id', $administrador->id);

        $this->assertDatabaseHas('bandas_operacionales', [
            'id' => $banda->id,
            'modo' => 'bloqueada',
            'actualizado_por_user_id' => $administrador->id,
            'version' => 2,
        ]);
        $this->assertSame(1, $camara->refresh()->version_plano);

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}/bandas/{$banda->id}", [
                'usos_permitidos' => ['transito_pt'],
                'modo' => 'operativa',
                'version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
    }

    public function test_rechaza_usos_de_saldos_o_repaletizaje_y_perfiles_no_administrativos(): void
    {
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        [$camara, , $banda] = $this->crearCamaraConBanda();
        $payload = [
            'usos_permitidos' => ['repaletizaje', 'saldos'],
            'modo' => 'operativa',
            'version' => 1,
        ];

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}/bandas/{$banda->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usos_permitidos.0');

        $this->actingAs($supervisor, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}/bandas/{$banda->id}", [
                'usos_permitidos' => ['transito_pt'],
                'modo' => 'en_vaciado',
                'motivo_estado' => 'Cierre de cámara',
                'version' => 1,
            ])
            ->assertForbidden();
    }

    public function test_crear_y_ampliar_camara_sincroniza_bandas_sin_perder_configuracion(): void
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        $respuesta = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/configuracion/camaras', [
                'nombre' => 'Cámara dinámica',
                'tipo' => 'transito',
                'contenido' => 'productos',
                'bandas' => 1,
                'posiciones_por_banda' => 2,
                'niveles' => 1,
            ])
            ->assertCreated();
        $camara = Camara::query()->findOrFail($respuesta->json('data.id'));
        $bandaInicial = $camara->bandasOperacionales()->firstOrFail();
        $bandaInicial->update([
            'usos_permitidos' => ['inspeccion'],
            'version' => 2,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}", [
                'nombre' => $camara->nombre,
                'tipo' => $camara->tipo,
                'contenido' => 'productos',
                'bandas' => 2,
                'posiciones_por_banda' => 2,
                'niveles' => 1,
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.bandas_operacionales');

        $this->assertDatabaseCount('bandas_operacionales', 2);
        $this->assertSame(['inspeccion'], $bandaInicial->refresh()->usos_permitidos);
        $this->assertSame(
            ['transito_pt', 'inspeccion', 'retenidos'],
            $camara->bandasOperacionales()->where('numero', 2)->firstOrFail()->usos_permitidos,
        );
    }

    /** @return array{User, string} */
    private function identidadOperacional(): array
    {
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-BANDAS',
            'nombre' => 'Tablet bandas',
        ]);

        return [
            $operador,
            $operador->crearTokenParaDispositivo($dispositivo, 'tablet-bandas')->plainTextToken,
        ];
    }

    /** @return array{Camara, array<int, Posicion>, BandaOperacional} */
    private function crearCamaraConBanda(): array
    {
        $camara = Camara::create([
            'codigo' => 'CAM-BANDAS',
            'nombre' => 'Cámara con bandas',
            'contenido' => 'productos',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 3,
            'cantidad_niveles' => 1,
        ]);
        $posiciones = [];

        for ($numero = 1; $numero <= 3; $numero++) {
            $posiciones[] = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $numero,
                'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $numero),
            ]);
        }

        $banda = BandaOperacional::create([
            'camara_id' => $camara->id,
            'numero' => 1,
            'usos_permitidos' => ['transito_pt', 'inspeccion', 'retenidos'],
            'modo' => 'operativa',
        ]);

        return [$camara, $posiciones, $banda];
    }
}
