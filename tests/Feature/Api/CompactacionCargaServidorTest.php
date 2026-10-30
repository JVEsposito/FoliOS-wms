<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioSesionEstiba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompactacionCargaServidorTest extends TestCase
{
    use RefreshDatabase;

    public static function capacidades(): array
    {
        return ['catorce a piso' => [14, 1, 0], 'ocho a piso' => [8, 1, 0],
            'dos niveles' => [14, 2, 0], 'banda parcialmente ocupada' => [14, 1, 10]];
    }

    #[DataProvider('capacidades')]
    public function test_veinte_pallets_completan_la_banda_antes_de_abrir_la_siguiente(int $profundidad, int $niveles, int $iniciales): void
    {
        config(['planificador.mode' => 'guided', 'planificador.compute' => 'server',
            'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $tablet = Dispositivo::create(['codigo' => 'COMPACTAR', 'nombre' => 'Tablet de prueba', 'activo' => true]);
        $camara = Camara::create(['codigo' => 'CAM-COMPACTAR', 'nombre' => 'Cámara de prueba', 'contenido' => 'productos',
            'estado' => 'activa', 'cantidad_bandas' => 4, 'posiciones_por_banda' => $profundidad, 'cantidad_niveles' => $niveles,
            'creado_por_user_id' => $supervisor->id, 'actualizado_por_user_id' => $supervisor->id]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $supervisor);
        foreach (range(1, 4) as $banda) {
            foreach (range(1, $niveles) as $nivel) {
                foreach (range(1, $profundidad) as $posicion) {
                    Posicion::create(['camara_id' => $camara->id, 'banda' => $banda, 'posicion' => $posicion, 'nivel' => $nivel,
                        'etiqueta' => sprintf('B%02d-P%02d-N%d', $banda, $posicion, $nivel)]);
                }
            }
        }
        $carga = Carga::create(['temporada_id' => $temporada->id, 'codigo' => 'CAR-COMPACTAR', 'estado' => 'pendiente',
            'camara_objetivo_id' => $camara->id, 'creada_por_user_id' => $supervisor->id, 'actualizada_por_user_id' => $supervisor->id]);
        $datos = [];
        foreach (range(1, 20) as $n) {
            $folio = Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => 'COMPACTAR-'.$n, 'tipo_bulto' => 'pallet',
                'fecha_ingreso' => now(), 'activo' => true, 'exportadora' => 'Cliente', 'marca' => 'Marca', 'datos_externos' => ['envase' => 'Caja 5'],
                'condicion_termica' => 'prefrio_aprobado', 'habilitacion_almacenamiento' => 'habilitado', 'estado_operacional' => 'pendiente_ubicacion']);
            $asignacion = CargaFolio::create(['carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => 'pendiente',
                'asignado_por_user_id' => $supervisor->id, 'asignado_at' => now()]);
            ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);
            if ($n <= $iniciales) {
                $pos = $camara->posiciones()->where('banda', 1)->where('nivel', 1)->where('posicion', $n)->sole();
                UbicacionActual::withoutEvents(fn () => UbicacionActual::create(['folio_id' => $folio->id,
                    'camara_id' => $camara->id, 'posicion_id' => $pos->id, 'ubicado_at' => now()]));
            } else {
                $datos[] = ['folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial];
            }
        }
        $planes = app(ServicioPlanesOperacionales::class);
        $plan = $planes->crear($temporada, TipoPlanOperacional::RecepcionTunel, 'Recibir veinte pallets', $supervisor, $datos,
            contexto: ['planner_horizon' => 'rolling']);
        $destinos = [];
        foreach ($plan->tareas()->orderBy('secuencia')->get() as $paso) {
            $paso = $planes->asumir($paso, $operador, $tablet);
            if ($paso->secuencia === 1) {
                $rival = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
                $otraTablet = Dispositivo::create(['codigo' => 'RIVAL', 'nombre' => 'Otra tablet', 'activo' => true]);
                $siguiente = $plan->tareas()->where('secuencia', 2)->sole();
                try {
                    $planes->asumir($siguiente, $rival, $otraTablet);
                    $this->fail('Abrió otra banda mientras la banda de la carga tenía espacio comprometido.');
                } catch (ConflictoOperacion $error) {
                    $this->assertStringContainsString('banda', $error->getMessage());
                    $this->assertNull($siguiente->refresh()->responsable_user_id);
                }
            }
            $revision = $camara->refresh()->revision_reservas;
            $versionReserva = $paso->reservaActiva->version;
            $paso = $planes->renovar($paso, $operador, $tablet);
            $this->assertSame($revision, $camara->refresh()->revision_reservas, 'El pulso no modifica el mapa físico de otros camareros.');
            $this->assertGreaterThan($versionReserva, $paso->reservaActiva->version);
            $destino = $paso->posicionDestino;
            $destinos[] = [$destino->banda, $destino->nivel, $destino->posicion];
            $sesion = app(ServicioSesionEstiba::class)->abrirParaTarea($camara, $operador, $tablet, $paso);
            $paso = $planes->iniciar($paso, $operador, $tablet);
            app(ServicioMovimientoEstiba::class)->ubicar(operacionId: (string) Str::uuid(), numeroFolio: $paso->folio->numero_folio,
                tipoBulto: $paso->folio->tipo_bulto, posicionDestino: $destino, sesionDestino: $sesion, usuario: $operador,
                dispositivo: $tablet, versionDestinoConocida: $camara->refresh()->version_plano, generadoDispositivoAt: now(), tareaMovimiento: $paso);
        }
        $esperados = [];
        foreach (range($iniciales, 19) as $i) {
            $esperados[] = [intdiv($i, $profundidad * $niveles) + 1, $i % $niveles + 1, intdiv($i % ($profundidad * $niveles), $niveles) + 1];
        }
        $this->assertSame($esperados, $destinos, json_encode($destinos));
        $this->assertSame(20, UbicacionActual::where('camara_id', $camara->id)->count());
    }
}
