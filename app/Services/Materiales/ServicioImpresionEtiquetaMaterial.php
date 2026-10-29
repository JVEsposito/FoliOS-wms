<?php

namespace App\Services\Materiales;

use App\Enums\EstadoFolioSalidaReservado;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoRecepcionMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\FolioMaterial;
use App\Models\FolioSalidaReservadoTransformacion;
use App\Models\FolioTrabajoImpresionMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Models\PerfilImpresionEtiqueta;
use App\Models\RecepcionMaterial;
use App\Models\SalidaTransformacionMaterial;
use App\Models\TrabajoImpresionMaterial;
use App\Models\TransferenciaClienteMaterial;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioImpresionEtiquetaMaterial
{
    public function __construct(
        private readonly GeneradorEtiquetaMaterialPdf $pdf,
        private readonly GeneradorEtiquetaMaterialNlbl $nlbl,
        private readonly GeneradorEtiquetaMaterialZpl $zpl,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     * @return array{trabajo: TrabajoImpresionMaterial, contenido: string, mime: string, nombre: string}
     */
    public function generar(
        RecepcionMaterial $recepcion,
        array $datos,
        User $usuario,
        ?string $dispositivoId = null,
    ): array {
        $payload = [
            'origen' => 'recepcion',
            'recepcion_material_id' => $recepcion->id,
            'perfil_id' => $datos['perfil_id'],
            'formato' => $datos['formato'],
            'simbologia' => $datos['simbologia'],
            'canal' => $datos['canal'],
            'folio_ids' => collect($datos['folio_ids'])->sort()->values()->all(),
            'copias' => (int) $datos['copias'],
            'motivo_reimpresion' => $datos['motivo_reimpresion'] ?? null,
        ];

        return $this->generarTrabajo(
            $payload,
            $datos,
            $usuario,
            $dispositivoId,
            function (array $folioIds) use ($recepcion): array {
                $recepcion = RecepcionMaterial::query()
                    ->with(['cliente', 'proveedor'])
                    ->lockForUpdate()
                    ->findOrFail($recepcion->id);
                if ($recepcion->estado !== EstadoRecepcionMaterial::Confirmada) {
                    throw new DomainException('Solo se pueden generar etiquetas de una recepción confirmada.');
                }

                $materiales = FolioMaterial::query()
                    ->with([
                        'folio',
                        'item.cliente.cliente',
                        'bultoRecepcion.detalle.recepcion.proveedor',
                        'bultoRecepcion.detalle.recepcion.cliente',
                    ])
                    ->whereIn('folio_id', $folioIds)
                    ->whereHas('bultoRecepcion.detalle', fn ($consulta) => $consulta
                        ->where('recepcion_material_id', $recepcion->id))
                    ->get()
                    ->keyBy('folio_id');
                if ($materiales->count() !== count($folioIds)) {
                    throw new DomainException(
                        'Uno o más folios no pertenecen a la recepción seleccionada.',
                    );
                }

                return [
                    'recepcion_material_id' => $recepcion->id,
                    'orden_transformacion_material_id' => null,
                    'lote_transformacion_material_id' => null,
                    'etiquetas' => collect($folioIds)
                        ->map(fn (string $folioId): array => $this->etiquetaSnapshot(
                            $materiales->get($folioId),
                        ))
                        ->all(),
                ];
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{trabajo: TrabajoImpresionMaterial, contenido: string, mime: string, nombre: string}
     */
    public function generarTransformacion(
        OrdenTransformacionMaterial $orden,
        array $datos,
        User $usuario,
        ?string $dispositivoId = null,
    ): array {
        $reservaIds = collect($datos['reserva_ids'] ?? [])->sort()->values()->all();
        $payload = [
            'origen' => 'transformacion',
            'orden_transformacion_material_id' => $orden->id,
            'perfil_id' => $datos['perfil_id'],
            'formato' => $datos['formato'],
            'simbologia' => $datos['simbologia'],
            'canal' => $datos['canal'],
            'folio_ids' => collect($datos['folio_ids'] ?? [])->sort()->values()->all(),
            'copias' => (int) $datos['copias'],
            'motivo_reimpresion' => $datos['motivo_reimpresion'] ?? null,
        ];
        if ($reservaIds !== []) {
            $payload['reserva_ids'] = $reservaIds;
        }

        return $this->generarTrabajo(
            $payload,
            $datos,
            $usuario,
            $dispositivoId,
            function (array $folioIds) use ($orden, $reservaIds): array {
                $orden = OrdenTransformacionMaterial::query()
                    ->with('cliente:id,codigo,nombre')
                    ->lockForUpdate()
                    ->findOrFail($orden->id);
                $salidas = SalidaTransformacionMaterial::query()
                    ->with([
                        'folioMaterial.folio',
                        'folioMaterial.item',
                        'item',
                        'lote',
                    ])
                    ->whereIn('folio_id', $folioIds)
                    ->whereHas('lote', fn ($consulta) => $consulta
                        ->where('orden_transformacion_material_id', $orden->id)
                        ->where('estado', 'cerrado'))
                    ->get()
                    ->keyBy('folio_id');
                if ($salidas->count() !== count($folioIds)) {
                    throw new DomainException(
                        'Uno o más folios no son salidas cerradas de la orden seleccionada.',
                    );
                }
                // Folio producido desde un folio reservado: conserva la secuencia de su etiqueta.
                $reservasProducidas = FolioSalidaReservadoTransformacion::query()
                    ->whereIn('folio_id', $folioIds)
                    ->get()
                    ->keyBy('folio_id');
                $reservas = $this->foliosReservadosImprimibles($orden, $reservaIds);
                $lotes = $salidas->pluck('lote_transformacion_material_id')->unique()->values();

                return [
                    'recepcion_material_id' => null,
                    'orden_transformacion_material_id' => $orden->id,
                    'lote_transformacion_material_id' => $lotes->count() === 1 && $reservas->isEmpty()
                        ? $lotes->first()
                        : null,
                    'etiquetas' => [
                        ...collect($folioIds)
                            ->map(fn (string $folioId): array => $this->etiquetaTransformacionSnapshot(
                                $salidas->get($folioId),
                                $orden,
                                $reservasProducidas->get($folioId),
                            ))
                            ->all(),
                        ...$reservas
                            ->map(fn (FolioSalidaReservadoTransformacion $reserva): array => $this->etiquetaFolioReservadoSnapshot(
                                $reserva,
                                $orden,
                            ))
                            ->all(),
                    ],
                ];
            },
        );
    }

    /**
     * Folios reservados de la orden que aún no se producen, en orden de secuencia.
     *
     * @param  array<int, string>  $reservaIds
     * @return Collection<int, FolioSalidaReservadoTransformacion>
     */
    private function foliosReservadosImprimibles(
        OrdenTransformacionMaterial $orden,
        array $reservaIds,
    ): Collection {
        if ($reservaIds === []) {
            return collect();
        }

        $reservas = FolioSalidaReservadoTransformacion::query()
            ->whereIn('id', $reservaIds)
            ->where('orden_transformacion_material_id', $orden->id)
            ->orderBy('secuencia')
            ->lockForUpdate()
            ->get();
        if ($reservas->count() !== count($reservaIds)) {
            throw new DomainException(
                'Uno o más folios reservados no pertenecen a la orden seleccionada.',
            );
        }

        foreach ($reservas as $reserva) {
            if ($reserva->estado === EstadoFolioSalidaReservado::Usado) {
                throw new DomainException(sprintf(
                    'El folio %s ya fue producido: imprime su etiqueta desde los folios generados.',
                    $reserva->numero_folio,
                ));
            }
            if ($reserva->estado !== EstadoFolioSalidaReservado::Reservado) {
                throw new DomainException(sprintf(
                    'El folio %s fue anulado y no puede etiquetarse.',
                    $reserva->numero_folio,
                ));
            }
        }

        return $reservas;
    }

    /** @return array{trabajo: TrabajoImpresionMaterial, contenido: string, mime: string, nombre: string} */
    public function generarTransferencia(TransferenciaClienteMaterial $transferencia, array $datos, User $usuario, ?string $dispositivoId = null): array
    {
        $payload = ['origen' => 'transferencia_cliente', 'transferencia_cliente_material_id' => $transferencia->id,
            'perfil_id' => $datos['perfil_id'], 'formato' => $datos['formato'], 'simbologia' => $datos['simbologia'], 'canal' => $datos['canal'],
            'folio_ids' => collect($datos['folio_ids'])->sort()->values()->all(), 'copias' => (int) $datos['copias'], 'motivo_reimpresion' => $datos['motivo_reimpresion'] ?? null];

        return $this->generarTrabajo($payload, $datos, $usuario, $dispositivoId, function (array $ids) use ($transferencia): array {
            $transferencia = TransferenciaClienteMaterial::with('folioOrigen.folio')->lockForUpdate()->findOrFail($transferencia->id);
            if ($ids !== [$transferencia->folio_destino_id]) {
                throw new DomainException('Solo se imprime el folio destino de esta transferencia.');
            }
            $material = FolioMaterial::with(['folio', 'item.cliente.cliente', 'proveedorMaterial'])->findOrFail($transferencia->folio_destino_id);
            $etiqueta = $this->etiquetaSnapshot($material);
            $etiqueta = array_replace($etiqueta, ['origen' => 'transferencia_cliente',
                'folio_origen' => $transferencia->folioOrigen->folio->numero_folio,
                'cantidad' => number_format((float) $material->cantidad_inicial, 3, ',', '.'),
                'fecha_recepcion' => $material->folio->fecha_ingreso?->format('d/m/Y H:i'),
                'proveedor_codigo' => $material->proveedorMaterial?->codigo, 'proveedor_nombre' => $material->proveedorMaterial?->nombre ?? $material->proveedor,
                'lote_proveedor' => $material->lote, 'fecha_fabricacion' => $material->fecha_fabricacion?->toDateString(),
                'fecha_vencimiento' => $material->fecha_vencimiento?->toDateString(), 'bloqueado' => $material->motivo_bloqueo !== null,
                'motivo_bloqueo' => $material->motivo_bloqueo]);

            return ['recepcion_material_id' => null, 'orden_transformacion_material_id' => null, 'lote_transformacion_material_id' => null,
                'transferencia_cliente_material_id' => $transferencia->id, 'etiquetas' => [$etiqueta]];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $datos
     * @param  Closure(array<int, string>): array<string, mixed>  $resolver
     * @return array{trabajo: TrabajoImpresionMaterial, contenido: string, mime: string, nombre: string}
     */
    private function generarTrabajo(
        array $payload,
        array $datos,
        User $usuario,
        ?string $dispositivoId,
        Closure $resolver,
    ): array {
        $diseno = $datos['diseno'] ?? 'actual';
        if (! in_array($diseno, ['actual', DisenoEtiquetaClasicaMaterial::CODIGO], true)) {
            throw new DomainException('Selecciona un diseño de etiqueta válido.');
        }
        if ($diseno === DisenoEtiquetaClasicaMaterial::CODIGO) {
            if ($payload['simbologia'] !== 'code128') {
                throw new DomainException('Clásica bodega utiliza código de barras Code 128.');
            }
            if ($payload['origen'] !== 'recepcion') {
                throw new DomainException('El diseño Clásica bodega está disponible para recepciones de materiales.');
            }
            // Omitir "actual" conserva los hashes de clientes y trabajos anteriores.
            $payload['diseno'] = $diseno;
        }
        $payloadHash = $this->hash($payload);
        $contenidoGenerado = null;

        try {
            $trabajo = DB::transaction(function () use (
                $payload,
                $datos,
                $usuario,
                $dispositivoId,
                $resolver,
                $payloadHash,
                &$contenidoGenerado,
            ): TrabajoImpresionMaterial {
                $contenidoGenerado = null;
                $existente = TrabajoImpresionMaterial::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existente) {
                    if ($existente->solicitado_por_user_id !== $usuario->id
                        || ! hash_equals($existente->payload_hash, $payloadHash)) {
                        throw new ConflictoOperacion(
                            'El UUID de impresión ya fue utilizado con datos diferentes.',
                        );
                    }

                    return $existente->load('folios');
                }

                $contexto = $resolver($payload['folio_ids']);
                $perfil = PerfilImpresionEtiqueta::query()
                    ->whereKey($datos['perfil_id'])
                    ->where('activo', true)
                    ->lockForUpdate()
                    ->firstOrFail();
                $etiquetas = collect($contexto['etiquetas']);
                $reservasEtiquetadas = $this->reservasEtiquetadas($etiquetas);
                $reimpresos = $this->etiquetasReimpresas($etiquetas, $reservasEtiquetadas);
                $motivoReimpresion = $datos['motivo_reimpresion'] ?? null;
                if ($reimpresos !== [] && blank($motivoReimpresion)) {
                    // La cantidad real de un folio reservado difiere de la teórica ya impresa.
                    $soloCambioCantidad = collect($reimpresos)->every(
                        fn (string $clave): bool => (bool) $reservasEtiquetadas->get($clave)?->requiere_reimpresion,
                    );
                    if (! $soloCambioCantidad) {
                        throw new DomainException(
                            'Debes indicar el motivo para volver a generar una etiqueta.',
                        );
                    }
                    $motivoReimpresion = 'La cantidad real difiere de la cantidad teórica impresa.';
                }

                $perfilSnapshot = $this->perfilSnapshot($perfil);
                $contenidoSnapshot = $contexto['etiquetas'];
                if (($payload['diseno'] ?? null) === DisenoEtiquetaClasicaMaterial::CODIGO) {
                    DisenoEtiquetaClasicaMaterial::validarPerfil($perfilSnapshot);
                    $perfilSnapshot['diseno'] = DisenoEtiquetaClasicaMaterial::CODIGO;
                    $perfilSnapshot['diseno_version'] = DisenoEtiquetaClasicaMaterial::VERSION;
                    // Validar legibilidad antes de persistir un trabajo o marcar una reimpresión.
                    foreach ($contenidoSnapshot as $etiqueta) {
                        DisenoEtiquetaClasicaMaterial::disponer($etiqueta['clasica']);
                    }
                } else {
                    // El contenido del modelo actual conserva exactamente su estructura histórica.
                    $contenidoSnapshot = array_map(function (array $etiqueta): array {
                        unset($etiqueta['clasica']);

                        return $etiqueta;
                    }, $contenidoSnapshot);
                }
                $contenidoHash = $this->hash([
                    'perfil' => $perfilSnapshot,
                    'etiquetas' => $contenidoSnapshot,
                    'copias' => $payload['copias'],
                    'simbologia' => $payload['simbologia'],
                ]);
                $trabajo = TrabajoImpresionMaterial::create([
                    'operacion_id' => $datos['operacion_id'],
                    'payload_hash' => $payloadHash,
                    'origen' => $payload['origen'],
                    'transferencia_cliente_material_id' => $contexto['transferencia_cliente_material_id'] ?? null,
                    'recepcion_material_id' => $contexto['recepcion_material_id'],
                    'orden_transformacion_material_id' => $contexto['orden_transformacion_material_id'],
                    'lote_transformacion_material_id' => $contexto['lote_transformacion_material_id'],
                    'perfil_impresion_etiqueta_id' => $perfil->id,
                    'formato' => $datos['formato'],
                    'simbologia' => $datos['simbologia'],
                    'canal' => $datos['canal'],
                    'estado' => 'generado',
                    'copias' => $payload['copias'],
                    'motivo_reimpresion' => $motivoReimpresion,
                    'perfil_snapshot' => $perfilSnapshot,
                    'contenido_snapshot' => $contenidoSnapshot,
                    'contenido_hash' => $contenidoHash,
                    'solicitado_por_user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivoId,
                    'solicitado_at' => now(),
                ]);
                if (($payload['diseno'] ?? null) === DisenoEtiquetaClasicaMaterial::CODIGO) {
                    // Un error de legibilidad o archivo revierte también la auditoría.
                    $contenidoGenerado = $this->renderizar($trabajo);
                }

                foreach ($contenidoSnapshot as $etiqueta) {
                    $clave = $this->claveEtiqueta($etiqueta);
                    $reserva = $reservasEtiquetadas->get($clave);
                    FolioTrabajoImpresionMaterial::create([
                        'trabajo_impresion_material_id' => $trabajo->id,
                        'folio_id' => $etiqueta['folio_id'],
                        'folio_salida_reservado_id' => $reserva?->id,
                        'numero_folio_snapshot' => $etiqueta['numero_folio'],
                        'es_reimpresion' => in_array($clave, $reimpresos, true),
                    ]);
                    if ($reserva) {
                        $reserva->update([
                            'impresiones' => $reserva->impresiones + 1,
                            'ultima_impresion_at' => now(),
                            // La etiqueta del folio producido ya lleva la cantidad real.
                            'requiere_reimpresion' => $etiqueta['folio_id'] !== null
                                ? false
                                : $reserva->requiere_reimpresion,
                        ]);
                    }
                }

                return $trabajo->load('folios');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $contenidoGenerado = null;
            $trabajo = TrabajoImpresionMaterial::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if (! $trabajo
                || $trabajo->solicitado_por_user_id !== $usuario->id
                || ! hash_equals($trabajo->payload_hash, $payloadHash)) {
                throw new ConflictoOperacion(
                    'La generación de etiquetas entró en conflicto con otra operación.',
                    previous: $exception,
                );
            }
        }

        $contenido = $contenidoGenerado ?? $this->renderizar($trabajo);
        $extension = match ($trabajo->formato) {
            'pdf' => 'pdf',
            'nlbl' => 'nlbl',
            default => 'zpl',
        };
        $referencia = $trabajo->origen === 'transferencia_cliente'
            ? 'transferencia-'.mb_substr((string) $trabajo->transferencia_cliente_material_id, 0, 8)
            : ($trabajo->origen === 'transformacion'
            ? 'transformacion-'.mb_substr((string) $trabajo->orden_transformacion_material_id, 0, 8)
            : $this->referenciaRecepcion($trabajo));

        return [
            'trabajo' => $trabajo,
            'contenido' => $contenido,
            'mime' => match ($trabajo->formato) {
                'pdf' => 'application/pdf',
                'nlbl' => 'application/octet-stream',
                default => 'application/zpl',
            },
            'nombre' => "etiquetas-{$referencia}.{$extension}",
        ];
    }

    public function renderizar(TrabajoImpresionMaterial $trabajo): string
    {
        return match ($trabajo->formato) {
            'pdf' => $this->pdf->generar(
                $trabajo->contenido_snapshot,
                $trabajo->perfil_snapshot,
                $trabajo->copias,
            ),
            'nlbl' => $this->nlbl->generar(
                $trabajo->contenido_snapshot,
                $trabajo->perfil_snapshot,
                $trabajo->copias,
                $trabajo->simbologia,
            ),
            default => $this->zpl->generar(
                $trabajo->contenido_snapshot,
                $trabajo->perfil_snapshot,
                $trabajo->copias,
                $trabajo->simbologia,
            ),
        };
    }

    /**
     * Folio reservado al que corresponde cada etiqueta: el reservado que se imprime antes de
     * producir o el que dio origen al folio producido. Indexado por claveEtiqueta().
     *
     * @param  Collection<int, array<string, mixed>>  $etiquetas
     * @return Collection<string, FolioSalidaReservadoTransformacion>
     */
    private function reservasEtiquetadas(Collection $etiquetas): Collection
    {
        if ($etiquetas->every(fn (array $etiqueta): bool => ($etiqueta['origen'] ?? null) !== 'transformacion')) {
            return collect();
        }

        $porId = $etiquetas->pluck('folio_salida_reservado_id')->filter()->values();
        $porFolio = $etiquetas->pluck('folio_id')->filter()->values();

        return FolioSalidaReservadoTransformacion::query()
            ->where(fn ($consulta) => $consulta
                ->whereIn('id', $porId)
                ->orWhereIn('folio_id', $porFolio))
            ->lockForUpdate()
            ->get()
            ->flatMap(fn (FolioSalidaReservadoTransformacion $reserva): array => array_filter([
                'reserva:'.$reserva->id => $porId->contains($reserva->id) ? $reserva : null,
                'folio:'.$reserva->folio_id => $reserva->folio_id !== null ? $reserva : null,
            ]));
    }

    /**
     * Claves de las etiquetas que ya se habían impreso: el mismo folio, el folio reservado
     * que le dio origen, o el mismo folio reservado.
     *
     * @param  Collection<int, array<string, mixed>>  $etiquetas
     * @param  Collection<string, FolioSalidaReservadoTransformacion>  $reservas
     * @return array<int, string>
     */
    private function etiquetasReimpresas(Collection $etiquetas, Collection $reservas): array
    {
        $foliosImpresos = FolioTrabajoImpresionMaterial::query()
            ->whereIn('folio_id', $etiquetas->pluck('folio_id')->filter()->values()->all())
            ->distinct()
            ->pluck('folio_id')
            ->all();

        return $etiquetas
            ->filter(fn (array $etiqueta): bool => in_array($etiqueta['folio_id'], $foliosImpresos, true)
                || (int) $reservas->get($this->claveEtiqueta($etiqueta))?->impresiones > 0)
            ->map(fn (array $etiqueta): string => $this->claveEtiqueta($etiqueta))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $etiqueta */
    private function claveEtiqueta(array $etiqueta): string
    {
        return $etiqueta['folio_id'] !== null
            ? 'folio:'.$etiqueta['folio_id']
            : 'reserva:'.$etiqueta['folio_salida_reservado_id'];
    }

    /** @return array<string, mixed> */
    private function perfilSnapshot(PerfilImpresionEtiqueta $perfil): array
    {
        return [
            'id' => $perfil->id,
            'codigo' => $perfil->codigo,
            'nombre' => $perfil->nombre,
            'fabricante' => $perfil->fabricante,
            'modelo' => $perfil->modelo,
            'lenguaje' => $perfil->lenguaje,
            'dpi' => $perfil->dpi,
            'ancho_mm' => (float) $perfil->ancho_mm,
            'alto_mm' => (float) $perfil->alto_mm,
            'orientacion' => $perfil->orientacion,
        ];
    }

    /** @return array<string, mixed> */
    private function etiquetaSnapshot(FolioMaterial $material): array
    {
        $bulto = $material->bultoRecepcion;
        $recepcion = $bulto?->detalle?->recepcion;
        $cliente = $material->item?->cliente?->cliente;
        $proveedor = $recepcion?->proveedor;
        $fechaFisica = $recepcion?->fecha_recepcion ?? $material->folio?->fecha_ingreso ?? $recepcion?->confirmado_at;
        $cantidad = (float) $bulto?->cantidad;

        return [
            'origen' => 'recepcion',
            'folio_id' => $material->folio_id,
            'numero_folio' => $material->folio?->numero_folio,
            'fecha_recepcion' => $recepcion?->created_at?->format('d/m/Y H:i'),
            'cliente_codigo' => $cliente?->codigo,
            'cliente_nombre' => $cliente?->nombre,
            'item_codigo' => $material->item?->codigo,
            'item_nombre' => $material->item?->nombre,
            'cantidad' => number_format((float) $bulto?->cantidad, 3, ',', '.'),
            'unidad_medida' => $material->unidad_medida,
            'numero_guia' => $recepcion?->numero_guia_despacho,
            'proveedor_codigo' => $proveedor?->codigo,
            'proveedor_nombre' => $proveedor?->nombre,
            'lote_proveedor' => $bulto?->lote_proveedor,
            'fecha_fabricacion' => $bulto?->fecha_fabricacion?->toDateString(),
            'fecha_vencimiento' => $bulto?->fecha_vencimiento?->toDateString(),
            'bloqueado' => (bool) $bulto?->bloqueado,
            'motivo_bloqueo' => $bulto?->motivo_bloqueo,
            'orden_transformacion' => null,
            'numero_lote_transformacion' => null,
            'fecha_transformacion' => null,
            'clasica' => [
                'cliente' => (string) $recepcion?->cliente?->nombre,
                'fecha' => $fechaFisica?->copy()->timezone(config('app.operational_timezone', 'America/Santiago'))->format('d/m/Y') ?? 'SIN FECHA',
                'guia' => $recepcion?->numero_guia_despacho ?: 'PENDIENTE',
                'proveedor' => (string) $proveedor?->nombre,
                'codigo' => (string) $material->item?->codigo,
                'descripcion' => (string) $material->item?->nombre,
                'cantidad' => rtrim(rtrim(number_format($cantidad, 3, ',', '.'), '0'), ','),
                'unidad' => mb_strtoupper((string) $material->unidad_medida),
                'folio' => (string) $material->folio?->numero_folio,
                'bloqueado' => filled($material->motivo_bloqueo) || $material->folio?->estado_operacional === EstadoOperacionalFolio::Bloqueado,
                'motivo_bloqueo' => $material->motivo_bloqueo ?: $bulto?->motivo_bloqueo,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function etiquetaTransformacionSnapshot(
        SalidaTransformacionMaterial $salida,
        OrdenTransformacionMaterial $orden,
        ?FolioSalidaReservadoTransformacion $reserva = null,
    ): array {
        $material = $salida->folioMaterial;
        $lote = $salida->lote;

        return [
            'origen' => 'transformacion',
            'folio_id' => $salida->folio_id,
            'numero_folio' => $material?->folio?->numero_folio,
            'fecha_recepcion' => null,
            'cliente_codigo' => $orden->cliente?->codigo,
            'cliente_nombre' => $orden->cliente?->nombre,
            'item_codigo' => $salida->item?->codigo,
            'item_nombre' => $salida->item?->nombre,
            'cantidad' => number_format((float) $salida->cantidad_producida, 3, ',', '.'),
            'unidad_medida' => $salida->item?->unidad_medida ?? $material?->unidad_medida,
            'numero_guia' => null,
            'proveedor_codigo' => null,
            'proveedor_nombre' => null,
            'lote_proveedor' => $material?->lote,
            'fecha_fabricacion' => null,
            'fecha_vencimiento' => null,
            'bloqueado' => false,
            'motivo_bloqueo' => null,
            'orden_transformacion' => 'OT-'.mb_strtoupper(mb_substr($orden->id, 0, 8)),
            // Si vino de un folio reservado, repite la secuencia de la etiqueta anticipada.
            'numero_lote_transformacion' => $reserva?->secuencia ?? $lote?->numero_lote,
            'fecha_transformacion' => $lote?->cerrado_at?->toAtomString(),
            'armado' => $this->tarjaArmado(
                $orden,
                (string) $material?->folio?->numero_folio,
                (float) $salida->cantidad_producida,
                $salida->item?->codigo,
                $salida->item?->nombre,
            ),
        ];
    }

    /**
     * Etiqueta anticipada de un folio reservado: lleva la cantidad teórica y se imprime antes
     * de producir. Al cerrar el lote el folio se crea con este mismo número.
     *
     * @return array<string, mixed>
     */
    private function etiquetaFolioReservadoSnapshot(
        FolioSalidaReservadoTransformacion $reserva,
        OrdenTransformacionMaterial $orden,
    ): array {
        $salida = (array) data_get($orden->snapshot_receta, 'salida', []);
        $tarja = $this->tarjaArmado(
            $orden,
            $reserva->numero_folio,
            (float) $reserva->cantidad_teorica,
            $salida['codigo'] ?? null,
            $salida['nombre'] ?? null,
        );

        return [
            'origen' => 'transformacion',
            'folio_id' => null,
            'folio_salida_reservado_id' => $reserva->id,
            'numero_folio' => $reserva->numero_folio,
            'fecha_recepcion' => null,
            'cliente_codigo' => $orden->cliente?->codigo,
            'cliente_nombre' => $orden->cliente?->nombre,
            'item_codigo' => $salida['codigo'] ?? null,
            'item_nombre' => $salida['nombre'] ?? null,
            'cantidad' => number_format((float) $reserva->cantidad_teorica, 3, ',', '.'),
            'unidad_medida' => $salida['unidad_medida'] ?? null,
            'numero_guia' => null,
            'proveedor_codigo' => null,
            'proveedor_nombre' => null,
            'lote_proveedor' => $tarja['lote'] ?? sprintf(
                'TR-%s-%03d',
                $orden->fecha_operacional->format('ymd'),
                $reserva->secuencia,
            ),
            'fecha_fabricacion' => null,
            'fecha_vencimiento' => null,
            'bloqueado' => false,
            'motivo_bloqueo' => null,
            'orden_transformacion' => 'OT-'.mb_strtoupper(mb_substr($orden->id, 0, 8)),
            'numero_lote_transformacion' => $reserva->secuencia,
            'fecha_transformacion' => null,
            'armado' => $tarja,
        ];
    }

    /**
     * Datos de la tarja de armado del pallet, si la orden se planificó con tarja: proveedor
     * de la caja, cliente dueño, bodega, ítem, fecha del armado, cajas, lote y folio único.
     *
     * @return array<string, string>|null
     */
    private function tarjaArmado(
        OrdenTransformacionMaterial $orden,
        string $numeroFolio,
        float $cantidad,
        ?string $codigoItem,
        ?string $nombreItem,
    ): ?array {
        $datos = $orden->datos_armado;
        if (! is_array($datos) || ! isset($datos['lote'])) {
            return null;
        }
        $entera = abs($cantidad - round($cantidad)) < 0.0005;

        return [
            'proveedor' => (string) (($datos['proveedor_codigo'] ?? null) ?: ($datos['proveedor_nombre'] ?? '')),
            'proveedor_nombre' => (string) ($datos['proveedor_nombre'] ?? ''),
            'cliente' => mb_strtoupper((string) $orden->cliente?->nombre),
            'bodega' => mb_strtoupper((string) ($datos['nombre_bodega'] ?? '')),
            'codigo' => (string) $codigoItem,
            'descripcion' => (string) $nombreItem,
            'fecha' => Carbon::parse((string) $datos['fecha'])->format('d-m-Y'),
            'cantidad' => $entera
                ? number_format($cantidad, 0, ',', '.')
                : rtrim(rtrim(number_format($cantidad, 3, ',', '.'), '0'), ','),
            'lote' => (string) $datos['lote'],
            'guia' => (string) ($datos['guia'] ?? ''),
            'turno' => (string) ($datos['turno'] ?? ''),
            'maquina' => (string) ($datos['maquina'] ?? ''),
            'folio' => $numeroFolio,
        ];
    }

    private function referenciaRecepcion(TrabajoImpresionMaterial $trabajo): string
    {
        $recepcion = RecepcionMaterial::query()->find($trabajo->recepcion_material_id);
        $guia = preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '-',
            (string) $recepcion?->numero_guia_despacho,
        );

        return $guia ?: mb_substr((string) $trabajo->recepcion_material_id, 0, 8);
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
