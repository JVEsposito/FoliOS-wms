<?php

namespace Tests\Feature\Domain;

use App\Models\CalibreValidacion;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\MarcaValidacion;
use App\Models\VariedadValidacion;
use App\Services\Validacion\ServicioProyeccionCatalogoValidacion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ProyeccionCatalogoPorLotesTest extends TestCase
{
    use RefreshDatabase;

    private function maestros(): array
    {
        $temporada = $this->crearTemporadaActivaPrueba(['codigo' => 'TEMP-LOTES', 'nombre' => 'Catálogo por lotes', 'activa' => true]);
        $clientes = [];
        foreach (['A', 'B'] as $nombre) {
            $cliente = ClienteValidacion::create(['temporada_id' => $temporada->id, 'nombre' => $nombre, 'activo' => true]);
            MarcaValidacion::create(['cliente_validacion_id' => $cliente->id, 'nombre' => 'Marca '.$nombre, 'activo' => true]);
            $clientes[] = $cliente;
        }
        $especie = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Cereza', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina', 'activo' => true]);
        VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Lapins', 'activo' => true]);
        for ($i = 0; $i < 10; $i++) {
            CalibreValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'C'.$i, 'activo' => true]);
        }
        for ($i = 0; $i < 51; $i++) {
            EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Envase '.$i, 'activo' => true]);
        }
        EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $clientes[0]->id, 'nombre' => 'Exclusivo A', 'activo' => true]);
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => '123456', 'activo' => true]);
        $csg->variedades()->attach($variedad->id);

        return [$temporada, $variedad];
    }

    public function test_mil_treinta_vinculos_se_guardan_en_tres_lotes_con_ids_y_codigos_estables(): void
    {
        [$temporada, $variedad] = $this->maestros();
        $servicio = app(ServicioProyeccionCatalogoValidacion::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $servicio->reconstruir($temporada);
        $escrituras = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower(str_replace(['`', '"'], '', $q['query'])), 'insert into combinaciones_validacion'));
        DB::disableQueryLog();
        $this->assertCount(3, $escrituras);
        $this->assertSame(1030, CombinacionValidacion::where('temporada_id', $temporada->id)->where('activo', true)->count());
        $this->assertSame(0, CombinacionValidacion::where('temporada_id', $temporada->id)->whereHas('articulo', fn ($q) => $q->where('variedad', 'Lapins'))->count());
        $this->assertSame(0, CombinacionValidacion::where('temporada_id', $temporada->id)
            ->whereHas('articulo', fn ($q) => $q->where('envase', 'Exclusivo A'))->whereHas('origen', fn ($q) => $q->where('cliente', 'B'))->count());
        $vinculo = CombinacionValidacion::where('temporada_id', $temporada->id)->firstOrFail();
        $vinculo->update(['codigo_externo' => 'CONSERVAR-CODIGO']);
        $creado = $vinculo->created_at->toAtomString();
        $servicio->reconstruir($temporada);
        $this->assertSame('CONSERVAR-CODIGO', $vinculo->fresh()->codigo_externo);
        $this->assertSame($creado, $vinculo->fresh()->created_at->toAtomString());
        $this->assertSame(1030, CombinacionValidacion::where('temporada_id', $temporada->id)->count());
        $variedad->update(['activo' => false]);
        $servicio->reconstruir($temporada);
        $this->assertSame(0, CombinacionValidacion::where('temporada_id', $temporada->id)->where('activo', true)->count());
        $this->assertSame(1030, CombinacionValidacion::where('temporada_id', $temporada->id)->count());
    }

    public function test_fallar_el_segundo_lote_revierte_toda_la_proyeccion(): void
    {
        [$temporada] = $this->maestros();
        $conexion = DB::connection();
        $anterior = $conexion->getEventDispatcher();
        $conexion->setEventDispatcher(clone $anterior);
        $escrituras = 0;
        $conexion->listen(function (QueryExecuted $query) use (&$escrituras): void {
            if (str_starts_with(strtolower(str_replace(['`', '"'], '', $query->sql)), 'insert into combinaciones_validacion') && ++$escrituras === 2) {
                throw new RuntimeException('Fallo simulado del segundo lote');
            }
        });
        try {
            app(ServicioProyeccionCatalogoValidacion::class)->reconstruir($temporada);
            $this->fail('No debía publicar una proyección parcial.');
        } catch (RuntimeException $error) {
            $this->assertSame('Fallo simulado del segundo lote', $error->getMessage());
        } finally {
            $conexion->setEventDispatcher($anterior);
        }
        foreach (['articulos_validacion', 'origenes_validacion', 'combinaciones_validacion'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->where('temporada_id', $temporada->id)->count());
        }
    }
}
