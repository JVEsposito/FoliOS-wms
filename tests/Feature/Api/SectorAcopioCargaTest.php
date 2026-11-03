<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Cliente;
use App\Models\Dispositivo;
use App\Models\Embarque;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaTareaMovimiento;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Cargas\ServicioAcopioCarga;
use App\Services\Cargas\ServicioPlanConcentracionCarga;
use App\Services\Estiba\ServicioPlanesOperacionales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reproduce la Cámara de tránsito 07 del 26-10-2026: la carga quedó con pallets sueltos al fondo
 * de bandas ocupadas por otro cliente (B02-P01, B02-P03, B03-P09) y sus pallets de túnel no
 * tenían ninguna posición pegada libre, aunque la cámara estaba casi vacía.
 */
class SectorAcopioCargaTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private User $usuario;

    private Camara $camara;

    /** @var array<string, Posicion> */
    private array $posiciones = [];

    private int $folios = 0;

    public function test_un_acopio_encajonado_abre_un_sector_en_las_bandas_libres_mas_cercanas(): void
    {
        $carga = $this->escenarioCamaraSiete();
        $recepcion = $this->recepcion($carga, 5);
        $tarea = $recepcion->tareas()->first();

        $acopio = app(ServicioAcopioCarga::class)->paraFolio($tarea->folio_id, $tarea->id);

        // B04 queda pegada a la otra carga (B03-P01..P04): el sector parte en B05, anclado en B06
        // para que el primer pallet caiga en B05-P01 con la regla de vecindad de siempre.
        $this->assertSame('nuevo', $acopio['sector']['tipo']);
        $this->assertSame([['banda' => 6, 'posicion' => 0, 'nivel' => 1]], $acopio['puntos']);
        $this->assertSame([5, 8], [$acopio['sector']['banda_desde'], $acopio['sector']['banda_hasta']]);
        $this->assertSame(8, $acopio['sector']['requeridas']);
        $this->assertSame($this->camara->id, $acopio['camara_objetivo_id']);

        $acopios = app(ServicioAcopioCarga::class)->paraTareas($recepcion->tareas()->get());
        $this->assertSame($acopio, $acopios[$tarea->id], 'La tablet y el servidor ven el mismo sector.');
    }

    public function test_el_primer_pallet_va_al_fondo_del_sector_y_los_siguientes_crecen_desde_ahi(): void
    {
        $carga = $this->escenarioCamaraSiete();
        $recepcion = $this->recepcion($carga, 5);
        $planes = app(ServicioPlanesOperacionales::class);
        [$operador, $tablet] = $this->operador('A');
        [$otro, $otraTablet] = $this->operador('B');
        [$primera, $segunda] = $recepcion->tareas()->orderBy('secuencia')->take(2)->get()->all();
        $primera = $planes->asumir($primera, $operador, $tablet);
        $segunda = $planes->asumir($segunda, $otro, $otraTablet);

        // Junto al pallet suelto de B02 no hay lugar; el frente de B02 tampoco amplía el acopio.
        $this->assertRechazoAcopio(fn () => $planes->materializarDestino($primera, $this->posicion(2, 6), $operador, $tablet));
        $primera = $planes->materializarDestino($primera, $this->posicion(5, 1), $operador, $tablet);
        $this->assertSame($this->posicion(5, 1)->id, $primera->posicion_destino_id);

        $acopio = app(ServicioAcopioCarga::class)->paraFolio($segunda->folio_id, $segunda->id);
        $this->assertSame('grupo', $acopio['sector']['tipo']);
        $this->assertSame([['banda' => 5, 'posicion' => 1, 'nivel' => 1]], $acopio['puntos']);
        $this->assertRechazoAcopio(fn () => $planes->materializarDestino($segunda, $this->posicion(8, 1), $otro, $otraTablet));
        $segunda = $planes->materializarDestino($segunda, $this->posicion(5, 2), $otro, $otraTablet);
        $this->assertSame($this->posicion(5, 2)->id, $segunda->posicion_destino_id);
    }

    public function test_un_destino_reservado_se_respeta_al_iniciar_aunque_el_sector_se_haya_movido(): void
    {
        $carga = $this->escenarioCamaraSiete();
        $recepcion = $this->recepcion($carga, 5);
        $planes = app(ServicioPlanesOperacionales::class);
        [$operador, $tablet] = $this->operador('A');
        $tarea = $planes->asumir($recepcion->tareas()->orderBy('secuencia')->first(), $operador, $tablet);
        $tarea = $planes->materializarDestino($tarea, $this->posicion(5, 1), $operador, $tablet);

        // Otra carga ocupa B04: B05 deja de ser parte de un sector nuevo para esta carga.
        $otra = $this->carga('CAR-OTRA-2');
        $this->ubicar($this->folio($otra), $this->posicion(4, 1));
        $this->assertNotContains(
            ['banda' => 6, 'posicion' => 0, 'nivel' => 1],
            app(ServicioAcopioCarga::class)->paraFolio($tarea->folio_id, $tarea->id)['puntos'],
        );

        $iniciada = $planes->iniciar($tarea, $operador, $tablet);
        $this->assertSame(EstadoTareaMovimiento::EnProceso, $iniciada->estado);
        $this->assertSame($this->posicion(5, 1)->id, $iniciada->posicion_destino_id);
    }

    public function test_un_grupo_con_espacio_suficiente_sigue_siendo_el_acopio(): void
    {
        $carga = $this->camaraVacia(bandas: 3, profundidad: 6);
        foreach ([1, 2, 3] as $posicion) {
            $this->ubicar($this->folio($carga), $this->posicion(2, $posicion));
        }
        $recepcion = $this->recepcion($carga, 3);
        $tarea = $recepcion->tareas()->first();

        $acopio = app(ServicioAcopioCarga::class)->paraFolio($tarea->folio_id, $tarea->id);

        $this->assertSame('grupo', $acopio['sector']['tipo']);
        $this->assertSame([2, 2, 2], array_column($acopio['puntos'], 'banda'));
        $this->assertSame([1, 2, 3], array_column($acopio['puntos'], 'posicion'));
    }

    public function test_una_carga_nueva_parte_en_una_banda_libre_y_no_al_frente_de_una_ocupada(): void
    {
        $carga = $this->camaraVacia(bandas: 4, profundidad: 6);
        // B01 y B02 tienen pallets del mismo cliente sin carga y espacio al frente.
        foreach ([1, 2] as $banda) {
            foreach ([1, 2, 3] as $posicion) {
                $this->ubicar($this->folio(null), $this->posicion($banda, $posicion));
            }
        }
        $recepcion = $this->recepcion($carga, 4);
        $tarea = $recepcion->tareas()->first();

        $acopio = app(ServicioAcopioCarga::class)->paraFolio($tarea->folio_id, $tarea->id);

        $this->assertSame('nuevo', $acopio['sector']['tipo']);
        $this->assertSame([3, 4], [$acopio['sector']['banda_desde'], $acopio['sector']['banda_hasta']]);
        $this->assertSame([['banda' => 4, 'posicion' => 0, 'nivel' => 1]], $acopio['puntos']);
    }

    public function test_sin_bandas_libres_ni_espacio_pegado_el_pallet_igual_sale_del_tunel(): void
    {
        $carga = $this->camaraVacia(bandas: 2, profundidad: 3);
        // B01 llena con la carga encajonada al fondo; B02 con un pallet ajeno al fondo y espacio al frente.
        $this->ubicar($this->folio($carga), $this->posicion(1, 1));
        foreach ([2, 3] as $posicion) {
            $this->ubicar($this->folio(null), $this->posicion(1, $posicion));
        }
        $this->ubicar($this->folio(null), $this->posicion(2, 1));
        $this->ubicar($this->folio(null), $this->posicion(2, 2));
        $recepcion = $this->recepcion($carga, 1);
        $tarea = $recepcion->tareas()->first();
        $acopio = app(ServicioAcopioCarga::class)->paraFolio($tarea->folio_id, $tarea->id);

        $this->assertSame('libre', $acopio['sector']['tipo']);
        $this->assertSame([], $acopio['puntos']);
        app(ServicioAcopioCarga::class)->validarDestino($tarea, $this->posicion(2, 3));
    }

    public function test_la_concentracion_junta_la_carga_en_el_mismo_sector_que_las_recepciones(): void
    {
        $carga = $this->escenarioCamaraSiete();
        $this->recepcion($carga, 5);

        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar($carga, $this->usuario);

        $this->assertNotNull($plan);
        $this->assertSame('nuevo', $plan->contexto['sector_acopio']['tipo'] ?? null);
        foreach ($plan->tareas()->get() as $tarea) {
            $this->assertEquals([['banda' => 6, 'posicion' => 0, 'nivel' => 1]], $tarea->contexto['concentracion_puntos']);
        }
    }

    /**
     * Cámara de 8 bandas por 6 posiciones. B01 llena con otro cliente; B02 con la carga en P01 y
     * P03 entre pallets ajenos; B03 con otra carga en P01..P04 y la carga en P05; B04..B08 libres.
     */
    private function escenarioCamaraSiete(): Carga
    {
        $carga = $this->camaraVacia(bandas: 8, profundidad: 6);
        $otra = $this->carga('CAR-OTRA-1');
        foreach (range(1, 6) as $posicion) {
            $this->ubicar($this->folio(null), $this->posicion(1, $posicion));
        }
        $this->ubicar($this->folio($carga), $this->posicion(2, 1));
        $this->ubicar($this->folio(null), $this->posicion(2, 2));
        $this->ubicar($this->folio($carga), $this->posicion(2, 3));
        $this->ubicar($this->folio(null), $this->posicion(2, 4));
        $this->ubicar($this->folio(null), $this->posicion(2, 5));
        foreach (range(1, 4) as $posicion) {
            $this->ubicar($this->folio($otra), $this->posicion(3, $posicion));
        }
        $this->ubicar($this->folio($carga), $this->posicion(3, 5));

        return $carga;
    }

    public function test_dos_instructivos_no_comparten_banda_ni_con_reservas_antes_de_la_recepcion(): void
    {
        $carga = $this->camaraVacia(bandas: 4, profundidad: 6);
        [$a, $b] = $this->instructivos($carga);
        $recepcion = $this->recepcion($carga, 4);
        [$primera, $segunda, $tercera, $cuarta] = $recepcion->tareas()->orderBy('secuencia')->get()->all();
        CargaFolio::query()->whereIn('folio_id', [$primera->folio_id, $segunda->folio_id])->update(['instructivo_embarque_id' => $a->id]);
        CargaFolio::query()->whereIn('folio_id', [$tercera->folio_id, $cuarta->folio_id])->update(['instructivo_embarque_id' => $b->id]);
        $planes = app(ServicioPlanesOperacionales::class);
        [$operador, $tablet] = $this->operador('INST-A');
        [$otro, $otraTablet] = $this->operador('INST-B');
        $primera = $planes->asumir($primera, $operador, $tablet);
        $acopio = app(ServicioAcopioCarga::class)->paraFolio($primera->folio_id, $primera->id);
        $bandaA = $acopio['sector']['banda_desde'];
        $primera = $planes->materializarDestino($primera, $this->posicion($bandaA, 1), $operador, $tablet);
        $tercera = $planes->asumir($tercera, $otro, $otraTablet);
        // El pallet F001 todavía no llegó físicamente, pero su reserva protege la banda.
        try {
            $planes->materializarDestino($tercera, $this->posicion($bandaA, 2), $otro, $otraTablet);
            $this->fail('Se mezclaron instructivos en la misma banda.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('instructivo', $error->getMessage());
        }
        $acopioB = app(ServicioAcopioCarga::class)->paraFolio($tercera->folio_id, $tercera->id);
        $bandaB = $acopioB['sector']['banda_desde'];
        $this->assertNotSame($bandaA, $bandaB);
        $planes->materializarDestino($tercera, $this->posicion($bandaB, 1), $otro, $otraTablet);
        $propuestas = app(ServicioAcopioCarga::class)->paraTareas(collect([$segunda, $cuarta]));
        $this->assertSame([$bandaA], array_unique(array_column($propuestas[$segunda->id]['puntos'], 'banda')));
        $this->assertSame([$bandaB], array_unique(array_column($propuestas[$cuarta->id]['puntos'], 'banda')));
    }

    public function test_el_planificador_separa_instructivos_que_ya_estaban_mezclados_en_inventario(): void
    {
        $carga = $this->camaraVacia(bandas: 4, profundidad: 6);
        [$a, $b] = $this->instructivos($carga);
        foreach (range(1, 4) as $p) {
            $folio = $this->folio($carga);
            CargaFolio::query()->where('folio_id', $folio->id)->update(['instructivo_embarque_id' => $p <= 2 ? $a->id : $b->id]);
            $this->ubicar($folio, $this->posicion(1, $p));
        }
        $plan = app(ServicioPlanConcentracionCarga::class)->sincronizar($carga, $this->usuario);
        $this->assertNotNull($plan);
        $this->assertSame(0, $plan->contexto['porcentaje_actual']);
        $tareas = $plan->tareas()->where('contexto->tipo_decision', 'concentrar_carga')->get();
        $this->assertNotEmpty($tareas);
        foreach ($tareas as $tarea) {
            $this->assertContains($tarea->contexto['instructivo_embarque_id'], [$a->id, $b->id]);
            $this->assertStringContainsString('Instructivo F00', $tarea->instruccion);
        }
        $this->assertSame(1, PlanOperacional::query()->where('referencia_id', $carga->id)->count());
    }

    public function test_el_servidor_reserva_bandas_distintas_para_dos_instructivos_de_la_misma_orden(): void
    {
        $carga = $this->camaraVacia(bandas: 4, profundidad: 6);
        config(['planificador.compute' => 'server']);
        [$a, $b] = $this->instructivos($carga);
        $recepcion = $this->recepcion($carga, 2);
        [$primera, $segunda] = $recepcion->tareas()->orderBy('secuencia')->get()->all();
        CargaFolio::query()->where('folio_id', $primera->folio_id)->update(['instructivo_embarque_id' => $a->id]);
        CargaFolio::query()->where('folio_id', $segunda->folio_id)->update(['instructivo_embarque_id' => $b->id]);
        [$operador, $tablet] = $this->operador('SERVIDOR-A');
        [$otro, $otraTablet] = $this->operador('SERVIDOR-B');
        $planes = app(ServicioPlanesOperacionales::class);
        $primera = $planes->asumir($primera, $operador, $tablet);
        $segunda = $planes->asumir($segunda, $otro, $otraTablet);
        $this->assertNotNull($primera->posicion_destino_id);
        $this->assertNotNull($segunda->posicion_destino_id);
        $this->assertNotSame($primera->posicionDestino->banda, $segunda->posicionDestino->banda);
        $this->assertSame($primera->camara_destino_id, $segunda->camara_destino_id);
        $this->assertSame(2, ReservaTareaMovimiento::query()->conCompromisoVigente()->whereNotNull('bloqueo_posicion_id')->count());
    }

    private function instructivos(Carga $carga): array
    {
        $cliente = Cliente::create(['codigo' => 'INST', 'nombre' => 'Cliente instructivos', 'codigo_folio_materiales' => 'IN', 'activo' => true]);
        $embarque = Embarque::create(['temporada_id' => $this->temporada->id, 'cliente_id' => $cliente->id, 'carga_id' => $carga->id,
            'codigo' => 'EIN0000001', 'numero_correlativo' => 1, 'fecha_programada' => '2026-11-03', 'hora_programada' => '18:00',
            'intervalo_minutos' => 30, 'modalidad' => 'aereo', 'estado' => 'confirmado', 'version' => 1,
            'creado_por_user_id' => $this->usuario->id, 'actualizado_por_user_id' => $this->usuario->id]);
        $a = $embarque->instructivos()->create(['orden' => 1, 'numero_externo' => 'F001', 'cantidad_pallets' => 2]);
        $b = $embarque->instructivos()->create(['orden' => 2, 'numero_externo' => 'F002', 'cantidad_pallets' => 2]);

        return [$a, $b];
    }

    private function camaraVacia(int $bandas, int $profundidad): Carga
    {
        config([
            'planificador.mode' => 'guided',
            'planificador.generacion_automatica' => true,
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
            'planificador.frontier_max' => 4,
        ]);
        Temporada::query()->update(['activa' => false]);
        $this->temporada = Temporada::create(['codigo' => 'TEMP-ACOPIO', 'nombre' => 'Temporada acopio', 'fecha_inicio' => '2026-09-01', 'activa' => true]);
        $this->usuario = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $this->camara = Camara::create([
            'codigo' => 'CAM-ACOPIO', 'nombre' => 'Cámara de tránsito de prueba', 'contenido' => ContenidoCamara::Productos,
            'estado' => 'activa', 'cantidad_bandas' => $bandas, 'posiciones_por_banda' => $profundidad, 'cantidad_niveles' => 1,
            'creado_por_user_id' => $this->usuario->id, 'actualizado_por_user_id' => $this->usuario->id,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($this->camara, $this->usuario);
        foreach (range(1, $bandas) as $banda) {
            foreach (range(1, $profundidad) as $posicion) {
                $this->posiciones["{$banda}:{$posicion}"] = Posicion::create([
                    'camara_id' => $this->camara->id, 'banda' => $banda, 'posicion' => $posicion, 'nivel' => 1,
                    'etiqueta' => sprintf('B%02d-P%02d-N1', $banda, $posicion),
                ]);
            }
        }

        return $this->carga('CAR-000024');
    }

    private function carga(string $codigo): Carga
    {
        return Carga::create([
            'temporada_id' => $this->temporada->id, 'codigo' => $codigo, 'estado' => EstadoCarga::Pendiente,
            'prioridad' => PrioridadCarga::Urgente, 'version' => 1, 'creada_por_user_id' => $this->usuario->id,
            'actualizada_por_user_id' => $this->usuario->id, 'publicada_por_user_id' => $this->usuario->id, 'publicada_at' => now(),
        ]);
    }

    private function folio(?Carga $carga): Folio
    {
        $this->folios++;
        $folio = Folio::create([
            'temporada_id' => $this->temporada->id, 'numero_folio' => sprintf('PAL-ACO-%03d', $this->folios),
            'tipo_bulto' => TipoBulto::Pallet, 'fecha_ingreso' => now()->subDay()->addMinutes($this->folios), 'activo' => true,
            'marca' => 'FLA', 'exportadora' => 'LA AGUADA',
        ]);
        if ($carga) {
            $asignacion = CargaFolio::create([
                'carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => EstadoCargaFolio::Pendiente,
                'asignado_por_user_id' => $this->usuario->id, 'asignado_at' => now()->addSeconds($this->folios),
            ]);
            ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);
        }

        return $folio;
    }

    private function ubicar(Folio $folio, Posicion $posicion): void
    {
        UbicacionActual::withoutEvents(fn (): UbicacionActual => UbicacionActual::create([
            'folio_id' => $folio->id, 'camara_id' => $posicion->camara_id, 'posicion_id' => $posicion->id, 'ubicado_at' => now(),
        ]));
    }

    private function recepcion(Carga $carga, int $pallets): PlanOperacional
    {
        $folios = collect(range(1, $pallets))->map(fn (): Folio => $this->folio($carga));

        return app(ServicioPlanesOperacionales::class)->crear(
            temporada: $this->temporada, tipo: TipoPlanOperacional::RecepcionTunel,
            titulo: 'Recibir túnel 2', creadoPor: $this->usuario,
            tareas: $folios->map(fn (Folio $folio): array => [
                'folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
            ])->values()->all(), contexto: ['planner_horizon' => 'rolling'],
        );
    }

    private function posicion(int $banda, int $posicion): Posicion
    {
        return $this->posiciones["{$banda}:{$posicion}"];
    }

    /** @return array{User, Dispositivo} */
    private function operador(string $sufijo): array
    {
        return [
            User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]),
            Dispositivo::create(['codigo' => 'TABLET-ACOPIO-'.$sufijo.'-'.Str::upper(Str::random(4)), 'nombre' => "Tablet {$sufijo}", 'activo' => true]),
        ];
    }

    private function assertRechazoAcopio(callable $accion): void
    {
        try {
            $accion();
            $this->fail('El servidor aceptó un destino que no amplía el acopio de la carga.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('acopio', $error->getMessage());
        }
    }
}
