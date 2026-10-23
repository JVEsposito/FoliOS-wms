<?php

namespace Tests\Feature\Domain;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\CalibreValidacion;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\MarcaValidacion;
use App\Models\OrigenValidacion;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use App\Services\Validacion\ServicioProyeccionCatalogoValidacion;
use App\Services\Validacion\ServicioProyeccionParcialValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProyeccionParcialValidacionTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private array $datos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporada = Temporada::where('activa', true)->sole();
        $clientes = collect(['Exportadora A', 'Exportadora B'])->map(fn ($nombre) => ClienteValidacion::create([
            'temporada_id' => $this->temporada->id, 'nombre' => $nombre, 'activo' => true]));
        foreach ($clientes as $cliente) {
            MarcaValidacion::create(['cliente_validacion_id' => $cliente->id, 'nombre' => 'Marca '.$cliente->nombre, 'activo' => true]);
        }
        foreach (['Cereza', 'Kiwi'] as $indice => $nombre) {
            $especie = EspecieValidacion::create(['temporada_id' => $this->temporada->id, 'nombre' => $nombre, 'activo' => true]);
            $variedad = VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => $indice ? 'Hayward' : 'Santina', 'activo' => true]);
            $calibre = CalibreValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'J', 'activo' => true]);
            foreach ($clientes as $cliente) {
                $envase = EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $cliente->id, 'nombre' => 'Caja 5 kg', 'activo' => true]);
            }
            $csg = CsgValidacion::create(['temporada_id' => $this->temporada->id, 'codigo' => '00123'.$indice, 'predio' => 'Predio '.$nombre, 'activo' => true]);
            $csg->variedades()->attach($variedad->id);
            $this->datos[$nombre] = compact('especie', 'variedad', 'calibre', 'envase', 'csg');
        }
        app(ServicioProyeccionCatalogoValidacion::class)->reconstruir($this->temporada);
    }

    public static function cambios(): array
    {
        return array_map(fn ($tipo) => [$tipo], ['variedad', 'calibre', 'envase', 'especie', 'marca', 'csg', 'retirar', 'mover']);
    }

    #[DataProvider('cambios')]
    public function test_el_cambio_parcial_equivale_a_reconstruir_sin_tocar_otra_especie(string $tipo): void
    {
        $d = $this->datos['Cereza'];
        $intactos = DB::table('articulos_validacion')->where('especie', 'Kiwi')->orderBy('id')->get()->toJson();
        $this->travel(2)->minutes();
        $servicio = app(ServicioCatalogoJerarquicoValidacion::class);
        match ($tipo) {
            'variedad' => $servicio->guardarVariedad(['especie_validacion_id' => $d['especie']->id, 'nombre' => 'Lapins']),
            'calibre' => $servicio->guardarCalibre(['especie_validacion_id' => $d['especie']->id, 'nombre' => '2J']),
            'envase' => $servicio->guardarEnvase(['especie_validacion_id' => $d['especie']->id, 'cliente_validacion_id' => $d['envase']->cliente_validacion_id, 'nombre' => 'Nueva caja']),
            'especie' => $servicio->guardarEspecie(['temporada_id' => $this->temporada->id, 'nombre' => 'Cerezas'], $d['especie']),
            'marca' => $servicio->guardarMarca(['cliente_validacion_id' => MarcaValidacion::first()->cliente_validacion_id, 'nombre' => 'Nueva marca']),
            'csg' => $servicio->guardarCsg(['temporada_id' => $this->temporada->id, 'codigo' => '001239', 'predio' => 'Predio corregido', 'variedad_ids' => [$d['variedad']->id]], $d['csg']),
            'retirar' => $servicio->eliminar($d['envase']),
            'mover' => $servicio->guardarVariedad(['especie_validacion_id' => $this->datos['Kiwi']['especie']->id, 'nombre' => 'Santina'], $d['variedad']),
        };
        if ($tipo !== 'mover') {
            $this->assertSame($intactos, DB::table('articulos_validacion')->where('especie', 'Kiwi')->orderBy('id')->get()->toJson());
        }
        $parcial = $this->firma();
        app(ServicioProyeccionCatalogoValidacion::class)->reconstruir($this->temporada);
        $this->assertSame($parcial, $this->firma());
    }

    public function test_categoria_y_reintento_no_reconstruyen_y_publican_la_version_exacta(): void
    {
        $this->partialMock(ServicioProyeccionCatalogoValidacion::class, fn ($m) => $m->shouldNotReceive('reconstruir'));
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $version = $this->temporada->version_catalogo;
        $payload = ['temporada_id' => $this->temporada->id, 'nombre' => 'Exportación', 'activo' => true];
        $respuesta = $this->postJson('/api/administracion/validacion/categorias', $payload)->assertCreated()
            ->assertJsonPath('publicacion.desde', $version)->assertJsonPath('publicacion.version_catalogo', $version + 1);
        $this->putJson('/api/administracion/validacion/categorias/'.$respuesta->json('data.id'), $payload)->assertOk()
            ->assertJsonPath('publicacion.desde', $version + 1)->assertJsonPath('publicacion.version_catalogo', $version + 1);
    }

    public function test_conserva_ids_y_codigos_manuales_y_revierte_ante_un_fallo_de_publicacion(): void
    {
        $d = $this->datos['Cereza'];
        $combinacion = CombinacionValidacion::whereHas('articulo', fn ($q) => $q->where('envase_validacion_id', $d['envase']->id))->firstOrFail();
        $combinacion->update(['codigo_externo' => 'MANUAL-1']);
        $servicio = app(ServicioCatalogoJerarquicoValidacion::class);
        $servicio->eliminar($d['envase']);
        $servicio->guardarEnvase(['especie_validacion_id' => $d['especie']->id, 'cliente_validacion_id' => $d['envase']->cliente_validacion_id,
            'nombre' => 'Caja 5 kg', 'activo' => true], $d['envase']);
        $this->assertTrue($combinacion->fresh()->activo);
        $this->assertSame('MANUAL-1', $combinacion->fresh()->codigo_externo);
        $antes = $this->firma();
        $version = $this->temporada->fresh()->version_catalogo;
        $this->mock(ServicioProyeccionParcialValidacion::class, fn ($m) => $m->shouldReceive('sincronizar')->once()->andThrow(new \RuntimeException('fixture rollback')));
        try {
            app(ServicioCatalogoJerarquicoValidacion::class)->guardarCalibre(['especie_validacion_id' => $d['especie']->id, 'nombre' => 'FALLIDO']);
            $this->fail('Debe abortar la publicación');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture rollback', $e->getMessage());
        }
        $this->assertSame($version, $this->temporada->fresh()->version_catalogo);
        $this->assertDatabaseMissing('calibres_validacion', ['nombre' => 'FALLIDO']);
        $this->assertSame($antes, $this->firma());
    }

    private function firma(): array
    {
        return [
            ArticuloValidacion::where('activo', true)->orderBy('id')->get(['id', 'especie', 'variedad', 'calibre', 'envase', 'cliente_validacion_id'])->toArray(),
            OrigenValidacion::where('activo', true)->orderBy('id')->get(['id', 'cliente', 'marca', 'csg', 'predio'])->toArray(),
            CombinacionValidacion::where('activo', true)->orderBy('id')->get(['id', 'articulo_validacion_id', 'origen_validacion_id', 'codigo_externo'])->toArray(),
        ];
    }
}
