<?php

namespace App\Services\Operacion;

use App\Enums\EstadoCamara;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoAlmacenMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\AlmacenMaterial;
use App\Models\Anden;
use App\Models\Camara;
use App\Models\PlanoPlanta;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Validacion\ServicioPrioridadBufferRepaletizaje;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioPlanoPlanta
{
    public const CODIGO_PRINCIPAL = 'principal';

    /**
     * Proporción ancho/alto con que se dibuja un plano sin proporción guardada. Las
     * coordenadas 0..10000 son relativas a cada eje, así que la proporción decide si el
     * dibujo conserva la forma real de la planta.
     */
    public const PROPORCION_PREDETERMINADA = 1.7778;

    /** Categorías de áreas dibujadas; repa y recepcion_mp muestran indicadores vivos. */
    public const CATEGORIAS_ZONA = [
        'no_operativo',
        'repa',
        'recepcion_mp',
        'materiales',
        'packing',
        'bodega',
        'pasillo',
        'patio',
        'oficina',
        'muelle',
        'otro',
    ];

    /** Grosor mínimo de un pasillo y tamaño mínimo de cualquier otro elemento. */
    public const GROSOR_MINIMO_PASILLO = 100;

    public const TAMANO_MINIMO_ELEMENTO = 300;

    public function __construct(
        private readonly ServicioPrioridadBufferRepaletizaje $bufferRepaletizaje,
        private readonly ServicioRedPlanta $red,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $camaras
     * @param  array<int, array<string, mixed>>  $tuneles
     * @return array<string, mixed>
     */
    public function obtener(array $camaras, array $tuneles, bool $puedeEditar, ?Temporada $temporada = null): array
    {
        $plano = PlanoPlanta::query()->where('codigo', self::CODIGO_PRINCIPAL)->first();
        $catalogo = $this->catalogo($camaras, $tuneles);

        return [
            'configurado' => $plano !== null,
            'nombre' => $plano?->nombre ?? 'Planta principal',
            'proporcion' => $this->proporcion($plano),
            'version' => $plano?->version ?? 0,
            'actualizado_at' => $plano?->updated_at?->toAtomString(),
            'puede_editar' => $puedeEditar,
            'elementos' => $plano?->elementos ?? [],
            'conexiones' => $plano?->conexiones ?? [],
            'red' => $this->red->resumen($plano?->elementos ?? [], $plano?->conexiones ?? []),
            'catalogo' => $catalogo,
            'indicadores' => $this->indicadores($temporada),
        ];
    }

    /**
     * Indicadores vivos para las áreas dibujadas que representan procesos.
     *
     * @return array<string, mixed>
     */
    private function indicadores(?Temporada $temporada): array
    {
        if ($temporada === null) {
            return ['repa' => null, 'recepcion_mp' => null];
        }

        $buffer = $this->bufferRepaletizaje->estado($temporada->id);
        $recepciones = RecepcionRomana::query()
            ->where('temporada_id', $temporada->id);

        return [
            'repa' => [
                'pallets_pendientes' => $buffer['pallets_pendientes'],
                'maximo' => $buffer['maximo'],
                'umbral_alta' => $buffer['umbral_alta'],
                'prioridad' => $buffer['prioridad']->value,
            ],
            'recepcion_mp' => [
                'en_romana' => (clone $recepciones)
                    ->where('estado', '!=', EstadoRecepcionRomana::Cerrado->value)
                    ->count(),
                'pendientes_validacion' => (clone $recepciones)
                    ->where('estado_validacion_mp', EstadoValidacionMp::Pendiente->value)
                    ->count(),
                'en_validacion' => (clone $recepciones)
                    ->where('estado_validacion_mp', EstadoValidacionMp::EnCurso->value)
                    ->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function guardar(array $datos, User $usuario): PlanoPlanta
    {
        $conexiones = array_values($datos['conexiones'] ?? []);
        $this->validarElementos($datos['elementos']);
        $erroresRed = $this->red->validar($datos['elementos'], $conexiones);
        if ($erroresRed !== []) {
            throw ValidationException::withMessages($erroresRed);
        }

        return DB::transaction(function () use ($datos, $usuario, $conexiones): PlanoPlanta {
            $plano = PlanoPlanta::query()
                ->where('codigo', self::CODIGO_PRINCIPAL)
                ->lockForUpdate()
                ->first();
            $versionActual = $plano?->version ?? 0;

            if ($versionActual !== (int) $datos['version_esperada']) {
                throw new ConflictoOperacion('El plano fue actualizado por otra sesión. Recarga antes de volver a guardar.');
            }

            $atributos = [
                'nombre' => trim($datos['nombre']),
                // Sin proporción en la solicitud se conserva la vigente.
                'proporcion' => array_key_exists('proporcion', $datos)
                    ? ($datos['proporcion'] === null ? null : round((float) $datos['proporcion'], 4))
                    : $plano?->proporcion,
                'version' => $versionActual + 1,
                'elementos' => array_values($datos['elementos']),
                'conexiones' => $conexiones,
                'actualizado_por_user_id' => $usuario->id,
            ];

            if ($plano) {
                $plano->update($atributos);

                return $plano->refresh();
            }

            return PlanoPlanta::create([
                'codigo' => self::CODIGO_PRINCIPAL,
                ...$atributos,
            ]);
        });
    }

    public function proporcion(?PlanoPlanta $plano): float
    {
        return round((float) ($plano?->proporcion ?? self::PROPORCION_PREDETERMINADA), 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $elementos
     */
    private function validarElementos(array $elementos): void
    {
        $referencias = collect($elementos)
            ->reject(fn (array $elemento): bool => in_array($elemento['tipo'], ['zona', 'pasillo'], true))
            ->groupBy('tipo');
        $errores = [];

        foreach ($referencias as $tipo => $items) {
            if ($items->pluck('referencia_id')->contains(null)) {
                $errores["elementos.$tipo"][] = 'Los recintos existentes requieren una referencia válida.';

                continue;
            }

            if ($items->pluck('referencia_id')->duplicates()->isNotEmpty()) {
                $errores["elementos.$tipo"][] = 'Un recinto no puede aparecer más de una vez en el plano.';
            }

            $modelo = match ($tipo) {
                'camara' => Camara::query(),
                'tunel' => TunelPrefrio::query(),
                'anden' => Anden::query()->where('activo', true),
                'almacen' => AlmacenMaterial::query()
                    ->where('activo', true)
                    ->where('tipo', TipoAlmacenMaterial::Fisica->value),
            };
            $ids = $items->pluck('referencia_id')->unique()->values();
            if ($modelo->whereKey($ids)->count() !== $ids->count()) {
                $errores["elementos.$tipo"][] = 'Uno de los recintos referenciados ya no existe o no está activo.';
            }
        }

        foreach ($elementos as $indice => $elemento) {
            $categoriaValida = in_array(
                $elemento['categoria'] ?? 'otro',
                self::CATEGORIAS_ZONA,
                true,
            );

            if ($elemento['x'] + $elemento['ancho'] > 10000 || $elemento['y'] + $elemento['alto'] > 10000) {
                $errores["elementos.$indice"][] = 'El elemento debe quedar completamente dentro de los límites del plano.';
            }
            if ($elemento['tipo'] === 'zona' && $categoriaValida === false) {
                $errores["elementos.$indice.categoria"][] = 'La categoría de la zona no es válida.';
            }
            if (in_array($elemento['tipo'], ['zona', 'pasillo'], true) && $elemento['referencia_id'] !== null) {
                $errores["elementos.$indice.referencia_id"][] = 'Las áreas dibujadas no deben referenciar un recinto del catálogo.';
            }
            $menor = min($elemento['ancho'], $elemento['alto']);
            $mayor = max($elemento['ancho'], $elemento['alto']);
            if ($elemento['tipo'] === 'pasillo'
                && ($menor < self::GROSOR_MINIMO_PASILLO || $mayor < self::TAMANO_MINIMO_ELEMENTO)) {
                $errores["elementos.$indice"][] = 'Un pasillo requiere al menos 100 de grosor y 300 de largo.';
            }
            if ($elemento['tipo'] !== 'pasillo' && $menor < self::TAMANO_MINIMO_ELEMENTO) {
                $errores["elementos.$indice"][] = 'Solo los pasillos pueden tener menos de 300 de ancho o alto.';
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $camaras
     * @param  array<int, array<string, mixed>>  $tuneles
     * @return array<int, array<string, mixed>>
     */
    private function catalogo(array $camaras, array $tuneles): array
    {
        $items = collect($camaras)->map(fn (array $camara): array => [
            'tipo' => 'camara',
            'id' => $camara['id'],
            'codigo' => $camara['codigo'],
            'nombre' => $camara['nombre'],
            'contenido' => $camara['contenido'] ?? null,
            'detalle' => ($camara['ocupacion_porcentaje'] ?? 0).'% ocupado',
            'tono' => match ($camara['nivel_ocupacion'] ?? null) {
                'critica' => 'critical',
                'advertencia' => 'warning',
                default => 'success',
            },
            'estado' => 'operativa',
        ]);

        // Una cámara inactiva sigue existiendo físicamente: el plano la muestra
        // fuera de servicio en vez de perder la referencia.
        $activas = collect($camaras)->pluck('id')->all();
        $items = $items->concat(Camara::query()
            ->where('estado', EstadoCamara::Inactiva->value)
            ->whereKeyNot($activas)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'contenido'])
            ->map(fn (Camara $camara): array => [
                'tipo' => 'camara',
                'id' => $camara->id,
                'codigo' => $camara->codigo,
                'nombre' => $camara->nombre,
                'contenido' => $camara->contenido?->value,
                'detalle' => 'Fuera de servicio',
                'tono' => 'neutral',
                'estado' => 'fuera_servicio',
            ]));

        $items = $items->concat(collect($tuneles)->map(fn (array $tunel): array => [
            'tipo' => 'tunel',
            'id' => $tunel['id'],
            'codigo' => $tunel['codigo'],
            'nombre' => $tunel['nombre'],
            'detalle' => str_replace('_', ' ', $tunel['estado_operacional']),
            'tono' => $tunel['proceso_activo'] ? 'info' : 'neutral',
        ]));

        $andenes = Anden::query()
            ->where('activo', true)
            ->with(['presenciaActiva.carga:id,codigo'])
            ->orderBy('codigo')
            ->get()
            ->map(function (Anden $anden): array {
                $presencia = $anden->presenciaActiva;

                return [
                    'tipo' => 'anden',
                    'id' => $anden->id,
                    'codigo' => $anden->codigo,
                    'nombre' => $anden->nombre,
                    'detalle' => $presencia ? 'Ocupado · '.($presencia->patente ?: $presencia->carga?->codigo) : 'Disponible',
                    'tono' => $presencia ? 'warning' : 'success',
                    'ocupado' => $presencia !== null,
                    'patente' => $presencia?->patente,
                    'carga_codigo' => $presencia?->carga?->codigo,
                ];
            });

        $almacenes = AlmacenMaterial::query()
            ->where('activo', true)
            ->where('tipo', TipoAlmacenMaterial::Fisica->value)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre'])
            ->map(fn (AlmacenMaterial $almacen): array => [
                'tipo' => 'almacen',
                'id' => $almacen->id,
                'codigo' => $almacen->codigo,
                'nombre' => $almacen->nombre,
                'detalle' => 'Bodega física',
                'tono' => 'neutral',
            ]);

        return $items->concat($andenes)->concat($almacenes)->values()->all();
    }
}
