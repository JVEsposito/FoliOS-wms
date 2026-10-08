<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\IncidenciaVerificacionUbicacion;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\TomaInventarioMaterial;
use App\Models\TomaInventarioMaterialResultado;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Materiales\ServicioMovimientoAlmacenMaterial;
use App\Services\Materiales\ServicioTomaInventarioMaterial;
use App\Services\Verificaciones\ServicioResolverIncidenciaVerificacion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VerificacionMaterialesFixture;
use Tests\TestCase;

class TomaInventarioMaterialApiTest extends TestCase
{
    use VerificacionMaterialesFixture { setUp as fixtureSetUp; }

    private User $supervisor;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        Schema::table('items_materiales', function (Blueprint $t) {
            $t->string('nombre')->default('Insumo');
            $t->string('categoria')->default('Insumos');
        });
        Schema::table('camaras', fn (Blueprint $t) => $t->unsignedInteger('version_plano')->default(1));
        Schema::table('movimientos_almacenes_materiales', function (Blueprint $t) {
            $t->uuid('almacen_origen_id')->nullable();
            $t->uuid('almacen_destino_id')->nullable();
            $t->timestamp('ocurrido_at')->nullable();
        });
        (require database_path('migrations/2026_10_08_160000_crear_tomas_inventario_materiales.php'))->up();
        $id = DB::table('users')->insertGetId(['name' => 'Supervisor', 'rol' => 'supervisor_materiales', 'activo' => true]);
        $this->supervisor = User::findOrFail($id);
        $alcance = Mockery::mock(AlcanceOperacionalUsuario::class);
        $alcance->shouldReceive('puedeSupervisarCamara')->andReturn(true);
        $alcance->shouldReceive('puedeOperarCamara')->andReturn(true);
        $this->app->instance(AlcanceOperacionalUsuario::class, $alcance);
    }

    private function toma($posicion): TomaInventarioMaterial
    {
        $servicio = app(ServicioTomaInventarioMaterial::class);
        $toma = $servicio->crear(['operacion_id' => (string) Str::uuid(), 'camara_ids' => [$posicion->camara_id]], $this->supervisor);

        return $servicio->operar($toma, 'abrir', $this->datos($toma) + ['camarero_ids' => [$this->camarero->id]], $this->supervisor);
    }

    private function datos($toma): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version' => $toma->fresh()->version];
    }

    private function contar($toma, array $folios): TomaInventarioMaterial
    {
        $tarea = $toma->posiciones()->firstOrFail();

        return app(ServicioTomaInventarioMaterial::class)->contar($tarea, $this->datos($toma) + ['posicion_version' => $tarea->version, 'vacia' => ! $folios, 'folios' => $folios], $this->camarero);
    }

    public function test_foto_congelada_retiro_durante_toma_ajusta_esperado_y_api_es_ciega(): void
    {
        $posicion = $this->posicion();
        $folio = $this->folioEn($posicion, 'SECRETO', 100);
        $toma = $this->toma($posicion);
        $this->assertSame(100.0, (float) $toma->foto[0]['cantidad']);
        DB::table('saldos_materiales_almacenes')->where('folio_id', $folio)->update(['cantidad_actual' => 90, 'version' => 2]);
        DB::table('movimientos_almacenes_materiales')->insert(['id' => (string) Str::uuid(), 'folio_id' => $folio, 'almacen_origen_id' => $toma->foto[0]['almacen_id'], 'ocurrido_at' => now()]);
        $this->apiCiega();
        $antes = $this->getJson('/api/materiales/tomas/conteo')->assertOk()->getContent();
        $tarea = $toma->posiciones()->firstOrFail();
        $respuesta = $this->postJson("/api/materiales/tomas/posiciones/{$tarea->id}/contar", ['operacion_id' => (string) Str::uuid(), 'posicion_version' => 1, 'vacia' => false, 'folios' => [['numero_folio' => 'SECRETO', 'cantidad_contada' => 90]]])->assertOk();
        foreach ([$antes, $respuesta->getContent(), $this->getJson('/api/materiales/tomas/conteo')->getContent()] as $json) {
            foreach (['SECRETO', 'cantidad_esperada', 'esperado', 'foto', 'saldo', 'movimientos', 'cantidad_contada'] as $secreto) {
                $this->assertStringNotContainsString($secreto, $json);
            }
        }
        $resultado = TomaInventarioMaterialResultado::sole();
        $this->assertSame('coincide', $resultado->tipo);
        $this->assertSame(90.0, (float) $resultado->cantidad_esperada);
        $this->assertCount(1, $resultado->movimientos);
        $this->assertSame(100.0, (float) $toma->fresh()->foto[0]['cantidad']);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio, 'cantidad_actual' => 90]);
    }

    public function test_aceptar_sin_ajuste_recuento_e_idempotencia_conservan_inventario(): void
    {
        $p = $this->posicion();
        $f = $this->folioEn($p, 'A', 10);
        $toma = $this->contar($this->toma($p), [['numero_folio' => 'A', 'cantidad_contada' => 9]]);
        $s = app(ServicioTomaInventarioMaterial::class);
        $toma = $s->operar($toma, 'revisar', $this->datos($toma), $this->supervisor);
        $r = TomaInventarioMaterialResultado::where('vigente', true)->sole();
        $toma = $s->decidir($r, $this->datos($toma) + ['accion' => 'recontar', 'motivo' => 'Revisar conteo'], $this->supervisor);
        $this->assertSame('en_conteo', $toma->estado);
        $this->assertFalse($r->fresh()->vigente);
        $toma = $this->contar($toma, [['numero_folio' => 'A', 'cantidad_contada' => 9]]);
        $this->assertCount(2, $toma->posiciones()->first()->lecturas);
        $toma = $s->operar($toma, 'revisar', $this->datos($toma), $this->supervisor);
        $toma = $s->decidir(TomaInventarioMaterialResultado::where('vigente', true)->sole(), $this->datos($toma) + ['accion' => 'aceptar_sin_ajuste', 'motivo' => 'Error de lectura documentado'], $this->supervisor);
        $datos = $this->datos($toma);
        $toma = $s->operar($toma, 'aprobar', $datos, $this->supervisor);
        $s->operar($toma, 'aprobar', $datos, $this->supervisor);
        $this->assertSame('aprobada', $toma->estado);
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $f, 'cantidad_actual' => 10]);
    }

    public function test_aprobacion_atomica_revierte_el_primer_ajuste_si_falla_el_segundo(): void
    {
        $p = $this->posicion();
        $this->folioEn($p, 'A', 10);
        $this->folioEn($p, 'B', 10);
        $toma = $this->contar($this->toma($p), [['numero_folio' => 'A', 'cantidad_contada' => 9], ['numero_folio' => 'B', 'cantidad_contada' => 8]]);
        $s = app(ServicioTomaInventarioMaterial::class);
        $toma = $s->operar($toma, 'revisar', $this->datos($toma), $this->supervisor);
        foreach (TomaInventarioMaterialResultado::where('vigente', true)->get() as $r) {
            $toma = $s->decidir($r, $this->datos($toma) + ['accion' => 'ajustar', 'motivo' => 'Conteo físico aprobado'], $this->supervisor);
        }
        $movimientos = Mockery::mock(ServicioMovimientoAlmacenMaterial::class);
        $n = 0;
        $movimientos->shouldReceive('registrar')->twice()->andReturnUsing(function ($datos) use (&$n) {
            if (++$n === 2) {
                throw new \DomainException('Falla simulada');
            }
            DB::table('saldos_materiales_almacenes')->where('folio_id', $datos['folio_id'])->update(['cantidad_actual' => 0]);
            $mov = new MovimientoAlmacenMaterial;
            $mov->id = (string) Str::uuid();
            DB::table('movimientos_almacenes_materiales')->insert(['id' => $mov->id, 'folio_id' => $datos['folio_id']]);

            return $mov;
        });
        $this->app->instance(ServicioMovimientoAlmacenMaterial::class, $movimientos);
        try {
            $s->operar($toma, 'aprobar', $this->datos($toma), $this->supervisor);
            $this->fail('Debe fallar');
        } catch (\DomainException $e) {
            $this->assertSame('Falla simulada', $e->getMessage());
        }
        $this->assertSame('en_revision', $toma->fresh()->estado);
        $this->assertSame(20.0, (float) DB::table('saldos_materiales_almacenes')->sum('cantidad_actual'));
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
        $this->assertSame(0, TomaInventarioMaterialResultado::whereNotNull('movimiento_almacen_id')->count());
    }

    public function test_anular_no_modifica_inventario_y_camarero_no_aprueba(): void
    {
        $p = $this->posicion();
        $f = $this->folioEn($p, 'A', 10);
        $toma = $this->toma($p);
        $s = app(ServicioTomaInventarioMaterial::class);
        try {
            $s->operar($toma, 'aprobar', $this->datos($toma), $this->camarero);
            $this->fail('Camarero no debe aprobar');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $toma = $s->operar($toma, 'anular', $this->datos($toma) + ['motivo' => 'Cancelada por supervisor'], $this->supervisor);
        $this->assertSame('anulada', $toma->estado);
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $f, 'cantidad_actual' => 10]);
    }

    public function test_incidente_desconocido_se_resuelve_sin_ajuste_con_motivo_y_tipo_obligatorios(): void
    {
        $p = $this->posicion();
        [, $verificada] = $this->confirmar($this->itemEn($p), [['numero_folio' => 'DESCONOCIDO', 'cantidad_contada' => 1]]);
        $incidencia = IncidenciaVerificacionUbicacion::sole();
        $servicio = app(ServicioResolverIncidenciaVerificacion::class);
        $datos = ['operacion_id' => (string) Str::uuid(), 'tipo_resolucion' => 'error_de_conteo', 'motivo' => 'Número escaneado por error'];
        try {
            $servicio->resolver($incidencia, [...$datos, 'motivo' => '  '], $this->supervisor);
            $this->fail('Se exige motivo');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('motivo', $e->errors());
        }
        $resuelta = $servicio->resolver($incidencia, $datos, $this->supervisor);
        $servicio->resolver($resuelta, $datos, $this->supervisor);
        $this->assertSame('resuelta', $resuelta->estado);
        $this->assertSame('error_de_conteo', $resuelta->tipo_resolucion);
        $this->assertSame($this->supervisor->id, $resuelta->resuelto_por_user_id);
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
        // Frío utiliza el mismo cierre pero exige su supervisor.
        DB::table('camaras')->where('id', $p->camara_id)->update(['contenido' => 'productos']);
        $resuelta->update(['estado' => 'abierta', 'resolucion_operacion_id' => null]);
        $this->supervisor->rol = RolUsuario::SupervisorFrio;
        $this->assertSame('resuelta', $servicio->resolver($resuelta->fresh(), [...$datos, 'operacion_id' => (string) Str::uuid()], $this->supervisor)->estado);
    }
}
