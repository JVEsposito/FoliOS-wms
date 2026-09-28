<?php

namespace Tests\Feature\Api;

use App\Enums\AccionResolucionDiscrepancia;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\CustodiaTemporalManiobra;
use App\Models\DiscrepanciaManiobra;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\ManiobraOperacional;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscrepanciaManiobraApiTest extends TestCase
{
    use RefreshDatabase;

    private int $secuencia = 0;

    public function test_bandeja_exige_supervision_y_publica_solo_la_temporada_activa(): void
    {
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $camarero = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $temporadaActiva = Temporada::query()->where('activa', true)->firstOrFail();
        $temporadaHistorica = Temporada::create([
            'codigo' => 'HIST-DISC',
            'nombre' => 'Temporada histórica',
            'activa' => false,
        ]);

        $abierta = $this->crearDiscrepancia($temporadaActiva, 'PAL-DISC-ABIERTA');
        $this->crearDiscrepancia($temporadaActiva, 'PAL-DISC-RESUELTA', true, $supervisor);
        $this->crearDiscrepancia($temporadaHistorica, 'PAL-DISC-HISTORICA');

        $this->getJson('/api/discrepancias-maniobra')->assertUnauthorized();
        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/discrepancias-maniobra')
            ->assertForbidden();

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/discrepancias-maniobra')
            ->assertOk()
            ->assertJsonPath('resumen.abiertas', 1)
            ->assertJsonPath('resumen.resueltas', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $abierta->id)
            ->assertJsonPath('data.0.folio.numero', 'PAL-DISC-ABIERTA')
            ->assertJsonPath('data.0.maniobra.estado', 'pausada_discrepancia')
            ->assertJsonPath('data.0.maniobra.version', 3)
            ->assertJsonPath('data.0.tarea.estado', 'en_proceso')
            ->assertJsonPath('data.0.restricciones.cancelar', 'tarea_en_proceso')
            ->assertJsonMissing(['numero' => 'PAL-DISC-HISTORICA']);
    }

    public function test_filtra_resueltas_y_busca_por_folio_con_auditoria_completa(): void
    {
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->crearDiscrepancia($temporada, 'PAL-DISC-OTRA');
        $resuelta = $this->crearDiscrepancia(
            $temporada,
            'PAL-DISC-BUSCADA',
            true,
            $supervisor,
        );

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/discrepancias-maniobra?estado=resuelta&q=BUSCADA')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $resuelta->id)
            ->assertJsonPath('data.0.estado', 'resuelta')
            ->assertJsonPath('data.0.accion_resolucion', 'reanudar_maniobra')
            ->assertJsonPath('data.0.resolucion', 'Posición verificada físicamente.')
            ->assertJsonPath('data.0.resuelta_por.id', $supervisor->id)
            ->assertJsonPath('data.0.maniobra.plan.tipo', 'reordenamiento_camara')
            ->assertJsonPath('data.0.tarea.origen.posicion', 'B01-P01-N1')
            ->assertJsonPath('data.0.tarea.destino.posicion', 'B01-P02-N1');
    }

    public function test_distingue_pallet_no_encontrado_en_resumen_y_filtro(): void
    {
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $noEncontrado = $this->crearDiscrepancia($temporada, 'PAL-PERDIDO');
        $noEncontrado->update(['tipo' => 'pallet_no_encontrado']);
        $this->crearDiscrepancia($temporada, 'PAL-OTRO');

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/discrepancias-maniobra?tipo=pallet_no_encontrado')
            ->assertOk()
            ->assertJsonPath('resumen.abiertas', 2)
            ->assertJsonPath('resumen.pallets_no_encontrados', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $noEncontrado->id);
    }

    public function test_valida_los_filtros_de_la_bandeja(): void
    {
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/discrepancias-maniobra?estado=pendiente&por_pagina=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['estado', 'por_pagina']);
    }

    public function test_replanifica_solo_el_sufijo_reversible_y_deja_auditoria(): void
    {
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $discrepancia = $this->crearDiscrepancia(
            $temporada,
            'PAL-DISC-REPLAN',
            estadoTarea: 'bloqueada',
        );
        $maniobra = $discrepancia->maniobraOperacional;

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/discrepancias-maniobra')
            ->assertOk()
            ->assertJsonPath('data.0.restricciones.replanificar', null)
            ->assertJsonPath('data.0.restricciones.retorno_seguro', 'sin_custodia_temporal');

        $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/discrepancias-maniobra/{$discrepancia->id}/resolver", [
                'accion' => 'replanificar_sufijo',
                'version_maniobra' => $maniobra->version,
                'resolucion' => 'La geometría verificada cambió; recalcular desde el estado confirmado.',
            ])
            ->assertOk()
            ->assertJsonPath('data.accion_resolucion', 'replanificar_sufijo')
            ->assertJsonPath('data.maniobra.estado', 'cancelada')
            ->assertJsonPath('data.tarea.estado', 'cancelada');

        $this->assertSame('recalculada', $maniobra->planOperacional->refresh()
            ->contexto['replanificacion_discrepancia']['estado']);
        $this->assertSame(
            $discrepancia->id,
            $maniobra->planOperacional->contexto['replanificacion_discrepancia']['discrepancia_id'],
        );
    }

    public function test_retorno_seguro_transfiere_custodia_y_vuelve_a_la_misma_tablet(): void
    {
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $discrepancia = $this->crearDiscrepancia(
            $temporada,
            'PAL-DISC-OBJETIVO',
            estadoTarea: 'bloqueada',
        );
        $maniobra = $discrepancia->maniobraOperacional;
        $tareaObjetada = $discrepancia->tareaMovimiento;
        $operador = $discrepancia->reportadaPor;
        $dispositivo = $discrepancia->dispositivo;
        $posicionRetorno = $tareaObjetada->posicionOrigen;

        $maniobra->update([
            'responsable_user_id' => $operador->id,
            'dispositivo_id' => $dispositivo->id,
            'secuencia_actual' => 2,
        ]);
        $tareaObjetada->update([
            'secuencia' => 2,
            'secuencia_maniobra' => 2,
        ]);
        $blocker = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => 'PAL-DISC-CUSTODIA',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $extraccion = TareaMovimiento::create([
            'plan_operacional_id' => $maniobra->plan_operacional_id,
            'maniobra_operacional_id' => $maniobra->id,
            'secuencia' => 1,
            'secuencia_maniobra' => 1,
            'tipo_movimiento' => 'retiro',
            'tipo_paso_maniobra' => 'extraccion_temporal',
            'estado' => 'completada',
            'prioridad' => 'normal',
            'folio_id' => $blocker->id,
            'camara_origen_id' => $posicionRetorno->camara_id,
            'posicion_origen_id' => $posicionRetorno->id,
            'responsable_user_id' => $operador->id,
            'dispositivo_id' => $dispositivo->id,
            'completada_at' => now()->subMinute(),
        ]);
        $retornoOriginal = TareaMovimiento::create([
            'plan_operacional_id' => $maniobra->plan_operacional_id,
            'maniobra_operacional_id' => $maniobra->id,
            'secuencia' => 3,
            'secuencia_maniobra' => 3,
            'tipo_movimiento' => 'ubicacion_inicial',
            'tipo_paso_maniobra' => 'retorno_banda',
            'estado' => 'bloqueada',
            'prioridad' => 'normal',
            'folio_id' => $blocker->id,
            'camara_destino_id' => $posicionRetorno->camara_id,
            'contexto' => [
                'camara_retorno_id' => $posicionRetorno->camara_id,
                'banda_retorno' => $posicionRetorno->banda,
                'nivel_retorno' => $posicionRetorno->nivel,
                'profundidad_resultante' => $posicionRetorno->posicion,
            ],
        ]);
        $custodia = CustodiaTemporalManiobra::create([
            'maniobra_operacional_id' => $maniobra->id,
            'folio_id' => $blocker->id,
            'tarea_extraccion_id' => $extraccion->id,
            'camara_origen_id' => $posicionRetorno->camara_id,
            'posicion_origen_id' => $posicionRetorno->id,
            'banda_origen' => $posicionRetorno->banda,
            'posicion_origen' => $posicionRetorno->posicion,
            'nivel_origen' => $posicionRetorno->nivel,
            'estado' => 'activa',
            'bloqueo_folio_id' => $blocker->id,
            'user_id' => $operador->id,
            'dispositivo_id' => $dispositivo->id,
            'extraido_at' => now()->subMinute(),
        ]);
        $reservaBanda = ReservaBandaManiobra::create([
            'maniobra_operacional_id' => $maniobra->id,
            'camara_id' => $posicionRetorno->camara_id,
            'banda' => $posicionRetorno->banda,
            'nivel' => $posicionRetorno->nivel,
            'clave_bloqueo' => implode(':', [
                $posicionRetorno->camara_id,
                $posicionRetorno->banda,
                $posicionRetorno->nivel,
            ]),
            'reservada_at' => now(),
        ]);

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/discrepancias-maniobra')
            ->assertOk()
            ->assertJsonPath('data.0.maniobra.custodias_activas', 1)
            ->assertJsonPath('data.0.restricciones.replanificar', 'custodia_temporal_activa')
            ->assertJsonPath('data.0.restricciones.retorno_seguro', null);

        $respuesta = $this->actingAs($supervisor, 'sanctum')
            ->postJson("/api/discrepancias-maniobra/{$discrepancia->id}/resolver", [
                'accion' => 'retorno_seguro',
                'version_maniobra' => $maniobra->version,
                'resolucion' => 'La ruta útil cambió; devolver el pallet temporal antes de recalcular.',
            ])
            ->assertOk()
            ->assertJsonPath('data.accion_resolucion', 'retorno_seguro')
            ->assertJsonPath('data.maniobra.estado', 'cancelada')
            ->assertJsonPath('data.recuperacion.estado', 'en_ejecucion')
            ->assertJsonPath('data.recuperacion.tarea_estado', 'asumida')
            ->assertJsonPath('data.recuperacion.responsable_user_id', $operador->id)
            ->assertJsonPath('data.recuperacion.dispositivo_id', $dispositivo->id);
        $recuperacionId = $respuesta->json('data.recuperacion.maniobra_id');
        $tareaRecuperacionId = $respuesta->json('data.recuperacion.tarea_id');

        $this->assertSame($recuperacionId, $custodia->refresh()->maniobra_operacional_id);
        $this->assertSame($recuperacionId, $reservaBanda->refresh()->maniobra_operacional_id);
        $this->assertSame($tareaRecuperacionId, $retornoOriginal->refresh()->reemplazada_por_tarea_id);
        $this->assertDatabaseHas('tareas_movimiento', [
            'id' => $tareaRecuperacionId,
            'maniobra_operacional_id' => $recuperacionId,
            'folio_id' => $blocker->id,
            'posicion_destino_id' => $posicionRetorno->id,
            'tipo_paso_maniobra' => 'retorno_banda',
            'estado' => 'asumida',
        ]);
        $this->actingAs($operador, 'sanctum')
            ->getJson('/api/tareas-movimiento?asignacion=mias')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tareaRecuperacionId)
            ->assertJsonPath('data.0.contexto.tipo_decision', 'retorno_seguro_supervisado');
    }

    private function crearDiscrepancia(
        Temporada $temporada,
        string $numeroFolio,
        bool $resuelta = false,
        ?User $supervisor = null,
        string $estadoTarea = 'en_proceso',
    ): DiscrepanciaManiobra {
        $this->secuencia++;
        $indice = str_pad((string) $this->secuencia, 2, '0', STR_PAD_LEFT);
        $reportante = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $camara = Camara::create([
            'codigo' => "CAM-DISC-{$indice}",
            'nombre' => "Cámara discrepancia {$indice}",
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 2,
            'cantidad_niveles' => 1,
        ]);
        $origen = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        $destino = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 2,
            'nivel' => 1,
            'etiqueta' => 'B01-P02-N1',
        ]);
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => $numeroFolio,
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => "TAB-DISC-{$indice}",
            'nombre' => "Tablet discrepancia {$indice}",
            'activo' => true,
        ]);
        $plan = PlanOperacional::create([
            'temporada_id' => $temporada->id,
            'tipo' => 'reordenamiento_camara',
            'estado' => 'en_ejecucion',
            'prioridad' => 'normal',
            'titulo' => "Plan discrepancia {$indice}",
            'referencia_tipo' => 'camara_reordenamiento',
            'referencia_id' => $camara->id,
            'contexto' => ['bandas_analizadas' => [1]],
            'creado_por_user_id' => $reportante->id,
            'programado_at' => now(),
        ]);
        $maniobra = ManiobraOperacional::create([
            'plan_operacional_id' => $plan->id,
            'creado_por_user_id' => $reportante->id,
            'estado' => $resuelta ? 'pendiente' : 'pausada_discrepancia',
            'prioridad' => 'normal',
            'candidate_key' => "discrepancia-{$indice}",
            'titulo' => "Mover {$numeroFolio}",
            'costo_movimientos' => 1,
            'version' => 3,
        ]);
        $tarea = TareaMovimiento::create([
            'plan_operacional_id' => $plan->id,
            'maniobra_operacional_id' => $maniobra->id,
            'secuencia' => 1,
            'secuencia_maniobra' => 1,
            'tipo_movimiento' => 'reubicacion',
            'tipo_paso_maniobra' => 'movimiento_permanente',
            'estado' => $resuelta ? 'pendiente' : $estadoTarea,
            'prioridad' => 'normal',
            'folio_id' => $folio->id,
            'camara_origen_id' => $camara->id,
            'posicion_origen_id' => $origen->id,
            'camara_destino_id' => $camara->id,
            'posicion_destino_id' => $destino->id,
        ]);

        return DiscrepanciaManiobra::create([
            'maniobra_operacional_id' => $maniobra->id,
            'tarea_movimiento_id' => $tarea->id,
            'folio_id' => $folio->id,
            'tipo' => 'posicion_no_coincide',
            'detalle' => "La posición de {$numeroFolio} no coincide.",
            'estado' => $resuelta ? 'resuelta' : 'abierta',
            'reportada_por_user_id' => $reportante->id,
            'dispositivo_id' => $dispositivo->id,
            'reportada_at' => now()->subMinutes($this->secuencia),
            'resuelta_por_user_id' => $resuelta ? $supervisor?->id : null,
            'resuelta_at' => $resuelta ? now() : null,
            'accion_resolucion' => $resuelta
                ? AccionResolucionDiscrepancia::ReanudarManiobra
                : null,
            'resolucion' => $resuelta ? 'Posición verificada físicamente.' : null,
        ]);
    }
}
