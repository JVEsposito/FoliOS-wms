<?php

namespace Tests\Feature\Asistente;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\Folio;
use App\Models\OrigenValidacion;
use App\Models\Pais;
use App\Models\RetencionLoteSag;
use App\Services\Asistente\Consultas\RetencionesSag;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Tema «Lotes retenidos SAG»: los seguimientos vigentes y los pallets en planta con cajas de esos lotes. */
class RetencionesSagTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 12:00', 'America/Santiago'));
        $this->prepararAsistente();
        ClienteValidacion::create(['temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id, 'nombre' => $this->cliente->nombre, 'activo' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_vigentes_muestra_estado_destinos_y_pallets_en_planta(): void
    {
        $this->retencion('10056', 'retenido', todos: true);
        $this->folio('10056', 30);
        $this->folio('10056', 12);
        $this->folio('10056', 50, estado: 'despachado');

        $resultado = $this->vista('vigentes');

        $this->assertSame([['lote' => '10056', 'cliente' => 'Exportadora Andina', 'estado' => 'Retenido', 'destinos' => 'Todos los destinos',
            'deteccion' => '2026-11-20', 'pallets' => 2, 'cajas' => 42]], $resultado->filas);
        $this->assertSame(1, $resultado->cifras[0]['valor']);
    }

    public function test_vigentes_no_incluye_liberados_y_muestra_liberado_con_restricciones(): void
    {
        $pais = Pais::where('activo', true)->firstOrFail();
        $this->retencion('20001', 'retenido', destinos: [['tipo' => 'pais', 'id' => $pais->id, 'codigo' => $pais->iso_alpha2, 'nombre' => $pais->nombre_es]]);
        $this->retencion('20002', 'con_observaciones');
        $this->retencion('20003', 'liberado');

        $filas = collect($this->vista('vigentes')->filas)->keyBy('lote');

        $this->assertSame(['20001', '20002'], $filas->pluck('lote')->sort()->values()->all());
        $this->assertSame(['Liberado con restricciones', $pais->nombre_es], [$filas['20001']['estado'], $filas['20001']['destinos']]);
        $this->assertSame(['Con observaciones', 'Sin restricciones de destino'], [$filas['20002']['estado'], $filas['20002']['destinos']]);
    }

    public function test_folios_afectados_excluye_despachados_y_lotes_liberados(): void
    {
        $this->retencion('10056', 'retenido', todos: true);
        $this->retencion('30000', 'liberado');
        $enPlanta = $this->folio('10056', 30);
        $this->folio('10056', 50, estado: 'despachado');
        $this->folio('30000', 20);

        $filas = $this->vista('folios-afectados')->filas;

        $this->assertSame([['folio' => $enPlanta->numero_folio, 'lote' => '10056', 'cliente' => 'Exportadora Andina', 'estado' => 'Retenido',
            'cajas' => 30, 'ubicacion' => null, 'carga' => null]], $filas);
    }

    public function test_sin_el_modulo_no_se_ofrece(): void
    {
        $supervisor = $this->usuarioAsistente(RolUsuario::SupervisorFrio);

        $this->assertFalse(app(RetencionesSag::class)->puedeEjecutar($supervisor));
        $this->assertTrue(app(RetencionesSag::class)->puedeEjecutar($this->administrador));
    }

    public function test_sin_vista_por_defecto(): void
    {
        $this->assertNull(app(RetencionesSag::class)->vistaPorDefecto());
    }

    private function vista(string $vista, array $datos = []): ResultadoConsulta
    {
        return app(RetencionesSag::class)->ejecutar($this->administrador, ParametrosConsulta::desdeArreglo([...$datos, 'vista' => $vista]));
    }

    private function retencion(string $lote, string $estado, bool $todos = false, array $destinos = [], ?Cliente $cliente = null): RetencionLoteSag
    {
        return RetencionLoteSag::create(['temporada_id' => $this->temporada->id, 'cliente_id' => ($cliente ?? $this->cliente)->id,
            'numero_lote' => $lote, 'estado' => $estado, 'fecha_deteccion' => '2026-11-20', 'observacion' => 'Detección de la contraparte.',
            'todos_destinos' => $todos, 'destinos' => $destinos, 'definitivo' => false,
            'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id]);
    }

    private function folio(string $lote, int $cajas, string $estado = 'disponible'): Folio
    {
        $catalogo = ClienteValidacion::where('temporada_id', $this->temporada->id)->where('cliente_id', $this->cliente->id)->firstOrFail();
        $origen = OrigenValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'cliente_validacion_id' => $catalogo->id,
            'cliente' => $this->cliente->nombre, 'marca' => 'Marca SAG', 'csg' => '104512'], ['activo' => true]);

        return Folio::create(['temporada_id' => $this->temporada->id, 'numero_folio' => 'F-'.Str::random(8),
            'tipo_bulto' => 'pallet', 'estado_operacional' => $estado, 'activo' => true, 'fecha_ingreso' => now(),
            'exportadora' => $this->cliente->nombre, 'datos_externos' => ['cantidad_cajas' => 120, 'composicion' => [[
                'origen_validacion_id' => $origen->id, 'csg' => '104512', 'lote_materia_prima' => $lote,
                'proceso_packing' => 'PROCESO-SAG', 'cantidad_cajas' => $cajas,
            ]]]]);
    }
}
