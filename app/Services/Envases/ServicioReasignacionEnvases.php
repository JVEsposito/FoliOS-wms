<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\GuiaDespachoEnvase;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\ReasignacionEnvases;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\User;
use App\Services\Romana\ProductoresRecepcion;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Reasigna una guía completa de la cuenta corriente de envases a otro productor CSG o a otra cuenta (cliente).
 *
 * Solo mueve la cuenta: no toca kilos, Validación MP, lotes, conteos ni la existencia física. La guía puede
 * ser el ingreso de una recepción (o una de sus guías, cuando el camión trae varias), la salida del destare o
 * un despacho de envases. Se mueve lo vigente de la guía, con sus correcciones y ajustes ya aplicados.
 *
 * Asientos (sin efecto en la existencia):
 * - «reasignacion_salida» descuenta la guía de donde estaba; se suma al asiento que reasigna (su raíz), así
 *   en el estado de cuenta de origen la guía desaparece, como una anulación;
 * - «reasignacion_entrada» la deja en la cuenta de destino con el productor elegido y la fecha de la guía.
 *
 * Mientras una guía está reasignada no se corrige, anula ni concilia su recepción o despacho: se deshace la
 * reasignación, se corrige y se vuelve a reasignar. Así ninguna corrección queda en una cuenta que ya no
 * tiene la guía.
 */
class ServicioReasignacionEnvases
{
    public const DOCUMENTO = 'reasignacion_envases';

    private const RECIBIDOS = [TipoMovimientoEnvase::RecepcionFruta, TipoMovimientoEnvase::RecepcionArriendo,
        TipoMovimientoEnvase::DevolucionEnvases];

    private static bool $tablaLista = false;

    /** Guías reasignables a las que pertenece el movimiento, con su estado, para el diálogo de Oficina. */
    public function opciones(MovimientoEnvase $movimiento): array
    {
        return array_map(fn (string $clave): array => $this->vista($this->documento($clave)), $this->claves($movimiento));
    }

    public function reasignar(array $datos, User $usuario): ReasignacionEnvases
    {
        Gate::forUser($usuario)->authorize('ajustar-cuenta-envases');
        $payload = [
            'documento_clave' => (string) $datos['documento_clave'],
            'reasignacion_vigente_id' => $datos['reasignacion_vigente_id'] ?? null,
            'cliente_destino_id' => (string) $datos['cliente_destino_id'],
            'productor_csg_id' => (string) $datos['productor_csg_id'],
            'motivo' => trim((string) $datos['motivo']),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($datos, $payload, $hash, $usuario): ReasignacionEnvases {
            if ($existente = $this->reintento($datos['operacion_id'], $hash)) {
                return $existente;
            }
            $this->bloquear($payload['documento_clave']);
            $documento = $this->documento($payload['documento_clave'], bloquear: true);
            if ($documento['impedimento']) {
                throw new ConflictoOperacion($documento['impedimento']);
            }
            if (($documento['vigente']?->id) !== $payload['reasignacion_vigente_id']) {
                throw new ConflictoOperacion('La guía cambió mientras la revisabas. Actualiza la cuenta corriente y vuelve a intentarlo.');
            }
            $destino = Cliente::query()->where('activo', true)->find($payload['cliente_destino_id'])
                ?? throw ValidationException::withMessages(['cliente_destino_id' => 'Selecciona una cuenta corriente activa.']);
            $productor = $destino->productoresCsg()->wherePivot('activo', true)->whereKey($payload['productor_csg_id'])->first()
                ?? throw ValidationException::withMessages(['productor_csg_id' => 'Selecciona un productor CSG activo de esa cuenta.']);
            $snapshot = ['id' => $productor->id, 'codigo' => (string) $productor->codigo, 'nombre' => $productor->razon_social];
            $actual = $documento['actual'];
            if ($actual['cliente']->id === $destino->id && count($actual['productores']) === 1
                && $actual['productores'][0]['codigo'] === $snapshot['codigo']) {
                throw new ConflictoOperacion('La guía ya está en esa cuenta y con ese productor.');
            }
            $origen = $documento['origen'];
            if ($documento['vigente'] && $origen['cliente']->id === $destino->id && count($origen['productores']) === 1
                && $origen['productores'][0]['codigo'] === $snapshot['codigo']) {
                throw new ConflictoOperacion('Es la cuenta y el productor de origen de la guía: usa «Deshacer reasignación».');
            }

            $registro = $this->registrar($documento, $datos['operacion_id'], $hash, $destino, $snapshot, false, $payload['motivo'], $usuario);
            $documento['vigente']?->update(['vigente' => false]);
            $contexto = $this->contexto($documento, $registro, $destino, $snapshot, $usuario);
            // Sale de donde está hoy: la entrada vigente de una reasignación anterior o lo registrado.
            if ($documento['vigente']) {
                foreach ($documento['entradas'] as $entrada) {
                    $this->salida($entrada, -$documento['netos_entradas'][$entrada->id], $contexto, 'actual');
                }
            } else {
                foreach ($documento['raices'] as $raiz) {
                    $this->salida($raiz, -($documento['porciones'][$raiz->id] ?? 0), $contexto, 'origen');
                }
            }
            foreach ($documento['envases'] as $envase) {
                $this->entrada($documento, $destino, $snapshot, $envase, $contexto);
            }

            return $registro->refresh();
        }, attempts: 3);
    }

