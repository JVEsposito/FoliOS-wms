<?php

namespace Tests\Feature\Asistente;

use App\Models\Cliente;
use App\Services\Asistente\CatalogoConsultasAsistente;
use App\Services\Asistente\InterpreteReglasAsistente;
use App\Services\Asistente\LineaEntendido;
use App\Services\Asistente\ParametrosConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/**
 * Banco de 60 preguntas reales de la planta (resources/asistente/banco-preguntas.json).
 * La regla es una sola: ninguna respuesta falsa. El intérprete ejecuta la consulta que el
 * banco espera hoy, o pregunta; nunca otra consulta, otro periodo ni un filtro callado.
 * Al sumar una consulta nueva, se actualiza responde_hoy en el banco.
 */
class BancoPreguntasAsistenteTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    private const BANCO = __DIR__.'/../../../resources/asistente/banco-preguntas.json';

    protected function setUp(): void
    {
        parent::setUp();
        // Domingo 22-11-2026 a las 10:00, en el turno A.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 10:00', 'America/Santiago'));
        $this->prepararAsistente();
        Cliente::create(['codigo' => 'VAL', 'nombre' => 'Valle Alto', 'activo' => true]);
        $this->csg('104512');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function preguntas(): array
    {
        $banco = json_decode((string) file_get_contents(self::BANCO), true, flags: JSON_THROW_ON_ERROR);
        $casos = [];
        foreach ($banco['preguntas'] as $pregunta) {
            $casos[sprintf('%02d %s', $pregunta['n'], $pregunta['pregunta'])] = [$pregunta];
        }

        return $casos;
    }

    public function test_el_banco_tiene_60_preguntas_numeradas_y_consultas_que_existen(): void
    {
        $banco = json_decode((string) file_get_contents(self::BANCO), true, flags: JSON_THROW_ON_ERROR)['preguntas'];
        $claves = array_map(fn ($consulta) => $consulta->clave(), app(CatalogoConsultasAsistente::class)->todas());

        $this->assertCount(60, $banco);
        $this->assertSame(range(1, 60), array_column($banco, 'n'));
        foreach ($banco as $pregunta) {
            if ($pregunta['responde_hoy'] !== null) {
                $this->assertContains($pregunta['responde_hoy'], $claves, "Pregunta {$pregunta['n']}");
            }
        }
    }

    /** @param  array<string, mixed>  $caso */
    #[DataProvider('preguntas')]
    public function test_ninguna_respuesta_falsa(array $caso): void
    {
        $interpretacion = app(InterpreteReglasAsistente::class)->interpretar($this->administrador, $caso['pregunta']);
        $consulta = $interpretacion['consulta'];
        $mensaje = "Pregunta {$caso['n']}: «{$caso['pregunta']}»";

        $this->assertSame($caso['responde_hoy'], $consulta?->clave(), $mensaje);
        if ($consulta === null) {
            return;
        }

        $parametros = $interpretacion['parametros'];
        $entendido = app(LineaEntendido::class)->construir($consulta, $parametros);
        if (array_key_exists('turno', $caso)) {
            $this->assertSame($caso['turno'], $parametros->turno, $mensaje);
        }
        if (array_key_exists('periodo_explicito', $caso)) {
            $this->assertSame($caso['periodo_explicito'], $parametros->periodoExplicito, $mensaje);
        }
        if (($caso['periodo'] ?? null) === 'temporada_en_curso') {
            $this->assertStringStartsWith('Temporada en curso', (string) $parametros->etiquetaPeriodo, $mensaje);
        }
        if (array_key_exists('codigo', $caso)) {
            $this->assertSame($caso['codigo'], $parametros->codigo, $mensaje);
        }
        foreach ($caso['filtros'] ?? [] as $filtro) {
            $this->assertNotNull($this->idFiltro($parametros, $filtro), "{$mensaje}: falta el filtro {$filtro}");
            $this->assertContains($filtro, $consulta->filtros(), $mensaje);
        }
        // Un filtro reconocido que la consulta no aplica siempre se avisa en «Entendí».
        $esperados = $caso['no_aplicados'] ?? [];
        $this->assertCount(count($esperados), $entendido['no_aplicados'], $mensaje);
        foreach (['cliente', 'csg', 'especie'] as $filtro) {
            $reconocido = $this->idFiltro($parametros, $filtro) !== null;
            $aplicado = in_array($filtro, $consulta->filtros(), true);
            $this->assertSame($reconocido && ! $aplicado, in_array($filtro, $esperados, true), "{$mensaje}: aviso de {$filtro}");
        }
    }

    private function idFiltro(ParametrosConsulta $parametros, string $filtro): ?string
    {
        return match ($filtro) {
            'cliente' => $parametros->clienteId,
            'csg' => $parametros->csgId,
            'especie' => $parametros->especieId,
            default => null,
        };
    }
}
