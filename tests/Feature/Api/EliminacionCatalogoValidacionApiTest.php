<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\CalibreValidacion;
use App\Models\CategoriaValidacion;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\MarcaValidacion;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EliminacionCatalogoValidacionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_puede_retirar_todos_los_tipos_del_catalogo_sin_borrarlos_fisicamente(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'DEL-2026',
            'nombre' => 'Temporada eliminación',
            'activa' => true,
        ]);
        $cliente = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cliente prueba',
            'activo' => true,
        ]);
        $marca = MarcaValidacion::create([
            'cliente_validacion_id' => $cliente->id,
            'nombre' => 'Marca prueba',
            'activo' => true,
        ]);
        $especie = EspecieValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cereza',
            'activo' => true,
        ]);
        $categoria = CategoriaValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Exportación',
            'activo' => true,
        ]);
        $variedad = VariedadValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'Santina',
            'activo' => true,
        ]);
        $calibre = CalibreValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'XL',
            'activo' => true,
        ]);
        $envase = EnvaseValidacion::create([
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $cliente->id,
            'nombre' => '5 KG',
            'activo' => true,
        ]);
        $csg = CsgValidacion::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'CSG-001',
            'activo' => true,
        ]);
        $versionInicial = $temporada->refresh()->version_catalogo;

        $this->actingAs($administrador, 'sanctum');
        foreach ([
            "/api/administracion/validacion/marcas/{$marca->id}" => $marca,
            "/api/administracion/validacion/categorias/{$categoria->id}" => $categoria,
            "/api/administracion/validacion/especies/{$especie->id}" => $especie,
            "/api/administracion/validacion/variedades/{$variedad->id}" => $variedad,
            "/api/administracion/validacion/calibres/{$calibre->id}" => $calibre,
            "/api/administracion/validacion/envases/{$envase->id}" => $envase,
            "/api/administracion/validacion/csg/{$csg->id}" => $csg,
        ] as $ruta => $modelo) {
            $this->deleteJson($ruta)
                ->assertOk()
                ->assertJsonPath('data.id', $modelo->id)
                ->assertJsonPath('data.activo', false)
                ->assertJsonPath('message', 'Elemento eliminado del catálogo operativo.');

            $this->assertFalse($modelo->refresh()->activo);
        }

        $this->assertSame($versionInicial + 7, $temporada->refresh()->version_catalogo);
    }

    public function test_envase_eliminado_se_puede_encontrar_editar_y_restaurar_sin_duplicar_la_proyeccion(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'PDA-2026',
            'nombre' => 'Temporada proyección',
            'activa' => true,
        ]);
        $cliente = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cliente prueba',
            'activo' => true,
        ]);
        $servicio = app(ServicioCatalogoJerarquicoValidacion::class);
        $servicio->guardarMarca([
            'cliente_validacion_id' => $cliente->id,
            'nombre' => 'Marca prueba',
            'activo' => true,
        ]);
        $especie = $servicio->guardarEspecie([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cereza',
            'activo' => true,
        ]);
        $variedad = $servicio->guardarVariedad([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'Santina',
            'activo' => true,
        ]);
        $servicio->guardarCalibre([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'XL',
            'activo' => true,
        ]);
        $envase = $servicio->guardarEnvase([
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $cliente->id,
            'nombre' => '5 KG',
            'activo' => true,
        ]);
        $servicio->guardarCsg([
            'temporada_id' => $temporada->id,
            'codigo' => 'CSG-PDA',
            'variedad_ids' => [$variedad->id],
            'activo' => true,
        ]);

        $this->assertSame(1, ArticuloValidacion::query()->where('activo', true)->count());
        $this->assertSame(1, CombinacionValidacion::query()->where('activo', true)->count());
        $articulo = ArticuloValidacion::query()->where('activo', true)->sole();
        $combinacion = CombinacionValidacion::query()->where('activo', true)->sole();
        $versionInicial = $temporada->refresh()->version_catalogo;
        $ruta = '/api/administracion/validacion/envases';
        $payload = ['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $cliente->id, 'nombre' => '5 KG', 'activo' => true];

        $this->actingAs($administrador, 'sanctum')
            ->deleteJson("{$ruta}/{$envase->id}")
            ->assertOk()
            ->assertJsonPath('data.activo', false);

        $this->assertDatabaseHas('envases_validacion', [
            'id' => $envase->id,
            'activo' => false,
        ]);
        $this->assertSame(0, ArticuloValidacion::query()->where('activo', true)->count());
        $this->assertSame(0, CombinacionValidacion::query()->where('activo', true)->count());
        $this->assertSame($versionInicial + 1, $temporada->refresh()->version_catalogo);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/validacion/catalogos')
            ->assertOk()
            ->assertJsonCount(0, 'articulos')
            ->assertJsonCount(0, 'combinaciones');

        $this->getJson("/api/administracion/validacion/temporadas/{$temporada->id}/catalogo")
            ->assertOk()
            ->assertJsonPath('especies.0.envases.0.id', $envase->id)
            ->assertJsonPath('especies.0.envases.0.activo', false);
        $this->postJson($ruta, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Ya existe un envase eliminado con ese nombre para el cliente y la especie. En Envases, filtra por Eliminados para editarlo o restaurarlo.');

        $this->putJson("{$ruta}/{$envase->id}", [...$payload, 'activo' => false, 'codigo_externo' => 'CJ-5', 'kilos_netos_por_caja' => 5.2])
            ->assertOk()->assertJsonPath('data.activo', false);
        $this->assertSame(0, ArticuloValidacion::query()->where('activo', true)->count());

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Validador]), 'sanctum')
            ->postJson("{$ruta}/{$envase->id}/restaurar")->assertForbidden();
        $this->actingAs($administrador, 'sanctum');
        $versionAntesDeRestaurar = $temporada->refresh()->version_catalogo;
        for ($intento = 0; $intento < 2; $intento++) {
            $this->postJson("{$ruta}/{$envase->id}/restaurar")->assertOk()
                ->assertJsonPath('data.id', $envase->id)
                ->assertJsonPath('data.activo', true)
                ->assertJsonPath('data.codigo_externo', 'CJ-5')
                ->assertJsonPath('data.kilos_netos_por_caja', '5.2000')
                ->assertJsonPath('publicacion.version_catalogo', $versionAntesDeRestaurar + 1);
        }
        $this->assertSame($articulo->id, ArticuloValidacion::query()->where('activo', true)->sole()->id);
        $this->assertSame($combinacion->id, CombinacionValidacion::query()->where('activo', true)->sole()->id);
        $this->assertDatabaseCount('envases_validacion', 1);

        $this->postJson($ruta, $payload)->assertUnprocessable()
            ->assertJsonPath('message', 'Ese envase ya existe para el cliente y la especie. En Envases, selecciona Todos para encontrarlo y editarlo.');
        $this->deleteJson("{$ruta}/{$envase->id}")->assertOk();
        $this->deleteJson("/api/administracion/validacion/especies/{$especie->id}")->assertOk();
        $this->postJson("{$ruta}/{$envase->id}/restaurar")->assertUnprocessable();
        $this->putJson("{$ruta}/{$envase->id}", $payload)->assertUnprocessable();
        $this->assertFalse($envase->fresh()->activo);
    }
}
