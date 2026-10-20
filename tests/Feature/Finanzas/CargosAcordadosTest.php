<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Cierre\GestorCierres;
use App\Finanzas\Cierre\RevisionDiferencias;
use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\CalculadoraPrefactura;
use App\Finanzas\Prefactura\LibroPrefactura;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CargosAcordadosTest extends TestCase
{
    use HechosDePrueba;
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private string $cliente;

    private string $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->administrador, 'sanctum');
        $this->cliente = $this->clienteGlobal('EXPORTADORA CONTRATO');
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
        $this->servicio = $this->postJson('/api/finanzas/servicios', [
            'nombre' => 'Cuadrilla reservada', 'fuente' => 'declarado', 'aplicacion' => 'declarado', 'unidad_libre' => 'hora persona',
            'proceso_referencia' => 'RRHH', 'vigente_desde' => '2026-11-01', 'dia_desde' => 1,
        ])->assertCreated()->assertJsonPath('data.unidades.0.nombre', 'por hora persona')->json('data.clave');
        $this->precio(null, $this->servicio, 'cantidad', '3.5', '2026-11-01');
    }

    public function test_un_servicio_cancelado_se_cobra_por_compromiso_sin_inventar_una_ejecucion(): void
    {
        $datos = $this->datos();
        $cargo = $this->postJson('/api/finanzas/cargos', $datos)->assertCreated()->json('data.id');
        $this->assertSame(0, Hecho::query()->count(), 'El borrador no se valoriza.');
        $this->postJson('/api/finanzas/cargos', [...$datos, 'cantidad' => '8.000000'])->assertCreated()->assertJsonPath('data.id', $cargo);
        $this->postJson('/api/finanzas/cargos', [...$datos, 'cantidad' => '9'])->assertUnprocessable()->assertJsonValidationErrors('operacion_id');
        $this->postJson("/api/finanzas/cargos/{$cargo}/aprobar")->assertOk();
        $hecho = Hecho::query()->where('fuente', 'declarado')->firstOrFail();
        $this->assertSame(['cancelado', 'compromiso'], [$hecho->datos['estado_ejecucion'], $hecho->datos['base_cobro']]);
        $this->assertSame('28', $this->calcular()['totales'][0]['monto']);
        $this->postJson("/api/finanzas/cargos/{$cargo}/aprobar")->assertOk();
        $this->capturar(['declarado'], true);
        $this->assertSame(1, Hecho::query()->count(), 'Aprobar y conciliar de nuevo no duplican la cantidad.');
        $this->getJson('/api/finanzas/hechos?mes=2026-11&fuente=declarado')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/finanzas/cargos/{$cargo}/historial")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_cada_cargo_usa_solo_su_concepto_y_la_captura_no_los_aplica_a_otras_operaciones(): void
    {
        $otro = $this->postJson('/api/finanzas/servicios', [
            'nombre' => 'Inspección comprometida', 'fuente' => 'declarado', 'aplicacion' => 'declarado', 'unidad_libre' => 'inspección',
            'vigente_desde' => '2026-11-01', 'dia_desde' => 1,
        ])->assertCreated()->json('data.clave');
        $this->precio(null, $otro, 'cantidad', '1000', '2026-11-01');
        $cargo = $this->postJson('/api/finanzas/cargos', $this->datos())->assertCreated()->json('data.id');
        $this->postJson("/api/finanzas/cargos/{$cargo}/aprobar")->assertOk();
        $this->assertCount(1, $this->calcular()['clientes'][0]['lineas']);
        $this->assertSame('28', $this->calcular()['totales'][0]['monto']);
        $this->postJson('/api/finanzas/cargos', $this->datos(['servicio' => 'repaletizaje']))->assertUnprocessable()->assertJsonValidationErrors('servicio');
    }

    public function test_anular_el_cargo_es_una_decision_economica_con_historial_y_respeta_el_cierre(): void
    {
        $cargo = $this->postJson('/api/finanzas/cargos', $this->datos())->assertCreated()->json('data.id');
        $this->postJson("/api/finanzas/cargos/{$cargo}/aprobar")->assertOk();
        $cierre = app(GestorCierres::class)->cerrar('2026-11', false, $this->administrador);
        $this->postJson("/api/finanzas/cargos/{$cargo}/anular", ['motivo' => 'Se dejó sin efecto el acuerdo comercial.'])->assertOk();
        $this->assertSame([], $this->calcular()['totales']);
        $congelado = app(LibroPrefactura::class)->mes(Periodo::mes('2026-11'));
        $this->assertSame('28', $congelado['totales'][0]['monto']);
        $this->assertSame('-28', app(RevisionDiferencias::class)->calcular($cierre)['resumen']['montos'][0]['monto']);
        $this->assertSame(2, Hecho::query()->count());
        $this->assertSame(Hecho::ESTADO_ANULADO, Hecho::query()->where('vigente', true)->firstOrFail()->estado);
    }

    public function test_si_la_captura_esta_ocupada_la_aprobacion_se_revierte_completa(): void
    {
        $cargo = $this->postJson('/api/finanzas/cargos', $this->datos())->assertCreated()->json('data.id');
        $this->conCapturaEnOtraSesion(fn () => $this->postJson("/api/finanzas/cargos/{$cargo}/aprobar")->assertUnprocessable());
        $this->assertNull(CargoAcordado::query()->findOrFail($cargo)->aprobado_at);
        $this->assertSame(0, Hecho::query()->count());
        $this->getJson("/api/finanzas/cargos/{$cargo}/historial")->assertJsonCount(1, 'data');
    }

    public function test_consulta_no_aprueba_cargos_y_los_borradores_anulados_no_generan_hechos(): void
    {
        $cargo = $this->postJson('/api/finanzas/cargos', $this->datos())->assertCreated()->json('data.id');
        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/finanzas/cargos?mes=2026-11')->assertOk();
        $this->postJson("/api/finanzas/cargos/{$cargo}/aprobar")->assertForbidden();
        $this->actingAs($this->administrador, 'sanctum')->postJson("/api/finanzas/cargos/{$cargo}/anular", ['motivo' => 'Borrador creado por error.'])->assertOk();
        $this->capturar(['declarado'], true);
        $this->assertSame(0, Hecho::query()->count());
        $this->postJson("/api/finanzas/cargos/{$cargo}/aprobar")->assertUnprocessable();
    }

    public function test_un_cargo_futuro_espera_su_fecha_y_finalizar_el_catalogo_no_revoca_un_acuerdo_aprobado(): void
    {
        $aprobado = $this->postJson('/api/finanzas/cargos', $this->datos(['fecha' => '2026-12-10']))->assertCreated()->json('data.id');
        $borrador = $this->postJson('/api/finanzas/cargos', $this->datos(['fecha' => '2026-12-12']))->assertCreated()->json('data.id');
        $this->postJson("/api/finanzas/cargos/{$aprobado}/aprobar")->assertOk();
        $this->postJson("/api/finanzas/servicios/{$this->servicio}/finalizar", ['vigente_hasta' => '2026-12-05', 'motivo' => 'Se dejan de ofrecer nuevas reservas.'])->assertOk();
        $this->postJson("/api/finanzas/cargos/{$borrador}/aprobar")->assertUnprocessable();
        $calculadora = app(CalculadoraPrefactura::class);
        $this->assertSame([], $calculadora->calcular(Periodo::mes('2026-12'))['totales']);
        $this->travelTo(CarbonImmutable::parse('2026-12-11 12:00', 'America/Santiago'));
        $this->assertSame('28', $calculadora->calcular(Periodo::mes('2026-12'))['totales'][0]['monto']);
    }

    private function datos(array $cambios = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'servicio' => $this->servicio, 'cliente_id' => $this->cliente,
            'fecha' => '2026-11-03', 'cantidad' => '8', 'base_cobro' => 'compromiso', 'estado_ejecucion' => 'cancelado',
            'respaldo' => 'Orden comercial OC-123: reserva de cuadrilla.'], ...$cambios];
    }

    private function calcular(): array
    {
        return app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'));
    }
}
