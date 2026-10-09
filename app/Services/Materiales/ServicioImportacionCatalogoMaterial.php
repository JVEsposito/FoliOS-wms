<?php

namespace App\Services\Materiales;

use App\Enums\CategoriaOperacionalMaterial;
use App\Models\ClienteMaterial;
use App\Models\ImportacionCatalogoMaterial;
use App\Models\ItemMaterial;
use App\Models\TemporadaMaterial;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioImportacionCatalogoMaterial
{
    private const MAX_FILAS = 5000;

    public function __construct(
        private readonly LectorPlanillaMaterial $lector,
    ) {}

    public function previsualizar(UploadedFile $archivo, User $usuario): ImportacionCatalogoMaterial
    {
        $filasLeidas = $this->lector->leer($archivo);

        if (count($filasLeidas) > self::MAX_FILAS) {
            throw new DomainException('La planilla supera el máximo de 5.000 filas permitidas.');
        }

        $codigosTemporadas = collect($filasLeidas)
            ->map(fn (array $fila): string => mb_strtoupper($this->texto($fila['temporada_codigo'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $codigosClientes = collect($filasLeidas)
            ->map(fn (array $fila): string => mb_strtoupper($this->texto($fila['cliente_codigo'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $codigos = collect($filasLeidas)
            ->map(fn (array $fila): string => mb_strtoupper($this->texto($fila['codigo'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $codigosExternos = collect($filasLeidas)
            ->map(fn (array $fila): string => $this->texto($fila['codigo_externo'] ?? ''))
            ->filter()
            ->unique()
            ->values();

        $temporadasPorCodigo = TemporadaMaterial::query()
            ->whereIn('codigo', $codigosTemporadas)
            ->get()
            ->keyBy(fn (TemporadaMaterial $temporada): string => mb_strtolower($temporada->codigo));
        $clientesPorCodigo = ClienteMaterial::query()
            ->whereIn('temporada_material_id', $temporadasPorCodigo->pluck('id'))
            ->whereIn('codigo', $codigosClientes)
            ->get()
            ->keyBy(fn (ClienteMaterial $cliente): string => $this->claveItem(
                $cliente->temporada_material_id,
                $cliente->codigo,
            ));
        $clienteIds = $clientesPorCodigo->pluck('id');
        $existentesPorCodigo = ItemMaterial::query()
            ->withCount('foliosMateriales')
            ->whereIn('cliente_material_id', $clienteIds)
            ->whereIn('codigo', $codigos)
            ->get()
            ->keyBy(fn (ItemMaterial $item): string => $this->claveItem(
                $item->cliente_material_id,
                $item->codigo,
            ));
        $existentesPorExterno = ItemMaterial::query()
            ->whereIn('cliente_material_id', $clienteIds)
            ->whereIn('codigo_externo', $codigosExternos)
            ->get()
            ->keyBy(fn (ItemMaterial $item): string => $this->claveItem(
                $item->cliente_material_id,
                (string) $item->codigo_externo,
            ));

        $filas = [];
        $errores = [];
        $codigosVistos = [];
        $externosVistos = [];

        foreach ($filasLeidas as $filaLeida) {
            $fila = $this->normalizarFila($filaLeida);
            $mensajes = $this->validarFila($fila);
            /** @var TemporadaMaterial|null $temporada */
            $temporada = $temporadasPorCodigo->get(mb_strtolower((string) $fila['temporada_codigo']));
            /** @var ClienteMaterial|null $cliente */
            $cliente = $temporada
                ? $clientesPorCodigo->get($this->claveItem($temporada->id, (string) $fila['cliente_codigo']))
                : null;

            if ($fila['temporada_codigo'] !== '' && ! $temporada) {
                $mensajes[] = 'La temporada no existe en el catálogo de materiales.';
            }
            if ($temporada && $fila['cliente_codigo'] !== '' && ! $cliente) {
                $mensajes[] = 'El cliente no existe dentro de la temporada indicada.';
            }

            $fila['temporada_material_id'] = $temporada?->id;
            $fila['temporada_nombre'] = $temporada?->nombre;
            $fila['cliente_material_id'] = $cliente?->id;
            $fila['cliente_nombre'] = $cliente?->nombre;
            $claveCodigo = $cliente
                ? $this->claveItem($cliente->id, (string) $fila['codigo'])
                : '';
            $claveExterno = $cliente && ($fila['codigo_externo'] ?? null)
                ? $this->claveItem($cliente->id, (string) $fila['codigo_externo'])
                : '';

            if ($claveCodigo !== '' && isset($codigosVistos[$claveCodigo])) {
                $mensajes[] = sprintf('El código ya fue declarado en la fila %d.', $codigosVistos[$claveCodigo]);
            } elseif ($claveCodigo !== '') {
                $codigosVistos[$claveCodigo] = $fila['fila'];
            }

            if ($claveExterno !== '' && isset($externosVistos[$claveExterno])) {
                $mensajes[] = sprintf('El código externo ya fue declarado en la fila %d.', $externosVistos[$claveExterno]);
            } elseif ($claveExterno !== '') {
                $externosVistos[$claveExterno] = $fila['fila'];
            }

            /** @var ItemMaterial|null $existente */
            $existente = $claveCodigo === '' ? null : $existentesPorCodigo->get($claveCodigo);
            if ($existente && mb_strtolower($this->texto($existente->unidad_medida)) === $fila['unidad_medida']) {
                // Conservar la representación histórica al descargar y reimportar.
                $fila['unidad_medida'] = $existente->unidad_medida;
            }
            if ($existente && $fila['categoria_operacional_original'] === '') {
                $fila['categoria_operacional'] = $existente->categoria_operacional?->value;
                $mensajes = array_values(array_diff($mensajes, ['Falta el tipo de ítem.']));
            }
            if ($cliente && ! $cliente->activo && (! $existente || $this->accionEstimada($fila, $existente) !== 'sin_cambios')) {
                $mensajes[] = 'El cliente se encuentra inactivo.';
            }

            /** @var ItemMaterial|null $duenoCodigoExterno */
            $duenoCodigoExterno = $claveExterno === '' ? null : $existentesPorExterno->get($claveExterno);

            if ($duenoCodigoExterno && (! $existente || $duenoCodigoExterno->id !== $existente->id)) {
                $mensajes[] = 'El código externo ya pertenece al ítem '.$duenoCodigoExterno->codigo.'.';
            }

            if ($existente
                && $existente->unidad_medida !== $fila['unidad_medida']
                && $existente->folios_materiales_count > 0) {
                $mensajes[] = 'La unidad de medida no puede cambiar porque el ítem ya posee folios asociados.';
            }

            $niveles = [...($existente?->only(NivelesStockMaterial::CAMPOS) ?? []), ...array_filter(array_intersect_key($fila, array_flip(NivelesStockMaterial::CAMPOS)), fn ($v) => $v !== null)];
            $mensajes = [...$mensajes, ...array_values(NivelesStockMaterial::errores($niveles))];
            if ($mensajes !== []) {
                $errores[] = [
                    'fila' => $fila['fila'],
                    'codigo' => $fila['codigo'],
                    'mensaje' => implode(' ', array_unique($mensajes)),
                ];

                continue;
            }

            $fila['accion'] = $this->accionEstimada($fila, $existente);
            $fila['huella_catalogo'] = $existente ? $this->huellaItem($existente) : null;
            $filas[] = $fila;
        }

        if ($filasLeidas === []) {
            $errores[] = [
                'fila' => 1,
                'codigo' => null,
                'mensaje' => 'La planilla no contiene filas de datos.',
            ];
        }

        $resumen = $this->resumen($filasLeidas, $filas, $errores);
        $conocidas = ['fila', ...array_diff(ServicioCatalogoItemsMateriales::COLUMNAS_IMPORTACION, ['tipo_item']), 'categoria_operacional'];
        $resumen['columnas_omitidas'] = collect($filasLeidas)->flatMap(fn ($f) => array_keys($f))->unique()->diff($conocidas)->values()->all();

        return ImportacionCatalogoMaterial::create([
            'nombre_archivo' => $archivo->getClientOriginalName(),
            'tipo_archivo' => mb_strtolower($archivo->getClientOriginalExtension()),
            'checksum' => hash_file('sha256', $archivo->getRealPath()),
            'estado' => $errores === [] ? 'borrador' : 'con_errores',
            'resumen' => $resumen,
            'filas' => $filas,
            'errores' => $errores !== [] ? $errores : null,
            'creado_por_user_id' => $usuario->id,
        ])->load(['creadoPor:id,name', 'confirmadoPor:id,name']);
    }

    public function confirmar(
        ImportacionCatalogoMaterial $importacion,
        User $usuario,
    ): ImportacionCatalogoMaterial {
        return DB::transaction(function () use ($importacion, $usuario): ImportacionCatalogoMaterial {
            $importacion = ImportacionCatalogoMaterial::query()
                ->lockForUpdate()
                ->findOrFail($importacion->id);

            if ($importacion->estado === 'confirmada') {
                return $importacion->load(['creadoPor:id,name', 'confirmadoPor:id,name']);
            }

            if (($importacion->errores ?? []) !== []) {
                throw new DomainException('La importación posee errores y no puede confirmarse.');
            }

            $creados = 0;
            $actualizados = 0;
            $sinCambios = 0;

            foreach ($importacion->filas as $fila) {
                $temporada = TemporadaMaterial::query()
                    ->whereKey($fila['temporada_material_id'])
                    ->where('codigo', $fila['temporada_codigo'])
                    ->lockForUpdate()
                    ->first();

                if (! $temporada) {
                    throw new DomainException(
                        'La temporada '.$fila['temporada_codigo'].' cambió después de la previsualización.',
                    );
                }

                $cliente = ClienteMaterial::query()
                    ->whereKey($fila['cliente_material_id'])
                    ->where('temporada_material_id', $temporada->id)
                    ->where('codigo', $fila['cliente_codigo'])
                    ->lockForUpdate()
                    ->first();

                if (! $cliente) {
                    throw new DomainException(
                        'El cliente '.$fila['cliente_codigo'].' cambió o fue desactivado después de la previsualización.',
                    );
                }

                $item = ItemMaterial::query()
                    ->where('cliente_material_id', $cliente->id)
                    ->where('codigo', $fila['codigo'])
                    ->lockForUpdate()
                    ->first();

                $this->validarHuellaCatalogo($fila, $item);
                if (! $cliente->activo && (! $item || $this->accionEstimada($fila, $item) !== 'sin_cambios')) {
                    throw new DomainException('No puede modificarse el catálogo de un cliente inactivo.');
                }

                if ($item
                    && $item->unidad_medida !== $fila['unidad_medida']
                    && $item->foliosMateriales()->exists()) {
                    throw new DomainException(
                        'La unidad de '.$item->codigo.' cambió después de la previsualización y ya posee folios asociados.',
                    );
                }

                if (($fila['codigo_externo'] ?? null) !== null) {
                    $codigoExternoOcupado = ItemMaterial::query()
                        ->where('cliente_material_id', $cliente->id)
                        ->where('codigo_externo', $fila['codigo_externo'])
                        ->when($item, fn ($consulta) => $consulta->where('id', '!=', $item->id))
                        ->lockForUpdate()
                        ->first();

                    if ($codigoExternoOcupado) {
                        throw new DomainException(
                            'El código externo de '.$fila['codigo'].' fue asignado después de la previsualización.',
                        );
                    }
                }

                $nuevo = $item === null;
                $item ??= new ItemMaterial([
                    'cliente_material_id' => $cliente->id,
                    'codigo' => $fila['codigo'],
                ]);
                $datos = [
                    'nombre' => $fila['nombre'],
                    'categoria_operacional' => $fila['categoria_operacional'],
                    'unidad_medida' => $fila['unidad_medida'],
                ];

                if ($nuevo || ($fila['categoria'] ?? null) !== null) {
                    $datos['categoria'] = $fila['categoria'];
                }
                if ($nuevo || ($fila['codigo_externo'] ?? null) !== null) {
                    $datos['codigo_externo'] = $fila['codigo_externo'];
                }
                if ($nuevo || ($fila['activo'] ?? null) !== null) {
                    $datos['activo'] = $fila['activo'] ?? true;
                }

                foreach (NivelesStockMaterial::CAMPOS as $campo) {
                    if (($fila[$campo] ?? null) !== null) {
                        $datos[$campo] = $fila[$campo];
                    }
                }
                $item->fill($datos);
                $cambio = $nuevo || $item->isDirty();

                if (! $cambio) {
                    $sinCambios++;

                    continue;
                }

                $item->fill([
                    'origen_sistema' => 'importacion_catalogo',
                    'sincronizado_at' => now(),
                    'actualizado_por_user_id' => $usuario->id,
                ]);
                if ($nuevo) {
                    $item->creado_por_user_id = $usuario->id;
                }
                $item->save();
                $nuevo ? $creados++ : $actualizados++;
            }

            $importacion->update([
                'estado' => 'confirmada',
                'resumen' => [
                    ...$importacion->resumen,
                    'creados' => $creados,
                    'actualizados' => $actualizados,
                    'sin_cambios' => $sinCambios,
                ],
                'confirmado_por_user_id' => $usuario->id,
                'confirmado_at' => now(),
            ]);

            return $importacion->refresh()
                ->load(['creadoPor:id,name', 'confirmadoPor:id,name']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, string|int>  $fila
     * @return array<string, string|int|bool|null>
     */
    private function normalizarFila(array $fila): array
    {
        return [
            'fila' => (int) $fila['fila'],
            'temporada_codigo' => mb_strtoupper($this->texto($fila['temporada_codigo'] ?? '')),
            'cliente_codigo' => mb_strtoupper($this->texto($fila['cliente_codigo'] ?? '')),
            'codigo' => mb_strtoupper($this->texto($fila['codigo'] ?? '')),
            'nombre' => $this->texto($fila['nombre'] ?? ''),
            'categoria' => $this->opcional($fila['categoria'] ?? ''),
            'categoria_operacional' => $this->categoriaOperacional($fila['categoria_operacional'] ?? ''),
            'categoria_operacional_original' => $this->texto($fila['categoria_operacional'] ?? ''),
            'unidad_medida' => mb_strtolower($this->texto($fila['unidad_medida'] ?? '')),
            'codigo_externo' => $this->opcional($fila['codigo_externo'] ?? ''),
            'activo' => $this->activo($fila['activo'] ?? ''),
            'activo_original' => $this->texto($fila['activo'] ?? ''),
            ...collect(NivelesStockMaterial::CAMPOS)->mapWithKeys(fn ($c) => [$c => ($v = $this->opcional($fila[$c] ?? '')) === null ? null : str_replace(',', '.', $v)])->all(),
        ];
    }

    /**
     * @param  array<string, string|int|bool|null>  $fila
     * @return array<int, string>
     */
    private function validarFila(array $fila): array
    {
        $errores = array_values(NivelesStockMaterial::errores($fila));

        if ($fila['temporada_codigo'] === '') {
            $errores[] = 'Falta el código de la temporada.';
        } elseif (mb_strlen((string) $fila['temporada_codigo']) > 30
            || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', (string) $fila['temporada_codigo']) !== 1) {
            $errores[] = 'El código de la temporada debe usar hasta 30 caracteres: letras, números, punto, guion o guion bajo.';
        }
        if ($fila['cliente_codigo'] === '') {
            $errores[] = 'Falta el código del cliente.';
        } elseif (mb_strlen((string) $fila['cliente_codigo']) > 80
            || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', (string) $fila['cliente_codigo']) !== 1) {
            $errores[] = 'El código del cliente debe usar hasta 80 caracteres: letras, números, punto, guion o guion bajo.';
        }
        if ($fila['codigo'] === '') {
            $errores[] = 'Falta el código.';
        } elseif (mb_strlen((string) $fila['codigo']) > 80
            || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', (string) $fila['codigo']) !== 1) {
            $errores[] = 'El código debe usar hasta 80 caracteres: letras, números, punto, guion o guion bajo.';
        }
        if (mb_strlen((string) $fila['nombre']) < 3 || mb_strlen((string) $fila['nombre']) > 180) {
            $errores[] = 'El nombre debe contener entre 3 y 180 caracteres.';
        }
        if (mb_strlen((string) ($fila['categoria'] ?? '')) > 100) {
            $errores[] = 'La categoría admite hasta 100 caracteres.';
        }
        if ($fila['categoria_operacional_original'] === '') {
            $errores[] = 'Falta el tipo de ítem.';
        } elseif ($fila['categoria_operacional'] === null) {
            $errores[] = 'El tipo de ítem debe ser insumo, material_mp o material_pt.';
        }
        if ($fila['unidad_medida'] === '' || mb_strlen((string) $fila['unidad_medida']) > 40) {
            $errores[] = 'La unidad de medida es obligatoria y admite hasta 40 caracteres.';
        }
        if (mb_strlen((string) ($fila['codigo_externo'] ?? '')) > 150) {
            $errores[] = 'El código externo admite hasta 150 caracteres.';
        }
        if ($fila['activo_original'] !== '' && $fila['activo'] === null) {
            $errores[] = 'Activo debe indicar sí/no, activo/inactivo, 1/0 o verdadero/falso.';
        }

        return $errores;
    }

    /**
     * @param  array<string, string|int|bool|null>  $fila
     */
    private function accionEstimada(array $fila, ?ItemMaterial $existente): string
    {
        if (! $existente) {
            return 'crear';
        }

        $cambio = $existente->nombre !== $fila['nombre']
            || $existente->unidad_medida !== $fila['unidad_medida']
            || (($fila['categoria'] ?? null) !== null && $existente->categoria !== $fila['categoria'])
            || $existente->categoria_operacional?->value !== $fila['categoria_operacional']
            || (($fila['codigo_externo'] ?? null) !== null && $existente->codigo_externo !== $fila['codigo_externo'])
            || (($fila['activo'] ?? null) !== null && $existente->activo !== $fila['activo']);

        $cambio = $cambio || collect(NivelesStockMaterial::CAMPOS)->contains(fn ($c) => ($fila[$c] ?? null) !== null && (float) $fila[$c] !== ($existente->$c === null ? null : (float) $existente->$c));

        return $cambio ? 'actualizar' : 'sin_cambios';
    }

    /**
     * @param  array<string, string|int|bool|null>  $fila
     */
    private function validarHuellaCatalogo(array $fila, ?ItemMaterial $item): void
    {
        if (! array_key_exists('huella_catalogo', $fila)) {
            throw new DomainException(
                'La previsualización del ítem '.$fila['codigo'].' ya no es vigente. Vuelve a previsualizar la planilla.',
            );
        }

        $huellaEsperada = $fila['huella_catalogo'] ?? null;
        $catalogoCambio = ($huellaEsperada === null && $item !== null)
            || ($huellaEsperada !== null && (
                ! is_string($huellaEsperada)
                || $item === null
                || ! hash_equals($huellaEsperada, $this->huellaItem($item))
            ));

        if ($catalogoCambio) {
            throw new DomainException(
                'El catálogo cambió después de la previsualización para el ítem '.$fila['codigo']
                .'. Vuelve a previsualizar la planilla.',
            );
        }
    }

    private function huellaItem(ItemMaterial $item): string
    {
        return hash('sha256', json_encode([
            'id' => (string) $item->id,
            'cliente_material_id' => $item->cliente_material_id,
            'codigo' => $item->codigo,
            'nombre' => $item->nombre,
            'categoria' => $item->categoria,
            'categoria_operacional' => $item->categoria_operacional?->value,
            'unidad_medida' => $item->unidad_medida,
            'codigo_externo' => $item->codigo_externo,
            'activo' => (bool) $item->activo,
            ...$item->only(NivelesStockMaterial::CAMPOS),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function texto(mixed $valor): string
    {
        return Str::of((string) $valor)->squish()->toString();
    }

    private function claveItem(string $clienteId, string $codigo): string
    {
        return mb_strtolower($clienteId.'|'.$codigo);
    }

    private function opcional(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto === '' ? null : $texto;
    }

    private function categoriaOperacional(mixed $valor): ?string
    {
        $texto = Str::of((string) $valor)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return match ($texto) {
            CategoriaOperacionalMaterial::Insumo->value => CategoriaOperacionalMaterial::Insumo->value,
            'mp', 'material_mp', 'material_mp_sin_preparar', 'material_sin_preparar', 'material_de_embalaje_sin_preparar' => CategoriaOperacionalMaterial::MaterialMp->value,
            'pt', 'material_pt', 'material_pt_preparado_para_linea', 'material_preparado', 'material_preparado_para_linea' => CategoriaOperacionalMaterial::MaterialPt->value,
            default => null,
        };
    }

    private function activo(mixed $valor): ?bool
    {
        $texto = Str::of((string) $valor)->ascii()->lower()->trim()->toString();

        return match ($texto) {
            '' => null,
            '1', 'si', 's', 'true', 'verdadero', 'activo' => true,
            '0', 'no', 'n', 'false', 'falso', 'inactivo' => false,
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, string|int>>  $leidas
     * @param  array<int, array<string, string|int|bool|null>>  $filas
     * @param  array<int, array<string, string|int|null>>  $errores
     * @return array<string, int>
     */
    private function resumen(array $leidas, array $filas, array $errores): array
    {
        $acciones = collect($filas)->countBy('accion');

        return [
            'filas_leidas' => count($leidas),
            'filas_validas' => count($filas),
            'filas_con_error' => count($errores),
            'nuevos_estimados' => (int) $acciones->get('crear', 0),
            'actualizaciones_estimadas' => (int) $acciones->get('actualizar', 0),
            'sin_cambios_estimados' => (int) $acciones->get('sin_cambios', 0),
        ];
    }
}
