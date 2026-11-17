<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\ReasignacionEnvases;
use App\Models\ReasignacionProductorEnvases;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reasigna un movimiento de envases (una guía de ingreso o de salida) a otro productor CSG de la misma cuenta,
 * completo o por cantidades de cada envase.
 *
 * No crea asientos: la cuenta del cliente, la existencia, los kilos y la guía no cambian. Solo cambia a qué
 * productor se atribuyen esas cantidades en el estado de cuenta (PDF) y en el Excel por productor, que muestran
 * la asignación definitiva sin filas de corrección. Cada reasignación queda como historial interno.
 *
 * Es independiente de «Reasignar guía» (que mueve la guía completa, también a otra cuenta): una guía reasignada
 * así no se reasigna por productor hasta deshacer esa reasignación.
 */
class ServicioReasignacionProductorEnvases
{
    private const RECIBIDOS = [TipoMovimientoEnvase::RecepcionFruta, TipoMovimientoEnvase::RecepcionArriendo, TipoMovimientoEnvase::DevolucionEnvases];

    /** Movimientos de la cuenta que se pueden reasignar entre productores. */
    public static function reasignable(MovimientoEnvase $movimiento): bool
    {
        return $movimiento->signo_cuenta !== 0 && ServicioCuentaCorrienteEnvases::claveProductor($movimiento) !== null;
    }

    /** Documento base de una reasignación: sin la parte por guía de ingreso. */
    public static function baseDe(string $clave): string
    {
        return ReasignacionesProductorVigentes::base($clave);
    }

    /** Guías del movimiento con sus productores y cantidades de hoy, para el diálogo de Oficina. */
    public function opciones(MovimientoEnvase $movimiento): array
    {
        [$documentos, $impedimento] = $this->documentos($this->base($movimiento), $movimiento->cliente, false);
        if ($documentos === []) {
            throw new ConflictoOperacion($impedimento ?? 'El movimiento ya no tiene envases vigentes en la cuenta (por ejemplo, está anulado o conciliado con otra salida).');
        }

        return array_map(fn (array $documento): array => $this->vista($documento), $documentos);
    }

