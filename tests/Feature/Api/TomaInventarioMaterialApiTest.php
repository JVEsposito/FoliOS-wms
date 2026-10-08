<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\IncidenciaVerificacionUbicacion;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\Posicion;
use App\Models\TomaInventarioMaterial;
use App\Models\TomaInventarioMaterialResultado;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Materiales\ConsultaTomaInventarioMaterial;
use App\Services\Materiales\ServicioAlmacenMaterial;
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
        Schema::create('destinos_materiales', fn (Blueprint $t) => $t->uuid('id')->primary());
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

    public function test_asigna_bloques_contiguos_por_camara_banda_posicion_y_nivel(): void
    {
        $camaras = [$this->posicion()->camara_id, $this->posicion()->camara_id];
        DB::table('posiciones')->delete();
        foreach ($camaras as $camara) {
            DB::table('camaras')->where('id', $camara)->update(['cantidad_bandas' => 2, 'posiciones_por_banda' => 3, 'cantidad_niveles' => 2]);
            // Inserción y UUID desordenados, independientes del recorrido físico.
            foreach ([2, 1] as $banda) {
                foreach ([3, 1, 2] as $numero) {
                    foreach ([2, 1] as $nivel) {
                        DB::table('posiciones')->insert(['id' => (string) Str::uuid(), 'camara_id' => $camara,
                            'banda' => $banda, 'posicion' => $numero, 'nivel' => $nivel, 'estado' => 'activa']);
                    }
                }
            }
        }
        DB::table('posiciones')->where('camara_id', max($camaras))->where('banda', 2)->where('posicion', 3)->where('nivel', 2)->delete();
        $usuarios = [$this->camarero->id];
        foreach (['Segundo', 'Tercero'] as $nombre) {
            $usuarios[] = DB::table('users')->insertGetId(['name' => $nombre, 'rol' => 'camarero_materiales', 'activo' => true]);
        }
        $servicio = app(ServicioTomaInventarioMaterial::class);
        $toma = $servicio->crear(['operacion_id' => (string) Str::uuid(), 'camara_ids' => array_reverse($camaras)], $this->supervisor);
        $toma = $servicio->operar($toma, 'abrir', $this->datos($toma) + ['camarero_ids' => array_reverse($usuarios)], $this->supervisor);
        $plano = Posicion::orderBy('camara_id')->orderBy('banda')->orderBy('posicion')->orderBy('nivel')->pluck('id');
        $asignadas = $toma->posiciones()->get()->keyBy('posicion_id');
        $this->assertCount(23, $asignadas);
        foreach ($plano as $indice => $posicion) {
            $this->assertSame($usuarios[intdiv($indice, 8)], $asignadas[$posicion]->user_id);
        }
        foreach ($usuarios as $usuario) {
            $ciego = app(ConsultaTomaInventarioMaterial::class)->ciego($toma, User::findOrFail($usuario));
            $this->assertCount($usuario === $usuarios[2] ? 7 : 8, $ciego['items']);
            $ids = array_column($ciego['items'], 'id');
            $this->assertSame($plano->filter(fn ($id) => $asignadas[$id]->user_id === $usuario)->map(fn ($id) => $asignadas[$id]->id)->values()->all(), $ids);
        }
    }

    public function test_mas_camareros_que_posiciones_no_duplica_ni_omite(): void
    {
        $p = $this->posicion();
        $usuarios = [$this->camarero->id];
        for ($i = 0; $i < 2; $i++) {
            $usuarios[] = DB::table('users')->insertGetId(['name' => 'Otro', 'rol' => 'camarero_materiales', 'activo' => true]);
        }
        $servicio = app(ServicioTomaInventarioMaterial::class);
        $toma = $servicio->crear(['operacion_id' => (string) Str::uuid(), 'camara_ids' => [$p->camara_id]], $this->supervisor);
        $toma = $servicio->operar($toma, 'abrir', $this->datos($toma) + ['camarero_ids' => $usuarios], $this->supervisor);
        $this->assertSame([$p->id], $toma->posiciones()->pluck('posicion_id')->all());
        $this->assertSame([$usuarios[0]], $toma->posiciones()->pluck('user_id')->all());
    }

    private function pareja(float $cantidad = 95): array
    {
        $original = $this->posicion();
        $id = (string) Str::uuid();
        DB::table('posiciones')->insert(['id' => $id, 'camara_id' => $original->camara_id, 'banda' => 1, 'posicion' => 2, 'nivel' => 1, 'estado' => 'activa']);
        $encontrada = Posicion::findOrFail($id);
        $folio = $this->folioEn($original, 'DESUBICADO', 100);
        $toma = $this->toma($original);
        $servicio = app(ServicioTomaInventarioMaterial::class);
        foreach ($toma->posiciones()->get() as $tarea) {
            $lecturas = $tarea->posicion_id === $id ? [['numero_folio' => 'DESUBICADO', 'cantidad_contada' => $cantidad]] : [];
            $toma = $servicio->contar($tarea, ['operacion_id' => (string) Str::uuid(), 'posicion_version' => 1, 'vacia' => ! $lecturas, 'folios' => $lecturas], $this->camarero);
        }
        $toma = $servicio->operar($toma, 'revisar', $this->datos($toma), $this->supervisor);
        $faltante = TomaInventarioMaterialResultado::where('tipo', 'faltante')->sole();
        $sobrante = TomaInventarioMaterialResultado::where('tipo', 'sobrante')->sole();
        $this->assertSame($faltante->saldo_id, $sobrante->saldo_id);

        return [$toma, $faltante, $sobrante, $original, $encontrada, $folio];
    }

    public function test_reubicar_y_ajustar_no_permite_dos_acciones_sobre_el_mismo_saldo(): void
    {
        [$toma, $faltante, $sobrante, $original, $encontrada, $folio] = $this->pareja();
        $s = app(ServicioTomaInventarioMaterial::class);
        $toma = $s->decidir($sobrante, $this->datos($toma) + ['accion' => 'reubicar_y_ajustar', 'posicion_destino_id' => $encontrada->id, 'motivo' => 'Revisado'], $this->supervisor);
        $toma = $s->decidir($faltante, $this->datos($toma) + ['accion' => 'ajustar', 'motivo' => 'Duplicado'], $this->supervisor);
        try {
            $s->operar($toma, 'aprobar', $this->datos($toma), $this->supervisor);
            $this->fail('No se deben aplicar dos acciones al mismo saldo');
        } catch (ConflictoOperacion $e) {
            $this->assertStringContainsString('dos correcciones', $e->getMessage());
        }
        $this->assertSame('en_revision', $toma->fresh()->estado);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio, 'posicion_id' => $original->id, 'cantidad_actual' => 100]);
        $this->assertDatabaseCount('reubicaciones_toma_inventario_materiales', 0);
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
    }

    public function test_combinar_exige_saldo_guardado_destino_contado_y_diferencia_real(): void
    {
        [$toma, , $sobrante, $original, $encontrada] = $this->pareja();
        $servicio = app(ServicioTomaInventarioMaterial::class);
        $datos = ['accion' => 'reubicar_y_ajustar', 'posicion_destino_id' => $encontrada->id, 'motivo' => 'Revisado'];
        foreach (['destino', 'cantidad', 'legado'] as $caso) {
            $foto = $sobrante->saldo_confirmado;
            $foto['cantidad'] = 100;
            if ($caso === 'legado') {
                unset($foto['cantidad']);
            }
            $sobrante->update(['saldo_confirmado' => $foto, 'cantidad_contada' => $caso === 'cantidad' ? 100 : 95]);
            try {
                $servicio->decidir($sobrante, [...$this->datos($toma), ...$datos, 'posicion_destino_id' => $caso === 'destino' ? $original->id : $encontrada->id], $this->supervisor);
                $this->fail('Debe rechazar '.$caso);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($caso === 'destino' ? 'posicion_destino_id' : 'accion', $e->errors());
            }
        }
        $this->assertNull($sobrante->fresh()->accion);
    }

    public function test_falla_del_ajuste_revierte_tambien_reubicacion_y_su_auditoria(): void
    {
        [$toma, $faltante, $sobrante, $original, $encontrada, $folio] = $this->pareja();
        $s = app(ServicioTomaInventarioMaterial::class);
        $toma = $s->decidir($sobrante, $this->datos($toma) + ['accion' => 'reubicar_y_ajustar', 'posicion_destino_id' => $encontrada->id, 'motivo' => 'Revisado'], $this->supervisor);
        $toma = $s->decidir($faltante, $this->datos($toma) + ['accion' => 'aceptar_sin_ajuste', 'motivo' => 'Pareja del sobrante'], $this->supervisor);
        DB::table('saldos_materiales_almacenes')->where('folio_id', $folio)->update(['cantidad_actual' => 90, 'version' => 2]);
        $almacen = Mockery::mock(ServicioAlmacenMaterial::class);
        $almacen->shouldReceive('sincronizarProyeccion')->once()->with($folio);
        $this->app->instance(ServicioAlmacenMaterial::class, $almacen);
        $movimientos = Mockery::mock(ServicioMovimientoAlmacenMaterial::class);
        $movimientos->shouldReceive('registrar')->once()->andReturnUsing(function ($datos) use ($encontrada, $sobrante, $toma) {
            $this->assertSame(-5.0, $datos['cantidad']);
            $this->assertSame($encontrada->id, $datos['posicion_destino_id']);
            $this->assertSame($toma->id, $datos['toma_inventario_id']);
            $this->assertDatabaseHas('reubicaciones_toma_inventario_materiales', ['resultado_id' => $sobrante->id]);
            throw new \DomainException('Ajuste rechazado');
        });
        $this->app->instance(ServicioMovimientoAlmacenMaterial::class, $movimientos);
        try {
            $s->operar($toma, 'aprobar', $this->datos($toma), $this->supervisor);
            $this->fail('Debe fallar');
        } catch (\DomainException $e) {
            $this->assertSame('Ajuste rechazado', $e->getMessage());
        }
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio, 'posicion_id' => $original->id, 'cantidad_actual' => 90, 'version' => 2]);
        $this->assertDatabaseHas('camaras', ['id' => $original->camara_id, 'version_plano' => 1]);
        $this->assertDatabaseCount('reubicaciones_toma_inventario_materiales', 0);
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
        $this->assertNull($sobrante->fresh()->movimiento_almacen_id);
        $this->assertSame('en_revision', $toma->fresh()->estado);
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
