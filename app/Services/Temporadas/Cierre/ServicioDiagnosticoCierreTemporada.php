<?php

namespace App\Services\Temporadas\Cierre;

use App\Enums\CategoriaPendienteCierre;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoEmbarque;
use App\Enums\EstadoHidrocoolerMateriaPrima;
use App\Enums\EstadoLoteInspeccionSag;
use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoSesionEstiba;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoBulto;
use App\Exceptions\TemporadaConPendientesDeCierre;
use App\Models\Temporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lista lo que una temporada deja abierto: recepciones, validaciones, lotes,
 * procesos, cargas, embarques, planes, sesiones y folios PT. Materiales no
 * participa: su inventario cruza de temporada con la migración auditada.
 *
 * Un registro deja de estar pendiente cuando se cierra en su módulo o cuando
 * un administrador lo regulariza (ServicioRegularizacionCierreTemporada).
 */
final class ServicioDiagnosticoCierreTemporada
{
    public const LIMITE_DETALLE = 200;

    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly ServicioTemporadaGlobal $temporadasGlobales,
    ) {}

    /**
     * @return array{
     *     temporada: array<string, mixed>,
     *     total: int,
     *     folios_en_camaras: int,
     *     regularizados: int,
     *     categorias: list<array<string, mixed>>
     * }
     */
    public function diagnosticar(Temporada $temporada, int $limite = self::LIMITE_DETALLE): array
    {
        $regularizados = DB::table('regularizaciones_cierre_temporada')
            ->where('temporada_id', $temporada->id)
            ->selectRaw('categoria, count(*) as cantidad')
            ->groupBy('categoria')
            ->pluck('cantidad', 'categoria');
        $categorias = [];

        foreach (CategoriaPendienteCierre::cases() as $categoria) {
            $total = $this->contar($temporada, $categoria);
            $categorias[] = [
                'categoria' => $categoria->value,
                'etiqueta' => $categoria->etiqueta(),
                'como_cerrar' => $categoria->comoCerrar(),
                'efecto_regularizacion' => $categoria->efectoRegularizacion(),
                'aplica' => $this->aplica($temporada, $categoria),
                'total' => $total,
                'regularizados' => (int) ($regularizados[$categoria->value] ?? 0),
                'bloqueo_regularizacion' => $this->bloqueoRegularizacion($temporada, $categoria),
                'items' => $limite > 0 && $total > 0
                    ? $this->pendientes($temporada, $categoria, limite: $limite)->all()
                    : [],
            ];
        }

        return [
            'temporada' => [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
                'tipo' => $temporada->tipo->value,
                'activa' => (bool) $temporada->activa,
            ],
            'total' => array_sum(array_column($categorias, 'total')),
            // Folios PT que todavía figuran en cámaras: bloquean la activación
            // aunque la temporada esté inactiva.
            'folios_en_camaras' => DB::table('ubicaciones_actuales as u')
                ->join('folios as f', 'f.id', '=', 'u.folio_id')
                ->where('f.temporada_id', $temporada->id)
                ->whereIn('f.tipo_bulto', $this->tiposPt())
                ->count(),
            'regularizados' => (int) $regularizados->sum(),
            'categorias' => $categorias,
        ];
    }

    public function contar(Temporada $temporada, CategoriaPendienteCierre $categoria): int
    {
        return $this->aplica($temporada, $categoria)
            ? $this->consulta($temporada, $categoria)[0]->count()
            : 0;
    }

    /**
     * Registros pendientes normalizados: id, referencia, estado, detalle,
     * responsable, fecha y, para folios, su ubicación.
     *
     * @param  list<string>|null  $ids  Restringe a esos registros.
     * @return Collection<int, array<string, mixed>>
     */
    public function pendientes(
        Temporada $temporada,
        CategoriaPendienteCierre $categoria,
        ?array $ids = null,
        ?int $limite = null,
    ): Collection {
        if (! $this->aplica($temporada, $categoria)) {
            return collect();
        }

        [$consulta, $detalle, $columnaId] = $this->consulta($temporada, $categoria);
        $filas = $consulta
            ->when($ids !== null, fn (Builder $q) => $q->whereIn($columnaId, $ids))
            ->orderBy('fecha')
            ->orderBy('referencia')
            ->when($limite !== null, fn (Builder $q) => $q->limit($limite))
            ->get();
        $usuarios = DB::table('users')
            ->whereIn('id', $filas->pluck('responsable_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        return $filas->map(fn (object $fila): array => [
            'id' => (string) $fila->id,
            'referencia' => (string) $fila->referencia,
            'estado' => $fila->estado !== null ? (string) $fila->estado : null,
            'detalle' => $detalle($fila),
            'responsable' => $fila->responsable_id !== null ? [
                'id' => (int) $fila->responsable_id,
                'nombre' => $usuarios[$fila->responsable_id] ?? null,
            ] : null,
            'fecha' => $fila->fecha !== null
                ? Carbon::parse((string) $fila->fecha, config('app.timezone'))->toAtomString()
                : null,
            'ubicacion' => isset($fila->camara_id) && $fila->camara_id !== null ? [
                'camara_id' => (string) $fila->camara_id,
                'camara' => $fila->camara_codigo,
                'posicion' => $fila->posicion_etiqueta,
            ] : null,
        ])->values();
    }

    /**
     * Motivo por el que la categoría todavía no puede regularizarse, o null.
     * Los folios PT se regularizan al final, cuando no quedan procesos que
     * los puedan tener reservados.
     */
    public function bloqueoRegularizacion(Temporada $temporada, CategoriaPendienteCierre $categoria): ?string
    {
        if ($categoria === CategoriaPendienteCierre::InspeccionSag) {
            return 'Cancela o finaliza la inspección SAG en su módulo: debe liberar sus reservas y preparación antes del cierre.';
        }

        if ($categoria !== CategoriaPendienteCierre::FoliosPt) {
            return null;
        }

        $abiertos = collect(CategoriaPendienteCierre::procesosDeFolios())
            ->filter(fn (CategoriaPendienteCierre $proceso): bool => $this->contar($temporada, $proceso) > 0)
            ->map(fn (CategoriaPendienteCierre $proceso): string => $proceso->enFrase())
            ->values();

        return $abiertos->isEmpty()
            ? null
            : 'Antes de regularizar folios PT, cierra o regulariza: '.$abiertos->implode(', ').'.';
    }

    /**
     * Impide activar $destino mientras la temporada vigente tenga registros
     * sin cerrar, o mientras otra temporada conserve folios PT en cámaras.
     *
     * Con $validarDestino, antes revisa las reglas propias del destino (tipo,
     * prefijo y vigencia), para que su error se informe primero.
     */
    public function asegurarPuedeActivarse(Temporada $destino, bool $validarDestino = true): void
    {
        if ($validarDestino) {
            $this->temporadasGlobales->asegurarActivable($destino);
            $this->temporadasGlobales->asegurarVigenciaProductiva($destino);
        }

        $vigente = $this->temporadas->buscar();
        $pendientes = [];

        if ($vigente !== null && $vigente->id !== $destino->id) {
            foreach (CategoriaPendienteCierre::cases() as $categoria) {
                $cantidad = $this->contar($vigente, $categoria);
                if ($cantidad > 0) {
                    $pendientes[] = $this->resumenPendiente($vigente, $categoria, $cantidad);
                }
            }
        }

        $ocupacion = DB::table('ubicaciones_actuales as u')
            ->join('folios as f', 'f.id', '=', 'u.folio_id')
            ->join('temporadas as t', 't.id', '=', 'f.temporada_id')
            ->whereIn('f.tipo_bulto', $this->tiposPt())
            // También revisa el destino: una temporada inactiva que aún
            // conserva folios en cámaras no puede volver a activarse.
            ->when($vigente, fn (Builder $q) => $q->where('f.temporada_id', '!=', $vigente->id))
            ->groupBy('t.id', 't.codigo')
            ->orderBy('t.codigo')
            ->get(['t.id', 't.codigo', DB::raw('count(*) as cantidad')]);

        foreach ($ocupacion as $fila) {
            $pendientes[] = [
                'temporada' => ['id' => (string) $fila->id, 'codigo' => (string) $fila->codigo],
                'categoria' => CategoriaPendienteCierre::FoliosPt->value,
                'etiqueta' => 'Folios PT que aún figuran en cámaras',
                'frase' => 'folios PT que aún figuran en cámaras',
                'cantidad' => (int) $fila->cantidad,
            ];
        }

        if ($pendientes === []) {
            return;
        }

        $detalle = collect($pendientes)
            ->groupBy(fn (array $p): string => $p['temporada']['codigo'])
            ->map(fn ($grupo, string $codigo): string => $codigo.': '.$grupo
                ->map(fn (array $p): string => "{$p['frase']} ({$p['cantidad']})")
                ->implode(', '))
            ->implode('; ');

        throw new TemporadaConPendientesDeCierre(
            "No se puede activar {$destino->codigo} mientras queden registros sin cerrar. {$detalle}. Ciérralos en su módulo o regularízalos en Cierre de temporada.",
            $pendientes,
        );
    }

    /** @return array{temporada: array{id: string, codigo: string}, categoria: string, etiqueta: string, frase: string, cantidad: int} */
    private function resumenPendiente(Temporada $temporada, CategoriaPendienteCierre $categoria, int $cantidad): array
    {
        return [
            'temporada' => ['id' => $temporada->id, 'codigo' => $temporada->codigo],
            'categoria' => $categoria->value,
            'etiqueta' => $categoria->etiqueta(),
            'frase' => $categoria->enFrase(),
            'cantidad' => $cantidad,
        ];
    }

    private function aplica(Temporada $temporada, CategoriaPendienteCierre $categoria): bool
    {
        return ! $categoria->soloTemporadaActiva() || $temporada->activa;
    }

    /** @return list<string> */
    private function tiposPt(): array
    {
        return [TipoBulto::Pallet->value, TipoBulto::Saldo->value];
    }

    /**
     * @return array{0: Builder, 1: Closure(object): string, 2: string}
     *                                                                  Consulta con columnas id, referencia, estado, responsable_id y fecha;
     *                                                                  el formateador del detalle y la columna del id.
     */
    private function consulta(Temporada $temporada, CategoriaPendienteCierre $categoria): array
    {
        [$consulta, $detalle, $alias] = match ($categoria) {
            CategoriaPendienteCierre::RecepcionesRomana => $this->recepciones($temporada),
            CategoriaPendienteCierre::ValidacionMp => $this->validacionesMp($temporada),
            CategoriaPendienteCierre::LotesMp => $this->lotesMp($temporada),
            CategoriaPendienteCierre::Hidrocooler => $this->hidrocooler($temporada),
            CategoriaPendienteCierre::Prefrio => $this->prefrio($temporada),
            CategoriaPendienteCierre::InspeccionSag => $this->inspeccionSag($temporada),
            CategoriaPendienteCierre::Embarques => $this->embarques($temporada),
            CategoriaPendienteCierre::Cargas => $this->cargas($temporada),
            CategoriaPendienteCierre::PlanesOperacionales => $this->planes($temporada),
            CategoriaPendienteCierre::SesionesEstiba => $this->sesiones(),
            CategoriaPendienteCierre::FoliosPt => $this->folios($temporada),
        };

        $consulta->whereNotExists(fn (Builder $regularizacion) => $regularizacion
            ->selectRaw('1')
            ->from('regularizaciones_cierre_temporada as reg')
            ->where('reg.categoria', $categoria->value)
            ->whereColumn('reg.entidad_id', "{$alias}.id"));

        return [$consulta, $detalle, "{$alias}.id"];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function recepciones(Temporada $temporada): array
    {
        $consulta = DB::table('recepciones_romana as r')
            ->where('r.temporada_id', $temporada->id)
            ->whereNotIn('r.estado', [EstadoRecepcionRomana::Cerrado->value, EstadoRecepcionRomana::Anulada->value])
            ->select([
                'r.id', 'r.numero_recepcion as referencia', 'r.estado',
                'r.creado_por_user_id as responsable_id', 'r.ingreso_at as fecha',
                'r.cliente_nombre_snapshot', 'r.patente_camion',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            $f->cliente_nombre_snapshot,
            $f->patente_camion ? "patente {$f->patente_camion}" : null,
            $this->estado($f->estado),
        ]), 'r'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function validacionesMp(Temporada $temporada): array
    {
        $consulta = DB::table('recepciones_romana as r')
            ->where('r.temporada_id', $temporada->id)
            ->where('r.estado', '!=', EstadoRecepcionRomana::Anulada->value)
            ->where('r.estado_validacion_mp', '!=', EstadoValidacionMp::Validada->value)
            ->select([
                'r.id', 'r.numero_recepcion as referencia', 'r.estado_validacion_mp as estado',
                DB::raw('coalesce(r.validacion_tomada_por_user_id, r.creado_por_user_id) as responsable_id'),
                DB::raw('coalesce(r.validacion_tomada_at, r.ingreso_at) as fecha'),
                'r.cliente_nombre_snapshot',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            $f->cliente_nombre_snapshot,
            $f->estado === EstadoValidacionMp::EnCurso->value ? 'Validación tomada sin confirmar' : 'Sin validar',
        ]), 'r'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function lotesMp(Temporada $temporada): array
    {
        $consulta = DB::table('lotes_materia_prima as l')
            ->where('l.temporada_id', $temporada->id)
            ->whereNotIn('l.estado', [
                EstadoLoteMateriaPrima::EntregadoProceso->value,
                EstadoLoteMateriaPrima::Anulado->value,
            ])
            ->select([
                'l.id', 'l.numero_lote as referencia', 'l.estado',
                'l.creado_por_user_id as responsable_id', 'l.created_at as fecha',
                'l.variedad_snapshot', 'l.cantidad_envases_primarios', 'l.kilos_netos_calculados',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            $f->variedad_snapshot,
            $f->cantidad_envases_primarios !== null ? "{$f->cantidad_envases_primarios} envases" : null,
            $f->kilos_netos_calculados !== null ? number_format((float) $f->kilos_netos_calculados, 0, ',', '.').' kg' : null,
            $this->estado($f->estado),
        ]), 'l'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function hidrocooler(Temporada $temporada): array
    {
        $consulta = DB::table('procesos_hidrocooler_materia_prima as h')
            ->join('lotes_materia_prima as l', 'l.id', '=', 'h.lote_materia_prima_id')
            ->where('l.temporada_id', $temporada->id)
            ->where('h.estado', EstadoHidrocoolerMateriaPrima::EnCurso->value)
            ->select([
                'h.id', 'h.codigo as referencia', 'h.estado',
                'h.iniciado_por_user_id as responsable_id', 'h.inicio_at as fecha',
                'l.numero_lote', 'h.equipo',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            "Lote {$f->numero_lote}",
            $f->equipo ? "equipo {$f->equipo}" : null,
        ]), 'h'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function prefrio(Temporada $temporada): array
    {
        $activos = collect(EstadoProcesoPrefrio::cases())
            ->filter->esActivo()
            ->map->value
            ->values()
            ->all();
        $consulta = DB::table('procesos_prefrio as p')
            ->leftJoin('tuneles_prefrio as t', 't.id', '=', 'p.tunel_prefrio_id')
            ->where('p.temporada_id', $temporada->id)
            ->whereIn('p.estado', $activos)
            ->select([
                'p.id', 'p.codigo as referencia', 'p.estado',
                DB::raw('coalesce(p.iniciado_por_user_id, p.creado_por_user_id) as responsable_id'),
                DB::raw('coalesce(p.iniciado_at, p.created_at) as fecha'),
                't.codigo as tunel_codigo',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            $f->tunel_codigo ? "Túnel {$f->tunel_codigo}" : null,
            $this->estado($f->estado),
        ]), 'p'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function inspeccionSag(Temporada $temporada): array
    {
        $consulta = DB::table('lotes_inspeccion_sag as s')
            ->leftJoin('clientes as c', 'c.id', '=', 's.cliente_id')
            ->where('s.temporada_id', $temporada->id)
            ->whereNotIn('s.estado', [
                EstadoLoteInspeccionSag::Finalizado->value,
                EstadoLoteInspeccionSag::Cancelado->value,
            ])
            ->select([
                's.id', 's.codigo as referencia', 's.estado',
                DB::raw('coalesce(s.iniciado_por_user_id, s.creado_por_user_id) as responsable_id'),
                DB::raw('coalesce(s.iniciado_at, s.created_at) as fecha'),
                'c.nombre as cliente_nombre',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            $f->cliente_nombre,
            $this->estado($f->estado),
        ]), 's'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function embarques(Temporada $temporada): array
    {
        // Un embarque confirmado queda resuelto por su carga; sin carga, nunca
        // se materializó y debe cancelarse.
        $consulta = DB::table('embarques as e')
            ->leftJoin('clientes as c', 'c.id', '=', 'e.cliente_id')
            ->where('e.temporada_id', $temporada->id)
            ->where(fn (Builder $q) => $q
                ->where('e.estado', EstadoEmbarque::Tentativo->value)
                ->orWhere(fn (Builder $confirmado) => $confirmado
                    ->where('e.estado', EstadoEmbarque::Confirmado->value)
                    ->whereNull('e.carga_id')))
            ->select([
                'e.id', 'e.codigo as referencia', 'e.estado',
                DB::raw('coalesce(e.confirmado_por_user_id, e.creado_por_user_id) as responsable_id'),
                'e.fecha_programada as fecha',
                'c.nombre as cliente_nombre',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            $f->cliente_nombre,
            $f->estado === EstadoEmbarque::Confirmado->value ? 'Confirmado sin carga' : 'Tentativo',
        ]), 'e'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function cargas(Temporada $temporada): array
    {
        $consulta = DB::table('cargas as c')
            ->where('c.temporada_id', $temporada->id)
            ->whereNotIn('c.estado', [EstadoCarga::Cerrada->value, EstadoCarga::Cancelada->value])
            ->select([
                'c.id', 'c.codigo as referencia', 'c.estado',
                DB::raw('coalesce(c.publicada_por_user_id, c.creada_por_user_id) as responsable_id'),
                DB::raw('coalesce(c.publicada_at, c.created_at) as fecha'),
                'c.numero_orden_externa',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            $f->numero_orden_externa ? "orden {$f->numero_orden_externa}" : null,
            $this->estado($f->estado),
        ]), 'c'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function planes(Temporada $temporada): array
    {
        $consulta = DB::table('planes_operacionales as p')
            ->where('p.temporada_id', $temporada->id)
            ->whereNotIn('p.estado', [
                EstadoPlanOperacional::Completado->value,
                EstadoPlanOperacional::Cancelado->value,
            ])
            ->select([
                'p.id', 'p.titulo as referencia', 'p.estado',
                DB::raw('coalesce(p.iniciado_por_user_id, p.creado_por_user_id) as responsable_id'),
                DB::raw('coalesce(p.iniciado_at, p.programado_at, p.created_at) as fecha'),
                'p.tipo',
            ]);

        return [$consulta, fn (object $f): string => $this->unir([
            $this->estado($f->tipo),
            $this->estado($f->estado),
        ]), 'p'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function sesiones(): array
    {
        $consulta = DB::table('sesiones_estiba as s')
            ->join('camaras as c', 'c.id', '=', 's.camara_id')
            // Las cámaras de Materiales siguen operando a través de temporadas.
            ->where('c.contenido', '!=', ContenidoCamara::Materiales->value)
            ->where('s.estado', EstadoSesionEstiba::Abierta->value)
            ->select([
                's.id', 'c.codigo as referencia', 's.estado',
                's.user_id as responsable_id', 's.iniciada_at as fecha',
                's.ultima_actividad_at',
            ]);

        return [$consulta, fn (object $f): string => $f->ultima_actividad_at
            ? 'Última actividad '.Carbon::parse((string) $f->ultima_actividad_at, config('app.timezone'))->format('d-m-Y H:i')
            : 'Sin actividad registrada', 's'];
    }

    /** @return array{0: Builder, 1: Closure(object): string, 2: string} */
    private function folios(Temporada $temporada): array
    {
        $terminales = [
            EstadoOperacionalFolio::Anulado->value,
            EstadoOperacionalFolio::RetiradoDefinitivo->value,
            EstadoOperacionalFolio::Despachado->value,
            EstadoOperacionalFolio::Agotado->value,
        ];
        $ultimoResponsable = DB::table('movimientos as m')
            ->select('m.user_id')
            ->whereColumn('m.folio_id', 'f.id')
            ->orderByDesc('m.created_at')
            ->limit(1);
        $consulta = DB::table('folios as f')
            ->leftJoin('ubicaciones_actuales as u', 'u.folio_id', '=', 'f.id')
            ->leftJoin('camaras as c', 'c.id', '=', 'u.camara_id')
            ->leftJoin('posiciones as p', 'p.id', '=', 'u.posicion_id')
            ->where('f.temporada_id', $temporada->id)
            ->whereIn('f.tipo_bulto', $this->tiposPt())
            ->where(fn (Builder $q) => $q
                ->whereNotIn('f.estado_operacional', $terminales)
                ->orWhereNotNull('u.id'))
            ->select([
                'f.id', 'f.numero_folio as referencia', 'f.estado_operacional as estado',
                'f.fecha_ingreso as fecha', 'f.tipo_bulto', 'f.variedad',
                'u.camara_id', 'c.codigo as camara_codigo', 'p.etiqueta as posicion_etiqueta',
            ])
            ->selectSub($ultimoResponsable, 'responsable_id');

        return [$consulta, fn (object $f): string => $this->unir([
            $f->tipo_bulto === TipoBulto::Saldo->value ? 'Saldo' : 'Pallet',
            $f->variedad,
            $this->estado($f->estado),
            $f->camara_id
                ? 'registro sin cerrar en '.trim("{$f->camara_codigo} ".($f->posicion_etiqueta ? "· {$f->posicion_etiqueta}" : 'sin posición'))
                : 'sin ubicación',
        ]), 'f'];
    }

    private function estado(?string $estado): ?string
    {
        return $estado === null ? null : ucfirst(str_replace('_', ' ', $estado));
    }

    /** @param  list<string|null>  $partes */
    private function unir(array $partes): string
    {
        return implode(' · ', array_values(array_filter($partes, fn ($parte) => $parte !== null && $parte !== '')));
    }
}
