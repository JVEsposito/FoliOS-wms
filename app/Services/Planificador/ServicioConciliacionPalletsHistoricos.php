<?php

namespace App\Services\Planificador;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\ProcesoPrefrioFolio;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Recupera pallets aprobados antes de habilitar la generación rolling.
 * No presupone dónde están hoy: el camarero debe encontrarlos y confirmar el
 * folio físicamente al iniciar la tarea.
 */
final class ServicioConciliacionPalletsHistoricos
{
    private const REFERENCIA_TIPO = 'folio_pendiente_ubicacion';

    public function __construct(private readonly ServicioPlanesOperacionales $planes) {}

    /** @return Builder<Folio> */
    private function sinObjetivo(Temporada $temporada, TipoBulto $tipo = TipoBulto::Pallet): Builder
    {
        return Folio::query()
            ->where('temporada_id', $temporada->id)
            ->where('activo', true)
            ->where('tipo_bulto', $tipo->value)
            ->whereIn('estado_operacional', [
                EstadoOperacionalFolio::PendienteUbicacion->value,
                EstadoOperacionalFolio::Disponible->value,
            ])
            ->whereDoesntHave('ubicacionActual')
            // Una tarea cancelada no constituye una ubicación ni un objetivo vigente.
            ->whereDoesntHave('tareasMovimiento', fn (Builder $consulta) => $consulta
                ->where('estado', '!=', EstadoTareaMovimiento::Cancelada->value));
    }

    /** @return Builder<Folio> */
    public function candidatos(Temporada $temporada): Builder
    {
        return $this->sinObjetivo($temporada)
            ->where('condicion_termica', CondicionTermicaFolio::PrefrioAprobado->value)
            ->where('habilitacion_almacenamiento', HabilitacionAlmacenamientoFolio::Habilitado->value)
            ->whereDoesntHave('asignacionCargaActual')
            ->whereDoesntHave('retencionOperacionalActiva')
            ->whereHas('procesosPrefrio', fn (Builder $consulta) => $consulta
                ->where('estado', EstadoFolioProcesoPrefrio::Aprobado->value)
                ->whereHas('proceso', fn (Builder $proceso) => $proceso
                    ->where('temporada_id', $temporada->id)
                    ->where('estado', EstadoProcesoPrefrio::Aprobado->value)))
            ->whereDoesntHave('procesosPrefrio', fn (Builder $consulta) => $consulta
                ->whereHas('proceso', fn (Builder $proceso) => $proceso
                    ->whereIn('estado', [
                        EstadoProcesoPrefrio::Borrador->value,
                        EstadoProcesoPrefrio::Cargando->value,
                        EstadoProcesoPrefrio::ListoParaIniciar->value,
                        EstadoProcesoPrefrio::EnProceso->value,
                        EstadoProcesoPrefrio::PendienteVerificacion->value,
                    ])));
    }

    /** @return array<string, mixed> */
    public function diagnosticar(Temporada $temporada, int $limite = 20): array
    {
        $sinObjetivo = $this->sinObjetivo($temporada)->count();
        $elegibles = $this->candidatos($temporada)->count();
        $requierenRevision = $this->sinObjetivo($temporada)
            ->whereNotIn('id', $this->candidatos($temporada)->select('id'))
            ->with(['procesosPrefrio.proceso', 'asignacionCargaActual', 'retencionOperacionalActiva'])
            ->orderBy('id')
            ->limit(max(0, $limite))
            ->get()
            ->map(fn (Folio $folio): array => [
                'folio' => $folio->numero_folio,
                'motivos' => $this->motivosRevision($folio, $temporada),
            ])->all();

        return [
            'sin_objetivo' => $sinObjetivo,
            'total' => $elegibles,
            'requieren_revision' => $sinObjetivo - $elegibles,
            'folios_revision' => $requierenRevision,
            'saldos_sin_objetivo' => $this->sinObjetivo($temporada, TipoBulto::Saldo)->count(),
            'saldos' => $this->sinObjetivo($temporada, TipoBulto::Saldo)
                ->orderBy('id')->limit(max(0, $limite))->pluck('numero_folio')->all(),
            'folios' => $this->candidatos($temporada)
                ->orderBy('id')
                ->limit(max(0, $limite))
                ->pluck('numero_folio')
                ->all(),
        ];
    }

