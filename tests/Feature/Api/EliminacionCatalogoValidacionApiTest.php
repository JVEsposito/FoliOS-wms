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
use App\Models\Temporada;
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

    public function test_eliminar_un_envase_despublica_su_proyeccion_pda_y_conserva_trazabilidad(): void
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
        $versionInicial = $temporada->refresh()->version_catalogo;

        $this->actingAs($administrador, 'sanctum')
            ->deleteJson("/api/administracion/validacion/envases/{$envase->id}")
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
    }
}
