<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Validacion\TransferenciaCatalogoValidacion;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CatalogoValidacionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostico_mide_paginas_del_catalogo_sin_modificar_la_proyeccion(): void
    {
        [$temporada] = $this->contexto();
        $antes = DB::table('combinaciones_validacion')->where('temporada_id', $temporada->id)->count();
        $this->assertSame(0, Artisan::call('validacion:medir-catalogo', ['--paginas' => 1, '--json' => true]));
        $medida = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(7, $medida['version']);
        $this->assertSame($antes, $medida['conteos']['combinaciones']);
        $this->assertCount(4, $medida['lectura']);
        foreach ($medida['lectura'] as $coleccion) {
            $this->assertSame(1, $coleccion['paginas']);
            $this->assertSame(1, $coleccion['filas']);
            $this->assertTrue($coleccion['completa']);
            $this->assertGreaterThan(0, $coleccion['max_bytes_pagina']);
            $this->assertGreaterThan(0, $coleccion['max_memoria_php_bytes']);
        }
        $this->assertSame($antes, DB::table('combinaciones_validacion')->where('temporada_id', $temporada->id)->count());
        $this->assertSame(7, $temporada->fresh()->version_catalogo);
        $this->assertSame(2, Artisan::call('validacion:medir-catalogo', ['--paginas' => -1]));
    }

    public function test_indice_paginado_no_lee_la_proyeccion_ni_espera_al_publicador(): void
    {
        [$temporada, $token] = $this->contexto();
        $bloqueo = Cache::lock('validacion-transferencia-v1:'.$temporada->id.':publicar', 120);
        $this->assertTrue($bloqueo->get());
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $indice = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=paginas-v1')
                ->assertOk()->assertJsonPath('formato', 'paginas-v1')->assertJsonPath('tamano_pagina', 1000)
                ->assertJsonPath('temporada.version_catalogo', 7)->assertJsonCount(4, 'colecciones');
            $this->withToken($token)->withHeader('If-None-Match', $indice->headers->get('ETag'))
                ->getJson('/api/validacion/catalogos?formato=paginas-v1')->assertStatus(304)->assertContent('');
            $consultas = mb_strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' '));
            foreach (['articulos_validacion', 'categorias_validacion', 'origenes_validacion', 'combinaciones_validacion', 'cache_locks'] as $tabla) {
                $this->assertStringNotContainsString($tabla, $consultas);
            }
            $this->assertFalse(Cache::lock('validacion-transferencia-v1:'.$temporada->id.':publicar', 120)->get());
        } finally {
            DB::disableQueryLog();
            $bloqueo->release();
        }
    }

    public function test_paginas_limitadas_conservan_todas_las_filas_sin_offset_ni_preparar_el_catalogo_completo(): void
    {
        [$temporada, $token] = $this->contexto();
        $origen = DB::table('origenes_validacion')->where('temporada_id', $temporada->id)->value('id');
        $articulos = $combinaciones = [];
        for ($i = 1; $i <= 2501; $i++) {
            $id = sprintf('aa000000-0000-4000-8000-%012d', $i);
            $articulos[] = ['id' => $id, 'temporada_id' => $temporada->id, 'especie' => 'Cereza', 'variedad' => 'Santina', 'calibre' => '2J', 'envase' => '5 kg', 'activo' => true];
            $combinaciones[] = ['id' => sprintf('bb000000-0000-4000-8000-%012d', $i), 'temporada_id' => $temporada->id, 'articulo_validacion_id' => $id, 'origen_validacion_id' => $origen, 'activo' => true];
        }
        foreach (array_chunk($articulos, 500) as $lote) {
            DB::table('articulos_validacion')->insert($lote);
        }
        foreach (array_chunk($combinaciones, 500) as $lote) {
            DB::table('combinaciones_validacion')->insert($lote);
        }
        $bloqueo = Cache::lock('validacion-transferencia-v1:'.$temporada->id.':publicar', 120);
        $this->assertTrue($bloqueo->get());
        try {
            foreach (['articulos', 'combinaciones'] as $coleccion) {
                $ids = [];
                $cursor = null;
                $paginas = 0;
                do {
                    DB::flushQueryLog();
                    DB::enableQueryLog();
                    $ruta = '/api/validacion/catalogos/paginas/'.$temporada->id.'/7/'.$coleccion.($cursor ? '?desde='.$cursor : '');
                    $pagina = $this->withToken($token)->getJson($ruta)->assertOk()->assertJsonPath('desde', $cursor)
                        ->assertJsonPath('version', 7)->assertJsonPath('coleccion', $coleccion);
                    $consultas = mb_strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' '));
                    DB::disableQueryLog();
                    $this->assertStringNotContainsString(' offset ', $consultas);
                    $this->assertStringNotContainsString('for share', $consultas);
                    $this->assertStringContainsString('limit 1001', $consultas);
                    $this->assertLessThanOrEqual(1000, count($pagina->json('filas')));
                    $this->assertSame(hash('sha256', $pagina->getContent()), $pagina->headers->get('X-Catalog-Target'));
                    $ids = [...$ids, ...array_column($pagina->json('filas'), 'id')];
                    $cursor = $pagina->json('siguiente');
                    $paginas++;
                } while ($cursor !== null);
                $this->assertSame(3, $paginas);
                $this->assertCount(2502, array_unique($ids));
                $esperados = DB::table($coleccion.'_validacion')->where('temporada_id', $temporada->id)->orderBy('id')->pluck('id')->all();
                $this->assertSame($esperados, $ids);
            }
        } finally {
            DB::disableQueryLog();
            $bloqueo->release();
        }
    }

    public function test_paginas_rechazan_otra_version_cursores_invalidos_y_relaciones_retiradas(): void
    {
        [$temporada, $token] = $this->contexto();
        $ruta = '/api/validacion/catalogos/paginas/'.$temporada->id.'/7/';
        $this->getJson($ruta.'articulos')->assertUnauthorized();
        $this->withToken($token)->getJson($ruta.'articulos?desde[]=x')->assertUnprocessable();
        $this->withToken($token)->getJson($ruta.'tabla-no-permitida')->assertNotFound();
        $this->withToken($token)->getJson($ruta.'origenes')->assertOk()->assertJsonPath('filas.0.variedad_ids', null);
        $this->withToken($token)->getJson($ruta.'categorias')->assertOk()->assertJsonPath('filas.0.nombre', 'Exportación');
        DB::table('articulos_validacion')->where('temporada_id', $temporada->id)->update(['activo' => false]);
        $temporada->increment('version_catalogo');
        $this->withToken($token)->getJson($ruta.'combinaciones')->assertConflict();
        $this->withToken($token)->getJson(str_replace('/7/', '/8/', $ruta).'articulos')->assertOk()->assertJsonCount(0, 'filas')->assertJsonPath('siguiente', null);
        $this->withToken($token)->getJson(str_replace('/7/', '/8/', $ruta).'combinaciones')->assertOk()->assertJsonCount(0, 'filas');
    }

    public function test_fragmentos_conservan_ids_y_diccionarios_y_una_edicion_reutiliza_lo_que_no_cambio(): void
    {
        [$temporada, $token] = $this->contexto();
        $manifiesto = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
            ->assertOk()->assertJsonPath('formato', 'fragmentos-v1')->assertJsonCount(4, 'fragmentos')->json();
        $originales = collect($manifiesto['fragmentos'])->keyBy('coleccion');
        foreach ($manifiesto['fragmentos'] as $fragmento) {
            $respuesta = $this->withToken($token)->getJson($this->urlFragmento($temporada, $fragmento['hash']))->assertOk();
            $this->assertSame($fragmento['hash'], hash('sha256', $respuesta->getContent()));
            $this->assertSame($fragmento['bytes'], strlen($respuesta->getContent()));
            $datos = $respuesta->json();
            $this->assertSame($fragmento['filas'], count($datos['filas']));
            $tabla = ['categorias' => 'categorias_validacion', 'articulos' => 'articulos_validacion',
                'origenes' => 'origenes_validacion', 'combinaciones' => 'combinaciones_validacion'][$fragmento['coleccion']];
            $this->assertSame(DB::table($tabla)->where('temporada_id', $temporada->id)->value('id'),
                $datos['textos'][$datos['filas'][0][0]]);
            if ($fragmento['coleccion'] === 'articulos') {
                $this->assertSame('5 kg', $datos['textos'][$datos['filas'][0][array_search('envase', $datos['columnas'])]]);
            }
        }
        DB::table('articulos_validacion')->where('temporada_id', $temporada->id)->update(['envase' => 'Nuevo envase']);
        $temporada->increment('version_catalogo');
        $nuevo = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk()->json();
        foreach ($nuevo['fragmentos'] as $fragmento) {
            if ($fragmento['coleccion'] === 'articulos') {
                $this->assertNotSame($originales['articulos']['hash'], $fragmento['hash']);
            } else {
                $this->assertSame($originales[$fragmento['coleccion']]['hash'], $fragmento['hash']);
            }
        }
        // Una PDA que comenzó antes de editar puede terminar su snapshot coherente.
        $temporada->decrement('version_catalogo');
        $this->withToken($token)->getJson($this->urlFragmento($temporada, $originales['articulos']['hash']))->assertOk();
    }

    public function test_manifiesto_condicional_y_sus_fragmentos_no_releen_la_proyeccion(): void
    {
        [$temporada, $token] = $this->contexto();
        $primero = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk();
        $this->withToken($token)->getJson($this->urlFragmento($temporada, $primero->json('fragmentos.0.hash')))->assertOk();
        $this->withToken($token)->withHeader('If-None-Match', $primero->headers->get('ETag'))
            ->get('/api/validacion/catalogos?formato=fragmentos-v1')->assertStatus(304)->assertContent('');
        $consultas = mb_strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' '));
        DB::disableQueryLog();
        foreach (['articulos_validacion', 'categorias_validacion', 'origenes_validacion', 'combinaciones_validacion'] as $tabla) {
            $this->assertStringNotContainsString($tabla, $consultas);
        }
        // La representación antigua mantiene su ETag y contrato propios.
        $this->withToken($token)->withHeader('If-None-Match', $primero->headers->get('ETag'))
            ->getJson('/api/validacion/catalogos')->assertOk()->assertJsonCount(1, 'articulos');
    }

    public function test_retiros_cambios_de_version_y_cache_danada_no_publican_una_mezcla(): void
    {
        [$temporada, $token] = $this->contexto();
        $primero = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk()->json();
        $hash = $primero['fragmentos'][0]['hash'];
        Cache::put('validacion-fragmento-v1:'.$hash, '{incompleto', 60);
        $this->withToken($token)->getJson($this->urlFragmento($temporada, $hash))->assertStatus(409);
        $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk();
        $this->withToken($token)->getJson($this->urlFragmento($temporada, $hash))->assertOk();
        DB::table('articulos_validacion')->where('temporada_id', $temporada->id)->update(['activo' => false]);
        $temporada->increment('version_catalogo');
        $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk()
            ->assertJsonPath('cantidades.articulos', 0)->assertJsonPath('cantidades.combinaciones', 0);
        // Una parte de otra versión no puede incorporarse a la nueva.
        $articulo = collect($primero['fragmentos'])->firstWhere('coleccion', 'articulos');
        $this->withToken($token)->getJson($this->urlFragmento($temporada, $articulo['hash']))->assertStatus(409);
    }

    public function test_preparacion_concurrente_no_bloquea_el_worker_y_retira_versiones_antiguas(): void
    {
        [$temporada, $token] = $this->contexto();
        $bloqueo = Cache::lock('validacion-transferencia-v1:'.$temporada->id.':publicar', 120);
        $this->assertTrue($bloqueo->get());
        $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
            ->assertOk()->assertHeader('X-Catalog-Transfer', 'full-fallback')
            ->assertHeader('ETag', '"validacion-catalogo-'.$temporada->id.'-'.$temporada->version_catalogo.'"')
            ->assertJsonPath('temporada.version_catalogo', $temporada->version_catalogo)->assertJsonCount(1, 'articulos');
        $this->assertFalse(Cache::lock('validacion-transferencia-v1:'.$temporada->id.':publicar', 120)->get());
        $bloqueo->release();
        $inicial = clone $temporada;
        $hash = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk()->json('fragmentos.0.hash');
        for ($i = 0; $i < 3; $i++) {
            $temporada->increment('version_catalogo');
            $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk();
        }
        $this->withToken($token)->getJson($this->urlFragmento($inicial, $hash))->assertStatus(409);
        $this->withToken($token)->getJson($this->urlFragmento($temporada, $hash))->assertOk();
    }

    public function test_un_fallo_de_escritura_del_cache_no_publica_un_manifiesto_incompleto(): void
    {
        [$temporada, $token] = $this->contexto();
        Cache::extend('fallo-catalogo', fn () => new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                return str_starts_with($key, 'validacion-fragmento-v1:') ? false : parent::put($key, $value, $seconds);
            }
        });
        config(['cache.default' => 'fallo-catalogo', 'cache.stores.fallo-catalogo' => ['driver' => 'fallo-catalogo']]);
        $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
            ->assertOk()->assertHeader('X-Catalog-Transfer', 'full-fallback')->assertJsonCount(1, 'articulos');
        $this->assertNull(Cache::get('validacion-transferencia-v1:'.$temporada->id.':'.$temporada->version_catalogo));
    }

    public function test_tres_equipos_reciben_v418_con_cache_database_ocupado_sin_quedar_en_v417(): void
    {
        [$temporada, $token] = $this->contexto();
        config(['cache.default' => 'database']);
        $temporada->update(['version_catalogo' => 417]);
        $anterior = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
            ->assertOk()->assertJsonPath('formato', 'fragmentos-v1');
        DB::table('articulos_validacion')->where('temporada_id', $temporada->id)->update(['envase' => 'Envase corregido']);
        DB::table('categorias_validacion')->insert(['id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'nombre' => 'Categoría nueva', 'activo' => true]);
        $temporada->increment('version_catalogo');
        $nombreBloqueo = 'validacion-transferencia-v1:'.$temporada->id.':publicar';
        $publicador = Cache::lock($nombreBloqueo, 120);
        $this->assertTrue($publicador->get());
        try {
            for ($equipo = 0; $equipo < 3; $equipo++) {
                $respuesta = $this->withToken($token)->withHeader('If-None-Match', $anterior->headers->get('ETag'))
                    ->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
                    ->assertOk()->assertHeader('X-Catalog-Transfer', 'full-fallback')
                    ->assertJsonPath('temporada.version_catalogo', 418)->assertJsonPath('articulos.0.envase', 'Envase corregido')
                    ->assertJsonCount(2, 'categorias')->assertJsonCount(1, 'combinaciones');
                $this->assertArrayNotHasKey('fragmentos', $respuesta->json());
                $this->assertFalse(Cache::lock($nombreBloqueo, 120)->get());
            }
            $this->assertNull(Cache::get('validacion-transferencia-v1:'.$temporada->id.':418'));
        } finally {
            $publicador->release();
        }
        // La recuperación no rompe ni elimina el snapshot anterior.
        $this->withToken($token)->getJson('/api/validacion/catalogos/fragmentos/'.$temporada->id.'/417/'.$anterior->json('fragmentos.0.hash'))->assertOk();
        $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
            ->assertOk()->assertJsonPath('formato', 'fragmentos-v1')->assertJsonPath('temporada.version_catalogo', 418);
        $this->withToken($token)->withHeader('If-None-Match', '"validacion-partes-v1-'.$temporada->id.'-418"')
            ->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertStatus(304)->assertContent('');
    }

    public function test_cache_database_con_cero_filas_afectadas_reutiliza_partes_y_publica_la_version_nueva(): void
    {
        [$temporada, $token] = $this->contexto();
        $this->freezeTime();
        config(['cache.default' => 'database']);
        $inicial = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
            ->assertOk()->assertJsonPath('formato', 'fragmentos-v1')->json();
        $sinCambios = collect($inicial['fragmentos'])->firstWhere('coleccion', 'categorias');
        $clave = 'validacion-fragmento-v1:'.$sinCambios['hash'];
        $cuerpo = Cache::get($clave);
        $this->assertIsString($cuerpo);
        $reescritura = Cache::put($clave, $cuerpo, now()->addDay());
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertFalse($reescritura, 'MySQL no modifica una fila con el mismo valor y vencimiento.');
        }
        DB::table('articulos_validacion')->where('temporada_id', $temporada->id)->update(['envase' => 'Envase nuevo']);
        $temporada->increment('version_catalogo');
        $nuevo = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
            ->assertOk()->assertJsonPath('formato', 'fragmentos-v1')->assertJsonPath('temporada.version_catalogo', 8);
        $this->assertNull($nuevo->headers->get('X-Catalog-Transfer'));
        $this->assertSame($sinCambios['hash'], collect($nuevo->json('fragmentos'))->firstWhere('coleccion', 'categorias')['hash']);
        $this->assertNotSame(collect($inicial['fragmentos'])->firstWhere('coleccion', 'articulos')['hash'], collect($nuevo->json('fragmentos'))->firstWhere('coleccion', 'articulos')['hash']);
        foreach ($nuevo->json('fragmentos') as $fragmento) {
            $this->withToken($token)->getJson($this->urlFragmento($temporada, $fragmento['hash']))->assertOk();
        }
    }

    public function test_la_recuperacion_relee_la_version_bajo_bloqueo_y_no_usa_el_encabezado_antiguo(): void
    {
        [$temporada, $token] = $this->contexto();
        $this->mock(TransferenciaCatalogoValidacion::class)->shouldReceive('manifiesto')->once()
            ->andReturnUsing(function () use ($temporada): never {
                $temporada->increment('version_catalogo');
                DB::table('articulos_validacion')->where('temporada_id', $temporada->id)->update(['envase' => 'Última edición']);
                throw new HttpException(503, 'El publicador está ocupado.');
            });
        $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')
            ->assertOk()->assertJsonPath('temporada.version_catalogo', 8)->assertJsonPath('articulos.0.envase', 'Última edición')
            ->assertHeader('ETag', '"validacion-catalogo-'.$temporada->id.'-8"');
    }

    public function test_no_convierte_un_conflicto_de_version_ni_un_error_de_autorizacion_en_recuperacion(): void
    {
        [, $token] = $this->contexto();
        $this->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertUnauthorized();
        $this->mock(TransferenciaCatalogoValidacion::class)->shouldReceive('manifiesto')->once()
            ->andThrow(new HttpException(409, 'El catálogo cambió mientras se preparaba.'));
        $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertStatus(409);
    }

    private function urlFragmento(Temporada $temporada, string $hash): string
    {
        return "/api/validacion/catalogos/fragmentos/{$temporada->id}/{$temporada->version_catalogo}/{$hash}";
    }

    public function test_actualiza_filas_dentro_del_fragmento_y_recupera_la_parte_completa_si_la_base_vencio(): void
    {
        [$temporada, $token] = $this->contexto();
        $filas = [];
        for ($i = 1; $i <= 80; $i++) {
            $filas[] = ['id' => sprintf('aa000000-0000-4000-8000-%012d', $i), 'temporada_id' => $temporada->id,
                'especie' => 'Cereza', 'variedad' => 'Santina', 'calibre' => '2J', 'envase' => '5 kg', 'activo' => true];
        }
        DB::table('articulos_validacion')->insert($filas);
        $anterior = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk()->json();
        $base = collect($anterior['fragmentos'])->first(fn ($f) => $f['coleccion'] === 'articulos' && $f['grupo'] === 10);
        $id = $filas[0]['id'];
        DB::table('articulos_validacion')->where('id', $id)->update(['envase' => "Envase \"nuevo\" / exportación\u{2028}"]);
        DB::table('articulos_validacion')->where('id', $filas[1]['id'])->update(['activo' => false]);
        $temporada->increment('version_catalogo');
        $actual = $this->withToken($token)->getJson('/api/validacion/catalogos?formato=fragmentos-v1')->assertOk()->json();
        $destino = collect($actual['fragmentos'])->first(fn ($f) => $f['coleccion'] === 'articulos' && $f['grupo'] === 10);
        $delta = $this->withToken($token)->getJson($this->urlFragmento($temporada, $destino['hash']).'?base='.$base['hash'])
            ->assertOk()->assertJsonPath('formato', 'delta-fragmentos-v1')->assertJsonPath('base', $base['hash'])
            ->assertJsonPath('destino', $destino['hash'])->assertJsonPath('cambios.0.id', $id)
            ->assertJsonPath('eliminados.0', $filas[1]['id'])->assertJsonCount(1, 'cambios');
        $this->assertLessThan($destino['bytes'], strlen($delta->getContent()));
        $this->assertSame('"'.hash('sha256', $delta->getContent()).'"', $delta->headers->get('ETag'));
        Cache::forget('validacion-fragmento-v1:'.$base['hash']);
        $completo = $this->withToken($token)->getJson($this->urlFragmento($temporada, $destino['hash']).'?base='.$base['hash'])
            ->assertOk()->assertJsonPath('formato', 'fragmentos-v1');
        $this->assertSame($destino['hash'], hash('sha256', $completo->getContent()));
        $this->withToken($token)->getJson($this->urlFragmento($temporada, $destino['hash']).'?base[]=invalida')->assertStatus(422);
    }

    public function test_entrega_solo_los_campos_operativos_y_permite_actualizar_desde_v218_a_v227(): void
    {
        [$temporada, $token] = $this->contexto();
        $temporada->update(['version_catalogo' => 227]);
        $respuesta = $this->withToken($token)->withHeader('If-None-Match', "\"validacion-catalogo-{$temporada->id}-218\"")
            ->getJson('/api/validacion/catalogos')->assertOk()->assertJsonPath('temporada.version_catalogo', 227);
        foreach (['categorias', 'articulos', 'origenes', 'combinaciones'] as $coleccion) {
            $this->assertArrayNotHasKey('created_at', $respuesta->json($coleccion.'.0'));
            $this->assertArrayNotHasKey('updated_at', $respuesta->json($coleccion.'.0'));
        }
        $this->assertSame($respuesta->json('articulos.0.id'), $respuesta->json('combinaciones.0.articulo_validacion_id'));
        $this->assertSame($respuesta->json('origenes.0.id'), $respuesta->json('combinaciones.0.origen_validacion_id'));
    }

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
