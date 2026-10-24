<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Posicion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CamaraApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_listado_indica_ocupacion_y_bloqueo_para_otro_operador(): void
    {
        [$operador, $token] = $this->crearIdentidad('TABLET-01');
        [$consulta, $tokenConsulta] = $this->crearIdentidad('TABLET-02');
        $camara = $this->crearCamara('CAM-01');
        [$posicionUno] = $this->crearPosiciones($camara, 2);

        $sesionId = $this->withToken($token)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)
            ->postJson('/api/movimientos/ubicar', [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => 'FOLIO-0001',
                'tipo_bulto' => 'pallet',
                'posicion_destino_id' => $posicionUno->id,
                'sesion_destino_id' => $sesionId,
                'version_destino_conocida' => 0,
                'generado_dispositivo_at' => now()->toAtomString(),
            ])
            ->assertOk();

        auth()->forgetGuards();

        $this->withToken($tokenConsulta)
            ->getJson('/api/camaras')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $camara->id)
            ->assertJsonPath('data.0.ocupacion.ocupadas', 1)
            ->assertJsonPath('data.0.ocupacion.total', 2)
            ->assertJsonPath('data.0.ocupacion.porcentaje', 50)
            ->assertJsonPath('data.0.sentido_numeracion_bandas', 'izquierda_a_derecha')
            ->assertJsonPath('data.0.acceso.modo', 'solo_lectura')
            ->assertJsonPath('data.0.acceso.bloqueada', true)
            ->assertJsonPath('data.0.acceso.sesion.es_propia', false)
            ->assertJsonPath('data.0.acceso.sesion.usuario.id', $operador->id)
            ->assertJsonPath('data.0.acceso.sesion.usuario.nombre', $operador->name);

        $this->assertNotSame($operador->id, $consulta->id);
    }

    public function test_el_listado_usa_etag_y_no_recalcula_ocupacion_si_no_cambia(): void
    {
        [, $token] = $this->crearIdentidad('TABLET-LISTADO-ETAG');
        $camara = $this->crearCamara('CAM-LISTADO-ETAG');
        $this->crearPosiciones($camara, 3);

        $inicial = $this->withToken($token)
            ->getJson('/api/camaras')
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'ETag')
            ->assertJsonPath('data.0.ocupacion.total', 3)
            ->assertJsonPath('data.0.acceso.modo', 'disponible');
        $etagInicial = $inicial->headers->get('ETag');

        $this->assertNotNull($etagInicial);

        auth()->forgetGuards();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withToken($token)
            ->withHeader('If-None-Match', $etagInicial)
            ->get('/api/camaras')
            ->assertStatus(304)
            ->assertHeader('ETag', $etagInicial);

        $consultoOcupacion = collect(DB::getQueryLog())
            ->contains(fn (array $consulta): bool => preg_match(
                '/^select\b.*\bfrom\s+[`"]?(posiciones|ubicaciones_actuales)[`"]?/is',
                trim($consulta['query']),
            ) === 1);
        $this->assertFalse($consultoOcupacion);

        DB::disableQueryLog();
        auth()->forgetGuards();
        $this->withToken($token)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated();

        auth()->forgetGuards();
        $actualizado = $this->withToken($token)
            ->withHeader('If-None-Match', $etagInicial)
            ->getJson('/api/camaras')
            ->assertOk()
            ->assertJsonPath('data.0.acceso.modo', 'edicion');

        $this->assertNotSame($etagInicial, $actualizado->headers->get('ETag'));
    }

    public function test_el_plano_expone_posiciones_folios_y_acceso_de_edicion_propio(): void
    {
        [, $token] = $this->crearIdentidad('TABLET-01');
        $camara = $this->crearCamara('CAM-01');
        [$posicion] = $this->crearPosiciones($camara);
        $sesionId = $this->withToken($token)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)
            ->postJson('/api/movimientos/ubicar', [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => 'FOLIO-PLANO',
                'tipo_bulto' => 'saldo',
                'posicion_destino_id' => $posicion->id,
                'sesion_destino_id' => $sesionId,
                'version_destino_conocida' => 0,
                'generado_dispositivo_at' => now()->toAtomString(),
                'datos_folio' => [
                    'variedad' => 'Santina',
                    'calibre' => '2J',
                    'marca' => 'Prueba',
                ],
            ])
            ->assertOk();

        $this->withToken($token)
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.version_plano', 1)
            ->assertJsonPath('data.revision_reservas', (int) $camara->refresh()->revision_reservas)
            ->assertJsonPath('data.sentido_numeracion_bandas', 'izquierda_a_derecha')
            ->assertJsonPath('data.acceso.modo', 'edicion')
            ->assertJsonPath('data.acceso.sesion.es_propia', true)
            ->assertJsonCount(1, 'data.posiciones')
            ->assertJsonPath('data.posiciones.0.id', $posicion->id)
            ->assertJsonPath('data.posiciones.0.ocupada', true)
            ->assertJsonPath('data.posiciones.0.folio.numero_folio', 'FOLIO-PLANO')
            ->assertJsonPath('data.posiciones.0.folio.tipo_bulto', 'saldo')
            ->assertJsonPath('data.posiciones.0.folio.variedad', 'Santina')
            ->assertJsonPath('data.posiciones.0.folio.calibre', '2J');
    }

    public function test_el_plano_usa_etag_y_evade_la_consulta_pesada_cuando_no_cambia(): void
    {
        [, $token] = $this->crearIdentidad('TABLET-ETAG');
        [, $tokenOtroOperador] = $this->crearIdentidad('TABLET-ETAG-02');
        $camara = $this->crearCamara('CAM-ETAG');
        $this->crearPosiciones($camara, 3);

        $inicial = $this->withToken($token)
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'ETag')
            ->assertJsonPath('data.version_plano', 0)
            ->assertJsonPath('data.acceso.modo', 'disponible');
        $etagInicial = $inicial->headers->get('ETag');

        $this->assertNotNull($etagInicial);
        $this->assertStringContainsString('private', (string) $inicial->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $inicial->headers->get('Cache-Control'));

        $this->withToken($token)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated();
        $this->assertSame(0, $camara->refresh()->version_plano);

        $conSesion = $this->withToken($token)
            ->withHeader('If-None-Match', $etagInicial)
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.acceso.modo', 'edicion')
            ->assertJsonPath('data.acceso.sesion.es_propia', true);
        $etagConSesion = $conSesion->headers->get('ETag');

        $this->assertNotNull($etagConSesion);
        $this->assertNotSame($etagInicial, $etagConSesion);

        auth()->forgetGuards();
        $otroOperador = $this->withToken($tokenOtroOperador)
            ->withHeader('If-None-Match', $etagConSesion)
            ->getJson("/api/camaras/{$camara->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.acceso.modo', 'solo_lectura')
            ->assertJsonPath('data.acceso.sesion.es_propia', false);
        $this->assertNotSame($etagConSesion, $otroOperador->headers->get('ETag'));

        auth()->forgetGuards();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withToken($token)
            ->withHeader('If-None-Match', $etagConSesion)
            ->get("/api/camaras/{$camara->id}/plano")
            ->assertStatus(304)
            ->assertHeader('ETag', $etagConSesion);

        $consultoPosiciones = collect(DB::getQueryLog())
            ->contains(fn (array $consulta): bool => preg_match(
                '/from\s+[`"]?posiciones[`"]?/i',
                $consulta['query'],
            ) === 1);
        $this->assertFalse($consultoPosiciones);
    }

    public function test_las_camaras_requieren_autenticacion(): void
    {
        $this->getJson('/api/camaras')->assertUnauthorized();
    }

    /**
     * @return array{User, string, Dispositivo}
     */
    private function crearIdentidad(string $codigo): array
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $dispositivo = Dispositivo::create([
            'codigo' => $codigo,
            'nombre' => "Tablet {$codigo}",
        ]);
        $token = $usuario
            ->crearTokenParaDispositivo($dispositivo, strtolower($codigo))
            ->plainTextToken;

        return [$usuario, $token, $dispositivo];
    }

    private function crearCamara(string $codigo): Camara
    {
        return Camara::create([
            'codigo' => $codigo,
            'nombre' => "Cámara {$codigo}",
        ]);
    }

    /**
     * @return array<int, Posicion>
     */
    private function crearPosiciones(Camara $camara, int $cantidad = 1): array
    {
        $camara->update(['posiciones_por_banda' => $cantidad]);
        $posiciones = [];

        for ($indice = 1; $indice <= $cantidad; $indice++) {
            $posiciones[] = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $indice,
                'nivel' => 1,
                'etiqueta' => "A-{$indice}-1",
            ]);
        }

        return $posiciones;
    }
}
