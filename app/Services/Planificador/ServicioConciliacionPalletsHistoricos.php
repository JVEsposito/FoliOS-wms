<?php

namespace App\Services\Planificador;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Folio;
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
    private function sinObjetivo(Temporada $temporada): Builder
    {
        return Folio::query()
            ->where('temporada_id', $temporada->id)
            ->where('activo', true)
            ->where('tipo_bulto', TipoBulto::Pallet->value)
            ->whereIn('estado_operacional', [
                EstadoOperacionalFolio::PendienteUbicacion->value,
                EstadoOperacionalFolio::Disponible->value,
            ])
            ->whereDoesntHave('ubicacionActual')
            ->whereDoesntHave('tareasMovimiento');
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

    /** @return array{sin_objetivo: int, total: int, requieren_revision: int, folios: array<int, string>} */
    public function diagnosticar(Temporada $temporada, int $limite = 20): array
    {
        $sinObjetivo = $this->sinObjetivo($temporada)->count();
        $elegibles = $this->candidatos($temporada)->count();

        return [
            'sin_objetivo' => $sinObjetivo,
            'total' => $elegibles,
            'requieren_revision' => $sinObjetivo - $elegibles,
            'folios' => $this->candidatos($temporada)
                ->orderBy('id')
                ->limit(max(0, $limite))
                ->pluck('numero_folio')
                ->all(),
        ];
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

                $this->planes->crear(
                    temporada: $temporada,
                    tipo: TipoPlanOperacional::AlmacenamientoPallet,
                    titulo: "Ubicar pallet pendiente · {$folio->numero_folio}",
                    creadoPor: $usuario,
                    tareas: [[
                        'folio_id' => $folio->id,
                        'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                        'prioridad' => PrioridadOperacional::Alta,
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
                    prioridad: PrioridadOperacional::Alta,
                    motivo: 'Conciliación de pallet aprobado antes de habilitar el planificador.',
                    referenciaTipo: self::REFERENCIA_TIPO,
                    referenciaId: $folio->id,
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
