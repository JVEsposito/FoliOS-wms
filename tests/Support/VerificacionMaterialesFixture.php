<?php

namespace Tests\Support;

use App\Http\Middleware\ExigirCambioPasswordTablet;
use App\Models\Dispositivo;
use App\Models\Posicion;
use App\Models\User;
use App\Models\VerificacionUbicacion;
use App\Models\VerificacionUbicacionItem;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Verificaciones\ServicioVerificacionesUbicacion;
use App\Services\Verificaciones\ServicioVerificacionMateriales;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;

trait VerificacionMaterialesFixture
{
    protected User $camarero;

    protected Dispositivo $tablet;

    protected string $temporadaId;

    protected function setUp(): void
    {
        parent::setUp();
        // CI migra previamente la base MySQL completa. Esta suite utiliza un
        // esquema mínimo en una conexión privada para no recrear ni alterar
        // sus tablas y para probar exactamente las consultas del servicio.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        config(['verificaciones.habilitada' => true, 'verificaciones.posiciones_por_ronda' => 1, 'verificaciones.productos.posiciones_por_ronda' => 1]);
        Schema::create('temporadas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->boolean('activa');
            $t->date('fecha_inicio');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('rol');
            $t->boolean('activo');
            $t->timestamps();
        });
        Schema::create('dispositivos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->boolean('activo');
            $t->timestamps();
        });
        Schema::create('camaras', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo');
            $t->string('estado');
            $t->string('contenido');
            $t->integer('cantidad_bandas');
            $t->integer('posiciones_por_banda');
            $t->integer('cantidad_niveles');
            $t->timestamps();
        });
        Schema::create('posiciones', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('camara_id');
            $t->integer('banda');
            $t->integer('posicion');
            $t->integer('nivel');
            $t->string('estado');
            $t->timestamps();
        });
        Schema::create('folios', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('temporada_id');
            $t->string('numero_folio');
            $t->timestamps();
        });
        Schema::create('ubicaciones_actuales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('posicion_id');
            $t->uuid('folio_id');
            $t->timestamps();
        });
        Schema::create('tareas_movimiento', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->string('estado');
        });
        Schema::create('reservas_tareas_movimiento', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('bloqueo_posicion_id')->nullable();
            $t->uuid('bloqueo_tarea_id')->nullable();
        });
        Schema::create('reservas_posiciones_inspeccion_sag', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('clave_bloqueo')->nullable();
        });
        Schema::create('custodias_temporales_maniobra', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->string('estado');
        });
        Schema::create('carga_folios', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('carga_id');
            $t->string('estado');
            $t->uuid('bloqueo_folio_id')->nullable();
        });
        Schema::create('reservas_carga_folio', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
        });
        Schema::create('presencias_carga_anden', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('carga_id');
            $t->string('estado');
        });
        Schema::create('movimientos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('posicion_origen_id')->nullable();
            $t->uuid('posicion_destino_id')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_29_150000_crear_verificaciones_ubicacion.php'))->up();

        Schema::table('verificaciones_ubicacion', function (Blueprint $t): void {
            $t->string('contenido')->default('productos');
            $t->boolean('verificar_cantidad')->default(false);
            $t->decimal('tolerancia_cantidad_pct', 6, 3)->default(2);
        });

        Schema::table('folios', fn (Blueprint $t) => $t->boolean('activo')->default(true));
        Schema::create('items_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('unidad_medida');
        });
        Schema::create('folios_materiales', function (Blueprint $t): void {
            $t->uuid('folio_id')->primary();
            $t->uuid('item_material_id');
            $t->decimal('cantidad_actual', 14, 3);
            $t->decimal('cantidad_reservada', 14, 3)->default(0);
            $t->string('motivo_bloqueo')->nullable();
            $t->timestamps();
        });
        Schema::create('saldos_materiales_almacenes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('almacen_material_id');
            $t->uuid('camara_id')->nullable();
            $t->uuid('posicion_id')->nullable();
            $t->decimal('cantidad_actual', 14, 3);
            $t->decimal('cantidad_reservada', 14, 3)->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        foreach (['reservas_materiales', 'reservas_transformacion_materiales'] as $tabla) {
            Schema::create($tabla, function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('folio_id');
                $t->string('estado');
            });
        }
        Schema::create('retiros_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('posicion_id');
            $t->timestamps();
        });
        Schema::create('movimientos_almacenes_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->timestamps();
        });
        // La migración real incluye los campos de ronda agregados arriba por el fixture de frío.
        Schema::table('verificaciones_ubicacion', fn (Blueprint $t) => $t->dropColumn(['contenido', 'verificar_cantidad', 'tolerancia_cantidad_pct']));
        (require database_path('migrations/2026_10_08_140000_extender_verificaciones_materiales.php'))->up();
        config(['verificaciones.materiales.habilitada' => true, 'verificaciones.materiales.posiciones_por_ronda' => 1]);
        $this->temporadaId = (string) Str::uuid();
        DB::table('temporadas')->insert(['id' => $this->temporadaId, 'activa' => true,
            'fecha_inicio' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('users')->insertGetId(['name' => 'Camarero', 'rol' => 'camarero_materiales', 'activo' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->camarero = User::findOrFail($id);
        $dispositivoId = (string) Str::uuid();
        DB::table('dispositivos')->insert(['id' => $dispositivoId, 'activo' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $this->tablet = Dispositivo::findOrFail($dispositivoId);
    }

    protected function posicion(string $contenido = 'materiales'): Posicion
    {
        $camara = (string) Str::uuid();
        DB::table('camaras')->insert(['id' => $camara, 'codigo' => $contenido.'-'.substr($camara, 0, 4), 'estado' => 'activa', 'contenido' => $contenido,
            'cantidad_bandas' => 1, 'posiciones_por_banda' => 10, 'cantidad_niveles' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('posiciones')->insert(['id' => $id, 'camara_id' => $camara, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'estado' => 'activa', 'created_at' => now(), 'updated_at' => now()]);

        return Posicion::findOrFail($id);
    }

    protected function folioEn(Posicion $posicion, string $numero, float $cantidad = 10, bool $bloqueado = false): string
    {
        $id = (string) Str::uuid();
        $item = (string) Str::uuid();
        DB::table('folios')->insert(['id' => $id, 'temporada_id' => $this->temporadaId, 'numero_folio' => $numero, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('items_materiales')->insert(['id' => $item, 'unidad_medida' => 'unidad']);
        DB::table('folios_materiales')->insert(['folio_id' => $id, 'item_material_id' => $item, 'cantidad_actual' => $cantidad, 'motivo_bloqueo' => $bloqueado ? 'Vencido' : null]);
        DB::table('ubicaciones_actuales')->insert(['id' => (string) Str::uuid(), 'folio_id' => $id, 'posicion_id' => $posicion->id]);
        DB::table('saldos_materiales_almacenes')->insert(['id' => (string) Str::uuid(), 'folio_id' => $id, 'almacen_material_id' => (string) Str::uuid(), 'camara_id' => $posicion->camara_id, 'posicion_id' => $posicion->id, 'cantidad_actual' => $cantidad, 'version' => 1]);

        return $id;
    }

    protected function ronda(): VerificacionUbicacion
    {
        return VerificacionUbicacion::create(['temporada_id' => $this->temporadaId, 'user_id' => $this->camarero->id, 'dispositivo_id' => $this->tablet->id,
            'turno_inicio_at' => now()->startOfHour(), 'turno_fin_at' => now()->addHours(8), 'vence_at' => now()->addHours(8), 'contenido' => 'materiales', 'verificar_cantidad' => true, 'tolerancia_cantidad_pct' => 2, 'objetivo' => 1]);
    }

    protected function itemEn(Posicion $posicion): VerificacionUbicacionItem
    {
        return $this->ronda()->items()->create(['posicion_id' => $posicion->id,
            'snapshot_materiales' => app(ServicioVerificacionMateriales::class)->snapshot($posicion, $this->temporadaId)]);
    }

    protected function confirmar($item, array $folios, bool $vacia = false): array
    {
        return app(ServicioVerificacionesUbicacion::class)->registrar($item, $this->camarero, $this->tablet, (string) Str::uuid(), 1, null, $folios, $vacia);
    }

    protected function apiCiega(): void
    {
        $contexto = Mockery::mock(ContextoOperacional::class);
        $contexto->shouldReceive('obtener')->andReturn([$this->camarero, $this->tablet]);
        $this->app->instance(ContextoOperacional::class, $contexto);
        $this->withoutMiddleware([Authenticate::class, Authorize::class, ExigirCambioPasswordTablet::class]);
    }

    protected function assertCiega(string $contenido): void
    {
        foreach (['MAT-A', 'MAT-B', 'MAT-C', 'cantidad_esperada', 'folio_esperado', 'snapshot_materiales', 'cantidad_actual'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $contenido);
        }
    }
}
