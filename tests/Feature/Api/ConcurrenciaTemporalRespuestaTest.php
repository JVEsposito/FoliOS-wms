<?php

namespace Tests\Feature\Api;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class ConcurrenciaTemporalRespuestaTest extends TestCase
{
    public function test_un_deadlock_agotado_devuelve_503_sin_exponer_sql(): void
    {
        Route::get('/api/prueba-deadlock', static fn () => throw new QueryException('mysql',
            'select secreto from maniobras_operacionales for update', [],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')));
        $this->getJson('/api/prueba-deadlock')->assertStatus(503)->assertHeader('Retry-After', '1')
            ->assertJsonPath('codigo', 'concurrencia_temporal')->assertDontSee('SQLSTATE')->assertDontSee('secreto');
    }

    public function test_un_error_sql_distinto_no_se_disfraza_de_conflicto_temporal(): void
    {
        Route::get('/api/prueba-sql', static fn () => throw new QueryException('mysql', 'select inexistente', [], new PDOException('Unknown column')));
        $this->getJson('/api/prueba-sql')->assertStatus(500);
    }
}
