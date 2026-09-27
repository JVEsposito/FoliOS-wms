<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnulacionValidacionPalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_anula_pallet_pendiente_prefrio_y_conserva_auditoria(): void
    {
        [$catalogo, $tokenValidador] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-01');
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'SUP-ANU-01');
        $validacionId = $this->crearValidacion($tokenValidador, $catalogo, 'PAL-ANU-0001');
        $operacionId = (string) Str::uuid();

        $respuesta = $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionId}/anular", [
                'operacion_id' => $operacionId,
                'motivo_categoria' => 'folio_incorrecto',
                'motivo' => 'El número físico del pallet fue digitado incorrectamente.',
            ])
            ->assertOk()
            ->assertJsonPath('data.numero_folio', 'PAL-ANU-0001')
            ->assertJsonPath('data.motivo_categoria', 'folio_incorrecto')
            ->assertJsonPath('data.folio.estado_operacional', 'anulado')
            ->assertJsonPath('data.folio.activo', false)
            ->assertJsonPath(
                'message',
                'Validación anulada. El número de folio quedó disponible para ingresarlo nuevamente.',
            );

        $anulacionId = $respuesta->json('data.id');

        $this->assertDatabaseHas('validaciones_pallet', [
            'id' => $validacionId,
            'estado' => 'anulada',
        ]);
        $this->assertDatabaseHas('folios', [
            'numero_folio' => 'PAL-ANU-0001',
            'estado_operacional' => 'anulado',
            'activo' => false,
        ]);
        $this->assertDatabaseHas('anulaciones_validacion_pallet', [
            'id' => $anulacionId,
            'operacion_id' => $operacionId,
            'validacion_pallet_id' => $validacionId,
            'numero_folio' => 'PAL-ANU-0001',
            'motivo_categoria' => 'folio_incorrecto',
        ]);

        $folio = Folio::query()->where('numero_folio', 'PAL-ANU-0001')->firstOrFail();
        $this->assertSame($anulacionId, $folio->datos_externos['anulacion_validacion_id']);
    }

    public function test_validador_no_puede_anular_su_propio_pallet(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-02');
        $validacionId = $this->crearValidacion($token, $catalogo, 'PAL-ANU-0002');

        $this->conToken($token)
            ->postJson("/api/validacion/pallets/{$validacionId}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo_categoria' => 'otro',
                'motivo' => 'Intento no autorizado del propio validador.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('anulaciones_validacion_pallet', [
            'validacion_pallet_id' => $validacionId,
        ]);
        $this->assertDatabaseHas('folios', [
            'numero_folio' => 'PAL-ANU-0002',
            'estado_operacional' => 'pendiente_prefrio',
            'activo' => true,
        ]);
    }

    public function test_anulacion_es_idempotente_y_no_duplica_registro(): void
    {
        [$catalogo, $tokenValidador] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-03');
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'SUP-ANU-03');
        $validacionId = $this->crearValidacion($tokenValidador, $catalogo, 'PAL-ANU-0003');
        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'motivo_categoria' => 'error_etiqueta',
            'motivo' => 'La etiqueta física corresponde a otro pallet.',
        ];

        $primera = $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionId}/anular", $payload)
            ->assertOk()
            ->json('data.id');

        $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionId}/anular", $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $primera);

        $this->assertDatabaseCount('anulaciones_validacion_pallet', 1);
    }

    public function test_no_permite_anular_si_el_pallet_ya_avanzo_desde_pendiente_prefrio(): void
    {
        [$catalogo, $tokenValidador] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-04');
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'SUP-ANU-04');
        $validacionId = $this->crearValidacion($tokenValidador, $catalogo, 'PAL-ANU-0004');

        Folio::query()->where('numero_folio', 'PAL-ANU-0004')->update([
            'estado_operacional' => 'disponible',
            'condicion_termica' => 'prefrio_aprobado',
            'habilitacion_almacenamiento' => 'habilitado',
        ]);

        $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionId}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo_categoria' => 'otro',
                'motivo' => 'Se intentó anular después de que el pallet avanzó.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->assertDatabaseMissing('anulaciones_validacion_pallet', [
            'validacion_pallet_id' => $validacionId,
        ]);
    }

    public function test_folio_anulado_no_puede_reactivarse_fuera_de_una_nueva_validacion(): void
    {
        [$catalogo, $tokenValidador] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-05');
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'SUP-ANU-05');
        $validacionId = $this->crearValidacion($tokenValidador, $catalogo, 'PAL-ANU-0005');

        $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionId}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo_categoria' => 'pallet_duplicado',
                'motivo' => 'La etiqueta fue duplicada físicamente por error.',
            ])
            ->assertOk();

        $folio = Folio::query()->where('numero_folio', 'PAL-ANU-0005')->firstOrFail();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fue anulado en Validación y es inmutable');
        $folio->update(['activo' => true]);
    }

    public function test_folio_anulado_puede_ingresarse_nuevamente_y_conserva_la_auditoria(): void
    {
        [$catalogo, $tokenValidador] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-REINGRESO');
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'SUP-ANU-REINGRESO');
        $validacionOriginalId = $this->crearValidacion(
            $tokenValidador,
            $catalogo,
            'PAL-ANU-REINGRESO',
        );
        $folioOriginal = Folio::query()
            ->where('numero_folio', 'PAL-ANU-REINGRESO')
            ->firstOrFail();

        $anulacionId = $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionOriginalId}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo_categoria' => 'cantidad_cajas_incorrecta',
                'motivo' => 'Se ingresaron 129 cajas y correspondían 120.',
            ])
            ->assertOk()
            ->json('data.id');

        $reingreso = $this->payload($catalogo, 'PAL-ANU-REINGRESO');
        $reingreso['cantidad_cajas'] = 120;

        $validacionNuevaId = $this->conToken($tokenValidador)
            ->postJson('/api/validacion/pallets', $reingreso)
            ->assertCreated()
            ->assertJsonPath('data.numero_intento', 2)
            ->assertJsonPath('data.estado', 'aceptada')
            ->assertJsonPath('data.resultado', 'aprobado')
            ->assertJsonPath('data.cantidad_cajas', 120)
            ->assertJsonPath('data.folio.activo', true)
            ->assertJsonPath('data.folio.estado_operacional', 'pendiente_prefrio')
            ->json('data.id');

        $folioReingresado = Folio::query()
            ->where('numero_folio', 'PAL-ANU-REINGRESO')
            ->firstOrFail();

        $this->assertSame($folioOriginal->id, $folioReingresado->id);
        $this->assertSame($validacionNuevaId, $folioReingresado->datos_externos['validacion_id']);
        $this->assertArrayNotHasKey('anulacion_validacion_id', $folioReingresado->datos_externos);
        $this->assertDatabaseCount('folios', 1);
        $this->assertDatabaseHas('validaciones_pallet', [
            'id' => $validacionOriginalId,
            'estado' => 'anulada',
        ]);
        $this->assertDatabaseHas('anulaciones_validacion_pallet', [
            'id' => $anulacionId,
            'validacion_pallet_id' => $validacionOriginalId,
            'folio_id' => $folioOriginal->id,
        ]);
    }

    public function test_oficina_lista_candidatos_y_auditoria_de_anulaciones(): void
    {
        [$catalogo, $tokenValidador] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-06');
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'SUP-ANU-06');
        $validacionA = $this->crearValidacion($tokenValidador, $catalogo, 'PAL-ANU-0006');
        $this->crearValidacion($tokenValidador, $catalogo, 'PAL-ANU-0007');

        $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionA}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo_categoria' => 'cantidad_cajas_incorrecta',
                'motivo' => 'La cantidad física de cajas no correspondía al registro.',
            ])
            ->assertOk();

        $this->conToken($tokenSupervisor)
            ->getJson('/api/validacion/anulaciones')
            ->assertOk()
            ->assertJsonPath('resumen.total', 1)
            ->assertJsonPath('resumen.hoy', 1)
            ->assertJsonPath('resumen.por_categoria.cantidad_cajas_incorrecta', 1)
            ->assertJsonCount(1, 'candidatas')
            ->assertJsonPath('candidatas.0.numero_folio', 'PAL-ANU-0007')
            ->assertJsonPath('candidatas.0.articulo_validacion_id', $catalogo['articulo_validacion_id'])
            ->assertJsonPath('candidatas.0.origen_validacion_id', $catalogo['origen_validacion_id'])
            ->assertJsonPath('candidatas.0.categoria_validacion_id', $catalogo['categoria_validacion_id'])
            ->assertJsonCount(1, 'anulaciones')
            ->assertJsonPath('anulaciones.0.numero_folio', 'PAL-ANU-0006')
            ->assertJsonPath('anulaciones.0.folio.estado_operacional', 'anulado');
    }

    public function test_no_anula_una_validacion_de_una_temporada_inactiva(): void
    {
        [$catalogo, $tokenValidador] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-TEMP');
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'SUP-ANU-TEMP');
        $validacionId = $this->crearValidacion($tokenValidador, $catalogo, 'PAL-ANU-TEMP');
        $this->activarTemporadaNueva('ANU-NUEVA');

        $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionId}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo_categoria' => 'otro',
                'motivo' => 'Intento sobre una validación de la temporada anterior.',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'temporada_no_activa')
            ->assertJsonPath('temporada_activa', 'ANU-NUEVA');

        $this->assertDatabaseMissing('anulaciones_validacion_pallet', [
            'validacion_pallet_id' => $validacionId,
        ]);
        $this->assertDatabaseHas('validaciones_pallet', ['id' => $validacionId, 'estado' => 'aceptada']);
    }

    public function test_historial_y_contadores_de_anulaciones_muestran_solo_la_temporada_activa(): void
    {
        [$catalogo, $tokenValidador] = $this->contexto(RolUsuario::Validador, 'VAL-ANU-HIST');
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'SUP-ANU-HIST');
        $validacionId = $this->crearValidacion($tokenValidador, $catalogo, 'PAL-ANU-HIST');
        $this->conToken($tokenSupervisor)
            ->postJson("/api/validacion/pallets/{$validacionId}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo_categoria' => 'folio_incorrecto',
                'motivo' => 'Anulación registrada antes del cambio de temporada.',
            ])
            ->assertOk();

        $this->conToken($tokenSupervisor)
            ->getJson('/api/validacion/anulaciones')
            ->assertOk()
            ->assertJsonCount(1, 'anulaciones')
            ->assertJsonPath('resumen.total', 1);

        $this->activarTemporadaNueva('ANU-HIST-NUEVA');

        $this->conToken($tokenSupervisor)
            ->getJson('/api/validacion/anulaciones')
            ->assertOk()
            ->assertJsonCount(0, 'anulaciones')
            ->assertJsonPath('resumen.total', 0)
            ->assertJsonPath('resumen.hoy', 0)
            ->assertJsonCount(0, 'resumen.por_categoria');
    }

    /**
     * @return array{array<string, string|int>, string}
     */
    private function contexto(RolUsuario $rol, string $codigo): array
    {
        $temporada = (string) Str::uuid();
        $articulo = (string) Str::uuid();
        $origen = (string) Str::uuid();
        $categoria = (string) Str::uuid();

        $this->desactivarTemporadasDePrueba();
        DB::table('temporadas')->insert([
            'id' => $temporada,
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
            'version_catalogo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('articulos_validacion')->insert([
            'id' => $articulo,
            'temporada_id' => $temporada,
            'especie' => 'Cereza',
            'variedad' => 'Santina',
            'calibre' => '2J',
            'envase' => '5 kg',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('categorias_validacion')->insert([
            'id' => $categoria,
            'temporada_id' => $temporada,
            'nombre' => 'Exportación',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('origenes_validacion')->insert([
            'id' => $origen,
            'temporada_id' => $temporada,
            'cliente' => 'DIS',
            'marca' => 'ATLAS',
            'csg' => '105410',
            'predio' => 'OLM',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('combinaciones_validacion')->insert([
            'id' => (string) Str::uuid(),
            'temporada_id' => $temporada,
            'articulo_validacion_id' => $articulo,
            'origen_validacion_id' => $origen,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [, $token] = $this->acceso($rol, $codigo);

        return [[
            'temporada_id' => $temporada,
            'articulo_validacion_id' => $articulo,
            'origen_validacion_id' => $origen,
            'categoria_validacion_id' => $categoria,
            'catalogo_version' => 1,
        ], $token];
    }

    /**
     * @return array{User, string}
     */
    private function acceso(RolUsuario $rol, string $codigo): array
    {
        $usuario = User::factory()->create(['rol' => $rol]);
        $dispositivo = Dispositivo::create([
            'codigo' => $codigo,
            'nombre' => "PDA {$codigo}",
            'plataforma' => 'android',
            'activo' => true,
        ]);
        $token = $usuario->crearTokenParaDispositivo($dispositivo, "test-{$codigo}")->plainTextToken;

        return [$usuario, $token];
    }

    /**
     * @param  array<string, string|int>  $catalogo
     * @return array<string, mixed>
     */
    private function payload(array $catalogo, string $folio): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $folio,
            'tipo_bulto' => 'pallet',
            'cantidad_cajas' => 120,
            'linea_proceso' => 1,
            'turno' => 'A',
            ...$catalogo,
            'resultado' => 'aprobado',
            'motivo' => null,
            'observacion' => null,
            'generado_dispositivo_at' => now()->toAtomString(),
        ];
    }

    /**
     * @param  array<string, string|int>  $catalogo
     */
    private function crearValidacion(string $token, array $catalogo, string $folio): string
    {
        $payload = $this->payload($catalogo, $folio);
        $payload['operacion_id'] = (string) Str::uuid();

        return $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->json('data.id');
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function activarTemporadaNueva(string $codigo): void
    {
        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => $codigo,
            'nombre' => "Temporada {$codigo}",
            'activa' => true,
        ]);
    }
}