    public function reasignar(array $datos, User $usuario): ReasignacionProductorEnvases
    {
        Gate::forUser($usuario)->authorize('ajustar-cuenta-envases');
        $cantidades = [];
        foreach ($datos['envases'] ?? [] as $envase) {
            $tipo = (string) $envase['tipo_envase'];
            $cantidades[$tipo] = ($cantidades[$tipo] ?? 0) + (int) $envase['cantidad'];
        }
        ksort($cantidades);
        $payload = [
            'documento_clave' => (string) $datos['documento_clave'],
            'productor_origen' => ($origen = trim((string) ($datos['productor_origen_codigo'] ?? ''))) === '' ? null : $origen,
            'productor_csg_id' => (string) $datos['productor_csg_id'],
            'razon_social' => trim((string) $datos['razon_social']),
            'modalidad' => (string) $datos['modalidad'],
            'envases' => $datos['modalidad'] === ReasignacionProductorEnvases::PARCIAL ? $cantidades : [],
            'motivo' => trim((string) $datos['motivo']),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($datos, $payload, $hash, $usuario): ReasignacionProductorEnvases {
            // Primero el bloqueo de la recepción o el despacho: así lo que se lee después ya incluye la
            // reasignación que otro usuario haya guardado sobre el mismo movimiento.
            [$recepcion, $despacho] = $this->bloquear($payload['documento_clave']);
            $existente = ReasignacionProductorEnvases::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($existente) {
                if ($existente->payload_hash !== $hash) {
                    throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
                }

                return $existente;
            }
            $cliente = $recepcion?->cliente ?? $despacho->cliente;
            [$documentos, $impedimento] = $this->documentos(ReasignacionesProductorVigentes::base($payload['documento_clave']), $cliente, true);
            $documento = collect($documentos)->firstWhere('clave', $payload['documento_clave'])
                ?? throw new ConflictoOperacion($impedimento ?? 'La guía ya no tiene envases en la cuenta de este cliente. Actualiza la cuenta corriente.');
            if ($documento['impedimento']) {
                throw new ConflictoOperacion($documento['impedimento']);
            }
            $claveOrigen = ResumenEnvasesPorCsg::clave(['codigo' => $payload['productor_origen']]);
            $origen = $documento['productores'][$claveOrigen] ?? null;
            if ($origen === null || array_sum($origen['envases']) <= 0) {
                throw ValidationException::withMessages(['productor_origen_codigo' => 'El movimiento ya no está asignado a ese productor. Actualiza la cuenta corriente y vuelve a intentarlo.']);
            }
            $productor = $cliente->productoresCsg()->wherePivot('activo', true)->whereKey($payload['productor_csg_id'])->first()
                ?? throw ValidationException::withMessages(['productor_csg_id' => 'Selecciona un productor CSG activo y declarado en esta cuenta corriente.']);
            if (self::comparable($productor->razon_social) !== self::comparable($payload['razon_social'])) {
                throw ValidationException::withMessages(['razon_social' => 'La razón social no corresponde al CSG '.$productor->codigo.': es «'.$productor->razon_social.'».']);
            }
            if ('csg:'.trim((string) $productor->codigo) === $claveOrigen) {
                throw ValidationException::withMessages(['productor_csg_id' => 'El movimiento ya está asignado a ese productor.']);
            }

            $disponibles = array_filter($origen['envases'], fn (int $cantidad): bool => $cantidad > 0);
            $mover = $payload['modalidad'] === ReasignacionProductorEnvases::COMPLETA ? $disponibles : array_filter($payload['envases'], fn (int $c): bool => $c !== 0);
            if ($mover === []) {
                throw ValidationException::withMessages(['envases' => 'Indica cuántos envases se reasignan.']);
            }
            foreach ($mover as $tipo => $cantidad) {
                $nombre = TipoEnvaseRomana::tryFrom($tipo)?->etiqueta() ?? $tipo;
                if (! isset($disponibles[$tipo])) {
                    throw ValidationException::withMessages(['envases' => 'El productor de origen no tiene '.mb_strtolower($nombre).' en este movimiento.']);
                }
                if ($cantidad < 0 || $cantidad > $disponibles[$tipo]) {
                    throw ValidationException::withMessages(['envases' => 'Puedes reasignar hasta '.$disponibles[$tipo].' '.mb_strtolower($nombre).': es lo que tiene el productor de origen en este movimiento.']);
                }
            }
            $completa = $mover == $disponibles;

            return ReasignacionProductorEnvases::create([
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'temporada_id' => $documento['temporada_id'],
                'cliente_id' => $cliente->id, 'documento_clave' => $documento['clave'], 'lado' => $documento['lado'],
                'recepcion_romana_id' => $recepcion?->id, 'guia_recepcion_romana_id' => $documento['guia_recepcion_id'],
                'guia_despacho_envase_id' => $despacho?->id, 'numero_documento' => Str::limit((string) ($documento['numero'] ?: 'sin número'), 80, ''),
                'titulo' => Str::limit($documento['titulo'], 160, ''), 'fecha_documento' => $documento['fecha_at'],
                'productor_origen' => ['codigo' => $origen['codigo'], 'nombre' => $origen['nombre']],
                'productor_destino' => ['id' => $productor->id, 'codigo' => (string) $productor->codigo, 'nombre' => $productor->razon_social],
                'modalidad' => $completa ? ReasignacionProductorEnvases::COMPLETA : ReasignacionProductorEnvases::PARCIAL,
                'envases' => array_map(fn (string $tipo, int $cantidad): array => ['tipo_envase' => $tipo, 'cantidad' => $cantidad], array_keys($mover), $mover),
                'disponibles' => array_map(fn (string $tipo, int $cantidad): array => ['tipo_envase' => $tipo, 'cantidad' => $cantidad], array_keys($disponibles), $disponibles),
                'motivo' => $payload['motivo'], 'user_id' => $usuario->id, 'registrado_at' => now(),
            ]);
        }, attempts: 3);
    }

    /** Documento base del movimiento: el ingreso, la salida del destare o el despacho. */
    private function base(MovimientoEnvase $movimiento): string
    {
        if (! self::reasignable($movimiento)) {
            throw ValidationException::withMessages(['movimiento' => 'Solo se reasignan entre productores los ingresos y salidas de envases de una guía: no los ajustes, correcciones ni las guías ya reasignadas.']);
        }

        return ServicioCuentaCorrienteEnvases::claveProductor($movimiento);
    }

    /** @return array{0: ?RecepcionRomana, 1: ?GuiaDespachoEnvase} */
    private function bloquear(string $clave): array
    {
        $partes = explode(':', $clave);

        return match (true) {
            $partes[0] === 'recepcion' && isset($partes[1]) => [RecepcionRomana::query()->lockForUpdate()->find($partes[1])
                ?? throw ValidationException::withMessages(['documento_clave' => 'La recepción indicada no existe.']), null],
            $partes[0] === 'despacho' && isset($partes[1]) => [null, GuiaDespachoEnvase::query()->lockForUpdate()->find($partes[1])
                ?? throw ValidationException::withMessages(['documento_clave' => 'El despacho indicado no existe.'])],
            default => throw ValidationException::withMessages(['documento_clave' => 'El movimiento indicado no se puede reasignar entre productores.']),
        };
    }

