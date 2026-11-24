<?php

namespace Tests\Feature\Domain;

use App\Models\CalibreValidacion;
use App\Models\ClienteValidacion;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\MarcaValidacion;
use App\Models\Temporada;
use App\Models\VariedadValidacion;
use App\Services\Validacion\BloqueoCatalogoValidacion;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use App\Services\Validacion\ServicioProyeccionCatalogoValidacion;
use App\Services\Validacion\ServicioProyeccionParcialValidacion;
use App\Services\Validacion\ServicioReparacionCatalogoValidacion;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Casi todas las tablas operativas tienen una FK a temporadas: insertar en ellas toma
 * un bloqueo compartido de la fila de la temporada. Mientras el catálogo recalcula su
 * proyección, esa fila no puede quedar bloqueada, o Romana y lotes esperan con él.
 */
class CatalogoValidacionSinBloquearTemporadaMysqlTest extends TestCase
{
    use DatabaseMigrations;

    private Temporada $temporada;

    /** @var array<string, Model> */
    private array $d = [];

    /** @var list<array{compartido: ?string, exclusivo: ?string, escritor: ?string}> */
    private array $sondeos = [];

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requiere bloqueos de fila y FK de InnoDB.');
        }
        $configuracion = DB::connection()->getConfig();
        unset($configuracion['name']);
        config(['database.connections.sonda_catalogo' => $configuracion]);

        $this->temporada = Temporada::where('activa', true)->sole();
        $cliente = ClienteValidacion::create(['temporada_id' => $this->temporada->id, 'nombre' => 'Exportadora A', 'activo' => true]);
        MarcaValidacion::create(['cliente_validacion_id' => $cliente->id, 'nombre' => 'Marca A', 'activo' => true]);
        $especie = EspecieValidacion::create(['temporada_id' => $this->temporada->id, 'nombre' => 'Cereza', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina', 'activo' => true]);
        CalibreValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'J', 'activo' => true]);
        EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $cliente->id, 'nombre' => 'Caja 5 kg', 'activo' => true]);
        $csg = CsgValidacion::create(['temporada_id' => $this->temporada->id, 'codigo' => '001230', 'activo' => true]);
        $csg->variedades()->attach($variedad->id);
        app(ServicioProyeccionCatalogoValidacion::class)->reconstruir($this->temporada);
        $this->d = compact('cliente', 'especie', 'variedad', 'csg');
    }

    public function test_romana_y_lotes_no_esperan_mientras_el_catalogo_recalcula_combinaciones(): void
    {
        $this->app->instance(ServicioProyeccionParcialValidacion::class, $this->parcialConSonda(fn () => $this->sondear()));
        $catalogo = app(ServicioCatalogoJerarquicoValidacion::class);
        $escrituras = [
            'calibre' => fn () => $catalogo->guardarCalibre(['especie_validacion_id' => $this->d['especie']->id, 'nombre' => '2J']),
            'envase' => fn () => $catalogo->guardarEnvase(['especie_validacion_id' => $this->d['especie']->id, 'cliente_validacion_id' => $this->d['cliente']->id, 'nombre' => 'Caja 2,5 kg']),
            'marca' => fn () => $catalogo->guardarMarca(['cliente_validacion_id' => $this->d['cliente']->id, 'nombre' => 'Marca B']),
            'variedad' => fn () => $catalogo->guardarVariedad(['especie_validacion_id' => $this->d['especie']->id, 'nombre' => 'Lapins']),
            'csg' => fn () => $catalogo->guardarCsg(['temporada_id' => $this->temporada->id, 'codigo' => '001231', 'variedad_ids' => [$this->d['variedad']->id]]),
        ];
        // Una sola migración para todas: cada prueba con DatabaseMigrations rehace la base.
        foreach ($escrituras as $tipo => $escribir) {
            $version = Temporada::find($this->temporada->id)->version_catalogo;
            $this->sondeos = [];
            $escribir();

            $this->assertCount(1, $this->sondeos, $tipo);
            $this->assertNull($this->sondeos[0]['compartido'], "{$tipo}: una inserción con FK a la temporada esperó al catálogo.");
            // El CSG inserta una fila propia de la temporada (FK): solo se exige el modo compartido.
            if ($tipo !== 'csg') {
                $this->assertNull($this->sondeos[0]['exclusivo'], "{$tipo}: un bloqueo exclusivo de la temporada esperó al catálogo.");
            }
            // Las escrituras del catálogo siguen ordenadas entre sí por su propio bloqueo.
            $this->assertStringContainsString('Lock wait timeout', (string) $this->sondeos[0]['escritor'], "{$tipo}: otra escritura del catálogo pudo entrar en paralelo.");
            $this->assertSame($version + 1, Temporada::find($this->temporada->id)->version_catalogo, $tipo);
        }
    }

    public function test_la_reparacion_completa_tampoco_frena_las_inserciones_operativas(): void
    {
        $sonda = fn () => $this->sondear();
        $this->app->instance(ServicioProyeccionCatalogoValidacion::class, new class($sonda) extends ServicioProyeccionCatalogoValidacion
        {
            public function __construct(private readonly Closure $sonda)
            {
                parent::__construct(app(BloqueoCatalogoValidacion::class));
            }

            public function reconstruir(Temporada $temporada): void
            {
                parent::reconstruir($temporada);
                ($this->sonda)();
            }
        });

        app(ServicioReparacionCatalogoValidacion::class)->repararActiva();

        $this->assertCount(1, $this->sondeos);
        $this->assertNull($this->sondeos[0]['compartido'], 'Una inserción con FK a la temporada esperó a la reparación.');
        $this->assertStringContainsString('Lock wait timeout', (string) $this->sondeos[0]['escritor']);
    }

    private function parcialConSonda(Closure $sonda): ServicioProyeccionParcialValidacion
    {
        return new class($sonda) extends ServicioProyeccionParcialValidacion
        {
            public function __construct(private readonly Closure $sonda) {}

            public function sincronizar(Temporada $temporada, Model $elemento): void
            {
                parent::sincronizar($temporada, $elemento);
                ($this->sonda)();
            }
        };
    }

    /** Desde otra conexión y con un segundo de espera, como lo haría una petición de Romana. */
    private function sondear(): void
    {
        $id = $this->temporada->id;
        $this->sondeos[] = [
            'compartido' => $this->intentar('select id from temporadas where id = ? for share', [$id]),
            'exclusivo' => $this->intentar('select id from temporadas where id = ? for update', [$id]),
            'escritor' => $this->intentar('select temporada_id from bloqueos_catalogo_validacion where temporada_id = ? for update', [$id]),
        ];
    }

    private function intentar(string $sql, array $valores): ?string
    {
        $conexion = DB::connection('sonda_catalogo');
        $conexion->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $conexion->beginTransaction();
        try {
            $conexion->select($sql, $valores);

            return null;
        } catch (QueryException $error) {
            return $error->getMessage();
        } finally {
            $conexion->rollBack();
        }
    }
}
