<?php

namespace Tests\Feature\Asistente;

use App\Models\MovimientoEnvase;
use App\Services\Asistente\Consultas\ExistenciaEnvases;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Tema «Envases en planta»: la existencia física por propiedad y los propios entregados. */
class ExistenciaEnvasesTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00', 'America/Santiago'));
        $this->prepararAsistente();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_por_propiedad_coincide_con_la_existencia_de_la_oficina(): void
    {
        $this->movimiento('recepcion_compra', 50, 'propia', existencia: 1, cuenta: 0);
        $this->movimiento('recepcion_arriendo', 30, 'arrendada', existencia: 1, cuenta: 0);
        $this->movimiento('recepcion_fruta', 20, 'cliente', existencia: 1, cuenta: 1);
        $existencia = collect(app(ServicioCuentaCorrienteEnvases::class)->existencia($this->temporada->id)['tipos'])->firstWhere('tipo_envase', 'bins');

        $fila = $this->vista('por-propiedad')->filas[0];

        $this->assertSame(['envase' => 'Bins plástico', 'propios' => 50, 'clientes' => 20, 'arrendados' => 30, 'total' => 100,
            'reservado' => $existencia['reservado']['total'], 'disponible' => $existencia['disponible']], $fila);
        $this->assertSame($existencia['en_planta']['total'], $fila['total']);
    }

    public function test_propios_entregados_por_cliente(): void
    {
        $this->movimiento('recepcion_compra', 50, 'propia', existencia: 1, cuenta: 0);
        $this->movimiento('despacho_cliente', 12, 'propia', existencia: -1, cuenta: -1);

        $resultado = $this->vista('propios-entregados', ['cliente_id' => $this->cliente->id]);

        $this->assertSame([['cliente' => 'Exportadora Andina', 'envase' => 'Bins plástico', 'cantidad' => 12]], $resultado->filas);
    }

    public function test_sin_vista_rige_por_propiedad(): void
    {
        $this->assertSame('por-propiedad', app(ExistenciaEnvases::class)->vistaPorDefecto());
    }

    private function vista(string $vista, array $datos = []): ResultadoConsulta
    {
        return app(ExistenciaEnvases::class)->ejecutar($this->administrador, ParametrosConsulta::desdeArreglo([...$datos, 'vista' => $vista]));
    }

    private function movimiento(string $tipo, int $cantidad, string $propiedad, int $existencia, int $cuenta): void
    {
        MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id,
            'documento_tipo' => 'prueba', 'documento_id' => (string) Str::uuid(), 'numero_documento' => 'DOC-'.Str::random(4),
            'tipo_movimiento' => $tipo, 'tipo_envase' => 'bins', 'cantidad' => $cantidad, 'signo_cuenta' => $cuenta, 'signo_existencia' => $existencia,
            'propiedad' => $propiedad, 'ocurrido_at' => now(), 'estado_revision' => 'pendiente', 'creado_por_user_id' => $this->administrador->id,
        ]);
    }
}
