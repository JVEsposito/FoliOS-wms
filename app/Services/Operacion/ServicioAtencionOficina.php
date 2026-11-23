<?php

namespace App\Services\Operacion;

use App\Enums\EstadoAuditoriaIntegridadOperacional;
use App\Enums\EstadoDespachoMaterial;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\TipoRecepcionRomana;
use App\Models\AuditoriaIntegridadOperacional;
use App\Models\DespachoMaterial;
use App\Models\DiscrepanciaManiobra;
use App\Models\IngresoPorteria;
use App\Models\ProcesoPrefrio;
use App\Models\RecepcionRomana;
use App\Models\RetencionLoteSag;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Materiales\ServicioConsultaVencimientosMaterial;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class ServicioAtencionOficina
{
    public function __construct(
        private readonly CatalogoModulosAcceso $modulos,
        private readonly ServicioConsultaVencimientosMaterial $vencimientos,
        private readonly ServicioPanelGerencial $panel,
    ) {}

    public function obtener(User $usuario, ?Temporada $temporada): array
    {
        $gate = Gate::forUser($usuario);
        $id = $temporada?->id;
        $areas = [];
        if ($usuario->esCliente()) {
            return ['total' => null, 'parcial' => false, 'areas' => []];
        }

        $romana = $gate->allows('consultar-romana');
        if ($romana || $gate->allows('consultar-porteria')) {
            $areas['materia-prima'][] = $this->fuente('Ingresos de Portería por revisar', $romana ? '/oficina/romana' : '/oficina/porteria',
                IngresoPorteria::pendientesRevision()->count());
        }
        if ($romana) {
            $areas['materia-prima'][] = $this->fuente('Camiones con fruta por destarar', '/oficina/romana',
                $id === null ? null : RecepcionRomana::query()
                    ->where('temporada_id', $id)->whereNull('eliminada_at')
                    ->where('tipo_recepcion', '!=', TipoRecepcionRomana::SoloEnvases)
                    ->where('estado', EstadoRecepcionRomana::EnBasculaSalida)->count());
        }
        if ($gate->allows('gestionar-lotes-retenidos-sag')) {
            $areas['frigorifico'][] = $this->fuente('Lotes con retención general SAG', '/oficina/frigorifico/lotes-retenidos-sag',
                $id === null ? null : RetencionLoteSag::where('temporada_id', $id)->conEstado('retenido')->count());
        }
        if ($gate->allows('supervisar-camaras-productos')) {
            $areas['frigorifico'][] = $this->fuente('Discrepancias abiertas', '/oficina/frigorifico/discrepancias',
                $id === null ? null : DiscrepanciaManiobra::query()
                    ->whereHas('maniobraOperacional.planOperacional', fn ($q) => $q->where('temporada_id', $id))
                    ->where('estado', EstadoDiscrepanciaManiobra::Abierta)->count());
        }
        if ($gate->allows('consultar-prefrio')) {
            $ahora = now()->toImmutable();
            $areas['frigorifico'][] = $this->fuente('Túneles sobre el tiempo objetivo', '/oficina/prefrio',
                $id === null ? null : ProcesoPrefrio::where('temporada_id', $id)->ocupaTunel()
                    ->latest('created_at')
                    ->get(['tunel_prefrio_id', 'estado', 'iniciado_at', 'pendiente_verificacion_at', 'finalizado_at', 'duracion_objetivo_minutos'])
                    ->unique('tunel_prefrio_id')
                    ->filter(fn (ProcesoPrefrio $proceso): bool => $proceso->excedeObjetivo($proceso->minutosTranscurridos($ahora)))
                    ->count());
        }
        $consultaMateriales = $gate->allows('consultar-despachos-materiales');
        $materiales = $this->modulos->modulosUsuario($usuario);
        if ($consultaMateriales && in_array('materiales.despachos', $materiales, true)) {
            $areas['materiales'][] = $this->fuente('Despachos por cerrar', '/oficina/materiales/despachos',
                $id === null ? null : DespachoMaterial::where('temporada_id', $id)
                    ->whereIn('estado', [EstadoDespachoMaterial::Pendiente, EstadoDespachoMaterial::Parcial])->count());
        }
        if ($consultaMateriales && in_array('materiales.inventario', $materiales, true)) {
            $areas['materiales'][] = $this->fuente('Folios próximos a vencer', '/oficina/materiales/vencimientos',
                $id === null ? null : $this->vencimientos->consulta(['temporada_id' => $id, 'estado' => 'por_vencer'])
                    ->reorder()->distinct()->count('saldos_materiales_almacenes.folio_id'));
        }
        if ($gate->allows('consultar-panel-gerencial')) {
            $alertas = $id === null ? null : Cache::get($this->panel->claveCache($id))['alertas'] ?? null;
            $areas['gerencia'][] = $this->fuente('Focos del Panel gerencial', '/oficina/gerencia',
                is_array($alertas) ? count($alertas) : null);
        }
        if ($gate->allows('consultar-integridad-operacional')) {
            $auditoria = AuditoriaIntegridadOperacional::query()->latest('iniciada_at')->orderByDesc('id')
                ->first(['estado', 'hallazgos_activos']);
            $areas['administracion'][] = $this->fuente('Hallazgos activos de Salud operacional', '/oficina/administracion/integridad-operacional',
                $auditoria?->estado === EstadoAuditoriaIntegridadOperacional::Completada ? $auditoria->hallazgos_activos : null);
        }

        $total = $this->resumir(array_merge(...array_values($areas)));
        $resumen = array_map(fn (array $fuentes): array => [...$this->resumir($fuentes), 'fuentes' => $fuentes], $areas);
        if ($gate->allows('consultar-oficina-consultas')) {
            $resumen['consultas'] = ['referencia' => '/oficina/consultas'];
        }

        return ['total' => $total['cantidad'], 'parcial' => $total['parcial'], 'areas' => $resumen];
    }

    private function fuente(string $etiqueta, string $href, ?int $cantidad): array
    {
        return compact('etiqueta', 'href', 'cantidad');
    }

    private function resumir(array $fuentes): array
    {
        $conteos = array_filter(array_column($fuentes, 'cantidad'), fn (?int $cantidad): bool => $cantidad !== null);

        return [
            'cantidad' => $conteos === [] ? null : array_sum($conteos),
            'parcial' => $conteos !== [] && count($conteos) < count($fuentes),
        ];
    }
}
