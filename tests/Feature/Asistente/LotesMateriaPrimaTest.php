<?php

namespace Tests\Feature\Asistente;

use App\Enums\ContenidoCamara;
use App\Models\AsignacionCamaraLoteMateriaPrima;
use App\Models\Camara;
use App\Models\EntregaFrutaProceso;
use App\Models\LoteMateriaPrima;
use App\Services\Asistente\Consultas\LotesMateriaPrima;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Tema «Lotes de materia prima»: por estado, como el panel, y los más antiguos sin entregar a proceso. */
class LotesMateriaPrimaTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 12:00', 'America/Santiago'));
        $this->prepararAsistente();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_por_estado_cuenta_lotes_y_kilos_sin_anulados(): void
    {
        $csg = $this->csg('104512');
        $this->loteEn('asignado_camara', $csg, 1, 1000);
        $this->loteEn('asignado_camara', $csg, 2, 500);
        $this->loteEn('hidrocooler_retenido', $csg, 3, 300);
        $this->loteEn('anulado', $csg, 4, 900);

        $resultado = $this->vista('por-estado');

        $this->assertSame([
            ['estado' => 'Retenido por Hidrocooler', 'lotes' => 1, 'kilos' => 300.0, 'bins' => 100],
            ['estado' => 'Asignado a cámara', 'lotes' => 2, 'kilos' => 1500.0, 'bins' => 200],
        ], $resultado->filas);
        $this->assertSame(3, $resultado->cifras[0]['valor']);
    }

    public function test_por_estado_muestra_bins_por_entregar_de_una_entrega_parcial(): void
    {
        $lote = $this->loteEn('entrega_parcial_proceso', $this->csg('104512'), 1, 1000);
        $this->entregar($lote, 30);
        $this->entregar($lote, 10, anulada: true);

        $this->assertSame([['estado' => 'Entrega parcial a Packing', 'lotes' => 1, 'kilos' => 1000.0, 'bins' => 70]], $this->vista('por-estado')->filas);
    }

    public function test_antiguedad_ordena_por_ingreso_y_excluye_borradores_y_entregados(): void
    {
        $csg = $this->csg('104512');
        $nuevo = $this->loteEn('disponible_proceso', $csg, 1, 800, ingreso: now()->subDay());
        $viejo = $this->loteEn('asignado_camara', $csg, 2, 1200, ingreso: now()->subDays(4));
        $this->loteEn('borrador', $csg, 3, 400, ingreso: now()->subDays(9));
        $this->loteEn('entregado_proceso', $csg, 4, 400, ingreso: now()->subDays(9));
        $this->asignarCamara($viejo, 'MP-1');

        $filas = $this->vista('antiguedad')->filas;

        $this->assertSame([$viejo->numero_lote, $nuevo->numero_lote], array_column($filas, 'lote'));
        $this->assertSame(['lote' => $viejo->numero_lote, 'cliente' => 'Exportadora Andina', 'csg' => '104512', 'estado' => 'Asignado a cámara',
            'camara' => 'MP-1', 'dias' => 4, 'bins' => 100, 'kilos' => 1200.0], $filas[0]);
    }

    public function test_antiguedad_filtra_por_csg(): void
    {
        $this->loteEn('asignado_camara', $this->csg('104512'), 1, 1000);
        $otro = $this->csg('200300');
        $this->loteEn('asignado_camara', $otro, 2, 500);

        $this->assertSame(['200300'], array_column($this->vista('antiguedad', ['csg_id' => $otro->id])->filas, 'csg'));
    }

    public function test_sin_vista_rige_por_estado(): void
    {
        $this->assertSame('por-estado', app(LotesMateriaPrima::class)->vistaPorDefecto());
    }

    private function vista(string $vista, array $datos = []): ResultadoConsulta
    {
        return app(LotesMateriaPrima::class)->ejecutar($this->administrador, ParametrosConsulta::desdeArreglo([...$datos, 'vista' => $vista]));
    }

    private function loteEn(string $estado, $csg, int $numero, float $kilos, ?DateTimeInterface $ingreso = null): LoteMateriaPrima
    {
        $recepcion = $this->recepcion('REC-'.$numero, $kilos, ['ingreso_at' => $ingreso ?? now()->subHours(2)]);
        $lote = $this->lote($recepcion, $csg, $numero, $kilos, 'bins');
        $lote->forceFill(['estado' => $estado])->save();

        return $lote;
    }

    private function asignarCamara(LoteMateriaPrima $lote, string $codigo): AsignacionCamaraLoteMateriaPrima
    {
        $camara = Camara::firstOrCreate(['codigo' => $codigo], ['nombre' => "Cámara {$codigo}", 'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima, 'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);

        return AsignacionCamaraLoteMateriaPrima::firstOrCreate(['lote_materia_prima_id' => $lote->id], ['operacion_id' => (string) Str::uuid(),
            'camara_id' => $camara->id, 'asignado_por_user_id' => $this->administrador->id, 'asignado_at' => now()]);
    }

    private function entregar(LoteMateriaPrima $lote, int $bins, bool $anulada = false): void
    {
        $asignacion = $this->asignarCamara($lote, 'MP-1');
        EntregaFrutaProceso::create(['operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'lote_materia_prima_id' => $lote->id,
            'asignacion_camara_lote_id' => $asignacion->id, 'camara_id' => $asignacion->camara_id, 'cantidad_envases' => $bins,
            'saldo_anterior' => 100, 'saldo_posterior' => 100 - $bins, 'linea_proceso' => 'L1', 'turno' => 'A', 'numero_orden' => 'OP-1',
            'entregado_por_user_id' => $this->administrador->id, 'entregado_at' => now(),
            'anulado_at' => $anulada ? now() : null, 'anulado_por_user_id' => $anulada ? $this->administrador->id : null]);
    }
}
