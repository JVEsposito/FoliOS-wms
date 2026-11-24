<?php

namespace Tests\Feature\Asistente;

use App\Services\Asistente\InterpreteReglasAsistente;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/**
 * El intérprete sin IA responde solo cuando está seguro. Las preguntas de abajo salieron
 * de la revisión de la fase A: antes daban una cifra de otra consulta.
 */
class InterpreteReglasAsistenteTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 10:00', 'America/Santiago'));
        $this->prepararAsistente();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function preguntasClaras(): array
    {
        return [
            'kilos que llegaron' => ['cuantos kilos llegaron ayer', 'kilos-recepciones'],
            'cajas en existencia' => ['cuantas cajas hay en camara', 'cajas-pt'],
            'formatos' => ['que formatos de cajas tenemos', 'formatos-pt'],
            'kilos por productor' => ['kilos por productor esta semana', 'kilos-por-csg'],
            'demora de cargas' => ['cuanto se demoraron las cargas hoy', 'tiempos-cargas'],
            'camiones esperando' => ['camiones esperando destare', 'tiempos-romana'],
            'taras de la temporada' => ['revisa las taras de la temporada', 'revisar-taras'],
            'repaletizajes' => ['repaletizajes del turno b de ayer', 'repaletizajes'],
            'saldos' => ['que saldos quedan', 'saldos-pt'],
            'consumos' => ['consumos de materiales de las ot de esta semana', 'consumos-ot'],
        ];
    }

    #[DataProvider('preguntasClaras')]
    public function test_pregunta_clara_elige_su_consulta(string $texto, string $esperada): void
    {
        $this->assertSame($esperada, $this->interpretar($texto)['consulta']?->clave(), $texto);
    }

    /** @return array<string, array{string, string, string}> */
    public static function preguntasDeLotes(): array
    {
        return [
            'lotes retenidos sag' => ['lotes retenidos sag?', 'retenciones-sag', 'vigentes'],
            'lotes con observaciones' => ['que lotes sag tienen observaciones', 'retenciones-sag', 'vigentes'],
            'pallets con fruta retenida' => ['que pallets tienen fruta retenida', 'retenciones-sag', 'folios-afectados'],
            'donde estan los pallets retenidos' => ['donde estan los pallets retenidos', 'retenciones-sag', 'folios-afectados'],
            'retenidos en hidrocooler' => ['lotes retenidos en hidrocooler', 'lotes-mp', 'por-estado'],
            'disponibles para proceso' => ['cuantos lotes hay disponibles para proceso', 'lotes-mp', 'por-estado'],
            'resguardo sin proceso' => ['que lotes siguen en resguardo sin proceso', 'lotes-mp', 'antiguedad'],
            'mas antiguos' => ['lotes mas antiguos', 'lotes-mp', 'antiguedad'],
        ];
    }

    #[DataProvider('preguntasDeLotes')]
    public function test_lotes_y_sag_eligen_tema_y_vista(string $texto, string $tema, string $vista): void
    {
        $interpretacion = $this->interpretar($texto);

        $this->assertSame([$tema, $vista], [$interpretacion['consulta']?->clave(), $interpretacion['parametros']->vista], $texto);
    }

    /** @return array<string, array{string}> */
    public static function preguntasDudosas(): array
    {
        return [
            'solo tiempos' => ['tiempos de hoy'],
            'dos consultas a la vez' => ['cajas y kilos'],
            'nada que ver' => ['como esta el clima'],
            'pregunta de otro dominio' => ['cuantos trabajadores vinieron hoy'],
            'ordenes de proceso por turno' => ['que lotes entran a proceso en el turno a'],
            'lotes sag liberados' => ['que lotes sag se liberaron'],
        ];
    }

    #[DataProvider('preguntasDudosas')]
    public function test_pregunta_dudosa_no_ejecuta_nada(string $texto): void
    {
        $this->assertNull($this->interpretar($texto)['consulta'], $texto);
    }

    public function test_kilos_por_dia_pide_una_fila_por_jornada(): void
    {
        $interpretacion = $this->interpretar('kilos recibidos por dia esta semana');

        $this->assertSame('kilos-recepciones', $interpretacion['consulta']?->clave());
        $this->assertSame('dia', $interpretacion['parametros']->agrupar);
    }

    public function test_palabras_del_nombre_del_cliente_no_cuentan_como_palabras_clave(): void
    {
        // «Andina» no debe empujar ninguna consulta; «cajas» decide.
        $this->assertSame('cajas-pt', $this->interpretar('cajas de exportadora andina')['consulta']?->clave());
    }

    public function test_sugerencias_solo_entre_consultas_parecidas(): void
    {
        $claves = array_map(fn ($consulta) => $consulta->clave(), $this->interpretar('tiempos de hoy')['alternativas']);
        sort($claves);

        $this->assertSame(['tiempos-cargas', 'tiempos-prefrio', 'tiempos-romana'], $claves);
        $this->assertSame([], $this->interpretar('como esta el clima')['alternativas']);
    }

    private function interpretar(string $texto): array
    {
        return app(InterpreteReglasAsistente::class)->interpretar($this->administrador, $texto);
    }
}