    public function deshacer(ReasignacionEnvases $reasignacion, array $datos, User $usuario): ReasignacionEnvases
    {
        Gate::forUser($usuario)->authorize('ajustar-cuenta-envases');
        $payload = ['deshacer' => $reasignacion->id, 'motivo' => trim((string) $datos['motivo'])];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($reasignacion, $datos, $payload, $hash, $usuario): ReasignacionEnvases {
            if ($existente = $this->reintento($datos['operacion_id'], $hash)) {
                return $existente;
            }
            $this->bloquear($reasignacion->documento_clave);
            $reasignacion = ReasignacionEnvases::query()->lockForUpdate()->findOrFail($reasignacion->id);
            if (! $reasignacion->vigente || $reasignacion->deshace) {
                throw new ConflictoOperacion('Esa reasignación ya no está vigente. Actualiza la cuenta corriente.');
            }
            $documento = $this->documento($reasignacion->documento_clave, bloquear: true);
            if ($reasignacion->temporada_id !== app(ServicioTemporadaActiva::class)->buscar(bloquear: true)?->id) {
                throw new ConflictoOperacion('Solo se deshacen reasignaciones de la temporada activa.');
            }
            $origen = $documento['origen'];
            $registro = $this->registrar($documento, $datos['operacion_id'], $hash, $origen['cliente'],
                count($origen['productores']) === 1 ? $origen['productores'][0] : null, true, $payload['motivo'], $usuario);
            $reasignacion->update(['vigente' => false]);
            $contexto = $this->contexto($documento, $registro, $origen['cliente'], $registro->productor_destino, $usuario);
            foreach ($documento['entradas'] as $entrada) {
                $this->salida($entrada, -$documento['netos_entradas'][$entrada->id], $contexto, 'actual');
            }
            // Lo registrado vuelve a quedar como estaba: se anulan las salidas de esta guía.
            foreach ($documento['raices'] as $raiz) {
                $this->salida($raiz, -($documento['reasignado'][$raiz->id] ?? 0), $contexto, 'origen');
            }

            return $registro->refresh();
        }, attempts: 3);
    }

    /**
     * Bloqueo central: ninguna corrección, anulación o conciliación crea asientos sobre la recepción o el
     * despacho de una guía reasignada. Se llama al crear cualquier movimiento de envases.
     *
     * @throws ConflictoOperacion
     */
    public function asegurarSinReasignacion(MovimientoEnvase $nuevo): void
    {
        if ($nuevo->tipo_movimiento instanceof TipoMovimientoEnvase && $nuevo->tipo_movimiento->esReasignacion()) {
            return;
        }
        $recepciones = array_filter([$nuevo->recepcion_romana_id]);
        $despachos = $nuevo->documento_tipo === 'guia_despacho_envases' && $nuevo->documento_id ? [$nuevo->documento_id] : [];
        // En un despacho, movimiento_origen_id identifica el stock FIFO, no una guía que se modifica.
        // Las reversas y conciliaciones señalan explícitamente el asiento que sí están modificando.
        $afectado = match ($nuevo->tipo_movimiento) {
            TipoMovimientoEnvase::DespachoCliente => null,
            TipoMovimientoEnvase::ReversionDespacho => $nuevo->datos['reversa_de_movimiento_id'] ?? $nuevo->movimiento_origen_id,
            TipoMovimientoEnvase::ConciliacionSalida => $nuevo->datos['movimiento_conciliado_id'] ?? $nuevo->movimiento_origen_id,
            default => $nuevo->movimiento_origen_id,
        };
        $origenes = array_filter([$afectado, $nuevo->datos['reversa_de_movimiento_id'] ?? null,
            $nuevo->datos['movimiento_conciliado_id'] ?? null]);
        if ($recepciones === [] && $despachos === [] && $origenes === []) {
            return;
        }
        if (! $this->tablaLista()) {
            return;
        }
        foreach (MovimientoEnvase::query()->whereKey($origenes)->get(['id', 'recepcion_romana_id', 'documento_tipo', 'documento_id']) as $origen) {
            $recepciones[] = $origen->recepcion_romana_id;
            if ($origen->documento_tipo === 'guia_despacho_envases') {
                $despachos[] = $origen->documento_id;
            }
        }
        $recepciones = array_values(array_filter($recepciones));
        $despachos = array_values(array_filter($despachos));
        if ($recepciones === [] && $despachos === []) {
            return;
        }
        $vigente = ReasignacionEnvases::query()->with('clienteDestino:id,nombre')->where('vigente', true)
            ->where(fn ($q) => $q->whereIn('recepcion_romana_id', $recepciones)->orWhereIn('guia_despacho_envase_id', $despachos))
            ->first();
        if ($vigente) {
            throw new ConflictoOperacion(self::mensajeBloqueo($vigente));
        }
    }

    /** Si el despacho tiene una guía reasignada, no se cambia su productor: lo manda la reasignación. */
    public function asegurarDespachoSinReasignacion(GuiaDespachoEnvase $guia): void
    {
        $vigente = ReasignacionEnvases::query()->with('clienteDestino:id,nombre')->where('vigente', true)
            ->where('guia_despacho_envase_id', $guia->id)->first();
        if ($vigente) {
            throw new ConflictoOperacion(self::mensajeBloqueo($vigente));
        }
    }

    /** Recepción con alguna guía reasignada vigente: el motivo para no corregirla, o null. */
    public function bloqueoRecepcion(RecepcionRomana $recepcion): ?string
    {
        $vigente = ReasignacionEnvases::query()->with('clienteDestino:id,nombre')->where('vigente', true)
            ->where('recepcion_romana_id', $recepcion->id)->first();

        return $vigente ? self::mensajeBloqueo($vigente) : null;
    }

    /**
     * Guías de ingreso reasignadas por separado (de recepciones con varias guías): el estado de cuenta las
     * saca del reparto de su recepción, porque sus envases ya están en otra parte.
     *
     * @param  list<string>  $recepciones
     * @return list<string>
     */
    public static function guiasReasignadas(array $recepciones): array
    {
        if ($recepciones === []) {
            return [];
        }

        return ReasignacionEnvases::query()->where('vigente', true)->whereIn('recepcion_romana_id', $recepciones)
            ->whereNotNull('guia_recepcion_romana_id')->pluck('guia_recepcion_romana_id')->all();
    }

    /**
     * Corrección del N° de guía: los asientos de reasignación muestran el número nuevo. Los de una guía
     * reasignada por separado llevan su número; los de toda la recepción, el resumen de sus guías.
     *
     * @param  array<string, string>  $porGuia  id de la guía de ingreso => número nuevo
     */
    public static function propagarNumeroGuia(RecepcionRomana $recepcion, ?string $anterior, string $nuevo, array $porGuia): void
    {
        $registros = ReasignacionEnvases::query()->where('recepcion_romana_id', $recepcion->id)->where('lado', 'recibidos')->get();
        foreach ($registros as $registro) {
            $numero = $registro->guia_recepcion_romana_id ? ($porGuia[$registro->guia_recepcion_romana_id] ?? null)
                : ($registro->numero_documento === $anterior ? $nuevo : null);
            if ($numero === null || $numero === $registro->numero_documento) {
                continue;
            }
            MovimientoEnvase::query()->where('documento_tipo', self::DOCUMENTO)->where('documento_id', $registro->id)
                ->update(['numero_documento' => $numero]);
            $registro->update(['numero_documento' => $numero]);
        }
    }

    private static function mensajeBloqueo(ReasignacionEnvases $vigente): string
    {
        $productor = $vigente->productor_destino;

        return 'La guía '.$vigente->numero_documento.' está reasignada a '.($vigente->clienteDestino?->nombre ?? 'otra cuenta')
            .(! empty($productor['codigo']) ? ' · CSG '.$productor['codigo'] : '')
            .'. Deshaz la reasignación en Cuenta Envases antes de corregir, anular o conciliar esta recepción o despacho.';
    }

    /** @return list<string> */
    private function claves(MovimientoEnvase $movimiento): array
    {
        if ($movimiento->tipo_movimiento->esReasignacion()) {
            return [$movimiento->datos['reasignacion']['documento_clave']];
        }
        if ($movimiento->documento_tipo === 'guia_despacho_envases' && $movimiento->documento_id
            && in_array($movimiento->tipo_movimiento, [TipoMovimientoEnvase::DespachoCliente, TipoMovimientoEnvase::ReversionDespacho], true)) {
            return ['despacho:'.$movimiento->documento_id];
        }
        if (! $movimiento->recepcion_romana_id) {
            return [];
        }
        $raiz = $movimiento;
        if ($movimiento->movimiento_origen_id && ! in_array($movimiento->tipo_movimiento, [...self::RECIBIDOS,
            TipoMovimientoEnvase::SalidaRecepcionFruta, TipoMovimientoEnvase::AjusteCuenta], true)) {
            $raiz = MovimientoEnvase::query()->find($movimiento->movimiento_origen_id) ?? $movimiento;
        }
        $lado = match (true) {
            in_array($raiz->tipo_movimiento, self::RECIBIDOS, true) => 'recibidos',
            $raiz->tipo_movimiento === TipoMovimientoEnvase::SalidaRecepcionFruta => 'entregados',
            $raiz->tipo_movimiento === TipoMovimientoEnvase::AjusteCuenta => $raiz->datos['ajuste_recepcion']['lado'] ?? null,
            default => null,
        };
        $recepcionId = $movimiento->recepcion_romana_id;
        if ($lado === 'entregados') {
            return ["recepcion:{$recepcionId}:entregados"];
        }
        if ($lado !== 'recibidos') {
            return [];
        }
        $completa = "recepcion:{$recepcionId}:recibidos";
        if (ReasignacionEnvases::query()->where('documento_clave', $completa)->where('vigente', true)->exists()) {
            return [$completa];
        }
        $recepcion = RecepcionRomana::query()->find($recepcionId);
        $guias = $recepcion ? $this->guiasSeparables($recepcion, $this->raices($recepcion, 'recibidos', null)) : null;

        return $guias ? $guias->map(fn (GuiaRecepcionRomana $g): string => "{$completa}:guia:{$g->id}")->values()->all() : [$completa];
    }

    /** Bloquea la recepción o el despacho de la guía antes de leerla. */
    private function bloquear(string $clave): void
    {
        [$tipo, $id] = explode(':', $clave) + [null, null];
        match ($tipo) {
            'recepcion' => RecepcionRomana::query()->lockForUpdate()->find($id),
            'despacho' => GuiaDespachoEnvase::query()->lockForUpdate()->find($id),
            default => throw ValidationException::withMessages(['documento_clave' => 'La guía indicada no se puede reasignar.']),
        };
    }

    /**
     * Estado de una guía: sus asientos registrados (raíces) con lo vigente de cada uno, lo que le toca a la
     * guía, dónde está hoy, de dónde es y si se puede reasignar.
     */
    private function documento(string $clave, bool $bloquear = false): array
    {
        $partes = explode(':', $clave);
        $recepcion = null;
        $guiaRecepcion = null;
        $guiaDespacho = null;
        if ($partes[0] === 'recepcion' && in_array($partes[2] ?? null, ['recibidos', 'entregados'], true)
            && (count($partes) === 3 || (count($partes) === 5 && $partes[2] === 'recibidos' && $partes[3] === 'guia'))) {
            $recepcion = RecepcionRomana::query()->findOrFail($partes[1]);
            $lado = $partes[2];
            $guiaRecepcion = isset($partes[4]) ? GuiaRecepcionRomana::query()->where('recepcion_romana_id', $recepcion->id)
                ->where('vigente', 1)->findOrFail($partes[4]) : null;
            $cliente = $recepcion->cliente;
            $temporadaId = $recepcion->temporada_id;
        } elseif ($partes[0] === 'despacho' && count($partes) === 2) {
            $guiaDespacho = GuiaDespachoEnvase::query()->findOrFail($partes[1]);
            $lado = 'entregados';
            $cliente = $guiaDespacho->cliente;
            $temporadaId = $guiaDespacho->temporada_id;
        } else {
            throw ValidationException::withMessages(['documento_clave' => 'La guía indicada no se puede reasignar.']);
        }

        $raices = $recepcion ? $this->raices($recepcion, $lado, $guiaRecepcion, $bloquear)
            : MovimientoEnvase::query()->where('temporada_id', $temporadaId)->where('documento_tipo', 'guia_despacho_envases')
                ->where('documento_id', $guiaDespacho->id)->where('tipo_movimiento', TipoMovimientoEnvase::DespachoCliente->value)
                ->when($bloquear, fn ($q) => $q->lockForUpdate())->orderBy('ocurrido_at')->orderBy('id')->get();
        [$netos, $naturales, $reasignado] = $this->netos($raices, $clave, $bloquear);

        $impedimento = null;
        $porciones = [];
        if ($guiaRecepcion) {
            $separables = $this->guiasSeparables($recepcion, $raices);
            if (! $separables?->contains('id', $guiaRecepcion->id)) {
                $impedimento = 'Las guías de esta recepción ya no cuadran con lo ingresado: reasigna la recepción completa.';
            } else {
                $validados = collect($guiaRecepcion->envases_validados)->mapWithKeys(fn ($e): array => [$e['tipo_envase'] => (int) $e['cantidad']]);
                foreach ($raices as $raiz) {
                    if ($raiz->tipo_movimiento === TipoMovimientoEnvase::RecepcionFruta && $naturales[$raiz->id] !== 0) {
                        $porciones[$raiz->id] = $validados[$raiz->tipo_envase->value] ?? 0;
                    }
                }
            }
        } else {
            $porciones = $netos;
        }

        $vigente = ReasignacionEnvases::query()->where('documento_clave', $clave)->where('vigente', true)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
        $entradas = $vigente ? MovimientoEnvase::query()->where('documento_tipo', self::DOCUMENTO)->where('documento_id', $vigente->id)
            ->where('tipo_movimiento', TipoMovimientoEnvase::ReasignacionEntrada->value)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->get() : collect();
        $netosEntradas = $entradas->isEmpty() ? [] : $this->netos($entradas, $clave, $bloquear)[0];

        $envases = [];
        $sumar = function (MovimientoEnvase $m, int $cantidad) use (&$envases): void {
            if ($cantidad === 0) {
                return;
            }
            $clave = $m->tipo_envase->value.'|'.$m->propiedad->value;
            $envases[$clave] ??= ['tipo_envase' => $m->tipo_envase, 'propiedad' => $m->propiedad, 'cantidad' => 0];
            $envases[$clave]['cantidad'] += $cantidad;
        };
        foreach ($vigente ? $entradas : $raices as $m) {
            $sumar($m, $vigente ? $netosEntradas[$m->id] : ($porciones[$m->id] ?? 0));
        }
        $envases = collect($envases)->filter(fn (array $e): bool => $e['cantidad'] !== 0)
            ->sortBy(fn (array $e): string => sprintf('%02d|%s', $e['tipo_envase']->orden(), $e['propiedad']->value))->values()->all();

        $origen = ['cliente' => $cliente, 'productores' => $this->productoresOrigen($recepcion, $lado, $guiaRecepcion, $guiaDespacho)];
        $actual = $vigente ? ['cliente' => Cliente::query()->findOrFail($vigente->cliente_destino_id),
            'productores' => $vigente->productor_destino ? [$vigente->productor_destino] : []] : $origen;
        $fecha = $raices->min('ocurrido_at');

        // Una recepción se reasigna completa o por guía, no las dos cosas a la vez.
        $otra = $recepcion && $lado === 'recibidos' ? ReasignacionEnvases::query()->where('recepcion_romana_id', $recepcion->id)
            ->where('lado', 'recibidos')->where('vigente', true)->where('documento_clave', '!=', $clave)
            ->when($guiaRecepcion, fn ($q) => $q->whereNull('guia_recepcion_romana_id'), fn ($q) => $q->whereNotNull('guia_recepcion_romana_id'))
            ->exists() : false;
        $impedimento ??= match (true) {
            $otra && $guiaRecepcion !== null => 'La recepción completa está reasignada: deshaz esa reasignación para mover una sola guía.',
            $otra => 'Hay guías de esta recepción reasignadas por separado: reasigna cada guía o deshaz esas reasignaciones.',
            $temporadaId !== app(ServicioTemporadaActiva::class)->buscar(bloquear: $bloquear)?->id => 'Solo se reasignan guías de la temporada activa.',
            $recepcion && ($recepcion->estado === EstadoRecepcionRomana::Anulada || $recepcion->eliminada_at !== null) => 'La recepción está anulada o eliminada.',
            $recepcion && $recepcion->estado_validacion_mp !== EstadoValidacionMp::Validada => 'La recepción aún no está validada: todavía no mueve la cuenta de envases.',
            $recepcion && $recepcion->estado !== EstadoRecepcionRomana::Cerrado => 'La recepción aún no tiene destare: reasigna sus guías cuando el camión haya salido.',
            $guiaDespacho && $guiaDespacho->estado !== EstadoGuiaDespachoEnvase::Confirmada => 'El despacho no está confirmado o está anulado.',
            $envases === [] => 'La guía no tiene envases vigentes en la cuenta (por ejemplo, está anulada o conciliada con otra salida).',
            default => null,
        };

        return [
            'clave' => $clave, 'lado' => $lado, 'tipo' => $this->tipoPrincipal($recepcion, $lado),
            'recepcion' => $recepcion, 'guia_recepcion' => $guiaRecepcion, 'guia_despacho' => $guiaDespacho,
            'numero' => $guiaRecepcion?->numero_guia_despacho ?? ($guiaDespacho ? ($guiaDespacho->numero_guia_despacho ?: $guiaDespacho->numero)
                : ($lado === 'recibidos' ? $recepcion->numero_guia_despacho : ($recepcion->numero_guia_salida ?: $raices->first()?->numero_documento))) ?? 'sin número',
            'fecha' => $fecha ? CarbonImmutable::instance($fecha) : null,
            'temporada_id' => $temporadaId, 'raices' => $raices, 'netos' => $netos, 'porciones' => $porciones, 'reasignado' => $reasignado,
            'vigente' => $vigente, 'entradas' => $entradas, 'netos_entradas' => $netosEntradas, 'envases' => $envases,
            'origen' => $origen, 'actual' => $actual, 'impedimento' => $impedimento,
        ];
    }

    /** Asientos registrados de un lado de la recepción: ingresos o salida del destare, y sus ajustes de administración. */
    private function raices(RecepcionRomana $recepcion, string $lado, ?GuiaRecepcionRomana $guia, bool $bloquear = false): Collection
    {
        $tipos = $lado === 'recibidos' ? ($guia ? [TipoMovimientoEnvase::RecepcionFruta] : self::RECIBIDOS) : [TipoMovimientoEnvase::SalidaRecepcionFruta];

        return MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)->where('signo_cuenta', '!=', 0)
            ->where(fn ($q) => $q->whereIn('tipo_movimiento', array_map(fn (TipoMovimientoEnvase $t): string => $t->value, $tipos))
                ->when(! $guia, fn ($q) => $q->orWhere(fn ($q) => $q->where('tipo_movimiento', TipoMovimientoEnvase::AjusteCuenta->value)
                    ->where('datos->ajuste_recepcion->lado', $lado))))
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->orderBy('ocurrido_at')->orderBy('id')->get();
    }

    /**
     * Lo vigente de cada asiento como lo ve el cliente (con sus anulaciones, correcciones y reasignaciones), lo
     * que sería sin reasignaciones y lo que le quitaron las reasignaciones de esta guía.
     *
     * @return array{0: array<string, int>, 1: array<string, int>, 2: array<string, int>}
     */
    private function netos(Collection $raices, string $clave, bool $bloquear): array
    {
        $ids = $raices->pluck('id')->all();
        if ($ids === []) {
            return [[], [], []];
        }
        $vinculados = MovimientoEnvase::query()->whereNotIn('id', $ids)
            ->where(fn ($q) => $q->whereIn('movimiento_origen_id', $ids)->orWhereIn('datos->reversa_de_movimiento_id', $ids)
                ->orWhereIn('datos->movimiento_conciliado_id', $ids)->orWhereIn('datos->reasignacion->raiz_id', $ids))
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->get();
        $porId = $raices->keyBy('id')->union($vinculados->keyBy('id'));
        $netos = $naturales = $reasignado = [];
        foreach ($raices as $raiz) {
            $netos[$raiz->id] = $naturales[$raiz->id] = $raiz->cantidad * $raiz->signo_cuenta;
            $reasignado[$raiz->id] = 0;
        }
        foreach ($vinculados as $vinculado) {
            $raizId = ServicioCuentaCorrienteEnvases::corrige($vinculado, $porId);
            if ($raizId === null || ! isset($netos[$raizId])) {
                continue;
            }
            $impacto = $vinculado->cantidad * $vinculado->signo_cuenta;
            $netos[$raizId] += $impacto;
            if ($vinculado->tipo_movimiento === TipoMovimientoEnvase::ReasignacionSalida) {
                if (($vinculado->datos['reasignacion']['documento_clave'] ?? null) === $clave) {
                    $reasignado[$raizId] += $impacto;
                }
            } else {
                $naturales[$raizId] += $impacto;
            }
        }

        return [$netos, $naturales, $reasignado];
    }

    /**
     * Guías de una recepción con varias que se pueden reasignar por separado: cada envase con fruta está en un
     * solo asiento y lo validado por guía suma lo ingresado, como las muestra el estado de cuenta.
     *
     * @return Collection<int, GuiaRecepcionRomana>|null
     */
    private function guiasSeparables(RecepcionRomana $recepcion, Collection $raices): ?Collection
    {
        $guias = GuiaRecepcionRomana::query()->where('recepcion_romana_id', $recepcion->id)->where('vigente', 1)
            ->whereNotNull('envases_validados')->orderBy('secuencia')->get();
        if ($guias->count() < 2) {
            return null;
        }
        [, $naturales] = $this->netos($raices, '', false);
        $porTipo = [];
        foreach ($raices as $raiz) {
            if ($naturales[$raiz->id] === 0) {
                continue;
            }
            if ($raiz->tipo_movimiento !== TipoMovimientoEnvase::RecepcionFruta) {
                // Ajustes u otros ingresos de la recepción: no se pueden partir por guía.
                return null;
            }
            $tipo = $raiz->tipo_envase->value;
            if (isset($porTipo[$tipo])) {
                return null;
            }
            $porTipo[$tipo] = $naturales[$raiz->id];
        }
        foreach ($porTipo as $tipo => $cantidad) {
            $validado = $guias->sum(fn (GuiaRecepcionRomana $g): int => (int) (collect($g->envases_validados)->firstWhere('tipo_envase', $tipo)['cantidad'] ?? 0));
            if ($validado !== $cantidad) {
                return null;
            }
        }

        return $porTipo === [] ? null : $guias;
    }

    /** @return list<array{codigo: string, nombre: ?string}> productores a los que la cuenta atribuye hoy la guía, sin reasignar */
    private function productoresOrigen(?RecepcionRomana $recepcion, string $lado, ?GuiaRecepcionRomana $guia, ?GuiaDespachoEnvase $despacho): array
    {
        $simple = fn (?array $p): array => empty($p['codigo']) ? [] : [['codigo' => (string) $p['codigo'], 'nombre' => $p['nombre'] ?? null]];
        if ($despacho) {
            return $simple($despacho->productorDestino());
        }
        if ($lado === 'entregados') {
            return $simple(app(ProductoresRecepcion::class)->salida($recepcion));
        }
        if ($recepcion->esDevolucionEnvases()) {
            return $simple($recepcion->datosDevolucion()['productor'] ?? null);
        }
        if ($guia) {
            return SegmentoValidacionMp::query()->where('guia_recepcion_romana_id', $guia->id)
                ->whereHas('validacion', fn ($v) => $v->where('estado', EstadoValidacionMp::Validada->value))
                ->with('csg.productor')->get()
                ->map(fn (SegmentoValidacionMp $s): ?array => ($codigo = $s->csg_snapshot ?: $s->csg?->codigo)
                    ? ['codigo' => (string) $codigo, 'nombre' => $s->csg?->productor?->razon_social ?: $s->csg?->predio] : null)
                ->filter()->unique('codigo')->values()->all();
        }

        return array_map(fn (array $p): array => ['codigo' => $p['codigo'], 'nombre' => $p['nombre']],
            app(ProductoresRecepcion::class)->opciones($recepcion));
    }

    private function tipoPrincipal(?RecepcionRomana $recepcion, string $lado): TipoMovimientoEnvase
    {
        return match (true) {
            $recepcion === null => TipoMovimientoEnvase::DespachoCliente,
            $lado === 'entregados' => TipoMovimientoEnvase::SalidaRecepcionFruta,
            $recepcion->esDevolucionEnvases() => TipoMovimientoEnvase::DevolucionEnvases,
            $recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases => TipoMovimientoEnvase::RecepcionArriendo,
            default => TipoMovimientoEnvase::RecepcionFruta,
        };
    }

    private function registrar(array $documento, string $operacionId, string $hash, Cliente $destino, ?array $productor, bool $deshace, string $motivo, User $usuario): ReasignacionEnvases
    {
        $actual = $documento['actual'];

        return ReasignacionEnvases::create([
            'operacion_id' => $operacionId, 'payload_hash' => $hash, 'temporada_id' => $documento['temporada_id'],
            'documento_clave' => $documento['clave'], 'lado' => $documento['lado'],
            'recepcion_romana_id' => $documento['recepcion']?->id, 'guia_recepcion_romana_id' => $documento['guia_recepcion']?->id,
            'guia_despacho_envase_id' => $documento['guia_despacho']?->id, 'numero_documento' => $documento['numero'],
            'cliente_origen_id' => $actual['cliente']->id, 'productor_origen' => count($actual['productores']) === 1 ? $actual['productores'][0] : null,
            'cliente_destino_id' => $destino->id, 'productor_destino' => $productor,
            'deshace' => $deshace, 'vigente' => ! $deshace,
            'envases' => array_map(fn (array $e): array => ['tipo_envase' => $e['tipo_envase']->value, 'propiedad' => $e['propiedad']->value,
                'cantidad' => $e['cantidad']], $documento['envases']),
            'motivo' => $motivo, 'user_id' => $usuario->id, 'registrado_at' => now(),
        ]);
    }

    private function contexto(array $documento, ReasignacionEnvases $registro, Cliente $destino, ?array $productor, User $usuario): array
    {
        $cuenta = fn (Cliente $c): array => ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre];
        $actual = $documento['actual'];

        return [
            'id' => $registro->id, 'operacion_id' => $registro->operacion_id, 'documento_clave' => $documento['clave'], 'lado' => $documento['lado'],
            'tipo' => $documento['tipo']->value, 'deshace' => $registro->deshace,
            'recepcion_id' => $documento['recepcion']?->id, 'numero_recepcion' => $documento['recepcion']?->numero_recepcion,
            'guia_recepcion_id' => $documento['guia_recepcion']?->id, 'guia_despacho_id' => $documento['guia_despacho']?->id,
            'fecha_documento' => $documento['fecha']?->toAtomString(),
            // De dónde a dónde, para el kardex y Oficina.
            'desde_cuenta' => [...$cuenta($actual['cliente']), 'productor' => count($actual['productores']) === 1 ? $actual['productores'][0] : null],
            'hacia_cuenta' => [...$cuenta($destino), 'productor' => $productor],
            'motivo' => $registro->motivo, 'usuario_id' => $usuario->id, 'usuario' => $usuario->name,
            'registrado_at' => $registro->registrado_at->toAtomString(),
        ];
    }

    /** Descuenta (o devuelve) envases a un asiento: queda sumado a él, en su misma fecha y cuenta. */
    private function salida(MovimientoEnvase $raiz, int $impacto, array $contexto, string $donde): void
    {
        if ($impacto === 0) {
            return;
        }
        MovimientoEnvase::create([
            'operacion_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "reasignacion-envases:{$contexto['operacion_id']}:salida:{$raiz->id}")->toString(),
            'temporada_id' => $raiz->temporada_id, 'cliente_id' => $raiz->cliente_id,
            'documento_tipo' => self::DOCUMENTO, 'documento_id' => $contexto['id'], 'numero_documento' => $raiz->numero_documento,
            'tipo_movimiento' => TipoMovimientoEnvase::ReasignacionSalida, 'tipo_envase' => $raiz->tipo_envase, 'propiedad' => $raiz->propiedad,
            'cantidad' => abs($impacto), 'signo_cuenta' => $impacto > 0 ? 1 : -1, 'signo_existencia' => 0,
            'ocurrido_at' => $raiz->ocurrido_at, 'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente,
            'creado_por_user_id' => $contexto['usuario_id'],
            'datos' => ['reasignacion' => [...$contexto, 'raiz_id' => $raiz->id, 'desde' => $donde]],
        ]);
    }

    /** @param array{tipo_envase: TipoEnvaseRomana, propiedad: PropiedadEnvase, cantidad: int} $envase */
    private function entrada(array $documento, Cliente $destino, array $productor, array $envase, array $contexto): void
    {
        $fecha = $documento['fecha'] ?? now();
        $devolucion = $documento['raices']->map(fn (MovimientoEnvase $m): ?array => $m->datos['devolucion'] ?? null)->filter()->first();
        MovimientoEnvase::create([
            'operacion_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "reasignacion-envases:{$contexto['operacion_id']}:entrada:{$envase['tipo_envase']->value}:{$envase['propiedad']->value}")->toString(),
            'temporada_id' => $documento['temporada_id'], 'cliente_id' => $destino->id,
            'documento_tipo' => self::DOCUMENTO, 'documento_id' => $contexto['id'], 'numero_documento' => $documento['numero'],
            'tipo_movimiento' => TipoMovimientoEnvase::ReasignacionEntrada, 'tipo_envase' => $envase['tipo_envase'], 'propiedad' => $envase['propiedad'],
            'cantidad' => abs($envase['cantidad']), 'signo_cuenta' => $envase['cantidad'] > 0 ? 1 : -1, 'signo_existencia' => 0,
            'ocurrido_at' => $fecha instanceof DateTimeInterface ? $fecha : CarbonImmutable::parse($fecha),
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $contexto['usuario_id'],
            'datos' => ['reasignacion' => [...$contexto, 'productor' => $productor], ...($devolucion ? ['devolucion' => $devolucion] : [])],
        ]);
    }

    private function reintento(string $operacionId, string $hash): ?ReasignacionEnvases
    {
        $existente = ReasignacionEnvases::query()->where('operacion_id', $operacionId)->first();
        if ($existente && $existente->payload_hash !== $hash) {
            throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
        }

        return $existente;
    }

    /** Lo que Oficina muestra de una guía antes de reasignarla. */
    private function vista(array $documento): array
    {
        $cliente = fn (Cliente $c): array => ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre];
        $salida = $documento['lado'] === 'entregados';
        $historial = ReasignacionEnvases::query()->with(['clienteDestino:id,codigo,nombre', 'clienteOrigen:id,codigo,nombre', 'usuario:id,name'])
            ->where('documento_clave', $documento['clave'])->orderByDesc('registrado_at')->orderByDesc('created_at')->get();

        return [
            'clave' => $documento['clave'], 'lado' => $documento['lado'], 'numero' => $documento['numero'],
            'movimiento' => match ($documento['tipo']) {
                TipoMovimientoEnvase::RecepcionFruta => 'Ingreso con fruta',
                TipoMovimientoEnvase::SalidaRecepcionFruta => 'Entregados en el destare',
                TipoMovimientoEnvase::DespachoCliente => 'Despacho de envases',
                TipoMovimientoEnvase::DevolucionEnvases => 'Devolución de envases',
                default => ServicioCuentaCorrienteEnvases::etiquetaMovimiento($documento['tipo']),
            },
            'titulo' => ($salida ? ($documento['guia_despacho'] ? 'Guía de despacho N° ' : 'Guía de salida N° ') : 'Guía de ingreso N° ').$documento['numero'],
            'recepcion' => $documento['recepcion']?->numero_recepcion, 'despacho' => $documento['guia_despacho']?->numero,
            'fecha' => $documento['fecha']?->setTimezone(config('app.operational_timezone'))->format('Y-m-d H:i:s'),
            'envases' => array_map(fn (array $e): array => ['tipo_envase' => $e['tipo_envase']->value, 'nombre' => $e['tipo_envase']->etiqueta(),
                'propiedad' => $e['propiedad']->value, 'propiedad_nombre' => ServicioCuentaCorrienteEnvases::etiquetaPropiedad($e['propiedad']),
                // Efecto en la cuenta: las salidas en negativo.
                'cantidad' => $e['cantidad']], $documento['envases']),
            'origen' => ['cliente' => $cliente($documento['origen']['cliente']), 'productores' => $documento['origen']['productores']],
            'actual' => ['cliente' => $cliente($documento['actual']['cliente']), 'productores' => $documento['actual']['productores']],
            'reasignada' => $documento['vigente'] !== null, 'reasignacion_vigente_id' => $documento['vigente']?->id,
            'disponible' => $documento['impedimento'] === null, 'motivo_no_disponible' => $documento['impedimento'],
            'historial' => $historial->map(fn (ReasignacionEnvases $r): array => [
                'id' => $r->id, 'deshace' => $r->deshace, 'vigente' => $r->vigente,
                'origen' => ['cliente' => $r->clienteOrigen ? $cliente($r->clienteOrigen) : null, 'productor' => $r->productor_origen],
                'destino' => ['cliente' => $r->clienteDestino ? $cliente($r->clienteDestino) : null, 'productor' => $r->productor_destino],
                'motivo' => $r->motivo, 'usuario' => $r->usuario?->name, 'registrado_at' => $r->registrado_at?->toAtomString(),
            ])->values()->all(),
        ];
    }

    /** La tabla existe desde su migración: las migraciones anteriores que crean movimientos no la consultan. */
    private function tablaLista(): bool
    {
        return self::$tablaLista = self::$tablaLista || Schema::hasTable('reasignaciones_envases');
    }
}