    /** @return array<int, string> */
    private function motivosRevision(Folio $folio, Temporada $temporada): array
    {
        $motivos = [];
        if ($folio->condicion_termica !== CondicionTermicaFolio::PrefrioAprobado
            || ! $folio->procesosPrefrio->contains(fn (ProcesoPrefrioFolio $registro): bool => $registro->estado === EstadoFolioProcesoPrefrio::Aprobado
                && $registro->proceso?->temporada_id === $temporada->id
                && $registro->proceso?->estado === EstadoProcesoPrefrio::Aprobado)) {
            $motivos[] = 'sin_prefrio_aprobado_temporada';
        }
        if ($folio->habilitacion_almacenamiento !== HabilitacionAlmacenamientoFolio::Habilitado) {
            $motivos[] = 'no_habilitado';
        }
        if ($folio->asignacionCargaActual !== null) {
            $motivos[] = 'carga_asignada';
        }
        if ($folio->retencionOperacionalActiva !== null) {
            $motivos[] = 'retencion';
        }
        if ($folio->procesosPrefrio->contains(fn (ProcesoPrefrioFolio $registro): bool => $registro->proceso?->estado?->esActivo() === true)) {
            $motivos[] = 'prefrio_abierto';
        }

        return $motivos;
    }

    public function incorporar(Temporada $temporada, User $usuario, int $limite = 200): int
    {
        if (! $temporada->activa) {
            throw new DomainException('Solo se pueden conciliar pallets de la temporada activa.');
        }

        $ids = $this->candidatos($temporada)
            ->orderBy('id')
            ->limit(max(1, min(1000, $limite)))
            ->pluck('id');
        $incorporados = 0;

        foreach ($ids as $id) {
            $incorporados += DB::transaction(function () use ($temporada, $usuario, $id): int {
                // El generador habitual también bloquea el folio. Si lo tomó
                // antes, la consulta se repite tras esperar el lock.
                $folio = Folio::query()->lockForUpdate()->find($id);
                if (! $folio || ! $this->candidatos($temporada)->whereKey($id)->exists()) {
                    return 0;
                }

                $origen = ProcesoPrefrioFolio::query()
                    ->where('folio_id', $id)
                    ->where('estado', EstadoFolioProcesoPrefrio::Aprobado->value)
                    ->whereHas('proceso', fn (Builder $consulta) => $consulta
                        ->where('temporada_id', $temporada->id)
                        ->where('estado', EstadoProcesoPrefrio::Aprobado->value))
                    ->latest('created_at')
                    ->first();
                if (! $origen) {
                    return 0;
                }

                // El folio bloqueado serializa la siguiente edición de esta referencia.
                $ultimoCiclo = PlanOperacional::query()
                    ->where('referencia_tipo', self::REFERENCIA_TIPO)
                    ->where('referencia_id', $folio->id)
                    ->max('ciclo_referencia');

                // Rezago histórico: consume solo capacidad sobrante. La salida de
                // túnel, SAG y despachos siempre tienen precedencia.
                $this->planes->crear(
                    temporada: $temporada,
                    tipo: TipoPlanOperacional::AlmacenamientoPallet,
                    titulo: "Ubicar pallet pendiente · {$folio->numero_folio}",
                    creadoPor: $usuario,
                    tareas: [[
                        'folio_id' => $folio->id,
                        'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                        'prioridad' => PrioridadOperacional::Normal,
                        'instruccion' => "Localizar físicamente {$folio->numero_folio}, confirmar el folio en el pallet y ubicarlo según la frontera vigente.",
                        'contexto' => array_filter([
                            'origen_logico' => 'ubicacion_historica_por_verificar',
                            'confirmar_folio_fisicamente' => true,
                            'proceso_prefrio_id' => $origen->proceso_prefrio_id,
                            'cliente' => $folio->exportadora,
                            'exportadora' => $folio->exportadora,
                            'marca' => $folio->marca,
                            'formato' => data_get($folio->datos_externos, 'envase'),
                            'variedad' => $folio->variedad,
                            'calibre' => $folio->calibre,
                        ], static fn (mixed $valor): bool => $valor !== null && $valor !== ''),
                    ]],
                    prioridad: PrioridadOperacional::Normal,
                    motivo: 'Conciliación de pallet aprobado antes de habilitar el planificador.',
                    referenciaTipo: self::REFERENCIA_TIPO,
                    referenciaId: $folio->id,
                    cicloReferencia: ((int) $ultimoCiclo) + 1,
                    contexto: [
                        'planner_horizon' => 'rolling',
                        'origen_logico' => 'ubicacion_historica_por_verificar',
                        'proceso_prefrio_id' => $origen->proceso_prefrio_id,
                        'confirmar_folio_fisicamente' => true,
                    ],
                );

                return 1;
            }, attempts: 3);
        }

        return $incorporados;
    }
}
