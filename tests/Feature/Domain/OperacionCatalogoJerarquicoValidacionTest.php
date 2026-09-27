<?php

namespace Tests\Feature\Domain;

use App\Models\ArticuloValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\MarcaValidacion;
use App\Models\OrigenValidacion;
use App\Models\VariedadValidacion;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperacionCatalogoJerarquicoValidacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_proyecta_la_jerarquia_al_contrato_actual_de_la_pda(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2026',
            'nombre' => 'Temporada 2026',
            'activa' => true,
        ]);
        $servicio = app(ServicioCatalogoJerarquicoValidacion::class);

        $categoria = $servicio->guardarCategoria([
            'temporada_id' => $temporada->id,
            'nombre' => 'Exportación',
            'activo' => true,
        ]);

        $clienteGlobal = Cliente::create([
            'codigo' => 'OL-001',
            'nombre' => 'Los Olmos',
            'activo' => true,
        ]);
        $cliente = ClienteValidacion::create([
            'cliente_id' => $clienteGlobal->id,
            'temporada_id' => $temporada->id,
            'nombre' => 'Los Olmos',
            'codigo_externo' => 'OL-001',
            'activo' => true,
        ]);
        $servicio->guardarMarca([
            'cliente_validacion_id' => $cliente->id,
            'nombre' => 'Olmos Roja',
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
            'nombre' => 'xl',
            'activo' => true,
        ]);
        $servicio->guardarEnvase([
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $cliente->id,
            'nombre' => '5 KG',
            'activo' => true,
        ]);
        $csg = $servicio->guardarCsg([
            'temporada_id' => $temporada->id,
            'codigo' => 'csg-001',
            'predio' => 'Predio Norte',
            'variedad_ids' => [$variedad->id],
            'activo' => true,
        ]);

        $articulo = ArticuloValidacion::query()->sole();
        $origen = OrigenValidacion::query()->sole();
        $combinacion = CombinacionValidacion::query()->sole();

        $this->assertSame($especie->id, $articulo->especie_validacion_id);
        $this->assertSame($temporada->id, $categoria->temporada_id);
        $this->assertSame('XL', $articulo->calibre);
        $this->assertSame($cliente->id, $origen->cliente_validacion_id);
        $this->assertSame($csg->id, $origen->csg_validacion_id);
        $this->assertTrue($articulo->activo);
        $this->assertTrue($origen->activo);
        $this->assertTrue($combinacion->activo);
        $this->assertSame([
            'articulos' => 1,
            'origenes' => 1,
            'combinaciones' => 1,
        ], $servicio->datos($temporada)['proyeccion']);
        $this->assertSame('Exportación', $servicio->datos($temporada)['categorias']->sole()->nombre);
    }

    public function test_un_csg_solo_habilita_las_variedades_declaradas(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2027',
            'nombre' => 'Temporada 2027',
            'activa' => true,
        ]);
        $servicio = app(ServicioCatalogoJerarquicoValidacion::class);

        $cliente = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cliente',
            'activo' => true,
        ]);
        MarcaValidacion::create([
            'cliente_validacion_id' => $cliente->id,
            'nombre' => 'Marca',
            'activo' => true,
        ]);
        $especie = EspecieValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cereza',
            'activo' => true,
        ]);
        $santina = VariedadValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'Santina',
            'activo' => true,
        ]);
        VariedadValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'Lapins',
            'activo' => true,
        ]);
        $servicio->guardarCalibre([
            'especie_validacion_id' => $especie->id,
            'nombre' => 'J',
            'activo' => true,
        ]);
        $servicio->guardarEnvase([
            'especie_validacion_id' => $especie->id,
            'cliente_validacion_id' => $cliente->id,
            'nombre' => '5 KG',
            'activo' => true,
        ]);
        $servicio->guardarCsg([
            'temporada_id' => $temporada->id,
            'codigo' => 'CSG-002',
            'variedad_ids' => [$santina->id],
            'activo' => true,
        ]);

        $this->assertSame(2, ArticuloValidacion::query()->count());
        $this->assertSame(1, CombinacionValidacion::query()->where('activo', true)->count());
        $this->assertSame(
            'Santina',
            CombinacionValidacion::query()
                ->where('activo', true)
                ->firstOrFail()
                ->articulo
                ->variedad,
        );
        $this->assertSame(1, CsgValidacion::query()->sole()->variedades()->count());
    }

    public function test_un_envase_solo_proyecta_combinaciones_para_su_cliente(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2028',
            'nombre' => 'Temporada 2028',
            'activa' => true,
        ]);
        $servicio = app(ServicioCatalogoJerarquicoValidacion::class);

        $clienteA = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cliente A',
            'activo' => true,
        ]);
        $clienteB = ClienteValidacion::create([
            'temporada_id' => $temporada->id,
            'nombre' => 'Cliente B',
            'activo' => true,
        ]);
        foreach ([$clienteA, $clienteB] as $cliente) {
            MarcaValidacion::create([
                'cliente_validacion_id' => $cliente->id,
                'nombre' => "Marca {$cliente->nombre}",
                'activo' => true,
            ]);
        }

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
        foreach ([$clienteA, $clienteB] as $cliente) {
            $servicio->guardarEnvase([
                'especie_validacion_id' => $especie->id,
                'cliente_validacion_id' => $cliente->id,
                'nombre' => '5 KG',
                'activo' => true,
            ]);
        }
        $servicio->guardarCsg([
            'temporada_id' => $temporada->id,
            'codigo' => 'CSG-CLIENTES',
            'variedad_ids' => [$variedad->id],
            'activo' => true,
        ]);

        $this->assertSame(2, ArticuloValidacion::query()->where('activo', true)->count());
        $this->assertSame(2, OrigenValidacion::query()->where('activo', true)->count());
        $combinaciones = CombinacionValidacion::query()
            ->where('activo', true)
            ->with(['articulo.envaseCatalogo', 'origen'])
            ->get();
        $this->assertCount(2, $combinaciones);

        foreach ($combinaciones as $combinacion) {
            $this->assertSame(
                $combinacion->articulo->envaseCatalogo->cliente_validacion_id,
                $combinacion->origen->cliente_validacion_id,
            );
        }
    }
}
