<?php

namespace Tests\Feature\Api;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class ConcurrenciaTemporalRespuestaTest extends TestCase
{
    public function test_un_deadlock_agotado_devuelve_503_sin_exponer_sql(): void
    {
        Route::get('/api/prueba-deadlock', static fn () => throw new QueryException('mysql',
            'select secreto from maniobras_operacionales for update', [],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')))
            ->middleware('api');
        $this->getJson('/api/prueba-deadlock')->assertStatus(503)->assertHeader('Retry-After', '1')
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('codigo', 'concurrencia_temporal')->assertDontSee('SQLSTATE')->assertDontSee('secreto');
    }

    public function test_un_error_sql_distinto_no_se_disfraza_de_conflicto_temporal(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        Route::post('/api/prueba-sql/{folio}', static fn () => throw new QueryException('mysql',
            'select inexistente', [], new PDOException('Unknown column')))->middleware('api');

        $respuesta = $this->withHeader('X-Request-Id', 'externo')
            ->postJson('/api/prueba-sql/123456?patente=ab1234', ['datos' => 'contenido de prueba']);
        $referencia = $respuesta->headers->get('X-Request-Id');

        $this->assertNotNull($referencia);
        $this->assertNotSame('externo', $referencia);
        $respuesta->assertStatus(500)->assertJsonPath('referencia', $referencia)
            ->assertJsonPath('message', "Ocurrió un error en el servidor. Referencia {$referencia}.")
            ->assertDontSee('inexistente');
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $mensaje, array $contexto) use ($referencia): bool {
            return $mensaje === 'Solicitud API fallida'
                && $contexto === [
                    'referencia' => $referencia, 'metodo' => 'POST', 'ruta' => 'api/prueba-sql/{folio}',
                    'estado' => 500, 'duracion_ms' => $contexto['duracion_ms'],
                ]
                && is_int($contexto['duracion_ms']);
        });
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $mensaje, array $contexto): bool => $contexto['referencia'] === $referencia);
    }
}
