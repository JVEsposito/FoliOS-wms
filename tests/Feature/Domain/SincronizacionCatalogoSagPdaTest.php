<?php

namespace Tests\Feature\Domain;

use App\Enums\RolUsuario;
use App\Models\CalibreValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\MarcaValidacion;
use App\Models\ProductorCsg;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Clientes\ServicioCliente;
use App\Services\Consultas\ServicioAsociacionProductorCsg;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use App\Services\Validacion\ServicioSincronizacionCatalogoSag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SincronizacionCatalogoSagPdaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_flujo_sag_completo_expone_especie_cliente_y_combinacion_en_la_pda(): void
    {
        $temporada = $this->temporadaActiva('TEMP-PDA', '2026-07-01');
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $validador = User::factory()->create(['rol' => RolUsuario::Validador]);
        $cliente = $this->clienteGlobal('CLI-01', 'Exportadora Uno');

        app(ServicioCliente::class)->asegurarProyeccionesActivas($cliente, $administrador->id);
        $clienteValidacion = ClienteValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->where('cliente_id', $cliente->id)
            ->firstOrFail();
        MarcaValidacion::create([
            'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'Marca Uno',
            'activo' => true,
        ]);

        $productor = $this->productor('105410', 'CEREZA', 'SANTINA', $administrador);
        $pares = $productor->especies_variedades;
        app(ServicioSincronizacionCatalogoSag::class)->sincronizar($productor, $pares);
        app(ServicioAsociacionProductorCsg::class)->sincronizar(
            $productor,
            [$cliente->id],
            $administrador,
        );

        $especie = EspecieValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->where('nombre', 'Cereza')
            ->firstOrFail();
        $variedad = VariedadValidacion::query()
            ->where('especie_validacion_id', $especie->id)
            ->where('nombre', 'Santina')
            ->firstOrFail();

        $maestro = app(ServicioCatalogoJerarquicoValidacion::class)->datos($temporada);
        $especieMaestro = $maestro['especies']->firstWhere('id', $especie->id);
        $this->assertNotNull($especieMaestro);
        $this->assertTrue(
            $especieMaestro->variedades->contains('id', $variedad->id),
            'La especie y variedad creadas desde SAG deben aparecer inmediatamente en el maestro.',
        );

        $catalogo = app(ServicioCatalogoJerarquicoValidacion::class);
        $catalogo->guardarCalibre([
            'especie_validacion_id' => $especie->id,
            'nombre' => '2J',
            'activo' => true,
        ]);
        $catalogo->guardarEnvase([
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => '5 kg',
            'activo' => true,
        ]);

        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertJsonPath('temporada.id', $temporada->id)
            ->assertJsonFragment(['especie' => 'Cereza', 'variedad' => 'Santina'])
            ->assertJsonFragment(['cliente' => 'Exportadora Uno', 'marca' => 'Marca Uno'])
            ->assertJsonCount(1, 'combinaciones');
    }

    public function test_un_cliente_heredado_con_marcas_y_envases_no_desaparece_al_asociar_el_csg(): void
    {
        $temporada = $this->temporadaActiva('TEMP-HEREDADA', '2026-07-01');
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $validador = User::factory()->create(['rol' => RolUsuario::Validador]);

        $clienteValidacion = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'cliente_id' => null,
            'nombre' => 'Exportadora Heredada',
            'codigo_externo' => 'HER-01',
            'activo' => true,
        ]);
        MarcaValidacion::create([
            'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'Marca Heredada',
            'activo' => true,
        ]);
        $especie = EspecieValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cereza',
            'activo' => true,
        ]);
        $variedad = VariedadValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'Santina',
            'activo' => true,
        ]);
        CalibreValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => '2J',
            'activo' => true,
        ]);
        EnvaseValidacion::create([
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => '5 kg',
            'activo' => true,
        ]);

        $cliente = $this->clienteGlobal('HER-01', 'Exportadora Heredada');
        $productor = $this->productor('205410', 'CEREZA', 'SANTINA', $administrador);
        app(ServicioSincronizacionCatalogoSag::class)->sincronizar(
            $productor,
            $productor->especies_variedades,
        );
        app(ServicioAsociacionProductorCsg::class)->sincronizar(
            $productor,
            [$cliente->id],
            $administrador,
        );

        $this->assertSame(
            $cliente->id,
            $clienteValidacion->fresh()->cliente_id,
            'La proyección heredada debe adoptarse en vez de crear un cliente vacío paralelo.',
        );
        $this->assertSame(
            1,
            ClienteValidacion::query()
                ->where('temporada_id', $temporada->id)
                ->where('nombre', 'Exportadora Heredada')
                ->count(),
        );

        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertJsonFragment([
                'cliente' => 'Exportadora Heredada',
                'marca' => 'Marca Heredada',
                'csg' => '205410',
            ])
            ->assertJsonCount(1, 'combinaciones');
    }

    public function test_reconstruye_la_pda_si_la_asociacion_ya_existia_al_adoptar_el_cliente_heredado(): void
    {
        $temporada = $this->temporadaActiva('TEMP-ASOCIADA', '2026-07-01');
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $validador = User::factory()->create(['rol' => RolUsuario::Validador]);
        $cliente = $this->clienteGlobal('ASO-01', 'Exportadora Asociada');
        $clienteValidacion = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'cliente_id' => null,
            'nombre' => 'Exportadora Asociada',
            'codigo_externo' => 'ASO-01',
            'activo' => true,
        ]);
        MarcaValidacion::create([
            'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'Marca Asociada',
            'activo' => true,
        ]);

        $productor = $this->productor('405410', 'KIWI', 'HAYWARD', $administrador);
        app(ServicioSincronizacionCatalogoSag::class)->sincronizar(
            $productor,
            $productor->especies_variedades,
        );
        $especie = EspecieValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->where('nombre', 'Kiwi')
            ->firstOrFail();
        CalibreValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => '25',
            'activo' => true,
        ]);
        EnvaseValidacion::create([
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'P10G',
            'activo' => true,
        ]);
        DB::table('clientes_productores_csg')->insert([
            'id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'productor_csg_id' => $productor->id,
            'activo' => true,
            'asociado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ServicioAsociacionProductorCsg::class)->sincronizar(
            $productor,
            [$cliente->id],
            $administrador,
        );

        $this->assertSame($cliente->id, $clienteValidacion->fresh()->cliente_id);
        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertJsonFragment([
                'especie' => 'Kiwi',
                'variedad' => 'Hayward',
                'calibre' => '25',
                'envase' => 'P10G',
            ])
            ->assertJsonFragment([
                'cliente' => 'Exportadora Asociada',
                'marca' => 'Marca Asociada',
                'csg' => '405410',
            ])
            ->assertJsonCount(1, 'combinaciones');
    }

    public function test_sag_clientes_y_pda_resuelven_la_misma_temporada_activa(): void
    {
        Temporada::query()->update(['activa' => false]);
        $anterior = $this->crearTemporadaActivaPrueba([
            'codigo' => 'TEMP-ANTERIOR',
            'nombre' => 'Temporada anterior',
            'fecha_inicio' => '2025-07-01',
            'activa' => true,
        ]);
        $vigente = $this->crearTemporadaActivaPrueba([
            'codigo' => 'TEMP-VIGENTE',
            'nombre' => 'Temporada vigente',
            'fecha_inicio' => '2026-07-01',
            'activa' => true,
        ]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $validador = User::factory()->create(['rol' => RolUsuario::Validador]);
        $cliente = $this->clienteGlobal('VIG-01', 'Cliente Vigente');

        app(ServicioCliente::class)->asegurarProyeccionesActivas($cliente, $administrador->id);
        $productor = $this->productor('305410', 'KIWI', 'HAYWARD', $administrador);
        app(ServicioSincronizacionCatalogoSag::class)->sincronizar(
            $productor,
            $productor->especies_variedades,
        );

        $this->assertDatabaseHas('clientes_validacion', [
            'temporada_id' => $vigente->id,
            'cliente_id' => $cliente->id,
        ]);
        $this->assertDatabaseHas('especies_validacion', [
            'temporada_id' => $vigente->id,
            'nombre' => 'Kiwi',
        ]);
        $this->assertDatabaseMissing('especies_validacion', [
            'temporada_id' => $anterior->id,
            'nombre' => 'Kiwi',
        ]);

        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertJsonPath('temporada.id', $vigente->id);
    }

    public function test_el_comando_repara_la_proyeccion_existente_de_la_temporada_activa(): void
    {
        $temporada = $this->temporadaActiva('TEMP-REPARAR', '2026-07-01');
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $cliente = $this->clienteGlobal('REP-01', 'Exportadora Reparada');
        $clienteValidacion = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'cliente_id' => null,
            'nombre' => 'Exportadora Reparada',
            'codigo_externo' => 'REP-01',
            'activo' => true,
        ]);
        MarcaValidacion::create([
            'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'Marca Reparada',
            'activo' => true,
        ]);

        $productor = $this->productor('505410', 'KIWI', 'HAYWARD', $administrador);
        app(ServicioSincronizacionCatalogoSag::class)->sincronizar(
            $productor,
            $productor->especies_variedades,
        );
        $especie = EspecieValidacion::query()
            ->where('temporada_id', $temporada->id)
            ->where('nombre', 'Kiwi')
            ->firstOrFail();
        CalibreValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => '25',
            'activo' => true,
        ]);
        EnvaseValidacion::create([
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'P10G',
            'activo' => true,
        ]);
        DB::table('clientes_productores_csg')->insert([
            'id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'productor_csg_id' => $productor->id,
            'activo' => true,
            'asociado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('combinaciones_validacion', 0);

        $this->artisan('validacion:reparar-catalogo')
            ->expectsOutputToContain('Catálogo reparado')
            ->assertSuccessful();

        $this->assertSame($cliente->id, $clienteValidacion->fresh()->cliente_id);
        $this->assertDatabaseCount('origenes_validacion', 1);
        $this->assertDatabaseCount('combinaciones_validacion', 1);
    }

    public function test_la_pda_lee_la_unica_activa_aunque_otra_tenga_la_misma_fecha_de_inicio(): void
    {
        Temporada::query()->update(['activa' => false]);
        $anterior = Temporada::create([
            'codigo' => 'TEMP-MISMA-FECHA-1',
            'nombre' => 'Temporada creada primero',
            'fecha_inicio' => '2026-07-01',
            'activa' => false,
            'created_at' => '2026-07-01 08:00:00',
            'updated_at' => '2026-07-01 08:00:00',
        ]);
        $vigente = $this->crearTemporadaActivaPrueba([
            'codigo' => 'TEMP-MISMA-FECHA-2',
            'nombre' => 'Temporada creada después',
            'fecha_inicio' => '2026-07-01',
            'activa' => true,
            'created_at' => '2026-07-01 09:00:00',
            'updated_at' => '2026-07-01 09:00:00',
        ]);
        $validador = User::factory()->create(['rol' => RolUsuario::Validador]);

        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertJsonPath('temporada.id', $vigente->id)
            ->assertJsonMissing(['id' => $anterior->id]);
    }

    private function temporadaActiva(string $codigo, string $fechaInicio): Temporada
    {
        Temporada::query()->update(['activa' => false]);

        return $this->crearTemporadaActivaPrueba([
            'codigo' => $codigo,
            'nombre' => "Temporada {$codigo}",
            'fecha_inicio' => $fechaInicio,
            'activa' => true,
        ]);
    }

    private function clienteGlobal(string $codigo, string $nombre): Cliente
    {
        return Cliente::create([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'activo' => true,
        ]);
    }

    private function productor(
        string $codigo,
        string $especie,
        string $variedad,
        User $usuario,
    ): ProductorCsg {
        return ProductorCsg::create([
            'codigo' => $codigo,
            'razon_social' => "Productor {$codigo}",
            'predio' => "Predio {$codigo}",
            'estado_sag' => 'activo',
            'tipo_codigo' => 'CSG',
            'especies' => ["{$especie} - {$variedad}"],
            'especies_variedades' => [[
                'especie' => $especie,
                'variedad' => $variedad,
                'texto' => "{$especie} - {$variedad}",
            ]],
            'fuente_url' => 'https://sag.example.test',
            'primera_verificacion_at' => now(),
            'ultima_verificacion_at' => now(),
            'ultima_consulta_user_id' => $usuario->id,
            'respuesta_hash' => hash('sha256', $codigo),
            'datos_fuente' => [],
        ]);
    }
}
