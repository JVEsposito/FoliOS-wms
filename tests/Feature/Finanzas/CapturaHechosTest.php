<?php

namespace Tests\Feature\Finanzas;

use App\Finanzas\Captura\CapturaEnCurso;
use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FuenteHechos;
use App\Finanzas\Captura\Fuentes\Prefrio;
use App\Finanzas\Captura\Fuentes\RecepcionRomana;
use App\Finanzas\Models\Cursor;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Lectura;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CapturaHechosTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
    }

    public function test_una_recepcion_cerrada_es_un_hecho_y_cada_cambio_una_version(): void
    {
        $cliente = $this->clienteGlobal('PRODUCTOR DE PRUEBA SPA');
        $recepcion = $this->recepcionRomana($cliente, 'R-0001', 8450.5, '2026-11-03 09:15');
        $this->recepcionRomana($cliente, 'R-0002', 0, '2026-11-03 10:00', ['estado' => 'en_bascula_salida']);

        $lectura = $this->capturar();

        $this->assertSame(Lectura::ESTADO_COMPLETADA, $lectura->estado);
        $hechos = $this->hechos('romana_recepcion');
        $this->assertCount(1, $hechos, 'La recepción abierta todavía no es un hecho.');
        $hecho = $hechos->first();
        $this->assertSame($recepcion, $hecho->origen_id);
        $this->assertSame(1, $hecho->version);
        $this->assertSame(Hecho::MOTIVO_CAPTURA, $hecho->motivo_version);
        $this->assertSame($cliente, $hecho->cliente_id);
        $this->assertSame('PRODUCTOR DE PRUEBA SPA', $hecho->cliente_nombre);
        $this->assertSame('R-0001', $hecho->referencia);
        $this->assertSame(8450.5, $hecho->medidas['kilos_netos']);
        $this->assertSame('2026-11-03', $hecho->fecha_operacional->toDateString());
        $this->assertSame(1, $lectura->resumen['romana_recepcion']['nuevos']);

        // Releer sin cambios no crea versiones.
        $this->travel(1)->minutes();
        $segunda = $this->capturar();
        $this->assertSame(0, $segunda->resumen['romana_recepcion']['nuevos']);
        $this->assertCount(1, $this->hechos('romana_recepcion', soloVigentes: false));

        // Una corrección en la romana es una versión nueva; la anterior queda como historial.
        $this->travel(1)->minutes();
        DB::table('recepciones_romana')->where('id', $recepcion)->update(['peso_neto' => 8400, 'updated_at' => now()]);
        $this->capturar();
        $versiones = $this->hechos('romana_recepcion', soloVigentes: false);
        $this->assertSame([1, 2], $versiones->pluck('version')->all());
        $this->assertSame([false, true], $versiones->pluck('vigente')->all());
        $this->assertSame(8400.0, $versiones->last()->medidas['kilos_netos']);
        $this->assertSame(Hecho::MOTIVO_CAMBIO, $versiones->last()->motivo_version);

        // Si la recepción se reabre deja de ser un hecho, sin borrar su historial.
        $this->travel(1)->minutes();
        DB::table('recepciones_romana')->where('id', $recepcion)->update(['estado' => 'en_bascula_salida', 'updated_at' => now()]);
        $this->capturar();
        $ultima = $this->hechos('romana_recepcion')->sole();
        $this->assertSame(3, $ultima->version);
        $this->assertSame(Hecho::ESTADO_ANULADO, $ultima->estado);
        $this->assertSame(Hecho::MOTIVO_ANULACION, $ultima->motivo_version);
    }

    public function test_lo_anulado_antes_de_la_primera_lectura_no_deja_hechos(): void
    {
        $validacion = $this->validacion('PT-0100', $this->clienteGlobal('EXPORTADORA DEMO'));
        DB::table('validaciones_pallet')->where('id', $validacion)->update(['estado' => 'anulada']);

        $lectura = $this->capturar(['validacion_pt']);

        $this->assertCount(0, $this->hechos('validacion_pt', soloVigentes: false));
        $this->assertSame(1, $lectura->resumen['validacion_pt']['leidos']);
    }

    public function test_un_hecho_sin_cliente_se_completa_cuando_el_cliente_se_vincula(): void
    {
        $validacion = $this->validacion('PT-0101', null, 'EXPORTADORA NUEVA');
        $this->capturar(['validacion_pt']);
        $this->travel(30)->minutes();
        $this->capturar(['validacion_pt']);
        $this->assertNull($this->hechos('validacion_pt')->sole()->cliente_id);

        // El cliente se crea en los maestros: la validación no cambia, pero se vuelve a leer.
        $this->travel(30)->minutes();
        $cliente = $this->clienteGlobal('Exportadora Nueva');
        $lectura = $this->capturar(['validacion_pt']);

        $hecho = $this->hechos('validacion_pt')->sole();
        $this->assertSame($validacion, $hecho->origen_id);
        $this->assertSame(2, $hecho->version);
        $this->assertSame($cliente, $hecho->cliente_id);
        $this->assertSame('Exportadora Nueva', $hecho->cliente_nombre);
        $this->assertSame(0, $lectura->resumen['validacion_pt']['leidos'], 'La relectura no cuenta como registro cambiado.');
        $this->assertSame(1, $lectura->resumen['validacion_pt']['versiones']);
    }

    public function test_cada_lectura_repasa_un_margen_y_la_conciliacion_lee_todo(): void
    {
        $cliente = $this->clienteGlobal('PRODUCTOR DE PRUEBA SPA');
        $this->capturar(['romana_recepcion']);

        // Registros que se confirman con una hora anterior a la marca de la lectura anterior
        // (una transacción larga): dentro del margen se ven; más atrás, en la conciliación.
        $this->travel(20)->minutes();
        $this->recepcionRomana($cliente, 'R-0010', 1000, '2026-11-03 09:00', ['updated_at' => now()->subMinutes(40)]);
        $this->recepcionRomana($cliente, 'R-0011', 1000, '2026-11-03 09:00', ['updated_at' => now()->subMinutes(30)]);
        $this->capturar(['romana_recepcion']);
        $this->assertSame(['R-0011'], $this->hechos('romana_recepcion')->pluck('referencia')->all());

        $this->capturar(['romana_recepcion'], conciliar: true);
        $this->assertSame(['R-0010', 'R-0011'], $this->hechos('romana_recepcion')->pluck('referencia')->sort()->values()->all());
    }

    public function test_la_conciliacion_anula_lo_que_ya_no_existe_en_la_operacion(): void
    {
        $cliente = $this->clienteGlobal('PRODUCTOR DE PRUEBA SPA');
        $borrada = $this->recepcionRomana($cliente, 'R-0020', 500, '2026-11-03 09:00');
        $this->recepcionRomana($cliente, 'R-0021', 700, '2026-11-03 10:00');
        $this->capturar(['romana_recepcion']);

        DB::table('recepciones_romana')->where('id', $borrada)->delete();
        $this->travel(10)->minutes();
        $lectura = $this->capturar(['romana_recepcion'], conciliar: true);

        $this->assertTrue($lectura->conciliacion);
        $this->assertSame(1, $lectura->resumen['romana_recepcion']['eliminados']);
        $eliminada = $this->hechos('romana_recepcion')->firstWhere('origen_id', $borrada);
        $this->assertSame(Hecho::ESTADO_ANULADO, $eliminada->estado);
        $this->assertSame(Hecho::MOTIVO_ELIMINADO, $eliminada->motivo_version);
        $this->assertTrue($eliminada->datos['eliminado_en_operacion']);
        $this->assertSame(500.0, $eliminada->medidas['kilos_netos'], 'Conserva lo último que se supo del registro.');
        $this->assertSame(Hecho::ESTADO_VIGENTE, $this->hechos('romana_recepcion')->firstWhere('referencia', 'R-0021')->estado);
    }

    public function test_la_primera_lectura_del_dia_concilia_sola(): void
    {
        $this->assertTrue($this->capturar(['romana_recepcion'])->conciliacion);
        $this->travel(10)->minutes();
        $this->assertFalse($this->capturar(['romana_recepcion'])->conciliacion);
        $this->travel(1)->days();
        $this->assertTrue($this->capturar(['romana_recepcion'])->conciliacion);
    }

    public function test_una_fuente_con_error_no_detiene_a_las_demas(): void
    {
        $this->app->bind(Prefrio::class, fn () => new class implements FuenteHechos
        {
            public function clave(): string
            {
                return 'prefrio';
            }

            public function nombre(): string
            {
                return 'Prefrío';
            }

            public function descripcion(): string
            {
                return '';
            }

            public function intervalo(): bool
            {
                return false;
            }

            public function medidas(): array
            {
                return [];
            }

            public function cambios(ContextoCaptura $contexto, ?CarbonImmutable $desde): iterable
            {
                throw new RuntimeException('Tabla no disponible.');
            }

            public function porIds(ContextoCaptura $contexto, array $origenIds): iterable
            {
                return [];
            }
        });
        $this->recepcionRomana($this->clienteGlobal('PRODUCTOR DE PRUEBA SPA'), 'R-0030', 900, '2026-11-03 09:00');

        $lectura = $this->capturar(['romana_recepcion', 'prefrio']);

        $this->assertSame(Lectura::ESTADO_CON_ERRORES, $lectura->estado);
        $this->assertSame('No se pudo leer: Prefrío.', $lectura->error);
        $this->assertSame('Tabla no disponible.', $lectura->resumen['prefrio']['error']);
        $this->assertCount(1, $this->hechos('romana_recepcion'));
        $this->assertNull(Cursor::query()->find('prefrio'), 'La fuente con error se vuelve a leer completa.');
        $this->assertNotNull(Cursor::query()->find('romana_recepcion'));
    }

    public function test_no_corren_dos_capturas_a_la_vez(): void
    {
        $this->conCapturaEnOtraSesion(function (): void {
            try {
                $this->capturar();
                $this->fail('Dos capturas no deben correr a la vez.');
            } catch (CapturaEnCurso $enCurso) {
                $this->assertSame('Ya hay una captura de hechos en curso. Espere a que termine.', $enCurso->getMessage());
            }
        });

        // Liberada por la otra sesión, la captura vuelve a correr.
        $this->assertSame(Lectura::ESTADO_COMPLETADA, $this->capturar()->estado);
    }

    public function test_conciliar_una_fuente_no_pospone_a_las_demas(): void
    {
        $this->capturar(['romana_recepcion']);
        $lectura = $this->capturar(['romana_recepcion', 'validacion_pt']);
        $this->assertFalse($lectura->resumen['romana_recepcion']['conciliacion']);
        $this->assertTrue($lectura->resumen['validacion_pt']['conciliacion']);
        $this->assertNotNull(Cursor::query()->find('validacion_pt')->conciliada_at);
    }

    public function test_una_fuente_fallida_reintenta_su_conciliacion_sin_posponerla_24_horas(): void
    {
        $cliente = $this->clienteGlobal('PRODUCTOR PRUEBA');
        $borrada = $this->recepcionRomana($cliente, 'R-0040', 500, '2026-11-03 09:00');
        $otra = $this->recepcionRomana($cliente, 'R-0041', 700, '2026-11-03 10:00');
        $this->capturar(['romana_recepcion', 'validacion_pt']);
        $anterior = Cursor::query()->find('romana_recepcion')->conciliada_at->toDateTimeString();
        $this->travel(25)->hours();
        DB::table('recepciones_romana')->where('id', $borrada)->delete();
        DB::table('recepciones_romana')->where('id', $otra)->update(['peso_neto' => 800, 'updated_at' => now()]);

        $original = new RecepcionRomana;
        $fuente = Mockery::mock(FuenteHechos::class);
        $fuente->shouldReceive('clave')->andReturn('romana_recepcion');
        $fuente->shouldReceive('nombre')->andReturn('Recepciones en romana');
        $fuente->shouldReceive('cambios')->andReturnUsing(function ($contexto, $desde) use ($original): iterable {
            yield from $original->cambios($contexto, $desde);
            throw new RuntimeException('Fallo después de leer y escribir un lote.');
        });
        $this->app->bind(RecepcionRomana::class, fn () => $fuente);
        config(['finanzas.captura.lote' => 1]);
        $fallida = $this->capturar(['romana_recepcion', 'validacion_pt']);
        $this->assertSame(Lectura::ESTADO_CON_ERRORES, $fallida->estado);
        $this->assertSame($anterior, Cursor::query()->find('romana_recepcion')->conciliada_at->toDateTimeString());
        $this->assertSame(800.0, $this->hechos('romana_recepcion')->firstWhere('origen_id', $otra)->medidas['kilos_netos']);

        $this->app->bind(RecepcionRomana::class, fn () => new RecepcionRomana);
        $this->travel(10)->minutes();
        $lectura = $this->capturar(['romana_recepcion', 'validacion_pt']);
        $this->assertTrue($lectura->resumen['romana_recepcion']['conciliacion']);
        $this->assertFalse($lectura->resumen['validacion_pt']['conciliacion']);
        $this->assertSame(Hecho::ESTADO_ANULADO, $this->hechos('romana_recepcion')->firstWhere('origen_id', $borrada)->estado);
        $this->assertSame(2, $this->hechos('romana_recepcion')->firstWhere('origen_id', $otra)->version);
    }
}
