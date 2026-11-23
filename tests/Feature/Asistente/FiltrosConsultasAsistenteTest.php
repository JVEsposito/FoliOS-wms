<?php

namespace Tests\Feature\Asistente;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\Repaletizaje;
use App\Services\Asistente\Consultas\KilosPorCsg;
use App\Services\Asistente\Consultas\KilosRecepciones;
use App\Services\Asistente\Consultas\Repaletizajes;
use App\Services\Asistente\Consultas\RevisarTaras;
use App\Services\Asistente\Consultas\TiemposCargas;
use App\Services\Asistente\LineaEntendido;
use App\Services\Asistente\ParametrosConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Filtros que la fase A ignoraba: especie, cliente en repas y cargas, CSG en taras y kilos por jornada. */
class FiltrosConsultasAsistenteTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    private EspecieValidacion $cereza;

    private EspecieValidacion $arandano;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00', 'America/Santiago'));
        $this->prepararAsistente();
        $this->cereza = EspecieValidacion::query()->where('temporada_id', $this->temporada->id)->where('nombre', 'Cereza')->firstOrFail();
        $this->arandano = EspecieValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'nombre' => 'Arándano'], ['activo' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_kilos_recibidos_filtra_por_especie(): void
    {
        $this->recepcion('REC-1', 9000, ['especie_validacion_id' => $this->cereza->id]);
        $this->recepcion('REC-2', 4000, ['especie_validacion_id' => $this->arandano->id]);

        $resultado = app(KilosRecepciones::class)->ejecutar($this->administrador, new ParametrosConsulta(especieId: $this->cereza->id));

        $this->assertSame(9000.0, $resultado->cifras[0]['valor']);
    }

    public function test_kilos_por_csg_filtra_por_especie(): void
    {
        $csg = $this->csg('104512');
        $this->lote($this->recepcion('REC-1', 9000), $csg, 1, 9000)->update(['especie_validacion_id' => $this->cereza->id]);
        $this->lote($this->recepcion('REC-2', 4000), $csg, 2, 4000)->update(['especie_validacion_id' => $this->arandano->id]);

        $resultado = app(KilosPorCsg::class)->ejecutar($this->administrador, new ParametrosConsulta(especieId: $this->arandano->id));

        $this->assertEqualsWithDelta(4000.0, (float) $resultado->filas[0]['kilos'], 0.01);
    }

    public function test_kilos_por_jornada_parte_a_las_siete(): void
    {
        // 06:30 del 22 pertenece a la jornada del 21; 08:00 del 22, a la del 22.
        $local = fn (string $hora) => CarbonImmutable::parse($hora, 'America/Santiago')->utc();
        $this->recepcion('REC-1', 3000, ['ingreso_at' => $local('2026-11-22 06:30'), 'salida_at' => $local('2026-11-22 06:50')]);
        $this->recepcion('REC-2', 5000, ['ingreso_at' => $local('2026-11-22 08:00'), 'salida_at' => $local('2026-11-22 08:30')]);
        $parametros = ParametrosConsulta::desdeArreglo(['desde' => '2026-11-21', 'hasta' => '2026-11-22', 'agrupar' => 'dia']);

        $resultado = app(KilosRecepciones::class)->ejecutar($this->administrador, $parametros);

        $this->assertSame(['21-11-2026', '22-11-2026'], array_column($resultado->filas, 'jornada'));
        $this->assertSame([3000.0, 5000.0], array_column($resultado->filas, 'neto'));
        $this->assertStringContainsString('La jornada con más kilos fue el 22-11-2026', $resultado->resumen);
        $this->assertContains('Por jornada', app(LineaEntendido::class)->construir(app(KilosRecepciones::class), $parametros)['fichas']);
    }

    public function test_agrupar_por_dia_se_avisa_donde_no_aplica(): void
    {
        $entendido = app(LineaEntendido::class)->construir(app(TiemposCargas::class), new ParametrosConsulta(agrupar: 'dia'));

        $this->assertSame(['No agrupa por día: muestra el total del periodo.'], $entendido['no_aplicados']);
    }

    public function test_revisar_taras_filtra_por_csg(): void
    {
        $uno = $this->csg('104512');
        $otro = $this->csg('104513');
        // Neto por tote imposible en los dos: solo debe aparecer el del CSG pedido.
        $tote = ['tipo_envase_calculo_neto' => 'totes', 'cantidad_envase_calculo_neto' => 100, 'peso_neto_por_envase' => 7.9];
        $this->lote($this->recepcion('REC-1', 790, $tote), $uno, 1, 790);
        $this->lote($this->recepcion('REC-2', 790, $tote), $otro, 2, 790);
        $netoPorTote = fn ($resultado) => array_values(array_filter(
            array_column($resultado->filas, 'recepcion'),
            fn ($recepcion) => in_array($recepcion, ['REC-1', 'REC-2'], true),
        ));

        $todos = app(RevisarTaras::class)->ejecutar($this->administrador, new ParametrosConsulta);
        $filtrado = app(RevisarTaras::class)->ejecutar($this->administrador, new ParametrosConsulta(csgId: $uno->id));

        $this->assertEqualsCanonicalizing(['REC-1', 'REC-2'], array_unique($netoPorTote($todos)));
        $this->assertSame(['REC-1'], array_values(array_unique($netoPorTote($filtrado))));
    }

    public function test_repaletizajes_filtra_por_cliente_y_usa_la_jornada(): void
    {
        Cliente::create(['codigo' => 'VAL', 'nombre' => 'Valle Alto', 'activo' => true]);
        $this->repa('REPA-1', $this->folio('6000000001', 'ASI'), '2026-11-22');
        $this->repa('REPA-2', $this->folio('6000000002', 'Valle Alto'), '2026-11-22');
        $this->repa('REPA-3', $this->folio('6000000003', 'ASI'), '2026-11-23');

        $hoy = app(Repaletizajes::class)->ejecutar($this->administrador, new ParametrosConsulta);
        $andina = app(Repaletizajes::class)->ejecutar($this->administrador, new ParametrosConsulta(clienteId: $this->cliente->id));

        $this->assertEqualsCanonicalizing(['6000000001', '6000000002'], array_column($hoy->filas, 'folio'), 'La jornada de mañana no entra en hoy.');
        $this->assertSame(['6000000001'], array_column($andina->filas, 'folio'));
    }

    public function test_tiempos_de_cargas_filtra_por_cliente_por_sus_folios(): void
    {
        $this->carga('CAR-1', $this->folio('6000000001', 'ASI'));
        $this->carga('CAR-2', $this->folio('6000000002', 'Otra'));

        $resultado = app(TiemposCargas::class)->ejecutar($this->administrador, new ParametrosConsulta(clienteId: $this->cliente->id));

        $this->assertSame(['CAR-1'], array_column($resultado->filas, 'codigo'));
    }

    private function folio(string $numero, string $exportadora): Folio
    {
        return Folio::create([
            'temporada_id' => $this->temporada->id, 'numero_folio' => $numero, 'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible, 'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado, 'fecha_ingreso' => now()->subHour(),
            'activo' => true, 'variedad' => 'Santina', 'calibre' => '28 mm', 'marca' => 'MARCA', 'exportadora' => $exportadora,
            'origen_sistema' => 'validacion', 'identificador_externo' => (string) Str::uuid(),
            'estado_integracion' => EstadoIntegracionFolio::NoVinculado, 'datos_externos' => ['especie' => 'Cereza', 'cantidad_cajas' => 100],
        ]);
    }

    private function repa(string $codigo, Folio $folio, string $jornada): void
    {
        Repaletizaje::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $codigo), 'codigo' => $codigo,
            'tipo_resultado' => 'pallet', 'estrategia_folio' => 'conservar', 'folio_resultante_id' => $folio->id,
            'folio_conservado_id' => $folio->id, 'cantidad_objetivo' => 100, 'cantidad_resultante' => 100,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado->value, 'campos_mix' => [], 'snapshot' => [],
            'estado' => 'confirmado', 'user_id' => $this->administrador->id, 'confirmado_at' => now(), 'fecha_operacional' => $jornada,
        ]);
    }

    private function carga(string $codigo, Folio $folio): void
    {
        $carga = Carga::create([
            'temporada_id' => $this->temporada->id, 'codigo' => $codigo, 'estado' => 'borrador',
            'creada_por_user_id' => $this->administrador->id, 'actualizada_por_user_id' => $this->administrador->id,
        ]);
        $carga->forceFill(['publicada_at' => now()->subHours(2), 'cerrada_at' => now()->subHour()])->save();
        CargaFolio::create([
            'carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => EstadoCargaFolio::Pendiente,
            'asignado_por_user_id' => $this->administrador->id, 'asignado_at' => now()->subHours(2),
        ]);
    }
}
