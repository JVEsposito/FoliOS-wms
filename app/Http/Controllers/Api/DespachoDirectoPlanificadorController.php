<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TareaMovimientoResource;
use App\Models\TareaMovimiento;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Cargas\ServicioPlanDespachoDirecto;
use Illuminate\Http\Request;

class DespachoDirectoPlanificadorController extends Controller
{
    public function completarPrefrio(
        Request $request,
        TareaMovimiento $tareaMovimiento,
        ContextoOperacional $contexto,
        ServicioPlanDespachoDirecto $servicio,
    ): TareaMovimientoResource {
        [$usuario, $dispositivo] = $contexto->obtener($request);

        return new TareaMovimientoResource(
            $servicio->completarDesdePrefrio(
                $tareaMovimiento,
                $usuario,
                $dispositivo,
            )->load([
                'planOperacional:id,temporada_id,tipo,estado,prioridad,titulo,motivo,version,contexto',
                'folio:id,numero_folio,tipo_bulto,variedad,calibre,marca,exportadora,fecha_ingreso',
                'camaraOrigen:id,nombre',
                'posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
                'camaraDestino:id,nombre',
                'posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
                'responsable:id,name',
                'dispositivo:id,codigo,nombre',
                'reservaActiva:id,tarea_movimiento_id,bloqueo_tarea_id,bloqueo_posicion_id,estado,reservada_at,renovada_at,vence_at,version',
                'maniobraOperacional:id,plan_operacional_id,estado,prioridad,candidate_key,titulo,secuencia_actual,costo_movimientos,beneficio_estimado,riesgo_operacional,responsable_user_id,dispositivo_id,version,contexto',
                'maniobraOperacional.objetivos:id,tipo,estado,prioridad,titulo,motivo',
                'maniobraOperacional.ultimaDecisionArbitraje',
                'maniobraOperacional.ultimaDecisionArbitraje.ciclo:id,snapshot_version,created_at',
                'maniobraOperacional.pasos:id,maniobra_operacional_id,secuencia_maniobra,tipo_movimiento,tipo_paso_maniobra,estado,folio_id,camara_origen_id,posicion_origen_id,camara_destino_id,posicion_destino_id,instruccion,contexto',
                'maniobraOperacional.pasos.folio:id,numero_folio',
                'maniobraOperacional.pasos.camaraOrigen:id,nombre',
                'maniobraOperacional.pasos.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
                'maniobraOperacional.pasos.camaraDestino:id,nombre',
                'maniobraOperacional.pasos.posicionDestino:id,camara_id,etiqueta,banda,posicion,nivel',
                'maniobraOperacional.custodiasTemporales:id,maniobra_operacional_id,folio_id,camara_origen_id,posicion_origen_id,estado,extraido_at',
                'maniobraOperacional.custodiasTemporales.folio:id,numero_folio',
                'maniobraOperacional.custodiasTemporales.camaraOrigen:id,nombre',
                'maniobraOperacional.custodiasTemporales.posicionOrigen:id,camara_id,etiqueta,banda,posicion,nivel',
            ]),
        );
    }
}
