<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CatalogoValidacionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reutiliza_el_catalogo_por_version_sin_consultar_sus_tablas(): void
    {
        [$temporada, $token] = $this->contexto();
        $etag = "validacion-catalogo-{$temporada->id}-{$temporada->version_catalogo}";

        $this->withToken($token)
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertHeader('ETag', "\"{$etag}\"")
            ->assertJsonPath('temporada.id', $temporada->id)
            ->assertJsonCount(1, 'categorias')
            ->assertJsonCount(1, 'articulos')
            ->assertJsonCount(1, 'origenes')
            ->assertJsonCount(1, 'combinaciones');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withToken($token)
            ->withHeader('If-None-Match', "\"{$etag}\"")
            ->get('/api/validacion/catalogos')
            ->assertStatus(Response::HTTP_NOT_MODIFIED)
            ->assertContent('');

        $consultas = mb_strtolower(collect(DB::getQueryLog())
            ->pluck('query')
            ->implode(' '));
        DB::disableQueryLog();

        foreach ([
            'articulos_validacion',
            'categorias_validacion',
            'origenes_validacion',
            'combinaciones_validacion',
        ] as $tablaCatalogo) {
            $this->assertStringNotContainsString($tablaCatalogo, $consultas);
        }

        Temporada::query()->whereKey($temporada->id)->increment('version_catalogo');
        $versionActualizada = (int) $temporada->version_catalogo + 1;

        $this->withToken($token)
            ->withHeader('If-None-Match', "\"{$etag}\"")
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertHeader(
                'ETag',
                "\"validacion-catalogo-{$temporada->id}-{$versionActualizada}\"",
            );
    }

    /**
     * @return array{Temporada, string}
     */
    private function contexto(): array
    {
        $temporadaId = (string) Str::uuid();
        $articuloId = (string) Str::uuid();
        $categoriaId = (string) Str::uuid();
        $origenId = (string) Str::uuid();
        $ahora = now();

        $this->desactivarTemporadasDePrueba();
        DB::table('temporadas')->insert([
            'id' => $temporadaId,
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
            'version_catalogo' => 7,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('articulos_validacion')->insert([
            'id' => $articuloId,
            'temporada_id' => $temporadaId,
            'especie' => 'Cereza',
            'variedad' => 'Santina',
            'calibre' => '2J',
            'envase' => '5 kg',
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('categorias_validacion')->insert([
            'id' => $categoriaId,
            'temporada_id' => $temporadaId,
            'nombre' => 'Exportación',
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('origenes_validacion')->insert([
            'id' => $origenId,
            'temporada_id' => $temporadaId,
            'cliente' => 'DIS',
            'marca' => 'ATLAS',
            'csg' => '105410',
            'predio' => 'OLM',
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('combinaciones_validacion')->insert([
            'id' => (string) Str::uuid(),
            'temporada_id' => $temporadaId,
            'articulo_validacion_id' => $articuloId,
            'origen_validacion_id' => $origenId,
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $usuario = User::factory()->create([
            'rol' => RolUsuario::Validador,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'VAL-CATALOGO-01',
            'nombre' => 'PDA catálogo',
            'plataforma' => 'android',
            'activo' => true,
        ]);
        $token = $usuario
            ->crearTokenParaDispositivo($dispositivo, 'test-catalogo-condicional')
            ->plainTextToken;

        return [Temporada::query()->findOrFail($temporadaId), $token];
    }
}