    /**
     * Guías del documento base con su atribución por productor de hoy, tal como la muestran el estado de cuenta
     * y el Excel (con las reasignaciones anteriores ya aplicadas).
     *
     * @return array{0: list<array<string, mixed>>, 1: ?string} guías y el motivo por el que hoy no se reasignan
     */
    private function documentos(string $base, Cliente $cliente, bool $bloquear): array
    {
        $partes = explode(':', $base);
        $recepcion = $partes[0] === 'recepcion' ? RecepcionRomana::query()->find($partes[1]) : null;
        $despacho = $partes[0] === 'despacho' ? GuiaDespachoEnvase::query()->find($partes[1]) : null;
        $temporada = $recepcion?->temporada ?? $despacho?->temporada;
        if ($temporada === null) {
            return [[], null];
        }
        $estado = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($cliente, $temporada, null, null);
        $activa = app(ServicioTemporadaActiva::class)->buscar(bloquear: $bloquear)?->id === $temporada->id;
        // Una guía movida con «Reasignar guía» ya no está aquí: su ingreso o salida completa no se reparte. Una
        // sola guía de un camión con varias no impide repartir las otras guías del mismo camión.
        $reasignada = ReasignacionEnvases::query()->with('clienteDestino:id,nombre')->where('vigente', true)
            ->when($recepcion, fn ($q) => $q->where('recepcion_romana_id', $recepcion->id)->where('lado', $partes[2] ?? 'recibidos')
                ->whereNull('guia_recepcion_romana_id'))
            ->when($despacho, fn ($q) => $q->where('guia_despacho_envase_id', $despacho->id))->first();
        $impedimento = match (true) {
            ! $activa => 'Solo se reasignan movimientos de la temporada activa.',
            $recepcion && ($recepcion->estado === EstadoRecepcionRomana::Anulada || $recepcion->eliminada_at !== null) => 'La recepción está anulada o eliminada.',
            $recepcion && $recepcion->estado_validacion_mp !== EstadoValidacionMp::Validada => 'La recepción aún no está validada: todavía no mueve la cuenta de envases.',
            $despacho && $despacho->estado !== EstadoGuiaDespachoEnvase::Confirmada => 'El despacho no está confirmado o está anulado.',
            $reasignada !== null => 'La guía '.$reasignada->numero_documento.' está reasignada con «Reasignar guía» a '
                .($reasignada->clienteDestino?->nombre ?? 'otra cuenta').(! empty($reasignada->productor_destino['codigo']) ? ' · CSG '.$reasignada->productor_destino['codigo'] : '')
                .'. Para repartirla entre productores, deshaz esa reasignación primero.',
            default => null,
        };
        $documentos = [];
        foreach ($estado['atribucion_documentos'] as $clave => $documento) {
            if ($clave !== $base && ! str_starts_with($clave, $base.':guia:')) {
                continue;
            }
            $productores = array_filter($documento['productores'], fn (array $p): bool => array_sum($p['envases']) > 0);
            if ($productores === []) {
                continue;
            }
            uasort($productores, [ResumenEnvasesPorCsg::class, 'comparar']);
            $documentos[] = [...$documento, 'productores' => $productores, 'lado' => str_ends_with($base, ':entregados') || $despacho ? 'entregados' : 'recibidos',
                'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id,
                'guia_recepcion_id' => str_contains($clave, ':guia:') ? substr($clave, strrpos($clave, ':') + 1) : null,
                'impedimento' => $impedimento];
        }

        return [$documentos, $impedimento];
    }

    private function vista(array $documento): array
    {
        $historial = ReasignacionProductorEnvases::query()->with('usuario:id,name')->where('cliente_id', $documento['cliente_id'])
            ->where('documento_clave', $documento['clave'])->orderByDesc('registrado_at')->orderByDesc('created_at')->get();
        $envases = fn (array $porTipo): array => collect($porTipo)->filter(fn (int $c): bool => $c > 0)
            ->map(fn (int $cantidad, string $tipo): array => ['tipo_envase' => $tipo, 'nombre' => TipoEnvaseRomana::tryFrom($tipo)?->etiqueta() ?? $tipo,
                'orden' => TipoEnvaseRomana::tryFrom($tipo)?->orden() ?? 99, 'cantidad' => $cantidad])
            ->sortBy('orden')->map(fn (array $e): array => collect($e)->except('orden')->all())->values()->all();
        $lista = fn (array $lineas): array => collect($lineas)->map(fn (array $e): array => [...$e,
            'nombre' => TipoEnvaseRomana::tryFrom($e['tipo_envase'])?->etiqueta() ?? $e['tipo_envase']])->all();

        return [
            'clave' => $documento['clave'], 'lado' => $documento['lado'], 'titulo' => $documento['titulo'], 'numero' => $documento['numero'],
            'movimiento' => $documento['movimiento'], 'fecha' => $documento['fecha'], 'referencia' => $documento['referencia'], 'chofer' => $documento['chofer'],
            'productores' => array_values(array_map(fn (array $p): array => ['codigo' => $p['codigo'], 'nombre' => $p['nombre'],
                'envases' => $envases($p['envases'])], $documento['productores'])),
            'disponible' => $documento['impedimento'] === null, 'motivo_no_disponible' => $documento['impedimento'],
            'historial' => $historial->map(fn (ReasignacionProductorEnvases $r): array => [
                'id' => $r->id, 'modalidad' => $r->modalidad, 'origen' => $r->productor_origen, 'destino' => $r->productor_destino,
                'envases' => $lista($r->envases), 'motivo' => $r->motivo, 'usuario' => $r->usuario?->name,
                'registrado_at' => $r->registrado_at?->toAtomString(),
            ])->values()->all(),
        ];
    }

    private static function comparable(?string $texto): string
    {
        return ResumenEnvasesPorCsg::razonSocial($texto);
    }
}
