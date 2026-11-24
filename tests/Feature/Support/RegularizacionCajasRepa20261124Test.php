<?php

namespace Tests\Feature\Support;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\Folio;
use App\Models\User;
use App\Services\InspeccionSag\ServicioExistenciasSag;
use App\Services\Planificador\ServicioDepuracionIngresosCamara;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RegularizacionCajasRepa20261124;
use Tests\TestCase;

class RegularizacionCajasRepa20261124Test extends TestCase
{
    use RefreshDatabase;

    private string $temporadaId;

    private User $usuario;

    private array $respaldos = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('scripts/soporte/regularizar-cajas-repa-20261124.php');
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'REPA-REGU', 'nombre' => 'Regularización de prueba', ...$this->vigenciaProductiva(),
        ]);
        $this->temporadaId = $temporada->id;
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        foreach (RegularizacionCajasRepa20261124::PASOS as $numero => $paso) {
            $this->insertar($paso['id'], (string) $numero, $paso['antes'], $paso['activo'], $paso['estado'], $paso['actualizado']);
        }
        $sag = Mockery::mock(ServicioExistenciasSag::class);
        $sag->shouldReceive('sincronizar')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(ServicioExistenciasSag::class, $sag);
        $depuracion = Mockery::mock(ServicioDepuracionIngresosCamara::class);
        $depuracion->shouldReceive('depurarFolio')->zeroOrMoreTimes()->andReturn(0);
        $this->app->instance(ServicioDepuracionIngresosCamara::class, $depuracion);
    }

    protected function tearDown(): void
    {
        foreach ($this->respaldos as $ruta) {
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
        parent::tearDown();
    }

    private function insertar(string $id, string $numero, int $cantidad, bool $activo, string $estado, string $fecha): void
    {
        DB::table('folios')->insert([
            'id' => $id, 'numero_folio' => $numero, 'temporada_id' => $this->temporadaId,
            'tipo_bulto' => 'saldo', 'activo' => $activo, 'estado_operacional' => $estado,
            'fecha_ingreso' => $fecha, 'created_at' => $fecha, 'updated_at' => $fecha,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado->value,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado->value,
            'estado_integracion' => EstadoIntegracionFolio::NoVinculado->value,
            'origen_sistema' => 'validacion', 'exportadora' => 'CLIENTE',
            'datos_externos' => json_encode(['cantidad_cajas' => $cantidad, 'fecha_embalaje' => '2026-11-22',
                'composicion' => [['csg' => '111', 'calibre' => '2J', 'fecha_embalaje' => '2026-11-22',
                    'lote_materia_prima' => 'LOTE', 'proceso_packing' => 'PROCESO', 'cantidad_cajas' => $cantidad]]]),
        ]);
    }

    private function servicio(): RegularizacionCajasRepa20261124
    {
        return new RegularizacionCajasRepa20261124($this->temporadaId);
    }

    private function respaldo(): string
    {
        $ruta = sys_get_temp_dir().'/repa-regularizacion-'.bin2hex(random_bytes(8)).'.json';
        $this->respaldos[] = $ruta;

        return $ruta;
    }

    private function cajas(): array
    {
        return Folio::query()->orderBy('numero_folio')->get()->mapWithKeys(fn ($f) => [$f->numero_folio => $f->datos_externos['cantidad_cajas']])->all();
    }

    public function test_la_vista_previa_no_escribe_y_presenta_solo_los_cinco_objetivos(): void
    {
        $antes = $this->cajas();
        $plan = $this->servicio()->previsualizar();
        $this->assertCount(5, $plan);
        $this->assertSame($antes, $this->cajas());
        $this->assertDatabaseCount('transiciones_operacionales', 0);
    }

    public function test_regulariza_con_respaldo_auditoria_y_composicion_sin_tocar_los_casos_diferidos(): void
    {
        foreach (['6000005081' => 29, '6000001531' => 176, '6000005122' => 37] as $numero => $cantidad) {
            $this->insertar((string) Str::uuid(), (string) $numero, $cantidad, true, 'pendiente_ubicacion', '2026-11-24 12:00:00');
        }
        $ruta = $this->respaldo();
        $resultado = $this->servicio()->aplicar($this->usuario, $ruta);
        $this->assertTrue($resultado['aplicado']);
        $copia = json_decode(file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(5, $copia['folios_antes']);
        $this->assertSame($this->usuario->id, $copia['usuario_id']);
        foreach (RegularizacionCajasRepa20261124::PASOS as $numero => $paso) {
            $f = Folio::findOrFail($paso['id']);
            $this->assertSame($paso['despues'], $f->datos_externos['cantidad_cajas']);
            $this->assertSame(RegularizacionCajasRepa20261124::OPERACION, $f->datos_externos['regularizacion_cajas_fecha']['operacion_id']);
            if ($paso['despues'] === 0) {
                $this->assertFalse($f->activo);
                $this->assertSame('agotado', $f->estado_operacional->value);
                $this->assertSame([], $f->datos_externos['composicion']);
                $this->assertDatabaseMissing('trazabilidad_folio_origenes', ['folio_id' => $f->id]);
            } else {
                $this->assertTrue($f->activo);
                $linea = $f->datos_externos['composicion'][0];
                $this->assertSame(7, $linea['cantidad_cajas']);
                $this->assertSame('LOTE', $linea['lote_materia_prima']);
                $this->assertSame('2026-11-22', $linea['fecha_embalaje']);
                $this->assertDatabaseHas('trazabilidad_folio_origenes', ['folio_id' => $f->id, 'cantidad_cajas' => 7]);
            }
        }
        foreach (['6000005081' => 29, '6000001531' => 176, '6000005122' => 37] as $numero => $cantidad) {
            $this->assertSame($cantidad, Folio::where('numero_folio', $numero)->sole()->datos_externos['cantidad_cajas']);
        }
        $this->assertDatabaseCount('transiciones_operacionales', 1);
        $this->assertDatabaseHas('transiciones_operacionales', ['operacion_id' => RegularizacionCajasRepa20261124::OPERACION, 'estado' => 'aplicada']);
        $this->assertDatabaseCount('correcciones_validacion_pallet', 0);
        $this->assertDatabaseCount('repaletizajes', 0);
    }

    public function test_repetir_la_operacion_no_descontara_otra_vez_ni_creara_otro_respaldo(): void
    {
        $this->servicio()->aplicar($this->usuario, $this->respaldo());
        $antes = $this->cajas();
        $segundaRuta = $this->respaldo();
        $resultado = $this->servicio()->aplicar($this->usuario, $segundaRuta);
        $this->assertTrue($resultado['ya_aplicado']);
        $this->assertSame($antes, $this->cajas());
        $this->assertFileDoesNotExist($segundaRuta);
        $this->assertDatabaseCount('transiciones_operacionales', 1);
    }

    public function test_si_cambio_un_folio_no_modifica_ninguno_de_los_otros(): void
    {
        $paso = RegularizacionCajasRepa20261124::PASOS['6000005028'];
        $f = Folio::findOrFail($paso['id']);
        $datos = $f->datos_externos;
        $datos['cantidad_cajas'] = 90;
        DB::table('folios')->where('id', $f->id)->update(['datos_externos' => json_encode($datos)]);
        $antes = $this->cajas();
        try {
            $this->servicio()->aplicar($this->usuario, $this->respaldo());
            $this->fail('Se esperaba rechazar el saldo cambiado.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('cambió respecto', $error->getMessage());
        }
        $this->assertSame($antes, $this->cajas());
    }

    public function test_un_movimiento_posterior_se_rechaza_aunque_la_cantidad_vuelva_a_coincidir(): void
    {
        $paso = RegularizacionCajasRepa20261124::PASOS['6000005153'];
        DB::table('folios')->where('id', $paso['id'])->update(['updated_at' => '2026-11-24 18:30:00']);
        $this->expectException(ConflictoOperacion::class);
        $this->servicio()->previsualizar();
    }

    public function test_no_reparte_un_ajuste_de_saldo_entre_componentes_ambiguos(): void
    {
        $paso = RegularizacionCajasRepa20261124::PASOS['6000005028'];
        $datos = Folio::findOrFail($paso['id'])->datos_externos;
        $datos['composicion'] = [['csg' => '111', 'cantidad_cajas' => 40], ['csg' => '222', 'cantidad_cajas' => 49]];
        DB::table('folios')->where('id', $paso['id'])->update(['datos_externos' => json_encode($datos)]);
        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('varios componentes');
        $this->servicio()->aplicar($this->usuario, $this->respaldo());
    }

    public function test_un_fallo_posterior_revierte_cajas_estados_y_proyecciones(): void
    {
        $sag = Mockery::mock(ServicioExistenciasSag::class);
        $sag->shouldReceive('sincronizar')->once()->andThrow(new \DomainException('Fallo de sincronización de prueba.'));
        $this->app->instance(ServicioExistenciasSag::class, $sag);
        $antes = $this->cajas();
        try {
            $this->servicio()->aplicar($this->usuario, $this->respaldo());
            $this->fail('Se esperaba el fallo de sincronización.');
        } catch (\DomainException $error) {
            $this->assertSame('Fallo de sincronización de prueba.', $error->getMessage());
        }
        $this->assertSame($antes, $this->cajas());
        $this->assertDatabaseCount('trazabilidad_folio_origenes', 0);
        foreach (Folio::all() as $f) {
            $this->assertArrayNotHasKey('regularizacion_cajas_fecha', $f->datos_externos);
        }
    }

    public function test_sin_ejecutor_activo_no_se_modifica_el_inventario(): void
    {
        $this->usuario->activo = false;
        $this->expectException(ConflictoOperacion::class);
        $this->servicio()->aplicar($this->usuario, $this->respaldo());
    }

    public function test_sin_respaldo_nuevo_no_se_modifica_el_inventario(): void
    {
        $ruta = $this->respaldo();
        file_put_contents($ruta, 'ya existe');
        $antes = $this->cajas();
        try {
            $this->servicio()->aplicar($this->usuario, $ruta);
            $this->fail('Se esperaba rechazar la sobrescritura del respaldo.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('respaldo nuevo', $error->getMessage());
        }
        $this->assertSame($antes, $this->cajas());
        $this->assertSame('ya existe', file_get_contents($ruta));
    }
}
