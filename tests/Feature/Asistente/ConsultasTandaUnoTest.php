<?php

namespace Tests\Feature\Asistente;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\GuiaDespachoEnvase;
use App\Services\Asistente\Consultas\CamionesEnPlanta;
use App\Services\Asistente\Consultas\EnvasesRecibidos;
use App\Services\Asistente\Consultas\EstadoCargas;
use App\Services\Asistente\Consultas\FoliosDespachados;
use App\Services\Asistente\Consultas\GuiasEnvases;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Primera tanda de consultas nuevas: camiones en planta, envases, despachos de envases y cargas. */
class ConsultasTandaUnoTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistente();
    }

    public function test_camiones_en_planta_solo_cuenta_recepciones_abiertas(): void
    {
        $this->recepcion('REC-1', 9000);
        $this->recepcion('REC-2', 0, ['estado' => 'en_bascula_salida', 'salida_at' => null, 'peso_tara' => null, 'peso_neto' => null, 'patente_camion' => 'ABCD12', 'ingreso_at' => now()->subMinutes(70)]);
        $this->recepcion('REC-3', 0, ['estado' => 'en_bascula_ingreso', 'salida_at' => null, 'peso_tara' => null, 'peso_neto' => null, 'ingreso_at' => now()->subMinutes(10)]);

        $resultado = app(CamionesEnPlanta::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(['REC-2', 'REC-3'], array_column($resultado->filas, 'recepcion'));
        $this->assertSame(['Esperando destare', 'Báscula de ingreso'], array_column($resultado->filas, 'etapa'));
        $this->assertSame(1, $resultado->cifras[1]['valor']);
        $this->assertSame(70, $resultado->cifras[2]['valor']);
    }

    public function test_envases_recibidos_usa_lo_validado_o_lo_declarado(): void
    {
        $validada = $this->recepcion('REC-1', 9000);
        $validada->detallesEnvases()->create(['tipo_envase' => 'bins', 'cantidad_declarada' => 20, 'cantidad_validada' => 18, 'cantidad_vacia' => 2]);
        $declarada = $this->recepcion('REC-2', 3000);
        $declarada->detallesEnvases()->create(['tipo_envase' => 'totes', 'cantidad_declarada' => 300]);
        $anulada = $this->recepcion('REC-3', 3000, ['estado' => 'anulada']);
        $anulada->detallesEnvases()->create(['tipo_envase' => 'bins', 'cantidad_declarada' => 99]);

        $resultado = app(EnvasesRecibidos::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame([['tipo' => 'Tote', 'cantidad' => 300, 'vacios' => 0, 'recepciones' => 1], ['tipo' => 'Bins plástico', 'cantidad' => 18, 'vacios' => 2, 'recepciones' => 1]], $resultado->filas);
        $this->assertStringContainsString('300 tote, 18 bins plástico', $resultado->resumen);
    }

    public function test_despachos_de_envases_solo_lista_borradores(): void
    {
        $this->guia('GDE-2611-0001', 'borrador');
        $this->guia('GDE-2611-0002', 'confirmada');

        $resultado = app(GuiasEnvases::class)->ejecutar($this->administrador, (new ParametrosConsulta)->conVista('borrador'));

        $this->assertSame(['GDE-2611-0001'], array_column($resultado->filas, 'numero'));
    }

    public function test_cargas_abiertas_excluye_las_terminales(): void
    {
        $this->carga('CAR-1', 'en_preparacion');
        $this->carga('CAR-2', 'cerrada');

        $resultado = app(EstadoCargas::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame([['codigo' => 'CAR-1', 'estado' => 'en preparacion', 'folios' => 0, 'publicada' => null]], $resultado->filas);
    }

    public function test_folios_despachados_cuenta_la_salida_del_periodo(): void
    {
        $carga = $this->carga('CAR-1', 'cerrada');
        $this->asignar($carga, $this->folio('6000000001', TipoBulto::Pallet), EstadoCargaFolio::Despachado, now()->subHour());
        $this->asignar($carga, $this->folio('6000000002', TipoBulto::Saldo), EstadoCargaFolio::Despachado, now()->subHour());
        $this->asignar($carga, $this->folio('6000000003', TipoBulto::Pallet), EstadoCargaFolio::Despachado, now()->subDays(3));
        $this->asignar($carga, $this->folio('6000000004', TipoBulto::Pallet), EstadoCargaFolio::Pendiente, now()->subHour());

        $resultado = app(FoliosDespachados::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(2, $resultado->cifras[0]['valor']);
        $this->assertSame(1, $resultado->cifras[1]['valor']);
        $this->assertStringContainsString('2 folios (1 pallets y 1 saldos) en 1 cargas', $resultado->resumen);
    }

    public function test_sin_datos_lo_dice(): void
    {
        foreach ([CamionesEnPlanta::class, EnvasesRecibidos::class, GuiasEnvases::class, EstadoCargas::class, FoliosDespachados::class] as $clase) {
            $this->assertSame(ResultadoConsulta::SIN_DATOS, app($clase)->ejecutar($this->administrador, new ParametrosConsulta)->estado, $clase);
        }
    }

    private function guia(string $numero, string $estado): void
    {
        GuiaDespachoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'numero' => $numero, 'temporada_id' => $this->temporada->id,
            'cliente_id' => $this->cliente->id, 'cliente_codigo_snapshot' => 'ASI', 'cliente_nombre_snapshot' => 'Exportadora Andina',
            'estado' => $estado, 'salida_at' => now(), 'patente_camion' => 'ABCD12', 'version' => 1, 'creado_por_user_id' => $this->administrador->id,
        ]);
    }

    private function carga(string $codigo, string $estado): Carga
    {
        $carga = Carga::create([
            'temporada_id' => $this->temporada->id, 'codigo' => $codigo, 'estado' => 'borrador',
            'creada_por_user_id' => $this->administrador->id, 'actualizada_por_user_id' => $this->administrador->id,
        ]);
        $carga->forceFill(['estado' => $estado])->save();

        return $carga;
    }

    private function folio(string $numero, TipoBulto $tipo): Folio
    {
        return Folio::create([
            'temporada_id' => $this->temporada->id, 'numero_folio' => $numero, 'tipo_bulto' => $tipo,
            'estado_operacional' => EstadoOperacionalFolio::Disponible, 'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado, 'fecha_ingreso' => now()->subDays(4),
            'activo' => true, 'variedad' => 'Santina', 'calibre' => '28 mm', 'marca' => 'MARCA', 'exportadora' => 'ASI',
            'origen_sistema' => 'validacion', 'identificador_externo' => (string) Str::uuid(),
            'estado_integracion' => EstadoIntegracionFolio::NoVinculado, 'datos_externos' => ['especie' => 'Cereza', 'cantidad_cajas' => 100],
        ]);
    }

    private function asignar(Carga $carga, Folio $folio, EstadoCargaFolio $estado, $salida): void
    {
        $asignacion = CargaFolio::create([
            'carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => EstadoCargaFolio::Pendiente,
            'asignado_por_user_id' => $this->administrador->id, 'asignado_at' => $salida->copy()->subHour(),
        ]);
        $asignacion->forceFill(['estado' => $estado, 'finalizado_at' => $estado === EstadoCargaFolio::Despachado ? $salida : null])->save();
    }
}
