<?php

namespace App\Services\Materiales;

use App\Models\ClienteMaterial;
use App\Models\ClienteProveedorMaterial;
use App\Models\ItemMaterial;
use App\Models\ProveedorMaterial;
use App\Models\TemporadaMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ServicioPrevisualizacionImportacionRecepcionMaterial
{
    private const MAX_FILAS = 100;

    private const MAX_BULTOS_POR_FILA = 500;

    public function __construct(
        private readonly LectorPlanillaRecepcionMaterial $lector,
    ) {}

    /**
     * @return array{
     *     resumen: array{filas_leidas: int, filas_validas: int, filas_con_error: int, folios_estimados: int},
     *     filas: array<int, array<string, mixed>>,
     *     errores: array<int, array{fila: int, codigo_item: string|null, mensaje: string}>
     * }
     */
    public function previsualizar(
        UploadedFile $archivo,
        string $clienteId,
        string $proveedorId,
    ): array {
        $filasLeidas = $this->lector->leer($archivo);

        if (count($filasLeidas) > self::MAX_FILAS) {
            throw new DomainException('La planilla supera el máximo de 100 productos por recepción.');
        }

        $temporada = TemporadaMaterial::query()
            ->where('activa', true)
            ->whereHas('temporadaGlobal', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->first();

        if (! $temporada) {
            throw new DomainException('No existe una temporada global activa para Materiales.');
        }

        $catalogoCliente = ClienteMaterial::query()
            ->with('cliente')
            ->where('temporada_material_id', $temporada->id)
            ->where('cliente_id', $clienteId)
            ->where('activo', true)
            ->whereHas('cliente', fn ($consulta) => $consulta->where('activo', true))
            ->first();

        if (! $catalogoCliente) {
            throw new DomainException(
                'El cliente no está habilitado en el catálogo de Materiales de la temporada activa.',
            );
        }

        $proveedor = ProveedorMaterial::query()
            ->whereKey($proveedorId)
            ->where('activo', true)
            ->first();
        $vinculoProveedor = ClienteProveedorMaterial::query()
            ->where('cliente_id', $clienteId)
            ->where('proveedor_material_id', $proveedorId)
            ->where('activo', true)
            ->first();

        if (! $proveedor || ! $vinculoProveedor) {
            throw new DomainException(
                'El proveedor no está activo o no se encuentra autorizado para el cliente seleccionado.',
            );
        }

        $codigos = collect($filasLeidas)
            ->map(fn (array $fila): string => mb_strtoupper($this->texto($fila['codigo_item'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $items = ItemMaterial::query()
            ->where('cliente_material_id', $catalogoCliente->id)
            ->whereIn('codigo', $codigos)
            ->get()
            ->keyBy(fn (ItemMaterial $item): string => mb_strtolower($item->codigo));
        $categoriasAutorizadas = $this->categoriasAutorizadas($vinculoProveedor);
        $filas = [];
        $errores = [];
        $foliosEstimados = 0;

        foreach ($filasLeidas as $filaLeida) {
            $filaNumero = (int) ($filaLeida['fila'] ?? 0);
            $codigo = mb_strtoupper($this->texto($filaLeida['codigo_item'] ?? ''));
            $mensajes = [];

            if ($codigo === '') {
                $mensajes[] = 'Falta el código del ítem.';
            }

            /** @var ItemMaterial|null $item */
            $item = $codigo === '' ? null : $items->get(mb_strtolower($codigo));

            if ($codigo !== '' && ! $item) {
                $mensajes[] = 'El ítem no existe para el cliente seleccionado.';
            } elseif ($item && ! $item->activo) {
                $mensajes[] = 'El ítem se encuentra inactivo.';
            } elseif ($item && ! $item->categoria_operacional) {
                $mensajes[] = 'El ítem no posee tipo operacional configurado.';
            } elseif ($item) {
                $categoria = mb_strtolower(trim((string) $item->categoria));
                if ($categoria === '' || ! $categoriasAutorizadas->contains($categoria)) {
                    $mensajes[] = sprintf(
                        'El proveedor %s no está habilitado para la categoría %s.',
                        $proveedor->codigo,
                        trim((string) $item->categoria) !== '' ? $item->categoria : 'sin categoría',
                    );
                }
            }

            $aceptada = $this->decimal($filaLeida['cantidad_aceptada'] ?? '');
            $rechazada = $this->decimal($filaLeida['cantidad_rechazada'] ?? '');
            $rechazada ??= 0.0;
            $contada = $this->decimal($filaLeida['cantidad_contada'] ?? '');
            $contada ??= $aceptada !== null ? round($aceptada + $rechazada, 3) : null;
            $documental = $this->decimal($filaLeida['cantidad_documental'] ?? '');
            $documental ??= $contada;
            $tamanoBulto = $this->decimal($filaLeida['unidades_por_bulto'] ?? '');

            foreach ([
                'La cantidad documental' => $filaLeida['cantidad_documental'] ?? '',
                'La cantidad contada' => $filaLeida['cantidad_contada'] ?? '',
                'La cantidad aceptada' => $filaLeida['cantidad_aceptada'] ?? '',
                'La cantidad rechazada' => $filaLeida['cantidad_rechazada'] ?? '',
                'Las unidades por bulto' => $filaLeida['unidades_por_bulto'] ?? '',
            ] as $etiqueta => $valor) {
                if ($this->excedeTresDecimales($valor)) {
                    $mensajes[] = $etiqueta.' admite como máximo 3 decimales.';
                }
            }

            if ($aceptada === null || $aceptada < 0) {
                $mensajes[] = 'La cantidad aceptada debe ser un número mayor o igual a cero.';
            }
            if ($rechazada < 0) {
                $mensajes[] = 'La cantidad rechazada debe ser mayor o igual a cero.';
            }
            if ($contada === null || $contada <= 0) {
                $mensajes[] = 'La cantidad contada debe ser mayor que cero.';
            }
            if ($documental === null || $documental <= 0) {
                $mensajes[] = 'La cantidad documental debe ser mayor que cero.';
            }
            if ($contada !== null && $aceptada !== null
                && abs($contada - $aceptada - $rechazada) > 0.0001) {
                $mensajes[] = 'La cantidad contada debe coincidir con aceptada más rechazada.';
            }
            if ($aceptada !== null && $aceptada > 0 && ($tamanoBulto === null || $tamanoBulto <= 0)) {
                $mensajes[] = 'Indica las unidades por bulto para distribuir la cantidad aceptada.';
            }

            $bloqueado = $this->booleano($filaLeida['bloqueado'] ?? '');
            if ($bloqueado === null) {
                $mensajes[] = 'El campo bloqueado debe usar sí/no, verdadero/falso o 1/0.';
                $bloqueado = false;
            }
            $motivoBloqueo = $this->opcional($filaLeida['motivo_bloqueo'] ?? '');
            if ($bloqueado && ! $motivoBloqueo) {
                $mensajes[] = 'Un producto bloqueado debe indicar el motivo del bloqueo.';
            }
            if ($motivoBloqueo !== null && mb_strlen($motivoBloqueo) > 2000) {
                $mensajes[] = 'El motivo del bloqueo no puede superar 2000 caracteres.';
            }

            $fechaFabricacion = $this->fecha($filaLeida['fecha_fabricacion'] ?? '');
            $fechaVencimiento = $this->fecha($filaLeida['fecha_vencimiento'] ?? '');
            if ($this->texto($filaLeida['fecha_fabricacion'] ?? '') !== '' && ! $fechaFabricacion) {
                $mensajes[] = 'La fecha de fabricación no es válida.';
            }
            if ($this->texto($filaLeida['fecha_vencimiento'] ?? '') !== '' && ! $fechaVencimiento) {
                $mensajes[] = 'La fecha de vencimiento no es válida.';
            }
            if ($fechaFabricacion && $fechaVencimiento && $fechaVencimiento < $fechaFabricacion) {
                $mensajes[] = 'La fecha de vencimiento no puede ser anterior a la fabricación.';
            }

            $loteProveedor = $this->opcional($filaLeida['lote_proveedor'] ?? '');
            $observacion = $this->opcional($filaLeida['observacion'] ?? '');
            if ($loteProveedor !== null && mb_strlen($loteProveedor) > 100) {
                $mensajes[] = 'El lote del proveedor no puede superar 100 caracteres.';
            }
            if ($observacion !== null && mb_strlen($observacion) > 2000) {
                $mensajes[] = 'La observación no puede superar 2000 caracteres.';
            }

            $bultos = [];
            if ($aceptada !== null && $aceptada > 0 && $tamanoBulto !== null && $tamanoBulto > 0) {
                $bultos = $this->generarBultos(
                    $aceptada,
                    $tamanoBulto,
                    $loteProveedor,
                    $fechaFabricacion,
                    $fechaVencimiento,
                    $bloqueado,
                    $motivoBloqueo,
                );

                if (count($bultos) > self::MAX_BULTOS_POR_FILA) {
                    $mensajes[] = 'El producto genera más de 500 bultos; aumenta las unidades por bulto.';
                }
            }

            if ($mensajes !== []) {
                $errores[] = [
                    'fila' => $filaNumero,
                    'codigo_item' => $codigo !== '' ? $codigo : null,
                    'mensaje' => implode(' ', array_unique($mensajes)),
                ];

                continue;
            }

            $foliosEstimados += count($bultos);
            $filas[] = [
                'fila' => $filaNumero,
                'item' => [
                    'id' => $item->id,
                    'codigo' => $item->codigo,
                    'nombre' => $item->nombre,
                    'categoria' => $item->categoria,
                    'categoria_operacional' => $item->categoria_operacional->value,
                    'unidad_medida' => $item->unidad_medida,
                ],
                'cantidad_documental' => $documental,
                'cantidad_contada' => $contada,
                'cantidad_aceptada' => $aceptada,
                'cantidad_rechazada' => $rechazada,
                'unidades_por_bulto' => $tamanoBulto,
                'observacion' => $observacion,
                'bultos' => $bultos,
            ];
        }

        if ($filasLeidas === []) {
            $errores[] = [
                'fila' => 1,
                'codigo_item' => null,
                'mensaje' => 'La planilla no contiene productos.',
            ];
        }

        return [
            'resumen' => [
                'filas_leidas' => count($filasLeidas),
                'filas_validas' => count($filas),
                'filas_con_error' => count($errores),
                'folios_estimados' => $foliosEstimados,
            ],
            'filas' => $filas,
            'errores' => $errores,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function categoriasAutorizadas(ClienteProveedorMaterial $vinculo): Collection
    {
        $categorias = $vinculo->categorias ?? [];
        if (is_string($categorias)) {
            $categorias = json_decode($categorias, true) ?: [];
        }

        return collect(is_array($categorias) ? $categorias : [])
            ->map(fn ($categoria): string => mb_strtolower(trim((string) $categoria)))
            ->filter()
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generarBultos(
        float $cantidadAceptada,
        float $tamanoBulto,
        ?string $lote,
        ?string $fechaFabricacion,
        ?string $fechaVencimiento,
        bool $bloqueado,
        ?string $motivoBloqueo,
    ): array {
        $bultos = [];
        $restante = round($cantidadAceptada, 3);

        while ($restante > 0.0001 && count($bultos) <= self::MAX_BULTOS_POR_FILA) {
            $cantidad = round(min($tamanoBulto, $restante), 3);
            $bultos[] = [
                'cantidad' => $cantidad,
                'lote_proveedor' => $lote,
                'fecha_fabricacion' => $fechaFabricacion,
                'fecha_vencimiento' => $fechaVencimiento,
                'bloqueado' => $bloqueado,
                'motivo_bloqueo' => $bloqueado ? $motivoBloqueo : null,
            ];
            $restante = round($restante - $cantidad, 3);
        }

        return $bultos;
    }

    private function decimal(mixed $valor): ?float
    {
        $texto = $this->normalizarDecimal($valor);

        return $texto !== null ? round((float) $texto, 3) : null;
    }

    private function excedeTresDecimales(mixed $valor): bool
    {
        $texto = $this->normalizarDecimal($valor);
        if ($texto === null) {
            return false;
        }

        $numero = (float) $texto;

        return abs($numero - round($numero, 3)) > 0.0000001;
    }

    private function normalizarDecimal(mixed $valor): ?string
    {
        $texto = $this->texto($valor);
        if ($texto === '') {
            return null;
        }

        $texto = str_replace(["\u{00A0}", ' ', "'"], '', $texto);
        $ultimaComa = strrpos($texto, ',');
        $ultimoPunto = strrpos($texto, '.');

        if ($ultimaComa !== false && $ultimoPunto !== false) {
            if ($ultimaComa > $ultimoPunto) {
                $texto = str_replace('.', '', $texto);
                $texto = str_replace(',', '.', $texto);
            } else {
                $texto = str_replace(',', '', $texto);
            }
        } elseif ($ultimaComa !== false) {
            $texto = str_replace(',', '.', $texto);
        }

        return is_numeric($texto) ? $texto : null;
    }

    private function booleano(mixed $valor): ?bool
    {
        $texto = Str::of($this->texto($valor))->ascii()->lower()->toString();
        if ($texto === '') {
            return false;
        }

        return match ($texto) {
            '1', 'si', 's', 'true', 'verdadero', 'bloqueado' => true,
            '0', 'no', 'n', 'false', 'falso', 'libre', 'sin_bloqueo' => false,
            default => null,
        };
    }

    private function fecha(mixed $valor): ?string
    {
        $texto = $this->texto($valor);
        if ($texto === '') {
            return null;
        }

        if (is_numeric($texto) && (float) $texto >= 1) {
            return Carbon::create(1899, 12, 30)
                ->addDays((int) floor((float) $texto))
                ->toDateString();
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $formato) {
            try {
                $fecha = Carbon::createFromFormat('!'.$formato, $texto);
                if ($fecha && $fecha->format($formato) === $texto) {
                    return $fecha->toDateString();
                }
            } catch (Throwable) {
                // Continúa probando los formatos operacionales admitidos.
            }
        }

        return null;
    }

    private function texto(mixed $valor): string
    {
        return trim((string) ($valor ?? ''));
    }

    private function opcional(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto === '' ? null : $texto;
    }
}
