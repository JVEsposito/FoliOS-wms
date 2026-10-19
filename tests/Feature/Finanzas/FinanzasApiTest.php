<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanzasApiTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
    }

    public function test_solo_administracion_o_un_perfil_con_finanzas_consulta_los_hechos(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum')
            ->getJson('/api/finanzas/hechos')->assertForbidden();
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, ['frigorifico.validacion']), 'sanctum')
            ->getJson('/api/finanzas/hechos')->assertForbidden();

        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]);
        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/finanzas/hechos')
            ->assertOk()
            ->assertJsonPath('puede_capturar', false);
        $this->actingAs($consulta, 'sanctum')->postJson('/api/finanzas/capturas')->assertForbidden();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->getJson('/api/finanzas/hechos')
            ->assertOk()
            ->assertJsonPath('puede_capturar', true);
    }

    public function test_el_perfil_inicial_de_administrador_incluye_finanzas(): void
    {
        $perfil = PerfilAcceso::query()->where('rol_base', 'administrador')->where('predeterminado', true)->firstOrFail();
        $this->assertContains(CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA, $perfil->modulos);

        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'perfil_acceso_id' => $perfil->id]);
        $this->actingAs($administrador, 'sanctum')->getJson('/api/finanzas/hechos')->assertOk();
    }

    public function test_los_hechos_del_mes_con_resumen_filtros_e_historial(): void
    {
        $cliente = $this->clienteGlobal('PRODUCTOR DE PRUEBA SPA');
        $this->recepcionRomana($cliente, 'R-1001', 8000, '2026-11-03 09:00');
        $this->recepcionRomana($cliente, 'R-1002', 4500.5, '2026-11-20 09:00');
        $this->recepcionRomana($cliente, 'R-0901', 3000, '2026-10-30 09:00');
        $camara = $this->camara('C1');
        // Estadía que empezó en octubre y sigue abierta: también es un hecho de noviembre.
        $folio = $this->folio('PT-2001', $cliente);
        $this->movimiento($folio, null, $camara, '2026-10-25 08:00');
        $this->ubicar($folio, $camara, '2026-10-25 08:00');
        $sinCliente = $this->folio('PT-2002', null, 'EXPORTADORA SIN VINCULAR');
        $this->movimiento($sinCliente, null, $camara, '2026-11-10 08:00');
        $this->ubicar($sinCliente, $camara, '2026-11-10 08:00');
        $this->capturar();

        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $respuesta = $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/finanzas/hechos?mes=2026-11')
            ->assertOk()
            ->assertJsonPath('periodo.mes', '2026-11')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('ultima_captura.estado', 'completada');

        $resumen = collect($respuesta->json('resumen'))->keyBy('fuente');
        $this->assertSame(2, $resumen['romana_recepcion']['hechos']);
        $this->assertSame(12500.5, $resumen['romana_recepcion']['medidas']['kilos_netos']);
        $this->assertSame(2, $resumen['estadia']['hechos']);
        $this->assertSame(1, $resumen['estadia']['sin_cliente']);
        $this->assertSame(['PRODUCTOR DE PRUEBA SPA', 'Sin cliente vinculado'], collect($respuesta->json('clientes'))->pluck('nombre')->all());

        $this->getJson('/api/finanzas/hechos?mes=2026-11&fuente=estadia&cliente=sin_cliente')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.referencia', 'PT-2002');
        $this->getJson('/api/finanzas/hechos?mes=2026-11&q=R-100')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->getJson('/api/finanzas/hechos?mes=2026-10')
            ->assertOk()
            ->assertJsonPath('meta.total', 2, 'Octubre: su recepción y la estadía que empezó ese mes.');

        // Una corrección queda como versión; el historial las muestra de la más nueva a la más antigua.
        $this->travel(5)->minutes();
        DB::table('recepciones_romana')->where('numero_recepcion', 'R-1001')->update(['peso_neto' => 7900, 'updated_at' => now()]);
        $this->capturar();
        $id = collect($this->getJson('/api/finanzas/hechos?mes=2026-11&q=R-1001')->json('data'))->sole()['id'];
        $this->getJson("/api/finanzas/hechos/{$id}")
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('versiones.0.medidas.kilos_netos', 7900)
            ->assertJsonPath('versiones.1.motivo_version', 'captura')
            ->assertJsonCount(2, 'versiones');

        $this->getJson('/api/finanzas/hechos?mes=noviembre')->assertUnprocessable()->assertJsonValidationErrors('mes');
    }

    public function test_leer_ahora_registra_quien_la_pidio_y_no_corre_dos_veces(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'name' => 'Administración Demo']);
        $this->recepcionRomana($this->clienteGlobal('PRODUCTOR DE PRUEBA SPA'), 'R-3001', 1000, '2026-11-03 09:00');

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/finanzas/capturas')
            ->assertCreated()
            ->assertJsonPath('data.origen', 'manual')
            ->assertJsonPath('data.estado', 'completada')
            ->assertJsonPath('data.solicitada_por', 'Administración Demo')
            ->assertJsonPath('data.resumen.romana_recepcion.nuevos', 1);

        $this->getJson('/api/finanzas/capturas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('fuentes.0.clave', 'romana_recepcion')
            ->assertJsonPath('configuracion.cada_minutos', 10);

        $this->conCapturaEnOtraSesion(
            fn () => $this->postJson('/api/finanzas/capturas')->assertStatus(409)->assertJsonPath('codigo', 'captura_en_curso'),
        );
    }

    public function test_la_oficina_de_hechos_esta_en_administracion(): void
    {
        $html = $this->get('/oficina/finanzas/hechos')->assertOk()->getContent();

        $this->assertStringContainsString('Hechos valorizables', $html);
        $this->assertStringContainsString('data-office-key="finanzas-hechos"', $html);
        // El título del grupo se oculta junto con sus oficinas si el perfil no habilita ninguna.
        $this->assertMatchesRegularExpression('/<p data-navigation-permissions="puede_consultar_finanzas"\s+data-navigation-module="finanzas.prefactura"\s+class="estiba-office-subgroup">Finanzas<\/p>/', $html);
    }
}
