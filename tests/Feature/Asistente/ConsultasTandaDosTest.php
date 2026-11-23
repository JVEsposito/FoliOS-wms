<?php

namespace Tests\Feature\Asistente;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Models\AnulacionValidacionPallet;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\ProcesoPrefrio;
use App\Models\TunelPrefrio;
use App\Models\ValidacionPallet;
use App\Services\Asistente\Consultas\AnulacionesValidacion;
use App\Services\Asistente\Consultas\PrefrioEnCurso;
use App\Services\Asistente\Consultas\ValidacionesPt;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Segunda tanda: prefrío en curso, validaciones PT y folios anulados. */
class ConsultasTandaDosTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    private ?Dispositivo $dispositivo = null;

    private ?string $articulo = null;

    private ?string $origen = null;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00', 'America/Santiago'));
        $this->prepararAsistente();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_prefrio_en_curso_marca_los_que_pasaron_su_objetivo(): void
    {
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-1', 'nombre' => 'Túnel 1', 'capacidad_posiciones' => 20, 'setpoint_habitual' => -1.5,
            'estado_administrativo' => 'activo', 'estado_tecnico' => 'operativo', 'version_configuracion' => 1,
            'creado_por_user_id' => $this->administrador->id,
        ]);
        $this->proceso('PF-1', $tunel, EstadoProcesoPrefrio::EnProceso, now()->subMinutes(300), 240);
        $this->proceso('PF-2', $tunel, EstadoProcesoPrefrio::EnProceso, now()->subMinutes(60), 240);
        $this->proceso('PF-3', $tunel, EstadoProcesoPrefrio::Aprobado, now()->subMinutes(500), 240);

        $resultado = app(PrefrioEnCurso::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(['PF-1', 'PF-2'], array_column($resultado->filas, 'proceso'));
        $this->assertSame([60, 0], array_column($resultado->filas, 'atraso'));
        $this->assertSame(1, $resultado->cifras[1]['valor']);
    }

    public function test_validaciones_pt_cuenta_solo_aceptadas_de_la_jornada(): void
    {
        $local = fn (string $hora) => CarbonImmutable::parse($hora, 'America/Santiago')->utc();
        foreach ([
            ['aceptada', 'aprobado', '2026-11-22 08:00', 100],
            ['aceptada', 'observado', '2026-11-22 10:00', 80],
            ['conflicto', 'aprobado', '2026-11-22 10:30', 50],
            ['aceptada', 'aprobado', '2026-11-21 15:00', 90],
        ] as $i => [$estado, $resultado, $hora, $cajas]) {
            $this->validacion((string) (6000000000 + $i), $estado, $resultado, $local($hora), $cajas);
        }

        $resultado = app(ValidacionesPt::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(2, $resultado->cifras[0]['valor']);
        $this->assertSame(1, $resultado->cifras[1]['valor']);
        $this->assertSame(180, $resultado->cifras[2]['valor']);
    }

    public function test_anulaciones_lista_las_del_periodo(): void
    {
        $this->anulacion('6000000001', now()->subHour());
        $this->anulacion('6000000002', now()->subDays(3));

        $resultado = app(AnulacionesValidacion::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(['6000000001'], array_column($resultado->filas, 'folio'));
        $this->assertSame('Etiqueta: Folio mal impreso', $resultado->filas[0]['motivo']);
    }

    public function test_sin_datos_lo_dice(): void
    {
        foreach ([PrefrioEnCurso::class, ValidacionesPt::class, AnulacionesValidacion::class] as $clase) {
            $this->assertSame(ResultadoConsulta::SIN_DATOS, app($clase)->ejecutar($this->administrador, new ParametrosConsulta)->estado, $clase);
        }
    }

    private function validacion(string $folio, string $estado, string $resultado, CarbonImmutable $cuando, int $cajas = 100): ValidacionPallet
    {
        $this->dispositivo ??= Dispositivo::create(['codigo' => 'PDA-1', 'nombre' => 'PDA', 'plataforma' => 'android', 'activo' => true]);
        if ($this->articulo === null) {
            $this->articulo = (string) Str::uuid();
            $this->origen = (string) Str::uuid();
            DB::table('articulos_validacion')->insert(['id' => $this->articulo, 'temporada_id' => $this->temporada->id, 'especie' => 'Cereza', 'variedad' => 'Santina', 'calibre' => '2J', 'envase' => '5 kg']);
            DB::table('origenes_validacion')->insert(['id' => $this->origen, 'temporada_id' => $this->temporada->id, 'cliente' => 'ASI', 'marca' => 'MARCA', 'csg' => '104512']);
        }

        return ValidacionPallet::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'numero_folio' => $folio, 'numero_intento' => 1, 'tipo_bulto' => 'pallet',
            'cantidad_cajas' => $cajas, 'linea_proceso' => 1, 'turno' => 'A', 'temporada_id' => $this->temporada->id,
            'articulo_validacion_id' => $this->articulo, 'origen_validacion_id' => $this->origen, 'resultado' => $resultado, 'estado' => $estado,
            'catalogo_version_dispositivo' => 1, 'catalogo_version_servidor' => 1, 'snapshot' => [],
            'user_id' => $this->administrador->id, 'dispositivo_id' => $this->dispositivo->id,
            'generado_dispositivo_at' => $cuando, 'recibido_servidor_at' => $cuando,
        ]);
    }

    private function folio(string $numero): Folio
    {
        return Folio::create([
            'temporada_id' => $this->temporada->id, 'numero_folio' => $numero, 'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible, 'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado, 'fecha_ingreso' => now()->subDays(4),
            'activo' => false, 'variedad' => 'Santina', 'calibre' => '28 mm', 'marca' => 'MARCA', 'exportadora' => 'ASI',
            'origen_sistema' => 'validacion', 'identificador_externo' => (string) Str::uuid(),
            'estado_integracion' => EstadoIntegracionFolio::NoVinculado, 'datos_externos' => ['especie' => 'Cereza', 'cantidad_cajas' => 100],
        ]);
    }

    private function proceso(string $codigo, TunelPrefrio $tunel, EstadoProcesoPrefrio $estado, $inicio, int $objetivo): void
    {
        ProcesoPrefrio::create([
            'temporada_id' => $this->temporada->id, 'codigo' => $codigo, 'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $codigo), 'tunel_prefrio_id' => $tunel->id, 'estado' => $estado,
            'setpoint' => -1.5, 'duracion_objetivo_minutos' => $objetivo, 'version' => 1,
            'creado_por_user_id' => $this->administrador->id, 'iniciado_at' => $inicio,
        ]);
    }

    private function anulacion(string $folio, $cuando): void
    {
        $validacion = $this->validacion($folio, 'anulada', 'aprobado', CarbonImmutable::instance($cuando)->subHour());
        DB::table('anulaciones_validacion_pallet')->insert([
            'id' => (string) Str::uuid(), 'validacion_pallet_id' => $validacion->id, 'folio_id' => $this->folio($folio)->id, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $folio),
            'numero_folio' => $folio, 'motivo_categoria' => 'Etiqueta', 'motivo' => 'Folio mal impreso',
            'anulado_por_user_id' => $this->administrador->id, 'anulado_at' => $cuando, 'snapshot' => '{}',
            'created_at' => $cuando, 'updated_at' => $cuando,
        ]);
        $this->assertTrue(AnulacionValidacionPallet::query()->where('numero_folio', $folio)->exists());
    }
}
