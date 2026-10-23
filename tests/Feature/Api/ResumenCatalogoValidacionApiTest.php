<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\CombinacionValidacion;
use App\Models\ImportacionValidacion;
use App\Models\OrigenValidacion;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResumenCatalogoValidacionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_resumen_no_consulta_la_proyeccion_ni_descarga_filas_de_importacion(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            ...$this->vigenciaProductiva(), 'codigo' => 'RESUMEN', 'nombre' => 'Resumen',
        ]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $articulo = ArticuloValidacion::create([
            'temporada_id' => $temporada->id, 'especie' => 'Cereza', 'variedad' => 'Santina',
            'calibre' => '2J', 'envase' => 'Caja', 'activo' => true,
        ]);
        $origen = OrigenValidacion::create([
            'temporada_id' => $temporada->id, 'cliente' => 'Cliente', 'marca' => 'Marca', 'csg' => '00123', 'activo' => true,
        ]);
        $combinacion = CombinacionValidacion::create([
            'temporada_id' => $temporada->id, 'articulo_validacion_id' => $articulo->id,
            'origen_validacion_id' => $origen->id, 'activo' => true,
        ]);
        $importacion = ImportacionValidacion::create([
            'temporada_id' => $temporada->id, 'nombre_archivo' => 'temporada.csv', 'tipo_archivo' => 'csv',
            'checksum' => hash('sha256', 'fixture'), 'estado' => 'borrador', 'creado_por_user_id' => $administrador->id,
            'resumen' => ['filas_validas' => 25000],
            'filas' => [['dato' => str_repeat('FILA_QUE_NO_DEBE_DESCARGARSE', 10000)]],
            'errores' => [['mensaje' => 'ERROR_QUE_NO_DEBE_DESCARGARSE']],
        ]);
        $consultas = [];
        DB::listen(function ($evento) use (&$consultas): void {
            $consultas[] = $evento->sql;
        });

        $respuesta = $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/validacion?resumen=1')
            ->assertOk()
            ->assertJsonPath('temporada.id', $temporada->id)
            ->assertJsonPath('importaciones.0.nombre_archivo', 'temporada.csv')
            ->assertJsonPath('importaciones.0.resumen.filas_validas', 25000)
            ->assertJsonMissingPath('combinaciones')
            ->assertJsonMissingPath('articulos')
            ->assertJsonMissingPath('origenes')
            ->assertJsonMissingPath('importaciones.0.filas')
            ->assertJsonMissingPath('importaciones.0.errores');
        $this->assertLessThan(20000, strlen($respuesta->getContent()));
        foreach ($consultas as $sql) {
            foreach (['articulos_validacion', 'origenes_validacion', 'combinaciones_validacion'] as $tabla) {
                $this->assertStringNotContainsString($tabla, $sql);
            }
        }

        // Los consumidores anteriores siguen recibiendo el contrato completo.
        $this->getJson('/api/administracion/validacion')
            ->assertOk()->assertJsonPath('combinaciones.0.id', $combinacion->id)
            ->assertJsonPath('importaciones.0.id', $importacion->id)
            ->assertJsonPath('importaciones.0.filas.0.dato', $importacion->filas[0]['dato'])
            ->assertJsonPath('importaciones.0.errores.0.mensaje', 'ERROR_QUE_NO_DEBE_DESCARGARSE');
    }

    public function test_consulta_completa_carga_solo_diez_importaciones_recientes_y_conserva_su_orden(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            ...$this->vigenciaProductiva(), 'codigo' => 'COMPLETA', 'nombre' => 'Completa',
        ]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $esperadas = [];
        foreach (range(1, 12) as $numero) {
            $importacion = new ImportacionValidacion([
                'temporada_id' => $temporada->id, 'nombre_archivo' => "importacion-{$numero}.csv", 'tipo_archivo' => 'csv',
                'checksum' => hash('sha256', (string) $numero), 'estado' => 'borrador',
                'creado_por_user_id' => $administrador->id, 'resumen' => [],
                'filas' => [['numero' => $numero]], 'errores' => [],
            ]);
            $importacion->created_at = now()->subMinutes(13 - $numero);
            $importacion->save();
            $esperadas[] = $importacion->id;
        }
        $consultas = [];
        DB::listen(function ($evento) use (&$consultas): void {
            if (str_contains($evento->sql, 'importaciones_validacion')) {
                $consultas[] = strtolower($evento->sql);
            }
        });

        $respuesta = $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/validacion')->assertOk()->assertJsonCount(10, 'importaciones');
        $this->assertSame(array_slice(array_reverse($esperadas), 0, 10), array_column($respuesta->json('importaciones'), 'id'));
        $respuesta->assertJsonPath('importaciones.0.filas.0.numero', 12)
            ->assertJsonPath('importaciones.0.creado_por.name', $administrador->name);
        $this->assertCount(2, $consultas);
        $this->assertStringNotContainsString('*', $consultas[0]);
        $this->assertStringContainsString('order by', $consultas[0]);
        $this->assertStringNotContainsString('order by', $consultas[1]);
    }

    public function test_resumen_respeta_temporada_solicitada_y_limita_importaciones(): void
    {
        $activa = $this->crearTemporadaActivaPrueba([
            ...$this->vigenciaProductiva(), 'codigo' => 'ACTIVA', 'nombre' => 'Activa',
        ]);
        $historica = Temporada::create([
            ...$this->vigenciaProductiva(), 'codigo' => 'HIST', 'nombre' => 'Histórica', 'activa' => false,
        ]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        foreach (range(1, 12) as $numero) {
            ImportacionValidacion::create([
                'temporada_id' => $historica->id, 'nombre_archivo' => "historica-{$numero}.csv", 'tipo_archivo' => 'csv',
                'checksum' => hash('sha256', (string) $numero), 'estado' => 'borrador',
                'creado_por_user_id' => $administrador->id, 'resumen' => [], 'filas' => [], 'errores' => [],
            ]);
        }
        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/validacion?resumen=1&temporada_id='.$historica->id)
            ->assertOk()->assertJsonPath('temporada.id', $historica->id)
            ->assertJsonCount(10, 'importaciones')
            ->assertJsonPath('importaciones.0.temporada_id', $historica->id);
        $this->getJson('/api/administracion/validacion?resumen=1')
            ->assertOk()->assertJsonPath('temporada.id', $activa->id)->assertJsonCount(0, 'importaciones');
        $this->getJson('/api/administracion/validacion?resumen=invalido')->assertUnprocessable();
    }

    public function test_resumen_conserva_la_autorizacion_del_catalogo(): void
    {
        $this->getJson('/api/administracion/validacion?resumen=1')->assertUnauthorized();
        $validador = User::factory()->create(['rol' => RolUsuario::Validador]);
        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/administracion/validacion?resumen=1')->assertForbidden();
    }
}
