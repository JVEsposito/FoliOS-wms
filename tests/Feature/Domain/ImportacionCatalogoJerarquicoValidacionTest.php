<?php

namespace Tests\Feature\Domain;

use App\Models\ArticuloValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\EnvaseValidacion;
use App\Models\ImportacionValidacion;
use App\Models\OrigenValidacion;
use App\Models\User;
use App\Services\Validacion\ServicioImportacionValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportacionCatalogoJerarquicoValidacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirma_una_planilla_en_jerarquias_y_conserva_la_proyeccion_pda(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2028',
            'nombre' => 'Temporada 2028',
            'activa' => true,
        ]);
        $usuario = User::factory()->create();
        $clienteGlobal = Cliente::create([
            'codigo' => 'OL-001',
            'nombre' => 'Los Olmos',
            'activo' => true,
        ]);
        ClienteValidacion::create([
            'cliente_id' => $clienteGlobal->id,
            'temporada_id' => $temporada->id,
            'nombre' => 'Los Olmos',
            'codigo_externo' => 'OL-001',
            'activo' => true,
        ]);
        $importacion = ImportacionValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre_archivo' => 'catalogo.csv',
            'tipo_archivo' => 'csv',
            'checksum' => hash('sha256', 'catalogo-2028'),
            'estado' => 'borrador',
            'resumen' => [
                'filas_validas' => 1,
                'filas_con_error' => 0,
                'combinaciones_detectadas' => 1,
            ],
            'filas' => [[
                'fila' => 2,
                'categoria' => 'Exportación',
                'especie' => 'Cereza',
                'variedad' => 'Santina',
                'calibre' => 'XL',
                'envase' => '5 KG',
                'cliente' => 'Los Olmos',
                'cliente_id' => $clienteGlobal->id,
                'marca' => 'Olmos Roja',
                'csg' => 'CSG-001',
                'predio' => 'Predio Norte',
                'codigo_articulo' => 'ART-001',
                'codigo_origen' => 'ORI-001',
                'codigo_combinacion' => 'COM-001',
            ]],
            'errores' => null,
            'creado_por_user_id' => $usuario->id,
        ]);

        $resultado = app(ServicioImportacionValidacion::class)->confirmar($importacion, $usuario);

        $this->assertSame('confirmada', $resultado->estado);
        $this->assertDatabaseHas('categorias_validacion', ['nombre' => 'Exportación']);
        $this->assertDatabaseHas('especies_validacion', ['nombre' => 'Cereza']);
        $this->assertDatabaseHas('variedades_validacion', ['nombre' => 'Santina']);
        $this->assertDatabaseHas('calibres_validacion', ['nombre' => 'XL']);
        $this->assertDatabaseHas('envases_validacion', ['nombre' => '5 KG']);
        $this->assertDatabaseHas('clientes_validacion', ['nombre' => 'Los Olmos']);
        $this->assertDatabaseHas('clientes', ['nombre' => 'Los Olmos', 'activo' => true]);
        $this->assertNotNull(ClienteValidacion::query()->where('nombre', 'Los Olmos')->value('cliente_id'));
        $clienteTemporada = ClienteValidacion::query()->where('nombre', 'Los Olmos')->sole();
        $this->assertSame(
            $clienteTemporada->id,
            EnvaseValidacion::query()->where('nombre', '5 KG')->sole()->cliente_validacion_id,
        );
        $this->assertSame(1, Cliente::query()->where('nombre', 'Los Olmos')->count());
        $this->assertDatabaseHas('marcas_validacion', ['nombre' => 'Olmos Roja']);
        $this->assertDatabaseHas('csg_validacion', [
            'codigo' => 'CSG-001',
            'predio' => 'Predio Norte',
        ]);
        $this->assertDatabaseCount('csg_variedades_validacion', 1);
        $this->assertDatabaseHas('articulos_validacion', [
            'codigo_externo' => 'ART-001',
            'activo' => true,
        ]);
        $this->assertDatabaseHas('origenes_validacion', [
            'codigo_externo' => 'ORI-001',
            'activo' => true,
        ]);
        $this->assertDatabaseHas('combinaciones_validacion', [
            'codigo_externo' => 'COM-001',
            'activo' => true,
        ]);

        $combinacion = CombinacionValidacion::where('temporada_id', $temporada->id)->sole();
        $combinacion->update(['activo' => false]);
        ArticuloValidacion::findOrFail($combinacion->articulo_validacion_id)->update(['activo' => false]);
        OrigenValidacion::findOrFail($combinacion->origen_validacion_id)->update(['activo' => false]);

        $nuevaImportacion = $importacion->replicate();
        $nuevaImportacion->checksum = hash('sha256', 'catalogo-2028-sin-codigos');
        $nuevaImportacion->filas = [[
            ...$importacion->filas[0],
            'codigo_articulo' => null,
            'codigo_origen' => null,
            'codigo_combinacion' => null,
        ]];
        $nuevaImportacion->save();
        $resultado = app(ServicioImportacionValidacion::class)->confirmar($nuevaImportacion, $usuario);

        $this->assertSame(0, $resultado->resumen['creados']['articulos']);
        $this->assertSame(0, $resultado->resumen['creados']['origenes']);
        $this->assertSame(0, $resultado->resumen['creados']['combinaciones']);
        $this->assertDatabaseHas('combinaciones_validacion', [
            'id' => $combinacion->id,
            'codigo_externo' => 'COM-001',
            'activo' => true,
        ]);
    }
}
