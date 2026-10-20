<?php

namespace App\Comex\Expediente;

use App\Comex\Configuracion\ReglasDocumentos;
use App\Comex\Models\ArchivoDocumento;
use App\Comex\Models\ClienteComex;
use App\Comex\Models\Documento;
use App\Comex\Models\EventoDocumento;
use App\Comex\Models\TipoDocumento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * El expediente de cada embarque: los documentos que pide según la configuración, lo que se
 * registró de cada uno y lo que falta, con una alerta que ordena el trabajo del día.
 */
final class Expedientes
{
    /** Hay documentos que se necesitan antes de la salida y la carga ya salió. */
    public const SALIO_INCOMPLETO = 'salio_incompleto';

    /** Faltan documentos de antes de la salida y el embarque es hoy o en los próximos días. */
    public const URGENTE = 'urgente';

    public const PENDIENTE = 'pendiente';

    public const COMPLETO = 'completo';

    public const CANCELADO = 'cancelado';

    public const SIN_SEGUIMIENTO = 'sin_seguimiento';

    public function __construct(
        private readonly EmbarquesOperacion $embarques,
        private readonly ReglasDocumentos $reglas,
    ) {}

    /** @return array<string, mixed> */
    public function panel(CarbonImmutable $desde, CarbonImmutable $hasta, ?string $clienteId): array
    {
        $this->reglas->olvidar();
        $embarques = $this->embarques->entre($desde, $hasta, $clienteId);
        $documentos = $this->documentos(array_column($embarques, 'id'));
        $filas = array_map(fn (array $embarque): array => $this->fila($embarque, $documentos->get($embarque['id'], collect())), $embarques);

        $seguidos = array_values(array_filter($filas, fn (array $fila): bool => ! in_array($fila['alerta'], [self::CANCELADO, self::SIN_SEGUIMIENTO], true)));
        $cuenta = fn (string $alerta): int => count(array_filter($seguidos, fn (array $fila): bool => $fila['alerta'] === $alerta));

        return [
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'hoy' => $this->hoy()->toDateString(),
            'dias_alerta' => $this->diasAlerta(),
            // Sin documentos activos no hay nada que exigir: el panel invita a configurarlos.
            'configurado' => $this->reglas->tipos()->contains(fn (TipoDocumento $tipo): bool => $tipo->activo),
            'resumen' => [
                'embarques' => count($seguidos),
                'completos' => $cuenta(self::COMPLETO),
                'pendientes' => $cuenta(self::PENDIENTE),
                'urgentes' => $cuenta(self::URGENTE),
                'salieron_incompletos' => $cuenta(self::SALIO_INCOMPLETO),
                'documentos_faltantes' => array_sum(array_map(fn (array $fila): int => $fila['resumen']['faltan_antes'] + $fila['resumen']['faltan_despues'], $seguidos)),
                'no_seguidos' => count($filas) - count($seguidos),
            ],
            'data' => $filas,
        ];
    }

