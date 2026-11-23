<?php

namespace Tests\Feature\Asistente;

use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\Temporada;
use App\Services\Asistente\ReconocedorEntidades;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconocedorEntidadesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 10:00', 'America/Santiago'));
        config(['app.operational_timezone' => 'America/Santiago']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Una jornada va de 07:00 a 06:59 del día siguiente (10:00 UTC en horario de verano de Santiago). */
    public function test_reconoce_hoy_ayer_y_esta_semana_como_jornadas(): void
    {
        $hoy = $this->reconocer('tiempos de romana hoy');
        $this->assertRango('2026-11-22 10:00:00', '2026-11-23 10:00:00', $hoy);
        $this->assertTrue($hoy['periodo_explicito']);

        $this->assertRango('2026-11-21 10:00:00', '2026-11-22 10:00:00', $this->reconocer('kilos de ayer'));
        $this->assertRango('2026-11-20 10:00:00', '2026-11-21 10:00:00', $this->reconocer('kilos de anteayer'));

        // 22-11-2026 es domingo: la semana parte el lunes 16.
        $this->assertRango('2026-11-16 10:00:00', '2026-11-23 10:00:00', $this->reconocer('cargas de esta semana'));
        $this->assertRango('2026-11-09 10:00:00', '2026-11-16 10:00:00', $this->reconocer('cargas de la semana pasada'));
    }

    public function test_a_las_tres_de_la_madrugada_hoy_sigue_siendo_la_jornada_que_empezo_ayer(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 03:00', 'America/Santiago'));

        $this->assertRango('2026-11-22 10:00:00', '2026-11-23 10:00:00', $this->reconocer('kilos de hoy'));
    }

    public function test_sin_periodo_usa_la_jornada_en_curso_y_no_es_explicito(): void
    {
        $resultado = $this->reconocer('kilos del 31-02');

        $this->assertRango('2026-11-22 10:00:00', '2026-11-23 10:00:00', $resultado);
        $this->assertFalse($resultado['periodo_explicito']);
    }

    public function test_reconoce_rango_entre_dos_fechas(): void
    {
        $this->assertRango('2026-11-18 10:00:00', '2026-11-20 10:00:00', $this->reconocer('kilos del 18/11 al 19/11/2026'));
    }

    public function test_reconoce_dia_de_la_semana(): void
    {
        // Domingo 22: el jueves es el 19; «el domingo pasado» es el 15.
        $this->assertRango('2026-11-19 10:00:00', '2026-11-20 10:00:00', $this->reconocer('cargas del jueves'));
        $this->assertRango('2026-11-15 10:00:00', '2026-11-16 10:00:00', $this->reconocer('cargas del domingo pasado'));
    }

    public function test_anoche_es_el_turno_b_que_termino_si_ahora_es_de_dia(): void
    {
        $resultado = $this->reconocer('cuantos camiones entraron anoche');

        $this->assertSame('B', $resultado['turno']);
        $this->assertRango('2026-11-21 22:00:00', '2026-11-22 10:00:00', $resultado);
    }

    public function test_anoche_durante_el_turno_b_es_la_noche_en_curso(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 23:00', 'America/Santiago'));

        $this->assertRango('2026-11-22 22:00:00', '2026-11-23 10:00:00', $this->reconocer('kilos de anoche'));
    }

    public function test_reconoce_turnos_nombrados(): void
    {
        $this->assertSame('B', $this->reconocer('kilos del turno b de ayer')['turno']);
        $this->assertRango('2026-11-21 22:00:00', '2026-11-22 10:00:00', $this->reconocer('kilos del turno b de ayer'));
        $this->assertSame('A', $this->reconocer('tiempos de romana esta mañana')['turno']);
        $this->assertSame('B', $this->reconocer('cargas del turno noche')['turno']);
        $this->assertNull($this->reconocer('kilos de esta semana turno b')['turno'], 'Un turno solo aplica a una jornada.');
    }

    public function test_la_temporada_en_curso_parte_en_su_primera_jornada(): void
    {
        $activa = Temporada::query()->where('activa', true)->firstOrFail();
        $activa->forceFill(['fecha_inicio' => '2026-10-01'])->save();

        $resultado = $this->reconocer('kilos de la temporada');

        $this->assertRango('2026-10-01 10:00:00', '2026-11-23 10:00:00', $resultado);
        $this->assertSame('Temporada en curso (desde 01-10-2026)', $resultado['periodo']);
        $this->assertFalse($resultado['temporada_anterior']);
    }

    public function test_reconoce_guias_de_envases_y_numero_de_guia(): void
    {
        $this->assertSame('GDE-2611-0163', $this->reconocer('donde esta la gde-2611-0163')['codigo']);
        $resultado = $this->reconocer('busca la guia 2345');
        $this->assertSame('2345', $resultado['codigo']);
        $this->assertContains('2345', $resultado['consumidas']);
    }

    public function test_reconoce_codigos_rec_car_pf(): void
    {
        $this->assertSame('REC-2611-0108', $this->reconocer('dame la rec-2611-0108')['codigo']);
        $this->assertSame('CAR-000059', $this->reconocer('estado de CAR-000059')['codigo']);
        $this->assertSame('PF-014', $this->reconocer('proceso PF-014')['codigo']);
    }

    public function test_reconoce_cliente_por_nombre_con_un_error_de_tipeo(): void
    {
        $cliente = Cliente::create(['codigo' => 'DIS', 'nombre' => 'Exportadora Andina', 'activo' => true]);

        $this->assertSame($cliente->id, $this->reconocer('kilos de exportadora andina')['cliente_id']);
        $this->assertSame($cliente->id, $this->reconocer('cajas de andinna')['cliente_id']);
        $this->assertNull($this->reconocer('cajas de mañana')['cliente_id']);
        $this->assertSame($cliente->id, $this->reconocer('cajas de DIS hoy')['cliente_id']);
    }

    public function test_reconoce_especie_de_la_temporada_activa(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);

        $resultado = $this->reconocer('neto por tote de cerezas');

        $this->assertSame($especie->id, $resultado['especie_id']);
        $this->assertSame('Cereza', $resultado['especie']);
    }

    public function test_temporada_pasada(): void
    {
        $this->assertTrue($this->reconocer('kilos de la temporada pasada')['temporada_anterior']);
        $this->assertFalse($this->reconocer('kilos de hoy')['temporada_anterior']);
    }

    private function assertRango(string $desde, string $hasta, array $resultado): void
    {
        $this->assertSame($desde, $resultado['desde']->toDateTimeString());
        $this->assertSame($hasta, $resultado['hasta']->toDateTimeString());
    }

    private function reconocer(string $texto): array
    {
        return app(ReconocedorEntidades::class)->reconocer($texto);
    }
}
