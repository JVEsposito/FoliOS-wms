<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\CargaFolio;
use App\Models\Cliente;
use App\Models\Embarque;
use App\Models\IncidenciaCargaFolio;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Operacion\ResumenDespachoOperacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class SubestadoInstructivosDespachoTest extends TestCase
{
    use PreparaDespachoFrigorifico, RefreshDatabase;

    private function preparar(array $programados = [2, 2], int $cantidadFolios = 4): array
    {
        $contexto = $this->crearContextoFrio($cantidadFolios);
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        $cliente = Cliente::create(['codigo' => 'CLI-SUB', 'nombre' => 'Cliente instructivos', 'activo' => true]);
        $embarque = Embarque::create([
            'temporada_id' => $carga->temporada_id, 'cliente_id' => $cliente->id, 'carga_id' => $carga->id,
            'codigo' => 'EAG0000001', 'numero_correlativo' => 1, 'fecha_programada' => now()->toDateString(),
            'hora_programada' => '18:00', 'intervalo_minutos' => 60, 'modalidad' => 'aereo', 'estado' => 'confirmado',
            'creado_por_user_id' => $contexto['administrador']->id,
            'actualizado_por_user_id' => $contexto['administrador']->id,
        ]);
        $instructivos = collect($programados)->map(fn ($cantidad, $indice) => $embarque->instructivos()->create([
            'orden' => $indice + 1, 'numero_externo' => sprintf('F%03d', $indice + 1),
            'cantidad_pallets' => $cantidad, 'booking' => 'BOOK-'.($indice + 1), 'recibidor' => 'Recibidor de prueba',
        ]));
        $asignaciones = collect($contexto['folios'])->map(fn ($folio) => CargaFolio::where('carga_id', $carga->id)->where('folio_id', $folio->id)->firstOrFail());

        return [$contexto, $carga, $embarque, $instructivos, $asignaciones];
    }

    private function fila(): array
    {
        return $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum')
            ->getJson('/api/operacion-ahora')->assertOk()->assertJsonPath('data.despacho.resumen.activas', 1)
            ->json('data.despacho.cargas.0');
    }

    public function test_cada_instructivo_muestra_su_carguio_y_estado_sin_heredar_el_del_camion(): void
    {
        [$contexto, $carga, , $instructivos, $asignaciones] = $this->preparar();
        foreach ($asignaciones as $indice => $asignacion) {
            $asignacion->update(['instructivo_embarque_id' => $instructivos[$indice < 2 ? 0 : 1]->id]);
        }
        foreach (array_slice($contexto['folios'], 0, 3) as $folio) {
            $this->enviarAlAnden($contexto, $carga, $folio);
        }
        $asignaciones[0]->update(['estado' => 'cargado']);
        $asignaciones[1]->update(['estado' => 'cargado']);
        $fila = $this->fila();
        $this->assertCount(2, $fila['instructivos']);
        [$a, $b] = $fila['instructivos'];
        $this->assertSame('F001', $a['numero_externo']);
        $this->assertSame('BOOK-1', $a['booking']);
        $this->assertSame('cargado_completo', $a['estado']);
        $this->assertSame(2, $a['carguio']['cargados']);
        $this->assertSame(100, $a['carguio']['porcentaje']);
        $this->assertSame('despacho_parcial', $b['estado']);
        $this->assertSame(0, $b['carguio']['cargados']);
        $this->assertSame(1, $b['carguio']['en_anden_por_cargar']);
        $this->assertSame(4, $fila['separacion']['total']);
        $this->assertSame(2, $fila['carguio']['cargados']);
        $this->assertFalse($fila['lista_para_cerrar']);
    }

    public function test_no_marca_completo_un_instructivo_con_pallets_programados_sin_asignar(): void
    {
        [$contexto, $carga, , $instructivos, $asignaciones] = $this->preparar([2, 1], 1);
        $asignaciones[0]->update(['instructivo_embarque_id' => $instructivos[0]->id]);
        $this->enviarAlAnden($contexto, $carga, $contexto['folios'][0]);
        $asignaciones[0]->update(['estado' => 'cargado']);
        [$a, $b] = $this->fila()['instructivos'];
        $this->assertSame(1, $a['pallets_asignados']);
        $this->assertSame(1, $a['pallets_pendientes_asignar']);
        $this->assertSame(2, $a['carguio']['total']);
        $this->assertSame(50, $a['carguio']['porcentaje']);
        $this->assertSame('cargando', $a['estado']);
        $this->assertSame('sin_asignar', $b['estado']);
        $this->assertSame(1, $b['separacion']['total']);
        $this->assertSame(0, $b['separacion']['porcentaje']);
        $this->assertFalse($b['separacion']['cumple_umbral']);
    }

    public function test_conserva_el_tratamiento_de_bandas_mezcladas_y_expone_folios_sin_instructivo(): void
    {
        [, , , $instructivos, $asignaciones] = $this->preparar([1, 1], 3);
        $asignaciones[0]->update(['instructivo_embarque_id' => $instructivos[0]->id]);
        $asignaciones[1]->update(['instructivo_embarque_id' => $instructivos[1]->id]);
        $fila = $this->fila();
        $this->assertCount(3, $fila['instructivos']);
        foreach ($fila['instructivos'] as $grupo) {
            $this->assertSame(0, $grupo['separacion']['acopiados']);
            $this->assertFalse($grupo['separacion']['cumple_umbral']);
        }
        $this->assertNull($fila['instructivos'][2]['id']);
        $this->assertSame(1, $fila['instructivos'][2]['pallets_asignados']);
        $this->assertSame($fila['separacion']['total'], array_sum(array_column($fila['instructivos'], 'pallets_asignados')));
    }

    public function test_una_incidencia_historica_se_atribuye_a_su_instructivo_y_no_a_los_demas(): void
    {
        [$contexto, $carga, , $instructivos, $asignaciones] = $this->preparar();
        foreach ($asignaciones as $indice => $asignacion) {
            $asignacion->update(['instructivo_embarque_id' => $instructivos[$indice < 2 ? 0 : 1]->id]);
        }
        $ubicacion = $contexto['folios'][2]->ubicacionActual;
        IncidenciaCargaFolio::create([
            'operacion_reporte_id' => (string) Str::uuid(), 'reporte_payload_hash' => hash('sha256', 'incidencia'),
            'carga_folio_id' => $asignaciones[2]->id, 'tipo' => 'pallet_inestable', 'estado' => 'abierta',
            'camara_id' => $contexto['camara']->id, 'posicion_id' => $ubicacion->posicion_id,
            'reportado_por_user_id' => $contexto['operador']->id, 'dispositivo_id' => $contexto['dispositivo']->id,
            'sesion_estiba_id' => $contexto['sesion']->id, 'reportada_at' => now(),
        ]);
        // Conserva una incidencia sin resolver de una asignación que dejó de estar vigente.
        $asignaciones[2]->reservaActiva()->delete();
        $asignaciones[2]->update(['estado' => 'reemplazado']);
        $fila = $this->fila();
        $this->assertSame(1, $fila['incidencias_abiertas']);
        $this->assertSame(0, $fila['instructivos'][0]['incidencias_abiertas']);
        $this->assertSame(1, $fila['instructivos'][1]['incidencias_abiertas']);
        $this->assertSame('con_incidencia', $fila['instructivos'][1]['estado']);
        $this->assertFalse($fila['lista_para_cerrar']);
    }

    public function test_agregar_instructivos_no_genera_consultas_por_cada_grupo(): void
    {
        [, $carga, $embarque, $instructivos, $asignaciones] = $this->preparar();
        $asignaciones->each(fn ($a) => $a->update(['instructivo_embarque_id' => $instructivos[0]->id]));
        $servicio = app(ResumenDespachoOperacion::class);
        $temporada = Temporada::findOrFail($carga->temporada_id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $servicio->obtener($temporada);
        $antes = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(3, 12) as $orden) {
            $embarque->instructivos()->create(['orden' => $orden, 'numero_externo' => 'F'.$orden, 'cantidad_pallets' => 1]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $resultado = $servicio->obtener($temporada);
        $despues = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(12, $resultado['cargas'][0]['instructivos']);
        $this->assertSame($antes, $despues);
    }
}