    /** @return array<string, mixed>|null */
    public function detalle(string $embarqueId): ?array
    {
        $this->reglas->olvidar();
        $embarque = $this->embarques->uno($embarqueId);
        if ($embarque === null) {
            return null;
        }

        $documentos = $this->documentos([$embarqueId])->get($embarqueId, collect());
        $ids = $documentos->pluck('id')->all();
        $archivos = $ids === [] ? collect() : ArchivoDocumento::query()->whereIn('documento_id', $ids)->orderByDesc('subido_at')->get()->groupBy('documento_id');
        $eventos = $ids === [] ? collect() : EventoDocumento::query()->whereIn('documento_id', $ids)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $expediente = $this->componer($embarque, $documentos);
        $tipos = $documentos->keyBy('id')->map(fn (Documento $documento): ?TipoDocumento => $this->reglas->tipos()->get($documento->tipo_documento_id));

        return [
            'embarque' => $embarque,
            'gestion' => $expediente['gestion'],
            'gestion_configurada' => ClienteComex::query()->whereKey($embarque['cliente_id'])->exists(),
            'alerta' => $expediente['alerta'],
            'resumen' => $expediente['resumen'],
            'documentos' => array_map(function (array $item) use ($archivos): array {
                $item['archivos'] = $item['documento_id'] === null ? [] : ($archivos->get($item['documento_id']) ?? collect())
                    ->map(fn (ArchivoDocumento $archivo): array => $this->archivo($archivo))->values()->all();

                return $item;
            }, $expediente['documentos']),
            'historial' => $eventos->map(fn (EventoDocumento $evento): array => [
                'id' => $evento->id,
                'documento' => $tipos->get($evento->documento_id)?->nombre,
                'tipo' => $evento->tipo,
                'estado_anterior' => $evento->estado_anterior,
                'estado_nuevo' => $evento->estado_nuevo,
                'datos' => $evento->datos,
                'usuario' => $evento->user_nombre,
                'at' => $evento->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<string>  $embarques
     * @return Collection<string, Collection<int, Documento>>
     */
    private function documentos(array $embarques): Collection
    {
        if ($embarques === []) {
            return collect();
        }

        return Documento::query()
            ->whereIn('embarque_id', $embarques)
            ->with('archivoVigente')
            ->get()
            ->groupBy('embarque_id');
    }

    /**
     * Una fila del panel: el embarque y su avance, sin el detalle de cada documento.
     *
     * @param  array<string, mixed>  $embarque
     * @param  Collection<int, Documento>  $documentos
     * @return array<string, mixed>
     */
    private function fila(array $embarque, Collection $documentos): array
    {
        $expediente = $this->componer($embarque, $documentos);
        $instructivos = $embarque['instructivos'];

        return [
            'id' => $embarque['id'],
            'codigo' => $embarque['codigo'],
            'cliente_id' => $embarque['cliente_id'],
            'cliente_nombre' => $embarque['cliente_nombre'],
            'fecha_programada' => $embarque['fecha_programada'],
            'hora_programada' => $embarque['hora_programada'],
            'modalidad' => $embarque['modalidad'],
            'estado' => $embarque['estado'],
            'nave_vuelo' => $embarque['nave_vuelo'],
            'contenedor' => $embarque['contenedor'],
            'destinos' => array_values(array_unique(array_filter(array_column($instructivos, 'pais')))),
            'recibidores' => array_values(array_unique(array_filter(array_column($instructivos, 'recibidor')))),
            'bookings' => array_values(array_unique(array_filter(array_column($instructivos, 'booking')))),
            'carga' => $embarque['carga'] === null ? null : [
                'codigo' => $embarque['carga']['codigo'],
                'estado' => $embarque['carga']['estado'],
                'salio' => $embarque['carga']['salio'],
                'folios' => $embarque['carga']['folios'],
            ],
            'gestion' => $expediente['gestion'],
            'alerta' => $expediente['alerta'],
            'resumen' => $expediente['resumen'],
            'faltantes' => array_values(array_map(
                fn (array $item): array => ['nombre' => $item['tipo']['nombre'], 'momento' => $item['tipo']['momento'], 'estado' => $item['estado']],
                array_filter($expediente['documentos'], fn (array $item): bool => $item['exigencia'] === ReglasDocumentos::EXIGIDO && ! $item['resuelto']),
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $embarque
     * @param  Collection<int, Documento>  $documentos
     * @return array{gestion: string, alerta: string, resumen: array<string, int>, documentos: list<array<string, mixed>>}
     */
    private function componer(array $embarque, Collection $documentos): array
    {
        $gestion = $this->reglas->gestion($embarque['cliente_id']);
        $paises = array_map(fn (array $instructivo): ?string => $instructivo['pais_iso2'], $embarque['instructivos']);
        $evaluacion = $this->reglas->evaluar($embarque['modalidad'], $gestion, $paises);
        $porTipo = $documentos->keyBy('tipo_documento_id');

        $items = [];
        foreach ($this->reglas->tipos() as $tipo) {
            $documento = $porTipo->get($tipo->id);
            if (! isset($evaluacion[$tipo->id]) && $documento === null) {
                continue;
            }
            $items[] = $this->item($tipo, $evaluacion[$tipo->id] ?? null, $documento, $embarque);
        }

        $exigidos = array_filter($items, fn (array $item): bool => $item['exigencia'] === ReglasDocumentos::EXIGIDO);
        $faltan = fn (string $momento): int => count(array_filter($exigidos, fn (array $item): bool => ! $item['resuelto'] && $item['tipo']['momento'] === $momento));
        $resumen = [
            'exigidos' => count($exigidos),
            'resueltos' => count(array_filter($exigidos, fn (array $item): bool => $item['resuelto'])),
            'faltan_antes' => $faltan(TipoDocumento::ANTES_SALIDA),
            'faltan_despues' => $faltan(TipoDocumento::DESPUES_SALIDA),
            'por_definir' => count(array_filter($items, fn (array $item): bool => $item['exigencia'] === ReglasDocumentos::POR_DEFINIR && ! $item['resuelto'])),
            'con_respaldo' => count(array_filter($items, fn (array $item): bool => $item['archivo'] !== null)),
        ];

        return [
            'gestion' => $gestion,
            'alerta' => $this->alerta($embarque, $gestion, $resumen),
            'resumen' => $resumen,
            'documentos' => $items,
        ];
    }

    /**
     * @param  array{exigencia: string, motivo: ?string}|null  $evaluacion
     * @param  array<string, mixed>  $embarque
     * @return array<string, mixed>
     */
    private function item(TipoDocumento $tipo, ?array $evaluacion, ?Documento $documento, array $embarque): array
    {
        $archivo = $documento?->archivoVigente;
        $faltantes = [];
        if ($documento?->estado === Documento::LISTO) {
            // La configuración pudo cambiar después de registrarlo.
            if ($tipo->pide_numero && blank($documento->numero)) {
                $faltantes[] = 'número';
            }
            if ($tipo->pide_fecha && $documento->fecha_emision === null) {
                $faltantes[] = 'fecha de emisión';
            }
            if ($tipo->pide_archivo && $archivo === null) {
                $faltantes[] = 'respaldo';
            }
        }

        return [
            'tipo' => [
                'id' => $tipo->id,
                'codigo' => $tipo->codigo,
                'nombre' => $tipo->nombre,
                'descripcion' => $tipo->descripcion,
                'momento' => $tipo->momento,
                'pide_numero' => $tipo->pide_numero,
                'pide_fecha' => $tipo->pide_fecha,
                'pide_archivo' => $tipo->pide_archivo,
                'activo' => $tipo->activo,
            ],
            'exigencia' => $evaluacion['exigencia'] ?? 'no_exigido',
            'motivo_exigencia' => $evaluacion['motivo'] ?? ($evaluacion === null ? 'Ya no se exige con la configuración o los datos actuales del embarque.' : null),
            'documento_id' => $documento?->id,
            'estado' => $documento?->estado ?? Documento::PENDIENTE,
            'resuelto' => $documento !== null && (($documento->estado === Documento::LISTO && $faltantes === []) || $documento->estado === Documento::NO_APLICA),
            'faltantes' => $faltantes,
            'numero' => $documento?->numero,
            'fecha_emision' => $documento?->fecha_emision?->toDateString(),
            'observacion' => $documento?->observacion,
            'motivo_no_aplica' => $documento?->motivo_no_aplica,
            'version' => $documento->version ?? 0,
            'actualizado_at' => $documento?->updated_at?->toIso8601String(),
            'actualizado_por' => $documento?->actualizado_por_nombre,
            'archivo' => $archivo === null ? null : $this->archivo($archivo),
            'referencia' => $this->referencia($tipo, $embarque),
        ];
    }

    /**
     * Lo que la operación ya registró para este documento (por ejemplo, el booking del
     * instructivo). Es una referencia: no marca el documento como listo.
     *
     * @param  array<string, mixed>  $embarque
     * @return array{etiqueta: string, valores: list<string>}|null
     */
    private function referencia(TipoDocumento $tipo, array $embarque): ?array
    {
        $dato = $tipo->dato_operacion;
        if ($dato === null || ! isset(TipoDocumento::DATOS_OPERACION[$dato])) {
            return null;
        }

        $valores = match ($dato) {
            'contenedor', 'sello' => [$embarque[$dato]],
            'instructivo' => array_column($embarque['instructivos'], 'numero'),
            default => array_column($embarque['instructivos'], $dato),
        };
        $valores = array_values(array_unique(array_filter(array_map(fn (mixed $valor): string => trim((string) $valor), $valores))));

        return $valores === [] ? null : ['etiqueta' => TipoDocumento::DATOS_OPERACION[$dato], 'valores' => $valores];
    }

    /**
     * @param  array<string, mixed>  $embarque
     * @param  array<string, int>  $resumen
     */
    private function alerta(array $embarque, string $gestion, array $resumen): string
    {
        if ($embarque['estado'] === 'cancelado') {
            return self::CANCELADO;
        }
        if ($gestion === ClienteComex::SIN_SEGUIMIENTO) {
            return self::SIN_SEGUIMIENTO;
        }
        if ($resumen['faltan_antes'] > 0 && ($embarque['carga']['salio'] ?? false)) {
            return self::SALIO_INCOMPLETO;
        }
        $limite = $this->hoy()->addDays($this->diasAlerta())->toDateString();
        if ($resumen['faltan_antes'] > 0 && $embarque['fecha_programada'] <= $limite) {
            return self::URGENTE;
        }

        return $resumen['faltan_antes'] + $resumen['faltan_despues'] + $resumen['por_definir'] > 0 ? self::PENDIENTE : self::COMPLETO;
    }

    /** @return array<string, mixed> */
    private function archivo(ArchivoDocumento $archivo): array
    {
        return [
            'id' => $archivo->id,
            'nombre' => $archivo->nombre_original,
            'mime' => $archivo->mime,
            'bytes' => $archivo->bytes,
            'subido_at' => $archivo->subido_at->toIso8601String(),
            'subido_por' => $archivo->subido_por_nombre,
            'reemplazado_at' => $archivo->reemplazado_at?->toIso8601String(),
        ];
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.operational_timezone'))->startOfDay();
    }

    private function diasAlerta(): int
    {
        return max(0, (int) config('comex.dias_alerta', 2));
    }
}
