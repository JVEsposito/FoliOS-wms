<?php

namespace App\Services\Comunicaciones;

use App\Enums\RolUsuario;
use App\Models\Carga;
use App\Models\DespachoMaterial;
use App\Models\Embarque;
use App\Models\Folio;
use App\Models\GuiaDespachoEnvase;
use App\Models\LoteInspeccionSag;
use App\Models\LoteMateriaPrima;
use App\Models\OrdenProcesoMp;
use App\Models\ProcesoHidrocoolerMateriaPrima;
use App\Models\ProcesoPrefrio;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\RecepcionMaterial;
use App\Models\RecepcionRomana;
use App\Models\ReferenciaComunicacion;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Citas dentro del chat y el correo interno.
 *
 * El texto guarda cada cita como `[[tipo:id]]`. Quien escribe sólo puede citar lo que ve en
 * su módulo y la etiqueta se fija al enviar. Quien lee abre la ficha con sus propios
 * permisos: sin acceso al módulo ve la etiqueta, pero no los datos del registro.
 */
class ReferenciasComunicaciones
{
    public const PATRON = '/\[\[([a-z_]{2,30}):([A-Za-z0-9-]{1,36})\]\]/';

    public const MAXIMO_POR_MENSAJE = 20;

    private const RESULTADOS_POR_TIPO = 5;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $tipos = null;

    public function __construct(private readonly ServicioTemporadaActiva $temporadas) {}

