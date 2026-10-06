<?php

namespace App\Services\RecepcionEmbalada;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\FuenteHabilitacionAlmacenamiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Folio;
use App\Models\PersonalAccessToken;
use App\Models\PlanOperacional;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Folios\ServicioHabilitacionAlmacenamiento;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ServicioEtiquetasPt;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioAceptacionFrutaEmbalada
{
    public function __construct(
        private readonly ContratoRecepcionEmbalada $contrato,
        private readonly ServicioPlanesOperacionales $planes,
        private readonly ServicioHabilitacionAlmacenamiento $habilitacion,
    ) {}

    public function estado(string $id): array
    {
        $aceptacion = DB::table('aceptaciones_fruta_embalada')->where('recepcion_id', $id)->first();
        if (! $aceptacion) {
            return ['estado' => 'borrador', 'revision' => $this->contrato->cargar($id), 'folios' => [], 'incidencias' => []];
        }
        $folios = DB::table('recepcion_fruta_embalada_folios as rf')->join('folios as f', 'f.id', '=', 'rf.folio_id')
            ->where('rf.aceptacion_id', $aceptacion->id)->orderBy('rf.recepcion_pallet_id')
            ->get(['rf.*', 'f.numero_folio', 'f.estado_operacional', 'f.condicion_termica', 'f.habilitacion_almacenamiento', 'f.activo']);

        $impresos = DB::table('impresion_etiqueta_pt_folios as pf')->join('impresiones_etiquetas_pt as p', 'p.id', '=', 'pf.impresion_id')
            ->where('p.tipo', 'planta')->whereIn('pf.folio_id', $folios->pluck('folio_id'))->pluck('pf.folio_id');
        $servicioEtiquetas = app(ServicioEtiquetasPt::class);
        $disponibles = $servicioEtiquetas->consulta()->whereIn('id', $folios->pluck('folio_id'))->get()->keyBy('id');
        $folios->transform(function ($f) use ($impresos, $disponibles, $servicioEtiquetas) {
            $f->etiqueta_impresa = $impresos->contains($f->folio_id);
            $f->etiqueta_pendiente = (bool) $f->folio_interno && ! $f->etiqueta_impresa && (bool) $f->activo;
            $folio = $disponibles->get($f->folio_id);
            $f->etiqueta = $folio ? $servicioEtiquetas->etiqueta($folio) : null;
            $f->version_etiqueta = $folio ? $servicioEtiquetas->version($folio) : null;

            return $f;
        });

        return ['id' => $aceptacion->id, 'recepcion_id' => $id, 'estado' => $aceptacion->estado,
            'advertencias' => json_decode($aceptacion->advertencias, true), 'snapshot' => json_decode($aceptacion->snapshot, true),
            'plan_operacional_id' => $aceptacion->plan_operacional_id, 'folios' => $folios,
            'incidencias' => DB::table('incidencias_recepcion_embalada')->whereIn('recepcion_folio_id', $folios->pluck('id'))->get(),
        ];
    }

    public function aceptar(string $id, array $datos, User $usuario): array
    {
        return DB::transaction(function () use ($id, $datos, $usuario): array {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            $hash = hash('sha256', json_encode([$id, $datos['version']], JSON_THROW_ON_ERROR));
            $existente = DB::table('aceptaciones_fruta_embalada')->where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
            if ($existente) {
                if ((int) $existente->user_id !== $usuario->id || $existente->recepcion_id !== $id || ! hash_equals($existente->payload_hash, $hash) || $existente->temporada_id !== $temporada->id) {
                    throw new ConflictoOperacion('Esta operación de aceptación ya se utilizó con otros datos.');
                }

                return $this->estado($id);
            }
            $revision = $this->contrato->cargar($id, bloquear: true);
            $cabecera = $revision['cabecera'];
            if ($cabecera['temporada_id'] !== $temporada->id || ! $usuario->activo) {
                throw new DomainException('Solo se acepta fruta embalada de la temporada activa con un usuario activo.');
            }
            if ($cabecera['estado'] !== 'borrador' || DB::table('aceptaciones_fruta_embalada')->where('recepcion_id', $id)->exists()) {
                throw new ConflictoOperacion('La recepción ya no está en borrador.');
            }
            if (! hash_equals($revision['version'], $datos['version'])) {
                throw new ConflictoOperacion('La recepción o su catálogo cambiaron. Revisa los pallets antes de aceptar.');
            }
            $aceptacionId = (string) Str::uuid();
            $advertencias = [];
            DB::table('aceptaciones_fruta_embalada')->insert([
                'id' => $aceptacionId, 'recepcion_id' => $id, 'temporada_id' => $temporada->id, 'user_id' => $usuario->id,
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'estado' => 'aceptada',
                'snapshot' => json_encode($revision, JSON_THROW_ON_ERROR), 'advertencias' => '[]', 'aceptada_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $tareas = [];
            foreach ($revision['pallets'] as $p) {
                $numero = $p['folio_origen'];
                $interno = Folio::where('numero_folio', $numero)->lockForUpdate()->exists();
                if ($interno) {
                    $numero = $this->numeroInterno();
                }
                $umbral = $p['umbral_prefrio'];
                $aprobado = (bool) $cabecera['llega_con_prefrio'] && ($umbral === null || (float) $p['temperatura_pulpa'] <= (float) $umbral);
                if ($umbral === null) {
                    $advertencias[] = ['pallet_id' => $p['id'], 'especie' => $p['especie'], 'tipo' => 'sin_umbral_prefrio', 'mensaje' => 'Se aceptó la condición térmica declarada porque la especie no tiene umbral configurado.'];
                }
                $folio = Folio::create([
                    'temporada_id' => $temporada->id, 'numero_folio' => $numero, 'tipo_bulto' => $p['tipo_bulto'],
                    'condicion_sag_id' => $p['condicion_sag_id'] ?? null, 'activo' => true, 'fecha_ingreso' => $cabecera['recepcion_at'],
                    'origen_sistema' => 'recepcion_externa', 'identificador_externo' => $p['folio_origen'],
                    'variedad' => $p['variedad'], 'calibre' => $p['calibre'], 'exportadora' => $revision['cliente'],
                    'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
                    'condicion_termica' => CondicionTermicaFolio::PendientePrefrio, 'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
                    'fecha_proceso_pt' => $p['fecha_proceso_origen'],
                    'datos_externos' => ['recepcion_fruta_embalada_id' => $id, 'recepcion_pallet_id' => $p['id'],
                        'planta_origen' => $revision['planta_origen'], 'guia' => $cabecera['numero_guia'], 'csp' => $p['csp'] ?? null,
                        'folio_origen' => $p['folio_origen'], 'folio_interno' => $interno, 'fecha_proceso' => $p['fecha_proceso_origen'],
                        'fecha_embalaje' => $p['fecha_proceso_origen'], 'temperatura_pulpa' => $p['temperatura_pulpa'], 'servicio' => $cabecera['servicio'],
                        'especie' => $p['especie'], 'envase' => $p['envase'], 'csg' => $p['csg'], 'predio' => $p['predio'], 'cantidad_cajas' => (int) $p['cantidad_cajas'],
                        'composicion' => [['especie' => $p['especie'], 'variedad' => $p['variedad'], 'envase' => $p['envase'], 'cliente' => $revision['cliente'],
                            'envase_validacion_id' => $p['envase_validacion_id'], 'csg' => $p['csg'], 'predio' => $p['predio'],
                            'fecha_embalaje' => $p['fecha_proceso_origen'], 'cantidad_cajas' => (int) $p['cantidad_cajas']]],
                    ],
                ]);
                $enlaceId = (string) Str::uuid();
                DB::table('recepcion_fruta_embalada_folios')->insert(['id' => $enlaceId, 'aceptacion_id' => $aceptacionId,
                    'recepcion_pallet_id' => $p['id'], 'folio_id' => $folio->id, 'folio_origen' => $p['folio_origen'], 'folio_interno' => $interno, 'created_at' => now(), 'updated_at' => now()]);
                if ((bool) $cabecera['llega_con_prefrio'] && ! $aprobado) {
                    DB::table('incidencias_recepcion_embalada')->insert(['id' => (string) Str::uuid(), 'recepcion_folio_id' => $enlaceId,
                        'temperatura_pulpa' => $p['temperatura_pulpa'], 'umbral_prefrio' => $umbral, 'estado' => 'abierta', 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($aprobado) {
                    $this->habilitacion->habilitar($folio, CondicionTermicaFolio::PrefrioAprobado, FuenteHabilitacionAlmacenamiento::PrefrioOrigen,
                        $usuario, procesoOrigen: 'recepcion_externa', referenciaOrigen: $id,
                        observacion: $umbral === null ? 'Prefrío declarado en origen; especie sin umbral configurado.' : 'Temperatura de pulpa dentro del umbral de origen.');
                    if ($folio->tipo_bulto === TipoBulto::Pallet) {
                        $tareas[] = ['folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial, 'prioridad' => PrioridadOperacional::Alta,
                            'instruccion' => 'Ubicar pallet externo '.$numero.' recibido de '.$revision['planta_origen']['nombre'].'.',
                            'contexto' => ['origen_logico' => 'recepcion_externa', 'recepcion_fruta_embalada_id' => $id, 'cliente' => $revision['cliente'], 'variedad' => $p['variedad'], 'envase' => $p['envase']]];
                    }
                }
            }
            $plan = $tareas === [] ? null : $this->planes->crear($temporada, TipoPlanOperacional::AlmacenamientoPallet,
                'Recepción externa · '.$cabecera['numero_guia'], $usuario, $tareas, PrioridadOperacional::Alta,
                referenciaTipo: 'recepcion_fruta_embalada', referenciaId: $id, contexto: ['planner_horizon' => 'rolling', 'origen_logico' => 'recepcion_externa']);
            DB::table('aceptaciones_fruta_embalada')->where('id', $aceptacionId)->update(['advertencias' => json_encode($advertencias, JSON_THROW_ON_ERROR), 'plan_operacional_id' => $plan?->id]);
            $this->registrarEstado($id, 'aceptada', $datos, $usuario);

            return $this->estado($id);
        }, attempts: 3);
    }

    public function anular(string $id, array $datos, User $usuario): array
    {
        return DB::transaction(function () use ($id, $datos, $usuario): array {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            DB::table('recepciones_fruta_embalada')->where('id', $id)->lockForUpdate()->first();
            $aceptacion = DB::table('aceptaciones_fruta_embalada')->where('recepcion_id', $id)->lockForUpdate()->first();
            if (! $aceptacion || $aceptacion->temporada_id !== $temporada->id || ! $usuario->activo) {
                throw new DomainException('Solo se anula una recepción aceptada de la temporada activa.');
            }
            if ($aceptacion->estado === 'anulada') {
                if ($aceptacion->anulacion_operacion_id !== $datos['operacion_id'] || (int) $aceptacion->anulada_por_user_id !== $usuario->id || $aceptacion->motivo_anulacion !== $datos['motivo']) {
                    throw new ConflictoOperacion('La recepción ya fue anulada con otra operación.');
                }

                return $this->estado($id);
            }
            if (DB::table('aceptaciones_fruta_embalada')->where('anulacion_operacion_id', $datos['operacion_id'])->exists()) {
                throw new ConflictoOperacion('La operación de anulación ya se utilizó con otra recepción.');
            }
            $ids = DB::table('recepcion_fruta_embalada_folios')->where('aceptacion_id', $aceptacion->id)->pluck('folio_id');
            $folios = Folio::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($folios as $folio) {
                if ($folio->origen_sistema !== 'recepcion_externa' || $folio->movimientos()->exists() || $folio->ubicacionActual()->exists()
                    || $folio->procesosPrefrio()->exists() || $folio->reservaCargaActual()->exists()
                    || DB::table('repaletizaje_detalles')->where('folio_origen_id', $folio->id)->exists()
                    || $folio->tareasMovimiento()->whereIn('estado', ['en_proceso', 'completada'])->exists()) {
                    throw new ConflictoOperacion('No se puede anular: uno de los folios tuvo movimientos o actividad física posterior.');
                }
            }
            foreach (TareaMovimiento::whereIn('folio_id', $ids)->whereIn('estado', ['pendiente', 'bloqueada', 'asumida'])->orderBy('id')->lockForUpdate()->get() as $tarea) {
                if (! $this->planes->cancelarPorReplanificacion($tarea, $usuario, $datos['motivo'])) {
                    throw new ConflictoOperacion('Una tarea ya tiene actividad física y no se puede cancelar.');
                }
            }
            DB::table('incidencias_recepcion_embalada')->whereIn('recepcion_folio_id', DB::table('recepcion_fruta_embalada_folios')->where('aceptacion_id', $aceptacion->id)->pluck('id'))->update(['estado' => 'cancelada', 'updated_at' => now()]);
            foreach ($folios as $folio) {
                $folio->update(['activo' => false, 'estado_operacional' => EstadoOperacionalFolio::Anulado]);
            }
            if ($aceptacion->plan_operacional_id) {
                $plan = PlanOperacional::lockForUpdate()->findOrFail($aceptacion->plan_operacional_id);
                $plan->update(['estado' => 'cancelado', 'version' => $plan->version + 1, 'cancelado_at' => now(),
                    'cancelado_por_user_id' => $usuario->id, 'motivo_cancelacion' => $datos['motivo']]);
            }
            DB::table('aceptaciones_fruta_embalada')->where('id', $aceptacion->id)->update(['estado' => 'anulada', 'anulacion_operacion_id' => $datos['operacion_id'],
                'anulada_por_user_id' => $usuario->id, 'anulada_at' => now(), 'motivo_anulacion' => $datos['motivo'], 'updated_at' => now()]);
            $this->registrarEstado($id, 'anulada', $datos, $usuario);

            return $this->estado($id);
        }, attempts: 3);
    }

    private function registrarEstado(string $id, string $estado, array $datos, User $usuario): void
    {
        $recepcion = RecepcionFrutaEmbalada::findOrFail($id)->load('pallets');
        $antes = $recepcion->toArray();
        $recepcion->update(['estado' => $estado, 'version' => $recepcion->version + 1, 'actualizado_por_user_id' => $usuario->id]);
        $token = $usuario->currentAccessToken();
        $recepcion->eventos()->create([
            'operacion_id' => $datos['operacion_id'], 'payload_hash' => hash('sha256', json_encode([$estado, $id, $datos], JSON_THROW_ON_ERROR)),
            'user_id' => $usuario->id, 'dispositivo_id' => $token instanceof PersonalAccessToken ? $token->dispositivo_id : null,
            'antes' => $antes, 'despues' => $recepcion->refresh()->load('pallets')->toArray(),
        ]);
    }

    private function numeroInterno(): string
    {
        $prefijo = config('recepcion_embalada.prefijo_folio_interno');
        if (! is_string($prefijo) || ! preg_match('/^[A-Z0-9]{1,10}$/D', $prefijo)) {
            throw new DomainException('Configura un prefijo interno de 1 a 10 letras mayúsculas o dígitos.');
        }
        DB::table('secuencias_folio_planta')->insertOrIgnore(['id' => 'planta', 'ultimo' => 0]);
        $secuencia = DB::table('secuencias_folio_planta')->where('id', 'planta')->lockForUpdate()->first();
        $ultimo = (int) $secuencia->ultimo;
        do {
            $ultimo++;
            if ($ultimo > 9999999999) {
                throw new DomainException('La secuencia interna de la planta está agotada.');
            }
            $numero = $prefijo.str_pad((string) $ultimo, 10, '0', STR_PAD_LEFT);
        } while (Folio::where('numero_folio', $numero)->exists());
        DB::table('secuencias_folio_planta')->where('id', 'planta')->update(['ultimo' => $ultimo]);

        return $numero;
    }
}