    /** @return array<int, array{clave: string, nombre: string}> Tipos que el usuario puede citar. */
    public function tiposDisponibles(User $usuario): array
    {
        return collect($this->tipos())
            ->filter(fn (array $tipo): bool => $this->permite($usuario, $tipo))
            ->map(fn (array $tipo, string $clave): array => ['clave' => $clave, 'nombre' => $tipo['nombre']])
            ->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function buscar(User $usuario, string $termino, ?string $tipo = null): array
    {
        $termino = trim(ltrim(trim($termino), '#'));
        if (mb_strlen($termino) < 2) {
            return [];
        }
        $tipos = collect($this->tipos())
            ->filter(fn (array $definicion, string $clave): bool => ($tipo === null || $tipo === $clave) && $this->permite($usuario, $definicion));
        $limite = $tipo === null ? self::RESULTADOS_POR_TIPO : 15;
        $temporada = $this->temporadas->buscar()?->id;
        $patron = '%'.addcslashes($termino, '%_\\').'%';

        return $tipos->flatMap(function (array $definicion, string $clave) use ($termino, $patron, $limite, $temporada): array {
            /** @var class-string<Model> $modelo */
            $modelo = $definicion['modelo'];
            $campos = $definicion['busqueda'];
            // Coincidencia exacta en cualquier temporada; parcial sólo en la temporada activa,
            // para no recorrer el historial completo en cada tecla.
            $exactos = $modelo::query()->with($definicion['con'] ?? [])
                ->where(fn ($q) => collect($campos)->each(fn (string $campo) => $q->orWhere($campo, $termino)))
                ->latest()->limit($limite)->get();
            $parciales = $modelo::query()->with($definicion['con'] ?? [])
                ->when($definicion['temporada'] ?? true, fn ($q) => $q->where('temporada_id', $temporada))
                ->where(fn ($q) => collect($campos)->each(fn (string $campo) => $q->orWhere($campo, 'like', $patron)))
                ->whereKeyNot($exactos->modelKeys())
                ->latest()->limit($limite)->get();

            return $exactos->concat($parciales)->take($limite)
                ->map(fn (Model $registro): array => [
                    'tipo' => $clave, 'nombre_tipo' => $definicion['nombre'], 'id' => (string) $registro->getKey(),
                    'etiqueta' => $this->etiqueta($definicion, $registro),
                    'resumen' => $this->resumen($definicion, $registro),
                ])->all();
        })->take(20)->values()->all();
    }

    /**
     * Valida las citas del texto con los permisos de quien escribe.
     *
     * @return array<int, array{tipo: string, referencia_id: string, etiqueta: string}>
     */
    public function extraer(User $usuario, ?string $texto): array
    {
        if ($texto === null || ! preg_match_all(self::PATRON, $texto, $coincidencias, PREG_SET_ORDER)) {
            return [];
        }
        $pedidas = collect($coincidencias)->map(fn (array $c): array => ['tipo' => $c[1], 'id' => $c[2]])
            ->unique(fn (array $c): string => $c['tipo'].':'.$c['id'])->values();
        if ($pedidas->count() > self::MAXIMO_POR_MENSAJE) {
            throw ValidationException::withMessages(['texto' => 'Puedes citar hasta '.self::MAXIMO_POR_MENSAJE.' registros o personas por mensaje.']);
        }

        $etiquetas = [];
        foreach ($pedidas->groupBy('tipo') as $tipo => $grupo) {
            $ids = $grupo->pluck('id')->all();
            if ($tipo === 'usuario') {
                $personas = User::query()->whereIn('id', array_filter($ids, 'ctype_digit'))->where('activo', true)
                    ->where('rol', '!=', RolUsuario::Cliente->value)->pluck('name', 'id');
                foreach ($ids as $id) {
                    $etiquetas[$tipo][$id] = $personas[(int) $id] ?? throw ValidationException::withMessages(['texto' => 'Sólo puedes mencionar a personas activas del equipo.']);
                }

                continue;
            }
            $definicion = $this->tipos()[$tipo] ?? throw ValidationException::withMessages(['texto' => 'El mensaje tiene una cita que FoliOS no reconoce.']);
            if (! $this->permite($usuario, $definicion)) {
                throw ValidationException::withMessages(['texto' => "No tienes acceso para citar {$definicion['plural']}."]);
            }
            $registros = $definicion['modelo']::query()->whereKey($ids)->get()->keyBy(fn (Model $m): string => (string) $m->getKey());
            foreach ($ids as $id) {
                $registro = $registros->get($id) ?? throw ValidationException::withMessages(['texto' => "{$definicion['nombre']} citado ya no existe. Quita la cita y vuelve a buscarlo."]);
                $etiquetas[$tipo][$id] = $this->etiqueta($definicion, $registro);
            }
        }

        return $pedidas->map(fn (array $c): array => ['tipo' => $c['tipo'], 'referencia_id' => $c['id'],
            'etiqueta' => mb_substr($etiquetas[$c['tipo']][$c['id']], 0, 120)])->all();
    }

    /** @param  array<int, array{tipo: string, referencia_id: string, etiqueta: string}>  $citas */
    public function guardar(string $columna, int $id, array $citas): void
    {
        if ($citas === []) {
            return;
        }
        ReferenciaComunicacion::query()->insert(array_map(fn (array $cita): array => [
            ...$cita, $columna => $id, 'created_at' => now(),
        ], $citas));
    }

    /** @return array<int, array<string, mixed>> */
    public function representar(Collection $referencias, User $lector): array
    {
        return $referencias->map(fn (ReferenciaComunicacion $r): array => [
            'tipo' => $r->tipo, 'id' => $r->referencia_id, 'etiqueta' => $r->etiqueta,
            'nombre_tipo' => $r->tipo === 'usuario' ? 'Persona' : ($this->tipos()[$r->tipo]['nombre'] ?? 'Registro'),
            'soy_yo' => $r->tipo === 'usuario' && $r->referencia_id === (string) $lector->id,
        ])->values()->all();
    }

    /** Texto legible para extractos: cada cita queda como «#etiqueta» o «@persona». */
    public function textoPlano(?string $texto, Collection $referencias): ?string
    {
        if ($texto === null) {
            return null;
        }
        $mapa = $referencias->keyBy(fn (ReferenciaComunicacion $r): string => $r->tipo.':'.$r->referencia_id);

        return preg_replace_callback(self::PATRON, function (array $c) use ($mapa): string {
            $referencia = $mapa->get($c[1].':'.$c[2]);

            return $referencia ? ($c[1] === 'usuario' ? '@' : '#').$referencia->etiqueta : $c[0];
        }, $texto);
    }

    public function mencionaA(Collection $referencias, User $usuario): bool
    {
        return $referencias->contains(fn (ReferenciaComunicacion $r): bool => $r->tipo === 'usuario' && $r->referencia_id === (string) $usuario->id);
    }

    /** @return array<string, mixed> Ficha del registro con los permisos de quien la abre. */
    public function ficha(User $lector, string $tipo, string $id): array
    {
        if ($tipo === 'usuario') {
            $persona = ctype_digit($id) ? User::query()->whereKey((int) $id)->where('rol', '!=', RolUsuario::Cliente->value)->first() : null;
            abort_if($persona === null, 404, 'Esta persona ya no está en el directorio.');

            return ['tipo' => 'usuario', 'nombre_tipo' => 'Persona', 'id' => (string) $persona->id, 'etiqueta' => $persona->name,
                'datos' => [['etiqueta' => 'Estado', 'valor' => $persona->activo ? 'Activa' : 'Inactiva']],
                'enlace' => null, 'modulo' => null,
                'puede_privado' => $persona->activo && $persona->id !== $lector->id];
        }
        $definicion = $this->tipos()[$tipo] ?? abort(404, 'Esta cita no corresponde a un registro de FoliOS.');
        abort_unless($this->permite($lector, $definicion), 403, "No tienes acceso a {$definicion['plural']}. Pide a quien lo citó más detalles.");
        $registro = $definicion['modelo']::query()->with($definicion['con'] ?? [])->find($id);
        abort_if($registro === null, 404, "{$definicion['nombre']} citado ya no existe en FoliOS.");
        [$enlace, $modulo] = $this->enlace($lector, $definicion, $registro);

        return ['tipo' => $tipo, 'nombre_tipo' => $definicion['nombre'], 'id' => (string) $registro->getKey(),
            'etiqueta' => $this->etiqueta($definicion, $registro),
            'datos' => collect(($definicion['datos'])($registro))
                ->filter(fn ($valor): bool => $valor !== null && $valor !== '')
                ->map(fn ($valor, string $etiqueta): array => ['etiqueta' => $etiqueta, 'valor' => $this->valor($valor)])
                ->values()->all(),
            'enlace' => $enlace, 'modulo' => $modulo, 'puede_privado' => false];
    }

    /** @param  array<string, mixed>  $tipo */
    private function permite(User $usuario, array $tipo): bool
    {
        return Gate::forUser($usuario)->allows($tipo['habilidad']);
    }

    /** @param  array<string, mixed>  $tipo */
    private function etiqueta(array $tipo, Model $registro): string
    {
        return (string) (($tipo['etiqueta'])($registro) ?: $registro->getKey());
    }

    /** @param  array<string, mixed>  $tipo */
    private function resumen(array $tipo, Model $registro): string
    {
        return collect(($tipo['datos'])($registro))->only($tipo['resumen'] ?? [])->filter(fn ($v): bool => $v !== null && $v !== '')
            ->map(fn ($v): string => $this->valor($v))->implode(' · ');
    }

    /**
     * @param  array<string, mixed>  $tipo
     * @return array{0: ?string, 1: ?string}
     */
    private function enlace(User $lector, array $tipo, Model $registro): array
    {
        foreach ($tipo['enlaces'] as $opcion) {
            [$habilidad, $modulo, $url] = $opcion instanceof Closure ? $opcion($registro) : $opcion;
            if (Gate::forUser($lector)->allows($habilidad)) {
                return [is_callable($url) ? $url($registro) : $url, $modulo];
            }
        }

        return [null, null];
    }

    private function valor(mixed $valor): string
    {
        if ($valor instanceof BackedEnum) {
            return method_exists($valor, 'etiqueta') ? (string) $valor->etiqueta() : ucfirst(str_replace('_', ' ', (string) $valor->value));
        }
        if ($valor instanceof CarbonInterface) {
            return $valor->timezone('America/Santiago')->format($valor->format('H:i:s') === '00:00:00' ? 'd-m-Y' : 'd-m-Y H:i');
        }
        if (is_float($valor) || (is_string($valor) && is_numeric($valor) && str_contains($valor, '.'))) {
            return number_format((float) $valor, 1, ',', '.');
        }

        return (string) $valor;
    }

    /** @return array<string, array<string, mixed>> */
    private function tipos(): array
    {
        $consultas = fn (string $q): array => ['consultar-oficina-consultas', 'Consultas', '/oficina/consultas/busqueda?q='.rawurlencode($q)];

        return $this->tipos ??= [
            'folio' => [
                'nombre' => 'Folio', 'plural' => 'los folios', 'modelo' => Folio::class, 'habilidad' => 'consultar-oficina-consultas',
                'busqueda' => ['numero_folio'], 'con' => ['temporada', 'ubicacionActual.posicion.camara'],
                'etiqueta' => fn (Folio $f) => $f->numero_folio, 'resumen' => ['Estado', 'Ubicación'],
                'datos' => fn (Folio $f): array => ['Tipo' => $f->tipo_bulto, 'Estado' => $f->estado_operacional,
                    'Variedad' => $f->variedad, 'Calibre' => $f->calibre, 'Exportadora' => $f->exportadora,
                    'Ubicación' => $f->ubicacionActual?->posicion ? trim(($f->ubicacionActual->posicion->camara?->codigo ?? '').' '.$f->ubicacionActual->posicion->etiqueta) : null,
                    'Temporada' => $f->temporada?->codigo],
                'enlaces' => [fn (Folio $f) => $consultas($f->numero_folio)],
            ],
            'recepcion' => [
                'nombre' => 'Recepción', 'plural' => 'las recepciones de romana', 'modelo' => RecepcionRomana::class, 'habilidad' => 'consultar-romana',
                'busqueda' => ['numero_recepcion', 'numero_guia_despacho', 'patente_camion'],
                'etiqueta' => fn (RecepcionRomana $r) => $r->numero_recepcion, 'resumen' => ['Cliente', 'Guía'],
                'datos' => fn (RecepcionRomana $r): array => ['Cliente' => $r->cliente_nombre_snapshot, 'Guía' => $r->numero_guia_despacho,
                    'Patente' => $r->patente_camion, 'Estado' => $r->estado, 'Validación MP' => $r->estado_validacion_mp, 'Ingreso' => $r->ingreso_at ?? $r->created_at],
                'enlaces' => [fn (RecepcionRomana $r) => $consultas($r->numero_recepcion), ['consultar-romana', 'Romana', '/oficina/romana']],
            ],
            'lote_mp' => [
                'nombre' => 'Lote MP', 'plural' => 'los lotes de materia prima', 'modelo' => LoteMateriaPrima::class, 'habilidad' => 'consultar-materia-prima',
                'busqueda' => ['numero_lote'], 'con' => ['cliente', 'recepcion'],
                'etiqueta' => fn (LoteMateriaPrima $l) => $l->numero_lote, 'resumen' => ['Cliente', 'Variedad'],
                'datos' => fn (LoteMateriaPrima $l): array => ['Cliente' => $l->cliente?->nombre, 'Recepción' => $l->recepcion?->numero_recepcion,
                    'Especie' => $l->especie_snapshot, 'Variedad' => $l->variedad_snapshot, 'Kilos netos' => $l->kilos_netos_confirmados, 'Estado' => $l->estado],
                'enlaces' => [['consultar-materia-prima', 'Lotes MP', fn (LoteMateriaPrima $l) => '/oficina/materia-prima/lotes?buscar='.rawurlencode((string) $l->numero_lote)]],
            ],
            'carga' => [
                'nombre' => 'Carga', 'plural' => 'las cargas', 'modelo' => Carga::class, 'habilidad' => 'consultar-catalogo-cargas',
                'busqueda' => ['codigo', 'numero_orden_externa', 'patente'],
                'etiqueta' => fn (Carga $c) => $c->codigo, 'resumen' => ['Estado', 'Orden externa'],
                'datos' => fn (Carga $c): array => ['Estado' => $c->estado, 'Orden externa' => $c->numero_orden_externa,
                    'Prioridad' => $c->prioridad, 'Patente' => $c->patente, 'Publicada' => $c->publicada_at],
                'enlaces' => [['consultar-catalogo-cargas', 'Cargas', '/oficina/frigorifico/despacho/cargas']],
            ],
            'embarque' => [
                'nombre' => 'Embarque', 'plural' => 'los embarques', 'modelo' => Embarque::class, 'habilidad' => 'consultar-catalogo-cargas',
                'busqueda' => ['codigo', 'patente_camion'], 'con' => ['cliente'],
                'etiqueta' => fn (Embarque $e) => $e->codigo, 'resumen' => ['Cliente', 'Fecha'],
                'datos' => fn (Embarque $e): array => ['Cliente' => $e->cliente?->nombre, 'Fecha' => $e->fecha_programada, 'Estado' => $e->estado, 'Patente' => $e->patente_camion],
                'enlaces' => [['consultar-catalogo-cargas', 'Calendario de embarques', '/oficina/frigorifico/despacho/calendario']],
            ],
            'proceso_prefrio' => [
                'nombre' => 'Prefrío', 'plural' => 'los procesos de prefrío', 'modelo' => ProcesoPrefrio::class, 'habilidad' => 'consultar-prefrio',
                'busqueda' => ['codigo'], 'con' => ['tunel'],
                'etiqueta' => fn (ProcesoPrefrio $p) => $p->codigo, 'resumen' => ['Túnel', 'Estado'],
                'datos' => fn (ProcesoPrefrio $p): array => ['Túnel' => $p->tunel?->codigo, 'Estado' => $p->estado, 'Inicio' => $p->iniciado_at],
                'enlaces' => [['consultar-prefrio', 'Prefrío', fn (ProcesoPrefrio $p) => '/oficina/prefrio?proceso='.$p->getKey()]],
            ],
            'orden_proceso' => [
                'nombre' => 'Orden de proceso', 'plural' => 'las órdenes de proceso', 'modelo' => OrdenProcesoMp::class, 'habilidad' => 'consultar-ordenes-proceso',
                'busqueda' => ['codigo'], 'con' => ['linea'],
                'etiqueta' => fn (OrdenProcesoMp $o) => $o->codigo, 'resumen' => ['Línea', 'Fecha'],
                'datos' => fn (OrdenProcesoMp $o): array => ['Línea' => $o->linea?->nombre, 'Fecha' => $o->fecha, 'Estado' => $o->estado],
                'enlaces' => [['consultar-ordenes-proceso', 'Órdenes de proceso', '/oficina/materia-prima/ordenes-proceso']],
            ],
            'hidrocooler' => [
                'nombre' => 'Hidrocooler', 'plural' => 'los procesos de hidrocooler', 'modelo' => ProcesoHidrocoolerMateriaPrima::class, 'habilidad' => 'consultar-hidrocooler-materia-prima',
                'busqueda' => ['codigo'], 'con' => ['lote'], 'temporada' => false,
                'etiqueta' => fn (ProcesoHidrocoolerMateriaPrima $h) => $h->codigo, 'resumen' => ['Lote', 'Estado'],
                'datos' => fn (ProcesoHidrocoolerMateriaPrima $h): array => ['Lote' => $h->lote?->numero_lote, 'Producto' => $h->producto_nombre_snapshot, 'Estado' => $h->estado],
                'enlaces' => [['consultar-hidrocooler-materia-prima', 'Hidrocooler', '/oficina/materia-prima/hidrocooler']],
            ],
            'recepcion_embalada' => [
                'nombre' => 'Recepción embalada', 'plural' => 'las recepciones de fruta embalada', 'modelo' => RecepcionFrutaEmbalada::class, 'habilidad' => 'consultar-recepciones-fruta-embalada',
                'busqueda' => ['numero_guia'], 'con' => ['cliente'],
                'etiqueta' => fn (RecepcionFrutaEmbalada $r) => 'Guía '.$r->numero_guia, 'resumen' => ['Cliente', 'Recepción'],
                'datos' => fn (RecepcionFrutaEmbalada $r): array => ['Cliente' => $r->cliente?->nombre, 'Recepción' => $r->recepcion_at, 'Estado' => $r->estado],
                'enlaces' => [['consultar-recepciones-fruta-embalada', 'Recepción fruta embalada', '/oficina/recepcion-fruta-embalada']],
            ],
            'recepcion_material' => [
                'nombre' => 'Recepción de materiales', 'plural' => 'las recepciones de materiales', 'modelo' => RecepcionMaterial::class, 'habilidad' => 'consultar-recepciones-materiales',
                'busqueda' => ['numero_guia_despacho', 'patente'], 'con' => ['cliente'],
                'etiqueta' => fn (RecepcionMaterial $r) => 'Guía '.$r->numero_guia_despacho, 'resumen' => ['Cliente', 'Fecha'],
                'datos' => fn (RecepcionMaterial $r): array => ['Cliente' => $r->cliente?->nombre, 'Fecha' => $r->fecha_recepcion, 'Patente' => $r->patente, 'Estado' => $r->estado],
                'enlaces' => [['consultar-recepciones-materiales', 'Recepciones de materiales', '/oficina/materiales/recepciones']],
            ],
            'despacho_material' => [
                'nombre' => 'Despacho de materiales', 'plural' => 'los despachos de materiales', 'modelo' => DespachoMaterial::class, 'habilidad' => 'consultar-despachos-materiales',
                'busqueda' => ['codigo'],
                'etiqueta' => fn (DespachoMaterial $d) => $d->codigo, 'resumen' => ['Destino', 'Estado'],
                'datos' => fn (DespachoMaterial $d): array => ['Destino' => $d->destino_nombre, 'Estado' => $d->estado, 'Creado' => $d->created_at],
                'enlaces' => [['consultar-despachos-materiales', 'Despachos de materiales', '/oficina/materiales/despachos']],
            ],
            'inspeccion_sag' => [
                'nombre' => 'Inspección SAG', 'plural' => 'las inspecciones SAG', 'modelo' => LoteInspeccionSag::class, 'habilidad' => 'consultar-inspeccion-sag',
                'busqueda' => ['codigo', 'numero_inspeccion_sag'], 'con' => ['cliente'],
                'etiqueta' => fn (LoteInspeccionSag $l) => $l->codigo, 'resumen' => ['Cliente', 'Estado'],
                'datos' => fn (LoteInspeccionSag $l): array => ['Cliente' => $l->cliente?->nombre, 'N° SAG' => $l->numero_inspeccion_sag, 'Tipo' => $l->tipo, 'Estado' => $l->estado],
                'enlaces' => [['consultar-inspeccion-sag', 'Inspección SAG', '/oficina/frigorifico/inspeccion-sag']],
            ],
            'guia_envases' => [
                'nombre' => 'Guía de envases', 'plural' => 'las guías de envases', 'modelo' => GuiaDespachoEnvase::class, 'habilidad' => 'consultar-cuenta-envases',
                'busqueda' => ['numero', 'numero_guia_despacho'],
                'etiqueta' => fn (GuiaDespachoEnvase $g) => $g->numero, 'resumen' => ['Cliente', 'Guía'],
                'datos' => fn (GuiaDespachoEnvase $g): array => ['Cliente' => $g->cliente_nombre_snapshot, 'Guía' => $g->numero_guia_despacho, 'Estado' => $g->estado, 'Salida' => $g->salida_at],
                'enlaces' => [['consultar-cuenta-envases', 'Despachos de envases', '/oficina/envases/despachos']],
            ],
        ];
    }
}
